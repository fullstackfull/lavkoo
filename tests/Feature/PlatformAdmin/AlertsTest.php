<?php

namespace Tests\Feature\PlatformAdmin;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\Support\SeedsPlatformMarketData;

class AlertsTest extends PlatformAdminTestCase
{
    use BuildsStorefrontLegalDocuments;
    use SeedsPlatformMarketData;

    private const URL = self::PREFIX . '/alerts';

    private ?string $owner = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMarketScenario();
        $this->buildStorefrontLegalDocumentsTable();
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.countries.AE.active' => true,
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.platform_admin.alerts' => [
                'zero_stock_warning_count' => 2,
                'zero_stock_critical_count' => 3,
                'pending_age_hours' => 24,
                'pending_warning_count' => 2,
                'pending_critical_count' => 3,
            ],
        ]);
    }

    public function test_the_platform_owner_sees_every_alert_worst_first_then_by_market_then_by_code(): void
    {
        $response = $this->alerts();

        $response->assertOk();
        $this->assertSame([
            ['critical', 'AE', 'legal_not_ready', 'The AE storefront is active but its legal documents are not approved, so it cannot take orders.'],
            ['critical', 'KW', 'legal_not_ready', 'The KW storefront is active but its legal documents are not approved, so it cannot take orders.'],
            ['warning', 'KW', 'stock_zero', '2 live products in KW are out of stock.'],
            ['info', 'SA', 'orders_pending_too_long', '1 order in SA has been pending for more than 24 hours.'],
            ['info', 'KW', 'orders_pending_too_long', '1 order in KW has been pending for more than 24 hours.'],
        ], array_map(fn (array $alert): array => [$alert['severity'], $alert['market'], $alert['code'], $alert['message']], $response->json('data')));
    }

    public function test_an_alert_has_a_stable_id_a_deep_link_and_the_time_it_was_made(): void
    {
        $this->travelTo('2026-11-17 12:00:00');

        $first = $this->alerts()->json('data.0');

        $this->assertSame(['id', 'severity', 'market', 'code', 'message', 'deep_link', 'generated_at'], array_keys($first));
        $this->assertSame(substr(sha1('legal_not_ready|AE'), 0, 16), $first['id']);
        $this->assertSame('markets/AE', $first['deep_link']);
        $this->assertSame(Carbon::parse('2026-11-17 12:00:00')->utc()->toIso8601ZuluString(), $first['generated_at']);

        $this->travelTo('2026-11-17 12:05:00');
        $this->assertSame($first['id'], $this->alerts()->json('data.0.id'), 'the same alert has the same id the next time');
        $this->assertCount(5, array_unique(array_column($this->alerts()->json('data'), 'id')), 'and each alert has its own');
    }

    public function test_the_answer_says_how_it_was_asked_and_how_many_there_are(): void
    {
        $this->assertSame(
            ['scope' => 'all', 'market' => 'all', 'severity' => null, 'generated_at' => Carbon::parse('2026-11-17 12:00:00')->utc()->toIso8601ZuluString(), 'page' => 1, 'per_page' => 25, 'total' => 5, 'has_more' => false],
            $this->alerts()->json('meta'),
        );
    }

    public function test_a_legal_alert_is_raised_only_for_an_active_market_while_storefronts_are_on_and_until_its_documents_are_approved(): void
    {
        $this->publishRequiredLegalDocuments(country: 'KW');
        $this->assertSame(['AE'], $this->marketsWith(code: 'legal_not_ready'), 'KW has published its documents');

        config(['gcc.storefronts.countries.AE.active' => false]);
        $this->assertSame([], $this->marketsWith(code: 'legal_not_ready'), 'a market that is not active is not selling and has nothing to be ready for');

        config(['gcc.storefronts.countries.AE.active' => true, 'gcc.storefronts.enabled' => false]);
        $this->assertSame([], $this->marketsWith(code: 'legal_not_ready'), 'with storefronts off the gate is not applied, so there is nothing to refuse');
    }

    public function test_without_the_legal_table_an_active_market_is_not_ready_and_nothing_fails(): void
    {
        Schema::drop('storefront_legal_documents');

        $this->assertSame(['AE', 'KW'], $this->marketsWith(code: 'legal_not_ready'));
    }

    /** @return array<string, array{0: int, 1: ?string}> */
    public static function zeroStockLevels(): array
    {
        return [
            'below the warning count' => [1, null],
            'at the warning count' => [2, 'warning'],
            'at the critical count' => [3, 'critical'],
            'above the critical count' => [5, 'critical'],
        ];
    }

    #[DataProvider('zeroStockLevels')]
    public function test_zero_stock_raises_an_alert_at_the_configured_counts(int $zeroProducts, ?string $severity): void
    {
        DB::table('products')->where('storefront_country', 'KW')->delete();
        for ($index = 1; $index <= $zeroProducts; $index++) {
            $this->product(id: 200 + $index, country: 'KW', stock: 0);
        }

        $alert = collect($this->alerts('?market=KW')->json('data'))->firstWhere('code', 'stock_zero');

        $this->assertSame($severity, $alert['severity'] ?? null);
    }

    /** @return array<string, array{0: int, 1: ?string}> */
    public static function stuckOrderLevels(): array
    {
        return [
            'none' => [0, null],
            'one is information' => [1, 'info'],
            'at the warning count' => [2, 'warning'],
            'at the critical count' => [3, 'critical'],
            'many' => [9, 'critical'],
        ];
    }

    #[DataProvider('stuckOrderLevels')]
    public function test_orders_pending_for_too_long_raise_an_alert_at_the_configured_counts(int $stuck, ?string $severity): void
    {
        DB::table('orders')->whereIn('id', [4, 5, 6])->delete();
        for ($index = 1; $index <= $stuck; $index++) {
            $this->order(id: 300 + $index, status: 'pending', at: '2026-11-10 10:00:00', products: [7]);
        }

        $alert = collect($this->alerts('?market=KW')->json('data'))->firstWhere('code', 'orders_pending_too_long');

        $this->assertSame($severity, $alert['severity'] ?? null);
    }

    public function test_an_order_is_stuck_only_when_it_has_been_pending_longer_than_the_configured_age_and_not_older_than_the_longest_window(): void
    {
        DB::table('orders')->delete();
        DB::table('order_details')->delete();
        $this->order(id: 1, status: 'pending', at: '2026-11-16 11:59:59', products: [7]);
        $this->order(id: 2, status: 'pending', at: '2026-11-16 12:00:00', products: [7]);
        $this->order(id: 3, status: 'confirmed', at: '2026-11-01 12:00:00', products: [7]);
        $this->order(id: 4, status: 'pending', at: '2026-08-18 00:00:00', products: [7]);
        $this->order(id: 5, status: 'pending', at: '2026-08-17 23:59:59', products: [7]);

        $this->assertSame(
            '2 orders in KW have been pending for more than 24 hours.',
            collect($this->alerts('?market=KW')->json('data'))->firstWhere('code', 'orders_pending_too_long')['message'],
            'the order at exactly 24 hours is not older than 24 hours, one that is only confirmed is not stuck, and one older than the longest window is not looked for',
        );

        config(['gcc.platform_admin.alerts.pending_age_hours' => 48]);
        $this->assertSame('1 order in KW has been pending for more than 48 hours.', collect($this->alerts('?market=KW')->json('data'))->firstWhere('code', 'orders_pending_too_long')['message']);
    }

    public function test_a_market_with_nothing_wrong_has_no_alert(): void
    {
        $this->assertSame([], $this->alerts('?market=QA')->assertOk()->json('data'));
        $this->assertSame(0, $this->alerts('?market=QA')->json('meta.total'));
    }

    public function test_one_market_is_answered_alone(): void
    {
        $this->assertSame(['legal_not_ready', 'stock_zero', 'orders_pending_too_long'], array_column($this->alerts('?market=KW')->json('data'), 'code'));
        $this->assertSame('KW', $this->alerts('?market=kw')->json('meta.market'));
    }

    public function test_a_restricted_viewer_sees_only_the_alerts_of_their_markets_and_is_refused_the_others(): void
    {
        $token = $this->mintToken($this->platformAdmin(countries: ['KW']));

        $response = $this->alerts(token: $token);

        $this->assertSame(['KW'], array_unique(array_column($response->json('data'), 'market')));
        $this->assertSame(['restricted', 'all', 3], [$response->json('meta.scope'), $response->json('meta.market'), $response->json('meta.total')]);
        $this->alerts('?market=AE', $token)->assertStatus(403)->assertJsonPath('errors.0.code', 'market_not_allowed');
        $this->alerts('?market=SA', $token)->assertStatus(403);
    }

    public function test_the_severity_filter_keeps_only_that_severity_and_refuses_one_that_does_not_exist(): void
    {
        $this->assertSame(['critical', 'critical'], array_column($this->alerts('?severity=critical')->json('data'), 'severity'));
        $this->assertSame(['info', 'info'], array_column($this->alerts('?severity=INFO')->json('data'), 'severity'));
        $this->assertSame('warning', $this->alerts('?severity=warning')->json('meta.severity'));

        foreach (['?severity=urgent', '?severity[]=info', '?severity[a]=info'] as $query) {
            $this->alerts($query)->assertStatus(422)->assertExactJson(['errors' => [[
                'code' => 'invalid_severity',
                'message' => 'The severity must be info, warning or critical.',
                'valid' => ['info', 'warning', 'critical'],
            ]]]);
        }
    }

    public function test_the_list_is_paginated_and_the_size_of_a_page_is_capped(): void
    {
        $first = $this->alerts('?per_page=2&page=1');
        $second = $this->alerts('?per_page=2&page=2');
        $third = $this->alerts('?per_page=2&page=3');
        $fourth = $this->alerts('?per_page=2&page=4');

        $this->assertSame([2, 2, 1, 0], [count($first->json('data')), count($second->json('data')), count($third->json('data')), count($fourth->json('data'))]);
        $this->assertSame([true, true, false, false], [$first->json('meta.has_more'), $second->json('meta.has_more'), $third->json('meta.has_more'), $fourth->json('meta.has_more')]);
        $this->assertSame([5, 5, 5, 5], [$first->json('meta.total'), $second->json('meta.total'), $third->json('meta.total'), $fourth->json('meta.total')]);
        $this->assertSame(array_column($this->alerts()->json('data'), 'id'), array_merge(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id'), array_column($third->json('data'), 'id')));

        $this->assertSame(50, $this->alerts('?per_page=1000')->json('meta.per_page'));
        $this->assertSame([1, 25], [$this->alerts('?page=0')->json('meta.page'), $this->alerts('?per_page=0')->json('meta.per_page')]);
        $this->assertSame([1, 25], [$this->alerts('?page=abc')->json('meta.page'), $this->alerts('?per_page[]=3')->json('meta.per_page')]);
    }

    public function test_the_overview_counts_the_alerts_of_each_market_by_severity(): void
    {
        $markets = collect($this->getJson(self::PREFIX . '/overview', $this->bearer($this->ownerToken()))->json('data.markets'))->keyBy('market');

        $this->assertSame(['critical' => 0, 'warning' => 0, 'info' => 1], $markets['SA']['alerts']);
        $this->assertSame(['critical' => 1, 'warning' => 0, 'info' => 0], $markets['AE']['alerts']);
        $this->assertSame(['critical' => 1, 'warning' => 1, 'info' => 1], $markets['KW']['alerts']);
        $this->assertSame(['critical' => 0, 'warning' => 0, 'info' => 0], $markets['QA']['alerts']);
    }

    public function test_the_thresholds_are_configuration_and_nonsense_means_the_defaults(): void
    {
        config(['gcc.platform_admin.alerts' => ['zero_stock_warning_count' => 'many', 'zero_stock_critical_count' => 0, 'pending_age_hours' => -1, 'pending_warning_count' => null, 'pending_critical_count' => 1]]);
        for ($index = 1; $index <= 8; $index++) {
            $this->product(id: 200 + $index, country: 'KW', stock: 0);
        }

        $kuwait = collect($this->alerts('?market=KW')->json('data'))->keyBy('code');

        $this->assertSame('warning', $kuwait['stock_zero']['severity'], 'ten products at zero, the two the world has and eight more, is the default warning');
        $this->assertSame('1 order in KW has been pending for more than 24 hours.', $kuwait['orders_pending_too_long']['message'], 'the default age is a day');
        $this->assertSame('info', $kuwait['orders_pending_too_long']['severity'], 'a critical count of 1 below the default warning count of 5 is raised to 5, so the levels stay in order and one order is still only information');
    }

    public function test_an_alert_names_a_count_and_a_market_and_nothing_a_person_wrote(): void
    {
        DB::table('products')->where('id', 7)->update(['name' => 'Secret <b>name</b>']);
        DB::table('sellers')->where('id', 10)->update(['f_name' => 'Private Seller Name']);

        $content = $this->alerts()->getContent();

        $this->assertStringNotContainsString('Secret', $content);
        $this->assertStringNotContainsString('Private', $content);
    }

    public function test_the_alerts_read_the_orders_once_and_the_stock_once_whatever_the_amount_of_data(): void
    {
        $this->app->forgetScopedInstances();
        $statements = $this->statementsRunBy(fn () => $this->alerts()->assertOk());

        $reads = array_filter($statements, static fn (string $sql): bool => str_starts_with(strtolower($sql), 'select'));
        $this->assertCount(1, array_filter($reads, static fn (string $sql): bool => str_contains($sql, 'orders')), 'one read of the orders, for the stuck ones');
        $this->assertCount(1, array_filter($reads, static fn (string $sql): bool => str_contains($sql, 'products') && str_contains($sql, 'current_stock')), 'one read of the stock');
    }

    public function test_every_read_is_audited_with_the_market_that_was_asked(): void
    {
        $this->alerts('?market=KW&severity=critical');

        $line = DB::table('platform_admin_audit_logs')->first();
        $this->assertSame(['alerts.view', 'KW'], [$line->action, $line->market]);
        $this->assertSame($this->sortedByKey(['status' => 200, 'params' => ['market' => 'KW', 'severity' => 'critical']]), $this->contextOf($line));
    }

    /** @return list<string> the markets with an alert of that code, in the order they come */
    private function marketsWith(string $code): array
    {
        return array_column(array_filter($this->alerts()->json('data'), static fn (array $alert): bool => $alert['code'] === $code), 'market');
    }

    private function alerts(string $query = '', ?string $token = null): TestResponse
    {
        return $this->getJson(self::URL . $query, $this->bearer($token ?? $this->ownerToken()));
    }

    private function ownerToken(): string
    {
        return $this->owner ??= $this->mintToken($this->platformAdmin());
    }
}
