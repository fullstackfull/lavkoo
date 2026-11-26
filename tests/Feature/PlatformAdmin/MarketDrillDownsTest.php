<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\PlatformAdminGrant;
use App\Services\PlatformAdmin\PlatformMarketFigures;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsInventoryHolds;
use Tests\Support\BuildsPlatformStaffWorld;
use Tests\Support\SeedsPlatformMarketData;

/**
 * The three drill-downs that complete L-13 beside the orders list: `GET /returns`, `GET /products` and `GET /inventory`.
 *
 * They are ONE file because they are one shape, and the shape is the thing being tested. The orders list settled four rules, and each of the three
 * follows all four rather than inventing a second way of answering the same card:
 *
 *  - the list is the COUNTS' OWN list. A market's returns are as many as the report counts, its products are the population the stock figures and the
 *    stock alerts are counted over (one shared query, `liveProducts()`), and its holds and pools add up to the overview's reservation and allocation
 *    figures. Each answer names the rule that put a row in the market;
 *  - NO CUSTOMER, and nothing a customer wrote. What personal data a list of this API may show is an open owner decision (L-13), so none is shown and
 *    none is read -- which this file proves by writing a person into every column that could hold one and then asserting the statements;
 *  - the market is a QUERY PARAMETER held to the viewer's grant by `MarketSelector`, `all` is refused, and the staff country scope narrows it;
 *  - reading the rows one by one is a capability of its own, in `NOT_GIVEN_BY_DEFAULT`, so nobody acquired it because a release shipped.
 *
 * The world is the platform tests' own (`SeedsPlatformMarketData`), whose docblock states every figure these lists have to agree with.
 */
class MarketDrillDownsTest extends PlatformAdminTestCase
{
    use BuildsInventoryHolds;
    use BuildsPlatformStaffWorld;
    use SeedsPlatformMarketData;

    /** Each drill-down, with the capability it needs and the member of `data` that holds its rows. */
    private const LISTS = [
        'returns' => ['capability' => PlatformAdminGrant::CAPABILITY_RETURNS, 'rows' => 'returns'],
        'products' => ['capability' => PlatformAdminGrant::CAPABILITY_PRODUCTS, 'rows' => 'products'],
        'inventory' => ['capability' => PlatformAdminGrant::CAPABILITY_INVENTORY, 'rows' => 'products'],
    ];

    /** What a page holds when nothing asks for a size, which every one of the four lists shares. */
    private const DEFAULT_PER_PAGE = 25;

