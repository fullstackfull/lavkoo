<?php

namespace Tests\Feature\PlatformAdmin;

use App\Console\Commands\PlatformAdminGrantCommand;
use App\Models\PlatformAdminGrant;
use App\Services\PlatformAdmin\PlatformMarketFigures;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Support\SeedsPlatformMarketData;

/**
 * `GET /orders`: ONE market's orders as a list rather than as a count, the first drill-down under a market card (L-13).
 *
 * The three things this file exists to hold:
 *
 *  - the list is the COUNTS' OWN list. Both read `PlatformMarketFigures::ordersWithTheirMarket()`, so a market's list and the overview's count of the
 *    same window and the same market can never disagree, and the answer names the rule that put an order in the market;
 *  - NO CUSTOMER is in it. An order gives its id, its status, when it was placed and how many lines it has, and nothing else: the owner has not yet
 *    decided what personal data a list of this API may show (L-13, open decision), so none is shown and none is even read;
 *  - reading the rows one by one is a capability of its own. A grant that names no capability does NOT include it, so nobody acquired the right to
 *    read a market's orders because a release shipped.
 */
class MarketOrdersTest extends PlatformAdminTestCase
{
    use SeedsPlatformMarketData;

    private const URL = self::PREFIX . '/orders';

    private ?string $owner = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMarketScenario();
    }

    public function test_a_market_lists_its_own_orders_newest_first_with_the_status_the_moment_and_how_many_lines(): void
    {
        $response = $this->orders('?market=SA');

        $response->assertOk();
        $this->assertSame([
            ['id' => 16, 'status' => 'processing', 'placed_at' => $this->zulu('2026-11-17 18:00:00'), 'lines' => 1],
            ['id' => 1, 'status' => 'pending', 'placed_at' => $this->zulu('2026-11-17 09:00:00'), 'lines' => 1],
            ['id' => 2, 'status' => 'confirmed', 'placed_at' => $this->zulu('2026-11-15 10:00:00'), 'lines' => 2],
            ['id' => 3, 'status' => 'delivered', 'placed_at' => $this->zulu('2026-11-10 10:00:00'), 'lines' => 1],
            ['id' => 14, 'status' => 'delivered', 'placed_at' => $this->zulu('2026-10-19 00:00:00'), 'lines' => 1],
        ], $response->json('data.orders'));
        $this->assertSame('SA', $response->json('data.market'));
        $this->assertSame(PlatformMarketFigures::ORDER_ATTRIBUTION, $response->json('data.attribution'));
        $this->assertSame(['page' => 1, 'per_page' => 25, 'has_more' => false], $response->json('meta'));
    }

    public function test_the_list_of_a_market_is_exactly_as_long_as_the_overview_counts_it_for_the_same_window(): void
    {
        foreach (['SA' => 5, 'AE' => 1, 'KW' => 3, 'QA' => 0, 'BH' => 0, 'OM' => 0] as $market => $counted) {
            $overview = $this->getJson(self::PREFIX . '/overview?market=' . $market, $this->bearer($this->ownerToken()));
            $listed = $this->orders('?market=' . $market)->json('data.orders');

            $overview->assertOk();
            $this->assertSame($counted, $overview->json('data.markets.0.orders.total'), "{$market}: the world's own figure");
            $this->assertCount($counted, $listed, "{$market}: the list and the count are the same rule read twice");
        }
    }

    public function test_an_order_that_belongs_to_no_single_market_is_in_no_market_list_and_never_in_saudis(): void
    {
        $listed = [];
        foreach (['SA', 'AE', 'KW', 'QA', 'BH', 'OM'] as $market) {
            $listed = array_merge($listed, $this->idsOf(market: $market));
        }

        // 8 spans SA and KW, 9 has no line at all, 10 names a product that is gone, 11 is of a country nobody configured.
        foreach ([8, 9, 10, 11] as $unattributed) {
            $this->assertNotContains($unattributed, $listed, "order {$unattributed} belongs to no market, so it is in no market's list");
        }
        $this->assertNotContains(8, $this->idsOf(market: 'SA'), 'an order half of whose lines are Saudi is not a Saudi order');
    }

    public function test_only_the_orders_of_the_window_are_listed_and_the_window_is_reported(): void
    {
        $response = $this->orders('?market=SA&from=2026-11-15&to=2026-11-17');

        $this->assertSame([16, 1, 2], array_column((array) $response->json('data.orders'), 'id'), 'the first and the last moment of the window are in it');
        $this->assertSame(
            ['from' => '2026-11-15', 'to' => '2026-11-17', 'days' => 3, 'clamped' => false, 'timezone' => date_default_timezone_get()],
            $response->json('data.window'),
        );
        $this->assertSame([], $this->orders('?market=SA&from=2026-10-01&to=2026-10-02')->json('data.orders'), 'a window with nothing in it is an empty list and not an error');
    }

    public function test_a_window_reaching_further_back_than_the_api_may_read_is_cut_and_says_so(): void
    {
        $response = $this->orders('?market=SA&from=2020-01-01&to=2026-11-17');

        $response->assertOk();
        $this->assertTrue($response->json('data.window.clamped'));
        $this->assertSame((int) config('gcc.platform_admin.window_days_max'), $response->json('data.window.days'));
    }

    public function test_a_window_that_cannot_be_read_is_refused_and_nothing_is_listed(): void
    {
        foreach (['?market=SA&from=yesterday', '?market=SA&to=2026-13-01', '?market=SA&from=2026-11-17&to=2026-11-01'] as $query) {
            $response = $this->orders($query);

            $response->assertStatus(422)->assertJsonPath('errors.0.code', 'invalid_window');
            $this->assertNull($response->json('data'), $query);
        }
    }

    public function test_no_customer_is_in_the_answer_and_no_customer_column_is_read(): void
    {
        $named = $this->nameTheCustomerOfEveryOrder();

        $statements = $this->statementsRunBy(function (): void {
            $body = strtolower($this->orders('?market=SA')->getContent());

            foreach (['fatimah', 'fatimah@example.test', '0500000000', 'king fahd road', 'customer'] as $person) {
                $this->assertStringNotContainsString($person, $body, 'a list of orders names no customer');
            }
        });

        $reads = $this->withoutAuditTrail($this->withoutSchemaChecks($statements));
        $this->assertNotEmpty($reads);
        foreach ($reads as $sql) {
            foreach (['customer_id', 'shipping_address', 'billing_address', 'customers'] as $column) {
                $this->assertStringNotContainsString($column, $sql, 'nothing about a person is even read: ' . $sql);
            }
        }
        $this->assertNotEmpty($named, 'the columns the test hid had to exist here, or it proved nothing: ' . implode(', ', $named));
    }

    public function test_every_market_at_once_is_refused_the_way_a_report_refuses_it(): void
    {
        foreach (['', '?market=all', '?market=ALL'] as $query) {
            $response = $this->orders($query);

            $response->assertStatus(422)->assertJsonPath('errors.0.code', 'one_market_only');
            $this->assertSame(
                $this->getJson(self::PREFIX . '/reports' . $query, $this->bearer($this->ownerToken()))->json('errors'),
                $response->json('errors'),
                'one answer for "every market at once", wherever it is asked for, and it names no one feature',
            );
        }
    }

    #[DataProvider('marketsThatAreNotOne')]
    public function test_a_market_that_is_not_one_is_a_422_that_names_the_ones_that_are(string $query): void
    {
        $response = $this->orders($query);

        $response->assertStatus(422)->assertJsonPath('errors.0.code', 'invalid_market');
        $this->assertSame(['all', 'SA', 'AE', 'KW', 'QA', 'BH', 'OM'], $response->json('errors.0.valid'));
    }

    /** @return array<string, array{0: string}> */
    public static function marketsThatAreNotOne(): array
    {
        return [
            'a country that is not configured' => ['?market=ZZ'],
            'a name rather than a code' => ['?market=Saudi'],
            'a list' => ['?market[]=SA'],
            'three letters' => ['?market=KWT'],
        ];
    }

    public function test_the_market_may_be_written_in_any_case(): void
    {
        foreach (['sa', 'Sa', 'sA'] as $written) {
            $this->assertSame('SA', $this->orders('?market=' . $written)->json('data.market'), $written);
        }
    }

    public function test_a_restricted_viewer_reads_their_own_market_and_is_refused_another(): void
    {
        $token = $this->restrictedTo('KW');

        $this->assertSame([4, 5, 6], $this->idsOf(market: 'KW', token: $token));
        $this->orders('?market=SA', $token)->assertStatus(403)->assertJsonPath('errors.0.code', 'market_not_allowed');
        // The case of a code does not widen a scope: the selector normalises before it asks whether the viewer may see it.
        $this->orders('?market=sa', $token)->assertStatus(403);
    }

    public function test_an_unknown_market_is_a_422_and_not_a_403_even_for_a_restricted_viewer(): void
    {
        $this->orders('?market=ZZ', $this->restrictedTo('KW'))->assertStatus(422)->assertJsonPath('errors.0.code', 'invalid_market');
    }

    public function test_reading_a_market_order_by_order_is_a_capability_a_grant_has_to_name(): void
    {
        $this->assertContains(PlatformAdminGrant::CAPABILITY_ORDERS, PlatformAdminGrant::NOT_GIVEN_BY_DEFAULT);

        foreach ([$this->mintToken($this->platformAdmin()), $this->mintToken($this->platformAdmin(grantOverrides: ['capabilities' => json_encode(['overview'])]))] as $token) {
            $refused = $this->orders('?market=SA', $token);

            $refused->assertStatus(403)->assertJsonPath('errors.0.code', 'capability_not_allowed');
            $this->assertSame('orders', $refused->json('errors.0.capability'), 'the refusal names the part of the API that is wanted');
        }

        $this->orders('?market=SA', $this->mintToken($this->platformAdmin(grantOverrides: ['capabilities' => json_encode(['orders'])])))->assertOk();
    }

    public function test_the_console_option_that_gives_a_capability_names_every_capability_there_is(): void
    {
        $signature = (string) (new ReflectionProperty(PlatformAdminGrantCommand::class, 'signature'))->getValue(new PlatformAdminGrantCommand());

        foreach (PlatformAdminGrant::CAPABILITIES as $capability) {
            $this->assertStringContainsString($capability, $signature, 'an operator typing --capabilities has to be able to read which names there are');
        }
    }

    public function test_a_page_holds_at_most_fifty_and_a_longer_one_is_cut_to_it(): void
    {
        $this->addSaudiOrders(count: 60);

        $response = $this->orders('?market=SA&per_page=500');

        $this->assertSame(50, $response->json('meta.per_page'), 'the ceiling is the ceiling, whatever was asked for');
        $this->assertCount(50, $response->json('data.orders'));
        $this->assertTrue($response->json('meta.has_more'));
    }

    public function test_the_pages_walk_the_whole_market_once_newest_first_with_no_order_twice_and_none_missed(): void
    {
        $this->addSaudiOrders(count: 60);
        $walked = [];

        for ($page = 1; $page <= 4; $page++) {
            $response = $this->orders('?market=SA&per_page=25&page=' . $page);
            $walked = array_merge($walked, (array) $response->json('data.orders'));

            $this->assertSame($page < 3, $response->json('meta.has_more'), "page {$page} says whether there is another");
        }

        $ids = array_column($walked, 'id');
        $this->assertCount(65, $ids, 'the five of the world plus sixty, each once');
        $this->assertSame($ids, array_values(array_unique($ids)), 'no order is on two pages');
        // The pages are one newest-first list cut up, which is what makes walking them complete: each order is placed no later than the one before
        // it, and two placed at the same moment are in a fixed order of their own, so no row can slip between two pages.
        for ($at = 1; $at < count($walked); $at++) {
            $this->assertTrue(
                [$walked[$at]['placed_at'], $walked[$at]['id']] < [$walked[$at - 1]['placed_at'], $walked[$at - 1]['id']],
                "order {$walked[$at]['id']} is after order {$walked[$at - 1]['id']} in the walk, so it must be older or lower-numbered",
            );
        }
    }

    public function test_a_page_that_is_not_a_page_is_the_default_and_never_an_error(): void
    {
        foreach (['0', '-1', 'two', '1.5'] as $asked) {
            $response = $this->orders('?market=SA&page=' . $asked . '&per_page=' . $asked);

            $response->assertOk();
            $this->assertSame(['page' => 1, 'per_page' => 25, 'has_more' => false], $response->json('meta'), "[{$asked}]");
        }
    }

    public function test_one_page_is_one_read_however_many_orders_the_market_has(): void
    {
        $before = $this->readsOfOneFreshPage();
        $this->addSaudiOrders(count: 60);
        $after = $this->readsOfOneFreshPage();

        $this->assertCount(1, $before, 'a page of orders is one statement: ' . implode(' | ', $before));
        $this->assertCount(count($before), $after, 'sixty more orders, and not one statement more');
        $this->assertStringContainsString('created_at', $before[0], 'and it is bounded by a date');
        $this->assertStringContainsString('limit', strtolower($before[0]), 'and by a page');
    }

    public function test_the_read_is_audited_with_the_market_that_was_asked_for(): void
    {
        $this->orders('?market=KW')->assertOk();

        $line = DB::table('platform_admin_audit_logs')->orderByDesc('id')->first();

        $this->assertSame('orders.view', $line->action);
        $this->assertSame('KW', $line->market);
    }

    public function test_a_refused_read_is_audited_with_the_market_that_was_asked_for_and_lists_nothing(): void
    {
        $response = $this->orders('?market=SA', $this->restrictedTo('KW'));

        $response->assertStatus(403);
        $this->assertNull($response->json('data'));

        $line = DB::table('platform_admin_audit_logs')->orderByDesc('id')->first();
        $this->assertSame('orders.view', $line->action);
        $this->assertSame('SA', $line->market, 'the trail says which market was asked for, which is the point of auditing a refusal');
    }

    /** @return list<int> */
    private function idsOf(string $market, ?string $token = null): array
    {
        return array_map('intval', array_column((array) $this->orders('?market=' . $market, $token)->json('data.orders'), 'id'));
    }

    private function restrictedTo(string $market): string
    {
        return $this->mintToken($this->platformAdmin(countries: [$market], grantOverrides: ['capabilities' => json_encode([PlatformAdminGrant::CAPABILITY_ORDERS])]));
    }

    /** More Saudi orders inside the default window, each of one line, so that paging and the statement bound have something to walk. */
    private function addSaudiOrders(int $count): void
    {
        for ($more = 1; $more <= $count; $more++) {
            $this->order(id: 2000 + $more, status: 'pending', at: '2026-11-0' . ($more % 7 + 1) . ' 10:00:00', products: [1]);
        }
    }

    /**
     * What a page costs, as a new request would run it: what the container shares for the length of a request is forgotten first, and the settings are
     * warm in both counts because the caller asks once before it measures.
     *
     * @return list<string>
     */
    private function readsOfOneFreshPage(): array
    {
        $this->orders('?market=SA')->assertOk();
        $this->app->forgetScopedInstances();
        $statements = $this->statementsRunBy(fn () => $this->orders('?market=SA')->assertOk());

        return array_values(array_filter(
            $this->withoutAuditTrail($this->withoutSchemaChecks($statements)),
            static fn (string $sql): bool => str_contains($sql, 'order_details'),
        ));
    }

    /**
     * A customer on every order, in every column of the orders table that names one, so that the test which says no person is in the answer has a
     * person to find. Returns the columns it wrote, so the test can say whether it proved anything.
     *
     * @return list<string>
     */
    private function nameTheCustomerOfEveryOrder(): array
    {
        $values = [];
        foreach (['customer_id' => 77, 'shipping_address' => 'King Fahd Road', 'billing_address' => 'King Fahd Road'] as $column => $value) {
            if (Schema::hasColumn('orders', $column)) {
                $values[$column] = $value;
            }
        }

        if ($values !== []) {
            DB::table('orders')->update($values);
        }

        return array_keys($values);
    }

    private function orders(string $query = '', ?string $token = null): TestResponse
    {
        return $this->getJson(self::URL . $query, $this->bearer($token ?? $this->ownerToken()));
    }

    /** The platform owner, with the capability this route wants: it is not one a grant holds by default. */
    private function ownerToken(): string
    {
        return $this->owner ??= $this->mintToken($this->platformAdminWithEveryCapability());
    }

    private function zulu(string $time): string
    {
        return Carbon::parse($time)->utc()->toIso8601ZuluString();
    }
}
