<?php

namespace Tests\Feature\Storefront;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\Support\BuildsSellerMarketWorld;
use Tests\TestCase;

/**
 * K-04, the first half: the order list and the order detail of the seller app (api/v3/seller) carry the storefront and the currency each order was
 * sold in, and the list can be narrowed to one market. Everything here is additive and off by default: while storefronts are switched off, both
 * endpoints answer exactly what they answered before this work, whatever the app sends.
 *
 * The off answers were recorded from the controller as it was before it was touched, and are compared as recorded. To record them again (only
 * for a change the owner approved): RECORD_SELLER_MARKET_GOLDEN=1 vendor/bin/phpunit tests/Feature/Storefront/SellerOrderMarketTest.php
 *
 * Seller 1 has five orders (101 Saudi in riyals, 102 Emirati, 103 Kuwaiti, 104 with no record at all, 105 Saudi with no currency). Seller 2 has an
 * Emirati, a Saudi and an unrecorded one, and the in-house shop has an order that carries seller 1's id: none of them may ever reach seller 1.
 */
class SellerOrderMarketTest extends TestCase
{
    use BuildsSellerMarketWorld;

    private const FIRST_PAGE = ['limit' => 10, 'offset' => 1];

    private const EVERY_ORDER_OF_SELLER_1 = [105, 104, 103, 102, 101];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSellerMarketWorld();
    }

    protected function tearDown(): void
    {
        $this->tearDownSellerMarketWorld();

        parent::tearDown();
    }

    /** @return array<string, array{bool, string}> */
    public static function ordersMigrationStates(): array
    {
        return [
            'before the orders migration has run' => [false, 'before_migration'],
            'after the orders migration has run' => [true, 'after_migration'],
        ];
    }

    // ---- off: the answers as they were ----

    #[DataProvider('ordersMigrationStates')]
    public function test_while_storefronts_are_off_the_order_list_answers_what_it_answered_before(bool $migrated, string $state): void
    {
        $this->buildSellerMarketWorld(ordersRecordTheirStorefront: $migrated);

        $this->assertRecordedAnswer(name: "order_list_{$state}", answer: $this->answerOf($this->sellerApi('POST', 'orders/list', self::FIRST_PAGE)));
    }

    #[DataProvider('ordersMigrationStates')]
    public function test_while_storefronts_are_off_a_market_filter_is_ignored(bool $migrated, string $state): void
    {
        $this->buildSellerMarketWorld(ordersRecordTheirStorefront: $migrated);

        foreach (['AE', 'ZZ', ['AE']] as $market) {
            $this->assertRecordedAnswer(
                name: "order_list_{$state}",
                answer: $this->answerOf($this->sellerApi('POST', 'orders/list', self::FIRST_PAGE + ['storefront' => $market])),
            );
        }
    }

    #[DataProvider('ordersMigrationStates')]
    public function test_while_storefronts_are_off_the_order_detail_answers_what_it_answered_before(bool $migrated, string $state): void
    {
        $this->buildSellerMarketWorld(ordersRecordTheirStorefront: $migrated);

        $this->assertRecordedAnswer(name: "order_detail_{$state}", answer: $this->answerOf($this->sellerApi('GET', 'orders/102')));
    }

    // ---- on: what each order carries ----

    public function test_with_storefronts_on_each_order_of_the_list_carries_its_storefront_and_its_currency(): void
    {
        $this->switchStorefrontsOn();

        $orders = $this->sellerApi('POST', 'orders/list', self::FIRST_PAGE)->assertOk()->json('orders');

        $this->assertSame(self::EVERY_ORDER_OF_SELLER_1, array_column($orders, 'id'));
        $this->assertSame(
            [105 => ['SA', null], 104 => [null, null], 103 => ['KW', 'KWD'], 102 => ['AE', 'AED'], 101 => ['SA', 'SAR']],
            $this->marketsOf($orders),
            'a record that was never written is null, and is not made up',
        );
    }

    public function test_with_storefronts_on_the_list_adds_nothing_else_once_the_orders_migration_has_run(): void
    {
        $this->switchStorefrontsOn();

        $this->assertRecordedAnswer(name: 'order_list_after_migration', answer: $this->answerOf($this->sellerApi('POST', 'orders/list', self::FIRST_PAGE)));
    }

    public function test_with_storefronts_on_before_the_orders_migration_has_run_each_order_carries_both_as_null_and_nothing_else(): void
    {
        $this->buildSellerMarketWorld(ordersRecordTheirStorefront: false);
        $this->switchStorefrontsOn();

        $answer = $this->answerOf($this->sellerApi('POST', 'orders/list', self::FIRST_PAGE));

        $this->assertSame(array_fill_keys(self::EVERY_ORDER_OF_SELLER_1, [null, null]), $this->marketsOf(json_decode(json_encode($answer->orders), true)));
        foreach ($answer->orders as $order) {
            unset($order->storefront_country, $order->currency_code);
        }
        $this->assertRecordedAnswer(name: 'order_list_before_migration', answer: $answer);
    }

    public function test_with_storefronts_on_the_order_detail_carries_the_storefront_and_the_currency_of_the_order(): void
    {
        $this->switchStorefrontsOn();

        $lines = $this->sellerApi('GET', 'orders/102')->assertOk()->json();

        $this->assertCount(1, $lines);
        $this->assertSame(['AE', 'AED'], [$lines[0]['order']['storefront_country'], $lines[0]['order']['currency_code']]);
        $this->assertSame([null, null], $this->marketOfOrderDetail(orderId: 104), 'an order with no record carries none');
    }

    public function test_with_storefronts_on_the_detail_adds_nothing_else_once_the_orders_migration_has_run(): void
    {
        $this->switchStorefrontsOn();

        $this->assertRecordedAnswer(name: 'order_detail_after_migration', answer: $this->answerOf($this->sellerApi('GET', 'orders/102')));
    }

    public function test_with_storefronts_on_before_the_orders_migration_has_run_the_detail_carries_both_as_null_and_nothing_else(): void
    {
        $this->buildSellerMarketWorld(ordersRecordTheirStorefront: false);
        $this->switchStorefrontsOn();

        $answer = $this->answerOf($this->sellerApi('GET', 'orders/102'));

        $this->assertSame([null, null], [$answer[0]->order->storefront_country, $answer[0]->order->currency_code]);
        unset($answer[0]->order->storefront_country, $answer[0]->order->currency_code);
        $this->assertRecordedAnswer(name: 'order_detail_before_migration', answer: $answer);
    }

    public function test_with_storefronts_on_the_two_are_carried_even_where_the_order_model_hides_its_record(): void
    {
        $this->switchStorefrontsOn();
        $this->hideTheRecordOfEveryOrder();

        $orders = $this->sellerApi('POST', 'orders/list', self::FIRST_PAGE)->assertOk()->json('orders');
        $order = $this->sellerApi('GET', 'orders/102')->assertOk()->json('0.order');

        $this->assertSame([105 => ['SA', null], 104 => [null, null], 103 => ['KW', 'KWD'], 102 => ['AE', 'AED'], 101 => ['SA', 'SAR']], $this->marketsOf($orders));
        $this->assertSame(['AE', 'AED'], [$order['storefront_country'], $order['currency_code']]);
        $this->assertArrayNotHasKey('currency_rate', $orders[0], 'the rate is not part of what the seller app is promised');
        $this->assertArrayNotHasKey('currency_rate', $order);
    }

    public function test_while_storefronts_are_off_nothing_is_shown_that_the_order_model_hides(): void
    {
        $this->hideTheRecordOfEveryOrder();

        $orders = $this->sellerApi('POST', 'orders/list', self::FIRST_PAGE)->assertOk()->json('orders');
        $order = $this->sellerApi('GET', 'orders/102')->assertOk()->json('0.order');

        foreach (['storefront_country', 'currency_code', 'currency_rate'] as $column) {
            $this->assertArrayNotHasKey($column, $orders[0]);
            $this->assertArrayNotHasKey($column, $order);
        }
    }

    // ---- on: the filter ----

    /** @return array<string, array{mixed, list<int>}> */
    public static function marketFilters(): array
    {
        return [
            'a market with one order' => ['AE', [102]],
            'a market in another currency' => ['KW', [103]],
            'the default market, which also holds the orders with no record' => ['SA', [105, 104, 101]],
            'a market in which the seller has no order' => ['QA', []],
            'a code that is no market' => ['ZZ', []],
            'a text that is no code' => ['SAU', []],
            'a code in lower case' => ['ae', [102]],
            'a code with spaces around it' => [' AE ', [102]],
            'a list instead of a code' => [['AE'], []],
            'a number instead of a code' => [971, []],
            'nothing' => [null, self::EVERY_ORDER_OF_SELLER_1],
            'an empty text' => ['', self::EVERY_ORDER_OF_SELLER_1],
        ];
    }

    /** @param list<int> $expectedIds */
    #[DataProvider('marketFilters')]
    public function test_the_list_can_be_narrowed_to_one_market(mixed $market, array $expectedIds): void
    {
        $this->switchStorefrontsOn();

        $answer = $this->sellerApi('POST', 'orders/list', self::FIRST_PAGE + ['storefront' => $market])->assertOk();

        $this->assertSame($expectedIds, array_column($answer->json('orders'), 'id'));
        $this->assertSame(count($expectedIds), $answer->json('total_size'));
    }

    public function test_the_market_filter_can_also_be_sent_in_the_query_string(): void
    {
        $this->switchStorefrontsOn();

        $answer = $this->withHeaders(['Authorization' => 'Bearer ' . self::SELLER_TOKEN, 'Accept' => 'application/json'])
            ->post('/api/v3/seller/orders/list?storefront=KW', self::FIRST_PAGE)->assertOk();

        $this->assertSame([103], array_column($answer->json('orders'), 'id'));
    }

    public function test_the_market_filter_is_one_more_condition_beside_the_other_filters_of_the_list(): void
    {
        $this->switchStorefrontsOn();
        DB::table('orders')->where('id', 101)->update(['order_status' => 'delivered']);

        $delivered = ['order_current_status' => '["delivered"]'];
        $searched = ['search_value' => 'ref'];

        $this->assertSame([101], array_column($this->sellerApi('POST', 'orders/list', self::FIRST_PAGE + $delivered + ['storefront' => 'SA'])->assertOk()->json('orders'), 'id'));
        $this->assertSame([], array_column($this->sellerApi('POST', 'orders/list', self::FIRST_PAGE + $delivered + ['storefront' => 'AE'])->assertOk()->json('orders'), 'id'));
        $this->assertSame([102], array_column($this->sellerApi('POST', 'orders/list', self::FIRST_PAGE + $searched + ['storefront' => 'AE'])->assertOk()->json('orders'), 'id'), 'a search narrows within the market, and finds no order of another seller');
        $this->assertSame([105, 104, 101], array_column($this->sellerApi('POST', 'orders/list', self::FIRST_PAGE + $searched + ['storefront' => 'SA'])->assertOk()->json('orders'), 'id'));
    }

    public function test_the_narrowed_list_is_paged_over_the_narrowed_orders(): void
    {
        $this->switchStorefrontsOn();

        $firstPage = $this->sellerApi('POST', 'orders/list', ['limit' => 2, 'offset' => 1, 'storefront' => 'SA'])->assertOk();
        $secondPage = $this->sellerApi('POST', 'orders/list', ['limit' => 2, 'offset' => 2, 'storefront' => 'SA'])->assertOk();

        $this->assertSame([105, 104], array_column($firstPage->json('orders'), 'id'));
        $this->assertSame([101], array_column($secondPage->json('orders'), 'id'));
        $this->assertSame([3, 3], [$firstPage->json('total_size'), $secondPage->json('total_size')]);
    }

    public function test_the_filter_narrows_the_orders_of_the_seller_who_asks_and_never_widens_them(): void
    {
        $this->switchStorefrontsOn();

        foreach (['SA', 'AE', 'KW', 'QA', 'ZZ'] as $market) {
            $own = array_column($this->sellerApi('POST', 'orders/list', self::FIRST_PAGE + ['storefront' => $market])->json('orders'), 'id');
            $others = array_column($this->sellerApi('POST', 'orders/list', self::FIRST_PAGE + ['storefront' => $market], token: self::OTHER_SELLER_TOKEN)->json('orders'), 'id');

            $this->assertSame([], array_diff($own, self::EVERY_ORDER_OF_SELLER_1), "seller 1 asking for {$market}");
            $this->assertSame([], array_diff($others, [201, 202, 203]), "seller 2 asking for {$market}");
        }

        $this->assertSame([203, 202], array_column($this->sellerApi('POST', 'orders/list', self::FIRST_PAGE + ['storefront' => 'SA'], token: self::OTHER_SELLER_TOKEN)->json('orders'), 'id'));
        $this->assertSame([201], array_column($this->sellerApi('POST', 'orders/list', self::FIRST_PAGE + ['storefront' => 'AE'], token: self::OTHER_SELLER_TOKEN)->json('orders'), 'id'));
    }

    public function test_the_default_market_is_the_one_the_platform_names_and_not_always_the_saudi_one(): void
    {
        $this->switchStorefrontsOn();
        config(['gcc.storefronts.default_country' => 'AE']);

        $emirati = $this->sellerApi('POST', 'orders/list', self::FIRST_PAGE + ['storefront' => 'AE'])->assertOk();
        $saudi = $this->sellerApi('POST', 'orders/list', self::FIRST_PAGE + ['storefront' => 'SA'])->assertOk();

        $this->assertSame([104, 102], array_column($emirati->json('orders'), 'id'), 'the orders with no record now belong to the Emirati market');
        $this->assertSame([105, 101], array_column($saudi->json('orders'), 'id'));
    }

    public function test_a_market_that_was_switched_off_or_taken_out_of_the_configuration_still_shows_the_orders_it_sold(): void
    {
        $this->switchStorefrontsOn();
        config([
            'gcc.storefronts.countries.AE.active' => false,
            'gcc.storefronts.countries' => array_diff_key(config('gcc.storefronts.countries'), ['KW' => true]),
        ]);

        $this->assertSame([102], array_column($this->sellerApi('POST', 'orders/list', self::FIRST_PAGE + ['storefront' => 'AE'])->assertOk()->json('orders'), 'id'));
        $this->assertSame([103], array_column($this->sellerApi('POST', 'orders/list', self::FIRST_PAGE + ['storefront' => 'KW'])->assertOk()->json('orders'), 'id'));
    }

    public function test_before_the_orders_migration_has_run_the_default_market_holds_every_order_and_another_holds_none(): void
    {
        $this->buildSellerMarketWorld(ordersRecordTheirStorefront: false);
        $this->switchStorefrontsOn();

        $saudi = $this->sellerApi('POST', 'orders/list', self::FIRST_PAGE + ['storefront' => 'SA'])->assertOk();
        $emirati = $this->sellerApi('POST', 'orders/list', self::FIRST_PAGE + ['storefront' => 'AE'])->assertOk();

        $this->assertSame(self::EVERY_ORDER_OF_SELLER_1, array_column($saudi->json('orders'), 'id'));
        $this->assertSame([], $emirati->json('orders'));
    }

    // ---- on: who may see it ----

    public function test_a_staff_member_who_may_see_orders_sees_the_market_of_the_orders_of_their_employer_only(): void
    {
        $this->switchStorefrontsOn();
        $this->insertStaff(sellerId: self::SELLER, roleId: $this->insertRole(sellerId: self::SELLER, name: 'Order viewer', permissions: ['orders.view']), token: 'staff-viewer-token-that-is-longer-than-thirty-characters');
        $this->insertStaff(sellerId: self::OTHER_SELLER, roleId: $this->insertRole(sellerId: self::OTHER_SELLER, name: 'Order viewer', permissions: ['orders.view']), token: 'staff-other-token-that-is-longer-than-thirty-characters');

        $mine = $this->sellerApi('POST', 'orders/list', self::FIRST_PAGE + ['storefront' => 'AE'], token: 'staff-viewer-token-that-is-longer-than-thirty-characters')->assertOk();
        $theirs = $this->sellerApi('POST', 'orders/list', self::FIRST_PAGE + ['storefront' => 'AE'], token: 'staff-other-token-that-is-longer-than-thirty-characters')->assertOk();
        $detail = $this->sellerApi('GET', 'orders/102', token: 'staff-viewer-token-that-is-longer-than-thirty-characters')->assertOk();

        $this->assertSame([[102, 'AE', 'AED']], array_map(fn (array $order) => [$order['id'], $order['storefront_country'], $order['currency_code']], $mine->json('orders')));
        $this->assertSame([201], array_column($theirs->json('orders'), 'id'));
        $this->assertSame('AE', $detail->json('0.order.storefront_country'));
    }

    public function test_a_staff_member_whose_role_does_not_cover_orders_is_refused_exactly_as_before_and_a_market_filter_changes_nothing(): void
    {
        $this->switchStorefrontsOn();
        $this->insertStaff(sellerId: self::SELLER, roleId: $this->insertRole(sellerId: self::SELLER, name: 'Products only', permissions: ['products.view']), token: 'staff-products-token-that-is-longer-than-thirty-characters');

        $this->sellerApi('POST', 'orders/list', self::FIRST_PAGE + ['storefront' => 'AE'], token: 'staff-products-token-that-is-longer-than-thirty-characters')->assertStatus(403);
        $this->sellerApi('GET', 'orders/102', token: 'staff-products-token-that-is-longer-than-thirty-characters')->assertStatus(403);
    }

    public function test_an_order_of_another_seller_is_not_found_and_tells_nothing_about_its_market(): void
    {
        $this->switchStorefrontsOn();

        $response = $this->sellerApi('GET', 'orders/201')->assertNotFound();

        $this->assertSame('order', $response->json('errors.0.code'));
        $this->assertStringNotContainsString('AED', (string) $response->getContent());
        $this->assertStringNotContainsString('storefront', (string) $response->getContent());
    }

    public function test_without_a_token_the_list_and_the_detail_are_refused_as_ever(): void
    {
        $this->switchStorefrontsOn();

        $this->postJson('/api/v3/seller/orders/list', self::FIRST_PAGE + ['storefront' => 'AE'])->assertStatus(401);
        $this->getJson('/api/v3/seller/orders/102')->assertStatus(401);
    }

    // ---- helpers ----

    /** What the order model would do if it hid its record the way the product and the coupon hide their country: the seller app must not depend on either. */
    private function hideTheRecordOfEveryOrder(): void
    {
        Order::retrieved(fn (Order $order) => $order->makeHidden(['storefront_country', 'currency_code', 'currency_rate']));
    }

    private function answerOf(TestResponse $response): stdClass|array
    {
        $this->assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getContent());
    }

    /** @return array{0: ?string, 1: ?string} */
    private function marketOfOrderDetail(int $orderId): array
    {
        $order = $this->sellerApi('GET', "orders/{$orderId}")->assertOk()->json('0.order');

        $this->assertArrayHasKey('storefront_country', $order);
        $this->assertArrayHasKey('currency_code', $order);

        return [$order['storefront_country'], $order['currency_code']];
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<int, array{0: ?string, 1: ?string}> the storefront and the currency of each order, by id
     */
    private function marketsOf(array $orders): array
    {
        $markets = [];

        foreach ($orders as $order) {
            $this->assertArrayHasKey('storefront_country', $order);
            $this->assertArrayHasKey('currency_code', $order);
            $markets[$order['id']] = [$order['storefront_country'], $order['currency_code']];
        }

        return $markets;
    }

    /**
     * What an answer was before, as text. The one value an engine hands back differently is the rate of the snapshot, a decimal the order model does
     * not cast (SQLite says 1, MySQL "1.00000000"), so it is written the short way on both sides; everything else, every key and its order, is exact.
     */
    private function assertRecordedAnswer(string $name, stdClass|array $answer): void
    {
        $encoded = json_encode($this->withTheRateWrittenTheShortWay($answer), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $path = dirname(__DIR__, 2) . "/Support/Fixtures/SellerMarket/{$name}.json";

        if (getenv('RECORD_SELLER_MARKET_GOLDEN') === '1') {
            file_put_contents($path, $encoded . "\n");
            $this->markTestSkipped("recorded {$name}");
        }

        $this->assertFileExists($path, 'no recorded answer: record it from the controller as it was');
        $this->assertSame(rtrim((string) file_get_contents($path)), $encoded, "the answer of {$name} changed");
    }

    private function withTheRateWrittenTheShortWay(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map($this->withTheRateWrittenTheShortWay(...), $value);
        }

        if (!$value instanceof stdClass) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value->{$key} = $key === 'currency_rate' && $item !== null ? rtrim(rtrim((string) $item, '0'), '.') : $this->withTheRateWrittenTheShortWay($item);
        }

        return $value;
    }
}
