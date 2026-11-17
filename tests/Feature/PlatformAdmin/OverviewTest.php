<?php

namespace Tests\Feature\PlatformAdmin;

use App\Services\Marketplace\InventoryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SeedsPlatformMarketData;

class OverviewTest extends PlatformAdminTestCase
{
    use SeedsPlatformMarketData;

    private const URL = self::PREFIX . '/overview';

    private const NO_SALES = ['by_currency' => null, 'reason' => 'order_currency_not_recorded'];

    private ?string $owner = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMarketScenario();
    }

    public function test_the_platform_owner_gets_every_market_with_exact_numbers_for_the_default_window(): void
    {
        $response = $this->overview();

        $response->assertOk();
        $markets = collect($response->json('data.markets'))->keyBy('market');
        $this->assertSame(['SA', 'AE', 'KW', 'QA', 'BH', 'OM'], $markets->keys()->all());

        $this->assertSame($this->market(code: 'SA', currency: 'SAR', active: true, total: 5, byStatus: ['pending' => 1, 'confirmed' => 1, 'processing' => 1, 'delivered' => 2], latest: '2026-11-17 18:00:00', backlog: 4, refunds: [2, 1], stock: [1, 3], alerts: [0, 0, 1]), $markets['SA']);
        $this->assertSame($this->market(code: 'AE', currency: 'AED', active: false, total: 1, byStatus: ['processing' => 1], latest: '2026-11-14 10:00:00', backlog: 1, refunds: [0, 0], stock: [0, 1], alerts: [0, 0, 0]), $markets['AE']);
        $this->assertSame($this->market(code: 'KW', currency: 'KWD', active: false, total: 3, byStatus: ['pending' => 1, 'delivered' => 1, 'canceled' => 1], latest: '2026-11-16 10:00:00', backlog: 1, refunds: [1, 1], stock: [2, 1], alerts: [0, 0, 1]), $markets['KW']);
        foreach (['QA' => 'QAR', 'BH' => 'BHD', 'OM' => 'OMR'] as $code => $currency) {
            $this->assertSame($this->market(code: $code, currency: $currency, active: false, total: 0, byStatus: [], latest: null, backlog: 0, refunds: [0, 0], stock: [0, 0], alerts: [0, 0, 0]), $markets[$code], "{$code} has nothing, and says so with zeros");
        }
    }

    public function test_orders_that_cannot_be_given_a_market_are_a_bucket_of_their_own_and_never_counted_as_saudi(): void
    {
        $unattributed = $this->overview()->json('data.unattributed');

        $this->assertSame([
            'orders' => ['total' => 4, 'by_status' => $this->statuses(['pending' => 2, 'confirmed' => 1, 'delivered' => 1]), 'latest_at' => $this->zulu('2026-11-13 13:00:00')],
            'sales' => self::NO_SALES,
            'pending_fulfilment' => ['count' => 3],
            'returns' => ['refund_requests' => 2, 'pending' => 1],
            'stock' => ['zero' => 1, 'low' => 0],
            'reason' => 'no_single_market',
        ], $unattributed);
    }

    public function test_money_is_never_reported_because_an_order_carries_no_currency(): void
    {
        $response = $this->overview();

        foreach ($response->json('data.markets') as $market) {
            $this->assertSame(self::NO_SALES, $market['sales'], $market['market']);
        }
        $this->assertSame(self::NO_SALES, $response->json('data.unattributed.sales'));
        foreach (['amount', 'revenue', 'total_sales', 'price', 'sum'] as $word) {
            $this->assertStringNotContainsString($word, strtolower($response->getContent()), 'no figure of money is in the answer, so none can be added to another currency');
        }
        $this->assertSame(['per_order' => false, 'reason' => 'order_currency_not_recorded'], $response->json('data.attribution.currency'));
    }

    public function test_the_answer_names_the_rule_that_decided_the_market_of_every_figure(): void
    {
        $this->assertSame([
            'orders' => ['rule' => 'all_lines_one_market', 'via' => 'order_details.product_id > products.storefront_country'],
            'refund_requests' => ['rule' => 'product_market', 'via' => 'refund_requests.product_id > products.storefront_country'],
            'stock' => ['rule' => 'live_physical_products_by_market', 'via' => 'products.storefront_country'],
            'products_country' => 'column',
            'currency' => ['per_order' => false, 'reason' => 'order_currency_not_recorded'],
        ], $this->overview()->json('data.attribution'));
    }

    public function test_the_window_is_the_last_thirty_days_by_default_and_the_answer_says_so(): void
    {
        $response = $this->overview();

        $this->assertSame(['from' => '2026-10-19', 'to' => '2026-11-17', 'days' => 30, 'clamped' => false, 'timezone' => 'Asia/Dhaka'], $response->json('data.window'));
        $this->assertSame(Carbon::parse('2026-11-17 12:00:00')->utc()->toIso8601ZuluString(), $response->json('data.generated_at'));
        $this->assertSame(['mode' => 'live', 'cached' => false], $response->json('data.freshness'));
        $this->assertSame(['scope' => 'all', 'market' => 'all'], ['scope' => $response->json('data.scope'), 'market' => $response->json('data.market')]);
    }

    public function test_the_first_and_last_moment_of_the_window_are_in_and_the_moments_just_outside_are_out(): void
    {
        $response = $this->overview('?from=2026-10-19&to=2026-10-19');

        $this->assertSame(1, $response->json('data.markets.0.orders.total'), 'the order at midnight of the first day is in; the one a second before is out');
        $this->assertSame(['delivered' => 1], array_filter($response->json('data.markets.0.orders.by_status')));

        $last = $this->overview('?from=2026-11-17&to=2026-11-17');
        $this->assertSame(['pending' => 1, 'processing' => 1], array_filter($last->json('data.markets.0.orders.by_status')), 'a whole day, up to the last second, and not the next one');
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function windows(): array
    {
        return [
            'from and to given' => ['?from=2026-11-10&to=2026-11-12', ['from' => '2026-11-10', 'to' => '2026-11-12', 'days' => 3, 'clamped' => false]],
            'only to: thirty days ending there' => ['?to=2026-11-12', ['from' => '2026-10-14', 'to' => '2026-11-12', 'days' => 30, 'clamped' => false]],
            'only from: up to today' => ['?from=2026-11-10', ['from' => '2026-11-10', 'to' => '2026-11-17', 'days' => 8, 'clamped' => false]],
            'a single day' => ['?from=2026-11-17&to=2026-11-17', ['from' => '2026-11-17', 'to' => '2026-11-17', 'days' => 1, 'clamped' => false]],
            'the longest window allowed' => ['?from=2026-08-18&to=2026-11-17', ['from' => '2026-08-18', 'to' => '2026-11-17', 'days' => 92, 'clamped' => false]],
            'one day too long is cut at the old end' => ['?from=2026-08-17&to=2026-11-17', ['from' => '2026-08-18', 'to' => '2026-11-17', 'days' => 92, 'clamped' => true]],
            'a year is cut to the longest window' => ['?from=2025-11-17&to=2026-11-17', ['from' => '2026-08-18', 'to' => '2026-11-17', 'days' => 92, 'clamped' => true]],
            'a leap day is a day' => ['?from=2028-02-28&to=2028-03-01', ['from' => '2028-02-28', 'to' => '2028-03-01', 'days' => 3, 'clamped' => false]],
        ];
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('windows')]
    public function test_the_window_is_resolved_and_bounded(string $query, array $expected): void
    {
        $window = $this->overview($query)->assertOk()->json('data.window');

        $this->assertSame($expected + ['timezone' => 'Asia/Dhaka'], $window);
    }

    public function test_the_default_and_the_longest_window_are_configuration(): void
    {
        config(['gcc.platform_admin.window_days_default' => 7, 'gcc.platform_admin.window_days_max' => 10]);

        $this->assertSame(['from' => '2026-11-11', 'to' => '2026-11-17', 'days' => 7, 'clamped' => false, 'timezone' => 'Asia/Dhaka'], $this->overview()->json('data.window'));
        $this->assertSame(['from' => '2026-11-08', 'to' => '2026-11-17', 'days' => 10, 'clamped' => true, 'timezone' => 'Asia/Dhaka'], $this->overview('?from=2026-11-01')->json('data.window'));
    }

    public function test_a_default_longer_than_the_longest_is_cut_to_it_and_nonsense_means_the_defaults(): void
    {
        config(['gcc.platform_admin.window_days_default' => 500, 'gcc.platform_admin.window_days_max' => 92]);
        $this->assertSame(92, $this->overview()->json('data.window.days'));

        config(['gcc.platform_admin.window_days_default' => 0, 'gcc.platform_admin.window_days_max' => -3]);
        $this->assertSame(30, $this->overview()->json('data.window.days'));
        $this->assertSame(92, $this->overview('?from=2020-01-01')->json('data.window.days'));
    }

    /** @return array<string, array{0: string}> */
    public static function badWindows(): array
    {
        return [
            'from after to' => ['?from=2026-11-12&to=2026-11-10'],
            'from in the future with no to' => ['?from=2026-12-01'],
            'a word' => ['?from=yesterday'],
            'a date that does not exist' => ['?from=2026-02-31&to=2026-03-02'],
            'a date with a time' => ['?from=2026-11-10T00:00:00&to=2026-11-12'],
            'the wrong order of parts' => ['?from=10-11-2026&to=12-11-2026'],
            'one digit months' => ['?from=2026-1-1&to=2026-1-2'],
            'an array' => ['?from[]=2026-11-10'],
            'a keyed array for to' => ['?to[a]=2026-11-10'],
        ];
    }

    #[DataProvider('badWindows')]
    public function test_a_window_that_cannot_be_read_is_refused_and_nothing_is_computed(string $query): void
    {
        $statements = $this->statementsRunBy(fn () => $this->overview($query)->assertStatus(422)->assertJsonPath('errors.0.code', 'invalid_window'));

        $this->assertSame([], array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'orders') || str_contains($sql, 'products'))), 'no figure was read');
    }

    public function test_a_single_market_is_answered_alone_and_without_the_bucket_that_belongs_to_the_whole(): void
    {
        $response = $this->overview('?market=KW');

        $response->assertOk()->assertJsonPath('data.market', 'KW')->assertJsonPath('data.scope', 'all')->assertJsonPath('data.unattributed', null);
        $this->assertSame(['KW'], array_column($response->json('data.markets'), 'market'));
        $this->assertSame(3, $response->json('data.markets.0.orders.total'));
    }

    public function test_the_market_may_be_written_in_any_case(): void
    {
        $this->assertSame('KW', $this->overview('?market=kw')->assertOk()->json('data.market'));
        $this->assertSame('all', $this->overview('?market=ALL')->assertOk()->json('data.market'));
        $this->assertSame('all', $this->overview('?market=')->assertOk()->json('data.market'));
    }

    /** @return array<string, array{0: string}> */
    public static function badMarkets(): array
    {
        return ['an unknown code' => ['?market=ZZ'], 'a country name' => ['?market=Kuwait'], 'a list' => ['?market[]=KW'], 'a keyed list' => ['?market[a]=KW'], 'three letters' => ['?market=KWT']];
    }

    #[DataProvider('badMarkets')]
    public function test_a_market_that_is_not_one_is_refused_with_the_codes_that_are(string $query): void
    {
        $this->overview($query)->assertStatus(422)->assertExactJson(['errors' => [[
            'code' => 'invalid_market',
            'message' => 'The market must be all or the code of a market.',
            'valid' => ['all', 'SA', 'AE', 'KW', 'QA', 'BH', 'OM'],
        ]]]);
    }

    public function test_a_restricted_viewer_sees_only_their_markets_and_never_the_bucket_of_orders_without_one(): void
    {
        $response = $this->overview(token: $this->mintToken($this->platformAdmin(countries: ['KW', 'AE'])));

        $response->assertOk()->assertJsonPath('data.scope', 'restricted')->assertJsonPath('data.market', 'all')->assertJsonPath('data.unattributed', null);
        $this->assertSame(['AE', 'KW'], array_column($response->json('data.markets'), 'market'));
        $this->assertSame([1, 3], array_column(array_column($response->json('data.markets'), 'orders'), 'total'));
        $this->assertStringNotContainsString('"SA"', $response->getContent());
    }

    public function test_a_restricted_viewer_asking_for_another_market_is_refused_and_the_trail_says_which(): void
    {
        $admin = $this->platformAdmin(countries: ['KW']);
        $token = $this->mintToken($admin);

        $response = $this->overview('?market=SA', $token);

        $response->assertStatus(403)->assertExactJson(['errors' => [['code' => 'market_not_allowed', 'message' => 'This account may not see that market.']]]);
        $this->overview('?market=KW', $token)->assertOk();
        $this->overview('?market=all', $token)->assertOk();

        $lines = DB::table('platform_admin_audit_logs')->orderBy('id')->get();
        $this->assertSame(['SA', 'KW', null], $lines->pluck('market')->all());
        $this->assertSame([403, 200, 200], $lines->map(fn ($line) => $this->contextOf($line)['status'])->all());
    }

    public function test_a_restricted_viewer_cannot_widen_their_scope_with_the_case_of_a_code_or_a_list(): void
    {
        $token = $this->mintToken($this->platformAdmin(countries: ['KW']));

        $this->overview('?market=sa', $token)->assertStatus(403);
        $this->overview('?market[]=SA&market[]=KW', $token)->assertStatus(422);
        $this->overview('?market=KW,SA', $token)->assertStatus(422);
    }

    public function test_an_unknown_market_is_a_422_and_not_a_403_even_for_a_restricted_viewer(): void
    {
        $this->overview('?market=ZZ', $this->mintToken($this->platformAdmin(countries: ['KW'])))->assertStatus(422);
    }

    public function test_a_viewer_with_no_market_gets_an_empty_overview(): void
    {
        $response = $this->overview(token: $this->mintToken($this->platformAdmin(countries: ['XX'])));

        $response->assertOk()->assertJsonPath('data.markets', [])->assertJsonPath('data.unattributed', null)->assertJsonPath('data.scope', 'restricted');
    }

    public function test_a_status_that_is_not_in_the_list_is_counted_under_its_own_name(): void
    {
        $this->order(id: 30, status: 'on_hold', at: '2026-11-16 10:00:00', products: [4]);

        $byStatus = $this->overview('?market=SA')->json('data.markets.0.orders.by_status');

        $this->assertSame(1, $byStatus['on_hold']);
        $this->assertSame(6, array_sum($byStatus));
    }

    public function test_an_order_is_counted_once_however_many_lines_it_has(): void
    {
        $this->order(id: 31, status: 'pending', at: '2026-11-16 10:00:00', products: [1, 2, 3, 4, 5, 6]);

        $this->assertSame(6, $this->overview('?market=SA')->json('data.markets.0.orders.total'));
    }

    public function test_an_order_whose_lines_are_all_one_product_is_that_products_market(): void
    {
        $this->order(id: 32, status: 'pending', at: '2026-11-16 10:00:00', products: [7, 7, 8]);

        $this->assertSame(4, $this->overview('?market=KW')->json('data.markets.0.orders.total'));
    }

    public function test_the_low_stock_rule_is_the_one_of_the_inventory_service_for_every_seller_limit(): void
    {
        DB::table('products')->delete();
        DB::table('sellers')->delete();
        $limits = [1 => 0, 2 => 0.5, 3 => 1, 4 => 1.5, 5 => 7, 6 => 3];
        $id = 100;
        foreach ($limits as $sellerId => $limit) {
            DB::table('sellers')->insert(['id' => $sellerId, 'f_name' => "Shop {$sellerId}", 'status' => 'approved', 'stock_limit' => $limit, 'created_at' => now(), 'updated_at' => now()]);
            foreach (range(0, 10) as $stock) {
                $this->product(id: $id++, country: 'SA', stock: $stock, sellerId: $sellerId);
            }
        }
        foreach (range(0, 10) as $stock) {
            $this->product(id: $id++, country: 'SA', stock: $stock);
        }
        Cache::flush();

        $inventory = app(InventoryService::class);
        $expectedLow = 0;
        $expectedZero = 0;
        foreach (array_keys($limits) as $sellerId) {
            $threshold = max(1, $inventory->stockLimitFor($sellerId));
            foreach (range(0, 10) as $stock) {
                $expectedLow += $stock > 0 && $stock <= $threshold ? 1 : 0;
                $expectedZero += $stock <= 0 ? 1 : 0;
            }
        }
        foreach (range(0, 10) as $stock) {
            $expectedLow += $stock > 0 && $stock <= 5 ? 1 : 0;
            $expectedZero += $stock <= 0 ? 1 : 0;
        }

        $this->assertSame(['zero' => $expectedZero, 'low' => $expectedLow], $this->overview('?market=SA')->json('data.markets.0.stock'));
    }

    public function test_without_the_platform_stock_limit_setting_the_threshold_is_one(): void
    {
        DB::table('business_settings')->where('type', 'stock_limit')->delete();
        Cache::flush();

        $this->assertSame(['zero' => 1, 'low' => 2], $this->overview('?market=SA')->json('data.markets.0.stock'), 'stock 1 is low and 5 is not, and the seller limit of 2 still applies to its own product');
    }

    public function test_before_the_products_migration_every_product_belongs_to_the_default_storefront_and_the_answer_says_so(): void
    {
        Schema::table('products', fn ($table) => $table->dropColumn('storefront_country'));

        $response = $this->overview();

        $response->assertOk()->assertJsonPath('data.attribution.products_country', 'default_storefront');
        $markets = collect($response->json('data.markets'))->keyBy('market');
        $this->assertSame(11, $markets['SA']['orders']['total'], 'every order with a line that is a product is now a Saudi one');
        $this->assertSame([0, 0, 0, 0, 0], [$markets['AE']['orders']['total'], $markets['KW']['orders']['total'], $markets['QA']['orders']['total'], $markets['BH']['orders']['total'], $markets['OM']['orders']['total']]);
        $this->assertSame(['zero' => 4, 'low' => 5], $markets['SA']['stock']);
        $this->assertSame(['refund_requests' => 4, 'pending' => 2], $markets['SA']['returns']);
        $this->assertSame(2, $response->json('data.unattributed.orders.total'), 'an order with no line and one whose product is gone still have no market');
        $this->assertSame(['refund_requests' => 1, 'pending' => 1], $response->json('data.unattributed.returns'));
    }

    public function test_the_default_country_of_that_fallback_is_the_configured_default_storefront(): void
    {
        Schema::table('products', fn ($table) => $table->dropColumn('storefront_country'));
        config(['gcc.storefronts.default_country' => 'KW']);

        $markets = collect($this->overview()->json('data.markets'))->keyBy('market');

        $this->assertSame(11, $markets['KW']['orders']['total']);
        $this->assertSame(0, $markets['SA']['orders']['total']);
    }

    public function test_a_read_runs_the_same_bounded_statements_whatever_the_amount_of_data(): void
    {
        $this->readsOfOneFreshRequest();
        $reads = $this->readsOfOneFreshRequest();

        $touchingOrders = array_filter($reads, static fn (string $sql): bool => str_contains($sql, 'orders'));
        $touchingRefunds = array_filter($reads, static fn (string $sql): bool => str_contains($sql, 'refund_requests'));
        $this->assertCount(2, $touchingOrders, 'the orders of the window, and the backlog, which also says how many of its orders are stuck, for the alerts');
        $this->assertCount(1, $touchingRefunds);
        foreach (array_merge($touchingOrders, $touchingRefunds) as $sql) {
            $this->assertStringContainsString('created_at', $sql, 'a read of orders or refund requests is bounded by a date: ' . $sql);
            $this->assertStringContainsString('group by', strtolower($sql), 'and is summed in the database, not in PHP');
        }

        for ($more = 1; $more <= 60; $more++) {
            $this->product(id: 1000 + $more, country: ['SA', 'AE', 'KW', 'QA'][$more % 4], stock: $more % 3);
            $this->order(id: 1000 + $more, status: 'pending', at: '2026-11-1' . ($more % 7) . ' 10:00:00', products: [1000 + $more, 1]);
            $this->refund(status: 'pending', at: '2026-11-1' . ($more % 7) . ' 10:00:00', product: 1000 + $more);
        }
        $afterMuchMore = $this->readsOfOneFreshRequest();

        $this->assertCount(count($reads), $afterMuchMore, 'sixty more of everything, and not one statement more: a fixed number, not one per market, order or product');
        $this->assertLessThanOrEqual(20, count($reads));
    }

    /**
     * The statements that read in one overview, as a new request would run them: what the container shares for the length of a request is
     * forgotten first. The caller asks once before it counts, so that what the platform caches for hours (its settings) is warm in both counts.
     *
     * @return list<string>
     */
    private function readsOfOneFreshRequest(): array
    {
        $this->app->forgetScopedInstances();
        $statements = $this->statementsRunBy(fn () => $this->overview('?from=2026-08-18&to=2026-11-17')->assertOk());

        return array_values(array_filter($statements, static fn (string $sql): bool => str_starts_with(strtolower($sql), 'select')));
    }

    private function overview(string $query = '', ?string $token = null): TestResponse
    {
        return $this->getJson(self::URL . $query, $this->bearer($token ?? $this->ownerToken()));
    }

    private function ownerToken(): string
    {
        return $this->owner ??= $this->mintToken($this->platformAdmin());
    }

    /**
     * @param array<string, int> $byStatus
     * @param array{0: int, 1: int} $refunds requests and how many are pending
     * @param array{0: int, 1: int} $stock zero and low
     * @param array{0: int, 1: int, 2: int} $alerts critical, warning and info
     *
     * @return array<string, mixed>
     */
    private function market(string $code, string $currency, bool $active, int $total, array $byStatus, ?string $latest, int $backlog, array $refunds, array $stock, array $alerts): array
    {
        return [
            'market' => $code,
            'storefront_currency' => $currency,
            'active' => $active,
            'orders' => ['total' => $total, 'by_status' => $this->statuses($byStatus), 'latest_at' => $latest === null ? null : $this->zulu($latest)],
            'sales' => self::NO_SALES,
            'pending_fulfilment' => ['count' => $backlog],
            'returns' => ['refund_requests' => $refunds[0], 'pending' => $refunds[1]],
            'stock' => ['zero' => $stock[0], 'low' => $stock[1]],
            'alerts' => ['critical' => $alerts[0], 'warning' => $alerts[1], 'info' => $alerts[2]],
        ];
    }

    /**
     * @param array<string, int> $counts
     *
     * @return array<string, int> every status the platform knows, in its order, with zero for the ones that have no order
     */
    private function statuses(array $counts): array
    {
        $all = array_fill_keys(['pending', 'confirmed', 'processing', 'out_for_delivery', 'delivered', 'returned', 'failed', 'canceled'], 0);

        return array_merge($all, $counts);
    }

    private function zulu(string $time): string
    {
        return Carbon::parse($time)->utc()->toIso8601ZuluString();
    }
}