    private ?string $owner = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMarketScenario();
    }

    // ---- returns ----------------------------------------------------------------------------------------------------------------------

    public function test_a_market_lists_its_own_returns_newest_first_with_the_status_the_moment_and_what_they_are_against(): void
    {
        $response = $this->ask('returns', '?market=SA');

        $response->assertOk();
        $this->assertSame([
            // `order_id` is null because the world's returns name no order, and the column DEFAULTS to zero rather than to NULL: 0 points at no order, so
            // it is reported as nothing. Answering 0 would send the app looking for order zero.
            ['id' => 1, 'status' => 'pending', 'requested_at' => $this->zulu('2026-11-16 10:00:00'), 'order_id' => null, 'product_id' => 1],
            ['id' => 2, 'status' => 'approved', 'requested_at' => $this->zulu('2026-11-12 10:00:00'), 'order_id' => null, 'product_id' => 2],
        ], $response->json('data.returns'));
        $this->assertSame(['SA', PlatformMarketFigures::RETURN_ATTRIBUTION], [$response->json('data.market'), $response->json('data.attribution')]);
        $this->assertSame(['page' => 1, 'per_page' => 25, 'has_more' => false], $response->json('meta'));
    }

    public function test_the_returns_of_a_market_are_exactly_as_many_as_its_report_counts_for_the_same_window(): void
    {
        foreach (['SA' => 2, 'KW' => 1, 'AE' => 0, 'QA' => 0, 'BH' => 0, 'OM' => 0] as $market => $counted) {
            $report = $this->getJson(self::PREFIX . '/reports?market=' . $market, $this->bearer($this->ownerToken()));

            $report->assertOk();
            $this->assertSame($counted, $report->json('data.returns.refund_requests'), "{$market}: the world's own figure");
            $this->assertCount($counted, (array) $this->ask('returns', '?market=' . $market)->json('data.returns'), "{$market}: the list and the count are the same rule read twice");
        }
    }

    public function test_a_return_of_no_market_is_in_no_market_list_and_never_in_saudis(): void
    {
        $listed = [];
        foreach (['SA', 'AE', 'KW', 'QA', 'BH', 'OM'] as $market) {
            $listed = array_merge($listed, array_column((array) $this->ask('returns', '?market=' . $market)->json('data.returns'), 'id'));
        }

        // Refund 5 names a product that is gone; refund 6 names a product of a country nobody configured.
        foreach ([5, 6] as $unattributed) {
            $this->assertNotContains($unattributed, $listed, "return {$unattributed} belongs to no market, so it is in no market's list");
        }
    }

    public function test_only_the_returns_of_the_window_are_listed(): void
    {
        // Refund 4 is a Saudi return of 2026-10-01, before the default window begins.
        $this->assertSame([1, 2], array_column((array) $this->ask('returns', '?market=SA')->json('data.returns'), 'id'));
        $this->assertSame([4], array_column((array) $this->ask('returns', '?market=SA&from=2026-09-01&to=2026-10-02')->json('data.returns'), 'id'));
        $this->assertSame([], $this->ask('returns', '?market=SA&from=2026-09-01&to=2026-09-02')->json('data.returns'), 'a window with nothing in it is an empty list and not an error');
    }

    public function test_no_customer_and_nothing_a_customer_wrote_is_in_a_returns_answer_or_read_by_it(): void
    {
        $written = $this->nameTheCustomerOfEveryReturn();

        $statements = $this->statementsRunBy(function (): void {
            $body = strtolower((string) $this->ask('returns', '?market=SA')->getContent());

            foreach (['fatimah', 'fatimah@example.test', '0500000000', 'it arrived broken', 'iban', 'customer'] as $person) {
                $this->assertStringNotContainsString($person, $body, 'a list of returns names no customer and repeats nothing they wrote');
            }
        });

        $reads = $this->withoutAuditTrail($this->withoutSchemaChecks($statements));
        $this->assertNotEmpty($reads);
        foreach ($reads as $sql) {
            foreach (['customer_id', 'refund_reason', 'payment_info', 'images', 'approved_note', 'rejected_note'] as $column) {
                $this->assertStringNotContainsString($column, $sql, 'nothing about a person is even read: ' . $sql);
            }
        }
        $this->assertNotEmpty($written, 'the columns the test hid had to exist here, or it proved nothing');
    }

    public function test_a_returns_answer_carries_no_amount_because_the_money_of_a_return_is_a_capability_of_its_own(): void
    {
        DB::table('refund_requests')->update(['amount' => 123.45]);

        $row = (array) $this->ask('returns', '?market=SA')->json('data.returns.0');

        $this->assertSame(['id', 'status', 'requested_at', 'order_id', 'product_id'], array_keys($row));
        $this->assertStringNotContainsString('123.45', (string) $this->ask('returns', '?market=SA')->getContent());
    }

    public function test_an_order_or_product_a_return_does_not_name_is_answered_as_nothing_and_never_as_row_zero(): void
    {
        DB::table('refund_requests')->where('id', 1)->update(['order_id' => 12]);

        $rows = (array) $this->ask('returns', '?market=SA')->json('data.returns');

        $this->assertSame(12, $rows[0]['order_id'], 'a return that names an order says which');
        $this->assertNull($rows[1]['order_id'], 'and one that names none says nothing, where the column says 0');
        $this->assertSame(0, (int) DB::table('refund_requests')->where('id', 2)->value('order_id'), 'the column really does hold a zero, or this proves nothing');
    }

    // ---- products ---------------------------------------------------------------------------------------------------------------------

    public function test_a_market_lists_its_live_products_shortest_stock_first_with_the_limit_that_applies_to_each(): void
    {
        $response = $this->ask('products', '?market=SA');

        $response->assertOk();
        $this->assertSame([
            ['id' => 1, 'name' => 'Product 1', 'stock' => 0, 'stock_limit' => 5, 'low' => false, 'oversold' => false],
            ['id' => 2, 'name' => 'Product 2', 'stock' => 1, 'stock_limit' => 5, 'low' => true, 'oversold' => false],
            // Products 5 and 6 belong to a shop whose own stock limit is 2, so 2 is low there and 3 is not.
            ['id' => 5, 'name' => 'Product 5', 'stock' => 2, 'stock_limit' => 2, 'low' => true, 'oversold' => false],
            ['id' => 6, 'name' => 'Product 6', 'stock' => 3, 'stock_limit' => 2, 'low' => false, 'oversold' => false],
            ['id' => 3, 'name' => 'Product 3', 'stock' => 5, 'stock_limit' => 5, 'low' => true, 'oversold' => false],
            ['id' => 4, 'name' => 'Product 4', 'stock' => 6, 'stock_limit' => 5, 'low' => false, 'oversold' => false],
        ], $response->json('data.products'));
        $this->assertSame(PlatformMarketFigures::PRODUCT_ATTRIBUTION, $response->json('data.attribution'));
    }

    public function test_the_products_listed_are_exactly_the_population_the_stock_figures_and_the_stock_alerts_are_counted_over(): void
    {
        foreach (['SA' => ['zero' => 1, 'low' => 3], 'AE' => ['zero' => 0, 'low' => 1], 'KW' => ['zero' => 2, 'low' => 1]] as $market => $counted) {
            $overview = $this->getJson(self::PREFIX . '/overview?market=' . $market, $this->bearer($this->ownerToken()));
            $listed = (array) $this->ask('products', '?market=' . $market)->json('data.products');

            $overview->assertOk();
            $this->assertSame($counted['zero'], $overview->json('data.markets.0.stock.zero'), "{$market}: the world's own zero figure");
            $this->assertSame($counted['zero'], count(array_filter($listed, static fn (array $row): bool => $row['stock'] <= 0)), "{$market}: the list's own rows at zero");
            $this->assertSame($counted['low'], $overview->json('data.markets.0.stock.low'), "{$market}: the world's own low figure");
            $this->assertSame($counted['low'], count(array_filter($listed, static fn (array $row): bool => $row['low'])), "{$market}: the list's own low rows");
        }
    }

    public function test_a_product_that_is_not_live_is_in_no_products_list_however_its_stock_stands(): void
    {
        // 10 is unpublished, 11 is not approved, 12 is digital, 13 is of a shop that is pending and 14 of one that is deleted. All are at zero stock.
        $listed = array_column((array) $this->ask('products', '?market=AE')->json('data.products'), 'id');

        $this->assertSame([15], $listed, 'only the live product of the Emirates is in its list');
    }

    public function test_a_product_whose_stock_has_gone_past_zero_says_so_and_is_the_first_row(): void
    {
        DB::table('products')->where('id', 3)->update(['current_stock' => -4]);

        $first = (array) $this->ask('products', '?market=SA')->json('data.products.0');

        $this->assertSame(['id' => 3, 'name' => 'Product 3', 'stock' => -4, 'stock_limit' => 5, 'low' => false, 'oversold' => true], $first);
        $this->assertSame(1, $this->getJson(self::PREFIX . '/overview?market=SA', $this->bearer($this->ownerToken()))->json('data.markets.0.stock.oversold'), 'and the count agrees');
    }

    public function test_no_shop_and_no_price_is_in_a_products_answer(): void
    {
        $columns = array_keys((array) $this->ask('products', '?market=SA')->json('data.products.0'));

        $this->assertSame(['id', 'name', 'stock', 'stock_limit', 'low', 'oversold'], $columns);
        $body = strtolower((string) $this->ask('products', '?market=SA')->getContent());
        foreach (['approved shop', 'purchase_price', 'unit_price'] as $absent) {
            $this->assertStringNotContainsString($absent, $body, 'who sells a product and what it costs is a different question');
        }
    }

    public function test_the_products_list_takes_no_window_because_stock_is_the_state_of_the_shelf_now(): void
    {
        $response = $this->ask('products', '?market=SA&from=2020-01-01&to=2020-01-02');

        $response->assertOk();
        $this->assertNull($response->json('data.window'), 'a window over a shelf would answer a question nobody asked');
        $this->assertCount(6, (array) $response->json('data.products'), 'and it changes nothing');
    }

    // ---- inventory --------------------------------------------------------------------------------------------------------------------

    public function test_on_an_installation_that_keeps_neither_inventory_table_the_answer_says_so_and_shows_no_zero(): void
    {
        $this->assertFalse(Schema::hasTable('inventory_reservations'));
        $this->assertFalse(Schema::hasTable('inventory_pools'));

        $response = $this->ask('inventory', '?market=SA');

        $response->assertOk();
        $this->assertSame(['holds' => false, 'pools' => false], $response->json('data.recorded'));
        $this->assertSame('inventory_not_recorded', $response->json('data.reason'));
        $this->assertSame([], $response->json('data.products'), 'an empty list and an unrecorded one are different facts, and the reason is which');
    }

    public function test_a_market_lists_the_products_that_hold_or_divide_stock_with_the_figures_of_each(): void
    {
        $this->buildInventoryTables();
        $this->hold(product: 3, quantity: 2);
        $this->hold(product: 3, quantity: 2);
        $this->hold(product: 1, quantity: 5, state: 'committed');
        $this->pool(product: 2, deficit: 0);
        $this->pool(product: 4, deficit: 3);

        $response = $this->ask('inventory', '?market=SA');

        $response->assertOk();
        $this->assertSame(['holds' => true, 'pools' => true], $response->json('data.recorded'));
        $this->assertNull($response->json('data.reason'));
        $this->assertSame([
            ['product_id' => 2, 'stock' => 1, 'holds' => 0, 'reserved_units' => 0, 'pools' => 1, 'short_units' => 0],
            ['product_id' => 3, 'stock' => 5, 'holds' => 2, 'reserved_units' => 4, 'pools' => 0, 'short_units' => 0],
            ['product_id' => 4, 'stock' => 6, 'holds' => 0, 'reserved_units' => 0, 'pools' => 1, 'short_units' => 3],
        ], $response->json('data.products'));
    }

    public function test_a_product_whose_only_hold_has_ended_is_in_no_inventory_list(): void
    {
        $this->buildInventoryTables();
        $this->hold(product: 1, quantity: 5, state: 'committed');
        $this->hold(product: 2, quantity: 5, state: 'released');

        $this->assertSame([], $this->ask('inventory', '?market=SA')->json('data.products'), 'committed and released are the two correct ends of a hold, and neither holds anything');
    }

    public function test_the_inventory_rows_of_a_market_add_up_to_the_figures_the_overview_counts(): void
    {
        $this->buildInventoryTables();
        $this->hold(product: 3, quantity: 2);
        $this->hold(product: 3, quantity: 2);
        $this->hold(product: 7, quantity: 1);
        $this->pool(product: 2, deficit: 0);
        $this->pool(product: 4, deficit: 3);

        foreach (['SA' => ['holds' => 2, 'units' => 4, 'pools' => 2, 'short' => 1, 'short_units' => 3], 'KW' => ['holds' => 1, 'units' => 1, 'pools' => 0, 'short' => 0, 'short_units' => 0]] as $market => $counted) {
            $overview = $this->getJson(self::PREFIX . '/overview?market=' . $market, $this->bearer($this->ownerToken()))->json('data.markets.0.stock');
            $rows = (array) $this->ask('inventory', '?market=' . $market)->json('data.products');

            $this->assertSame([$counted['holds'], $counted['units']], [$overview['reserved']['holds'], $overview['reserved']['units']], "{$market}: the world's own hold figures");
            $this->assertSame($counted['holds'], array_sum(array_column($rows, 'holds')), "{$market}: the holds of the list");
            $this->assertSame($counted['units'], array_sum(array_column($rows, 'reserved_units')), "{$market}: the units of the list");
            $this->assertSame($counted['pools'], array_sum(array_column($rows, 'pools')), "{$market}: the pools of the list");
            $this->assertSame($counted['short'], count(array_filter($rows, static fn (array $row): bool => $row['short_units'] > 0)), "{$market}: the short pools of the list");
            $this->assertSame($counted['short_units'], array_sum(array_column($rows, 'short_units')), "{$market}: the shortfall of the list");
        }
    }

    public function test_a_half_that_is_not_recorded_is_null_in_every_row_and_never_a_zero(): void
    {
        $this->buildInventoryTables();
        $this->hold(product: 3, quantity: 2);
        Schema::drop('inventory_pools');

        $response = $this->ask('inventory', '?market=SA');

        $response->assertOk();
        $this->assertSame(['holds' => true, 'pools' => false], $response->json('data.recorded'));
        $this->assertNull($response->json('data.reason'), 'one half is recorded, so there is no reason to give');
        $this->assertSame(
            [['product_id' => 3, 'stock' => 5, 'holds' => 1, 'reserved_units' => 2, 'pools' => null, 'short_units' => null]],
            $response->json('data.products'),
            'a pool figure of an installation that divides no stock is null and not zero',
        );
    }

    // ---- the four rules, on all three -------------------------------------------------------------------------------------------------

    #[DataProvider('theThreeLists')]
    public function test_every_market_at_once_is_refused_the_way_the_orders_list_refuses_it(string $list): void
    {
        foreach (['', '?market=all', '?market=ALL'] as $query) {
            $response = $this->ask($list, $query);

            $response->assertStatus(422)->assertJsonPath('errors.0.code', 'one_market_only');
            $this->assertSame(
                $this->getJson(self::PREFIX . '/orders' . $query, $this->bearer($this->ownerToken()))->json('errors'),
                $response->json('errors'),
                'one answer for "every market at once", wherever it is asked for',
            );
        }
    }

    #[DataProvider('theThreeLists')]
    public function test_a_market_that_is_not_one_is_a_422_and_the_market_may_be_written_in_any_case(string $list): void
    {
        $refused = $this->ask($list, '?market=ZZ');
        $refused->assertStatus(422)->assertJsonPath('errors.0.code', 'invalid_market');
        $this->assertSame(['all', 'SA', 'AE', 'KW', 'QA', 'BH', 'OM'], $refused->json('errors.0.valid'));

        foreach (['sa', 'Sa', 'sA'] as $written) {
            $this->assertSame('SA', $this->ask($list, '?market=' . $written)->json('data.market'), $written);
        }
    }

    #[DataProvider('theThreeLists')]
    public function test_a_restricted_viewer_reads_their_own_market_and_is_refused_another(string $list): void
    {
        $token = $this->mintToken($this->platformAdmin(countries: ['KW'], grantOverrides: ['capabilities' => json_encode([self::LISTS[$list]['capability']])]));

        $this->ask($list, '?market=KW', $token)->assertOk();
        $this->ask($list, '?market=SA', $token)->assertStatus(403)->assertJsonPath('errors.0.code', 'market_not_allowed');
        // The case of a code does not widen a scope: the selector normalises before it asks whether the viewer may see it.
        $this->ask($list, '?market=sa', $token)->assertStatus(403);
    }

    #[DataProvider('theThreeLists')]
    public function test_the_staff_country_scope_narrows_this_read_as_it_narrows_the_others(string $list): void
    {
        $this->buildStaffCountryTable();
        $this->switchStaffScopes(on: true);
        $token = $this->mintToken($this->employeeWithGrant(
            grantCountries: ['SA', 'KW'],
            staffCountries: ['KW'],
            grantOverrides: ['capabilities' => json_encode([self::LISTS[$list]['capability']])],
        ));

        $this->ask($list, '?market=KW', $token)->assertOk();
        $this->ask($list, '?market=SA', $token)->assertStatus(403)->assertJsonPath('errors.0.code', 'market_not_allowed');
    }

    #[DataProvider('theThreeLists')]
    public function test_reading_a_market_row_by_row_is_a_capability_a_grant_has_to_name(string $list): void
    {
        $capability = self::LISTS[$list]['capability'];
        $this->assertContains($capability, PlatformAdminGrant::NOT_GIVEN_BY_DEFAULT);

        foreach ([$this->mintToken($this->platformAdmin()), $this->mintToken($this->platformAdmin(grantOverrides: ['capabilities' => json_encode(['overview'])]))] as $token) {
            $refused = $this->ask($list, '?market=SA', $token);

            $refused->assertStatus(403)->assertJsonPath('errors.0.code', 'capability_not_allowed');
            $this->assertSame($capability, $refused->json('errors.0.capability'), 'the refusal names the part of the API that is wanted');
        }

        $this->ask($list, '?market=SA', $this->mintToken($this->platformAdmin(grantOverrides: ['capabilities' => json_encode([$capability])])))->assertOk();
    }

    #[DataProvider('theThreeLists')]
    public function test_the_read_is_audited_with_the_market_that_was_asked_for_whether_it_is_answered_or_refused(string $list): void
    {
        $this->ask($list, '?market=KW')->assertOk();
        $answered = DB::table('platform_admin_audit_logs')->orderByDesc('id')->first();

        $this->assertSame([$list . '.view', 'KW'], [$answered->action, $answered->market]);

        $restricted = $this->mintToken($this->platformAdmin(countries: ['KW'], grantOverrides: ['capabilities' => json_encode([self::LISTS[$list]['capability']])]));
        $this->ask($list, '?market=SA', $restricted)->assertStatus(403);
        $refused = DB::table('platform_admin_audit_logs')->orderByDesc('id')->first();

        $this->assertSame([$list . '.view', 'SA'], [$refused->action, $refused->market], 'the trail says which market was asked for, which is the point of auditing a refusal');
    }

    #[DataProvider('theThreeLists')]
    public function test_a_page_holds_at_most_fifty_and_a_page_that_is_not_a_page_is_the_default(string $list): void
    {
        $response = $this->ask($list, '?market=SA&per_page=500');
        $this->assertSame(50, $response->json('meta.per_page'), 'the ceiling is the ceiling, whatever was asked for');

        foreach (['0', '-1', 'two', '1.5'] as $asked) {
            $nonsense = $this->ask($list, '?market=SA&page=' . $asked . '&per_page=' . $asked);

            $nonsense->assertOk();
            $this->assertSame(['page' => 1, 'per_page' => 25], array_intersect_key((array) $nonsense->json('meta'), ['page' => 0, 'per_page' => 0]), "[{$asked}]");
        }
    }

    #[DataProvider('theThreeLists')]
    public function test_one_page_is_one_statement_over_the_market(string $list): void
    {
        $rowsMember = self::LISTS[$list]['rows'];
        $this->buildInventoryTables();
        $this->hold(product: 1, quantity: 1);
        $this->hold(product: 2, quantity: 1);

        $this->ask($list, '?market=SA')->assertOk();
        $this->app->forgetScopedInstances();
        $statements = $this->withoutAuditTrail($this->withoutSchemaChecks($this->statementsRunBy(fn () => $this->ask($list, '?market=SA')->assertOk())));
        // The page's own bound and not ` limit ` at large: the authentication reads three rows of its own with `limit 1`, and an earlier spelling of this
        // filter counted those and reported four statements where the list itself ran one.
        $reads = array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'limit ' . (self::DEFAULT_PER_PAGE + 1))));

        $this->assertCount(1, $reads, "a page of {$rowsMember} is one statement: " . implode(' | ', $statements));
        $this->assertStringContainsString('storefront_country', $reads[0], 'and it is bounded by the market');
    }

    public function test_the_pages_of_a_market_walk_its_returns_once_with_none_twice_and_none_missed(): void
    {
        for ($more = 1; $more <= 60; $more++) {
            $this->refund(status: 'pending', at: '2026-11-0' . ($more % 7 + 1) . ' 10:00:00', product: 1);
        }
        $walked = [];

        for ($page = 1; $page <= 4; $page++) {
            $response = $this->ask('returns', '?market=SA&per_page=25&page=' . $page);
            $walked = array_merge($walked, (array) $response->json('data.returns'));

            $this->assertSame($page < 3, $response->json('meta.has_more'), "page {$page} says whether there is another");
        }

        $ids = array_column($walked, 'id');
        $this->assertCount(62, $ids, 'the two of the world plus sixty, each once');
        $this->assertSame($ids, array_values(array_unique($ids)), 'no return is on two pages');
        for ($at = 1; $at < count($walked); $at++) {
            $this->assertTrue(
                [$walked[$at]['requested_at'], $walked[$at]['id']] < [$walked[$at - 1]['requested_at'], $walked[$at - 1]['id']],
                "return {$walked[$at]['id']} is after {$walked[$at - 1]['id']} in the walk, so it must be older or lower-numbered",
            );
        }
    }

    /** @return array<string, array{0: string}> */
    public static function theThreeLists(): array
    {
        return ['returns' => ['returns'], 'products' => ['products'], 'inventory' => ['inventory']];
    }

    private function ask(string $list, string $query = '', ?string $token = null): TestResponse
    {
        return $this->getJson(self::PREFIX . '/' . $list . $query, $this->bearer($token ?? $this->ownerToken()));
    }

    /**
     * A customer, and everything a customer writes, on every return, so that the test which says no person is in the answer has a person to find.
     *
     * @return list<string> the columns it wrote, so the test can say whether it proved anything
     */
    private function nameTheCustomerOfEveryReturn(): array
    {
        $values = [];
        foreach ([
            'customer_id' => 77,
            'refund_reason' => 'It arrived broken, telephone 0500000000',
            'payment_info' => 'IBAN SA0380000000608010167519',
            'approved_note' => 'Fatimah was refunded',
            'rejected_note' => 'Fatimah@example.test was told',
        ] as $column => $value) {
            if (Schema::hasColumn('refund_requests', $column)) {
                $values[$column] = $value;
            }
        }

        if ($values !== []) {
            DB::table('refund_requests')->update($values);
        }

        return array_keys($values);
    }

    /** The platform owner, with every capability: the four drill-downs are none a grant holds by default. */
    private function ownerToken(): string
    {
        return $this->owner ??= $this->mintToken($this->platformAdminWithEveryCapability());
    }

    private function zulu(string $time): string
    {
        return Carbon::parse($time)->utc()->toIso8601ZuluString();
    }
}
