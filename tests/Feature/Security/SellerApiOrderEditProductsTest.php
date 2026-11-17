<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Services\OrderEditService;
use App\Traits\OrderEditManager;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSellerApiOrders;
use Tests\Support\RecordsWhatAnOrderEditWrites;
use Tests\TestCase;

/**
 * The seller app edits an order by sending the products the order should hold afterwards. The order is the caller's own, but the product
 * ids are the app's to name, and the edit writes each of them as a line of the order, reprices the order from them and moves their stock:
 * so an edit may only name products of the order's own shop (or products the order already holds). Another seller's product and the
 * in-house product are refused, and the whole request is refused with them: no line, no price and no stock is written.
 *
 * Unlike SellerApiOrderOwnershipTest, which replaces the order writers with recorders to test which order a handler hands them, this one
 * runs the real writers: what is under test is what they leave in the database.
 */
class SellerApiOrderEditProductsTest extends TestCase
{
    use BuildsSellerApiOrders;
    use RecordsWhatAnOrderEditWrites;

    private const OWN_PRODUCT = 10;
    private const OTHER_SELLERS_PRODUCT = 20;
    private const IN_HOUSE_PRODUCT = 30;
    private const ANOTHER_OWN_PRODUCT = 11;
    private const ANOTHER_OWN_LINE = 40;
    private const OWN_LINE_KEY = '10-single-variant';
    private const LINE_WITHOUT_PRODUCT_KEY = '-single-variant';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSellerApiWorld();
        DB::table('business_settings')->insert(['type' => 'vendor_can_edit_order', 'value' => '1']);
        Cache::flush();
        $this->createSellerApiOrderTables();
        $this->createTablesTheOrderDetailsRelationsReadFrom();
        $this->createTablesTheEditWritersUse();
        $this->seedSellerApiOrders();
        DB::table('orders')->where('id', self::OWN_ORDER)->update(['payment_method' => 'cash_on_delivery', 'customer_id' => 7]);
        DB::table('users')->insert(['id' => 7, 'f_name' => 'Customer']);
    }

    protected function tearDown(): void
    {
        $this->tearDownSellerApiWorld();

        parent::tearDown();
    }

    // ---- products that are not the order's shop's ----

    /** @return iterable<string, array{0: string}> */
    public static function productEncodings(): iterable
    {
        yield 'a JSON string, as the seller app sends it' => ['json'];
        yield 'an array' => ['array'];
    }

    #[DataProvider('productEncodings')]
    public function test_an_edit_cannot_put_another_sellers_product_on_the_order_or_take_its_stock(string $encoding): void
    {
        $response = $this->submitEdit([$this->item(self::OTHER_SELLERS_PRODUCT, quantity: 3)], $encoding);

        $this->assertOrderUntouched();
        $this->assertNotFound($response, code: 'product');
    }

    #[DataProvider('productEncodings')]
    public function test_an_edit_cannot_put_the_in_house_product_on_the_order_or_take_its_stock(string $encoding): void
    {
        $response = $this->submitEdit([$this->item(self::IN_HOUSE_PRODUCT, quantity: 3)], $encoding);

        $this->assertOrderUntouched();
        $this->assertNotFound($response, code: 'product');
    }

    public function test_an_edit_naming_an_own_and_another_sellers_product_is_refused_as_a_whole(): void
    {
        $response = $this->submitEdit([$this->item(self::OWN_PRODUCT, quantity: 3), $this->item(self::OTHER_SELLERS_PRODUCT, quantity: 3)]);

        $this->assertOrderUntouched();
        $this->assertNotFound($response, code: 'product');
    }

    public function test_an_edit_cannot_zero_the_stock_of_another_sellers_product_with_a_huge_quantity(): void
    {
        $response = $this->submitEdit([$this->item(self::OTHER_SELLERS_PRODUCT, quantity: 999)]);

        $this->assertSame(50, $this->stockOf(self::OTHER_SELLERS_PRODUCT));
        $this->assertOrderUntouched();
        $this->assertNotFound($response, code: 'product');
    }

    public function test_an_edit_naming_a_product_that_does_not_exist_is_refused_and_writes_nothing(): void
    {
        $response = $this->submitEdit([$this->item(999, quantity: 1)]);

        $this->assertOrderUntouched();
        $this->assertNotFound($response, code: 'product');
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function idsThatAreNotWholeNumbers(): iterable
    {
        yield 'a missing id' => [null];
        yield 'an id with trailing garbage' => ['10abc'];
        yield 'a comma-joined list' => ['10,20'];
        yield 'a list' => [[10, 20]];
        yield 'a fraction' => [10.5];
    }

    #[DataProvider('idsThatAreNotWholeNumbers')]
    public function test_an_edit_naming_an_id_that_is_not_a_whole_number_is_refused_and_writes_nothing(mixed $id): void
    {
        $response = $this->submitEdit([['id' => $id] + $this->item(self::OWN_PRODUCT, quantity: 3)]);

        $this->assertOrderUntouched();
        $this->assertNotFound($response, code: 'product');
    }

    public function test_an_edit_without_a_product_list_is_refused_and_writes_nothing(): void
    {
        $response = $this->sellerApi('POST', 'orders/edit-order-submit', ['order_id' => (string) self::OWN_ORDER, 'products' => 'not json']);

        $this->assertOrderUntouched();
        $this->assertNotFound($response, code: 'product');
    }

    public function test_validating_an_edit_with_another_sellers_product_is_refused_and_prices_nothing(): void
    {
        $response = $this->sellerApi('POST', 'orders/edit-order-validation', ['order_id' => (string) self::OWN_ORDER, 'products' => json_encode([$this->item(self::OTHER_SELLERS_PRODUCT, quantity: 3)])]);

        $this->assertNull($response->json('order_amount'), 'the order was priced with the product');
        $this->assertNotFound($response, code: 'product');
    }

    public function test_validating_an_edit_with_the_in_house_product_is_refused_and_prices_nothing(): void
    {
        $response = $this->sellerApi('POST', 'orders/edit-order-validation', ['order_id' => (string) self::OWN_ORDER, 'products' => json_encode([$this->item(self::IN_HOUSE_PRODUCT, quantity: 3)])]);

        $this->assertNull($response->json('order_amount'), 'the order was priced with the product');
        $this->assertNotFound($response, code: 'product');
    }

    // ---- products the seller may put on the order ----

    #[DataProvider('productEncodings')]
    public function test_the_seller_can_still_edit_their_order_to_hold_more_of_their_own_product(string $encoding): void
    {
        $response = $this->submitEdit([$this->item(self::OWN_PRODUCT, quantity: 3)], $encoding);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('success', $response->json('status'));
        $this->assertSame([[self::OWN_PRODUCT, 3]], $this->linesOfTheOrder());
        // the two units the order held go back to the shelf, the three it holds now are taken
        $this->assertSame([49, 50, 50], $this->stockOfEachProduct());
        $this->assertEqualsWithDelta(300.0, (float) DB::table('orders')->where('id', self::OWN_ORDER)->value('order_amount'), 0.001);
        $this->assertSame(1, DB::table('order_edit_histories')->where('order_id', self::OWN_ORDER)->count());
    }

    /** @return iterable<string, array{0: bool}> */
    public static function ordersThatMayHoldALineWithoutAProduct(): iterable
    {
        yield 'an order of products only' => [false];
        yield 'an order that also holds a line without a product' => [true];
    }

    #[DataProvider('ordersThatMayHoldALineWithoutAProduct')]
    public function test_an_edit_that_leaves_out_a_product_of_the_order_removes_its_line_and_gives_its_units_back(bool $holdsALineWithoutAProduct): void
    {
        $this->theOrderAlsoHoldsTwoUnitsOfAnotherOwnProduct();
        if ($holdsALineWithoutAProduct) {
            $this->theOrderAlsoHoldsTwoUnitsWithoutAProductId();
        }

        $response = $this->submitEdit([$this->item(self::OWN_PRODUCT, quantity: 2)]);

        $this->assertSame('success', $response->json('status'));
        $this->assertEqualsCanonicalizing($holdsALineWithoutAProduct ? [[self::OWN_PRODUCT, 2], [0, 2]] : [[self::OWN_PRODUCT, 2]], $this->linesOfTheOrder());
        $this->assertSame(52, $this->stockOf(self::ANOTHER_OWN_PRODUCT), 'the units of the removed line did not go back to the shelf');
        $this->assertTheOrderIsWorthWhatItsLinesSay();
    }

    public function test_the_seller_can_still_validate_an_edit_of_their_own_product(): void
    {
        $response = $this->sellerApi('POST', 'orders/edit-order-validation', ['order_id' => (string) self::OWN_ORDER, 'products' => json_encode([$this->item(self::OWN_PRODUCT, quantity: 3)])]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('success', $response->json('status'));
        $this->assertEqualsWithDelta(300.0, (float) $response->json('order_amount'), 0.001);
        $this->assertSame([50, 50, 50], $this->stockOfEachProduct());
    }

    public function test_the_seller_can_still_edit_a_line_the_order_already_holds_even_if_the_product_is_no_longer_theirs(): void
    {
        DB::table('order_details')->where('id', self::OWN_LINE)->update(['product_id' => self::OTHER_SELLERS_PRODUCT]);

        $response = $this->submitEdit([$this->item(self::OTHER_SELLERS_PRODUCT, quantity: 1)]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('success', $response->json('status'));
        $this->assertSame([[self::OTHER_SELLERS_PRODUCT, 1]], $this->linesOfTheOrder());
    }

    public function test_an_edit_of_the_in_house_order_is_still_not_found(): void
    {
        $response = $this->sellerApi('POST', 'orders/edit-order-submit', ['order_id' => (string) self::IN_HOUSE_ORDER, 'products' => json_encode([$this->item(self::OWN_PRODUCT, quantity: 3)])]);

        $this->assertNotFound($response, code: 'order');
        $this->assertSame([50, 50, 50], $this->stockOfEachProduct());
    }

    // ---- another shop's product the order already holds ----

    /** @return iterable<string, array{0: int}> */
    public static function quantitiesTheHeldLineMayKeep(): iterable
    {
        yield 'the quantity it holds' => [2];
        yield 'fewer' => [1];
    }

    #[DataProvider('quantitiesTheHeldLineMayKeep')]
    public function test_an_order_that_holds_another_sellers_product_can_keep_or_reduce_the_line_and_the_units_go_back_to_that_shop(int $quantity): void
    {
        $this->theOrderHoldsTwoUnitsOfAnotherSellersProduct();

        $response = $this->submitEdit([$this->item(self::OTHER_SELLERS_PRODUCT, quantity: $quantity)]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('success', $response->json('status'));
        $this->assertSame([[self::OTHER_SELLERS_PRODUCT, $quantity]], $this->linesOfTheOrder());
        $this->assertSame(50 + 2 - $quantity, $this->stockOf(self::OTHER_SELLERS_PRODUCT));
    }

    /** @return iterable<string, array{0: int}> */
    public static function quantitiesTheHeldLineMayNotTake(): iterable
    {
        yield 'one more' => [3];
        yield 'a huge quantity' => [999];
        yield 'a negative quantity, which would put units on that shop\'s shelf' => [-999];
    }

    #[DataProvider('quantitiesTheHeldLineMayNotTake')]
    public function test_an_order_that_holds_another_sellers_product_cannot_take_more_of_it_or_put_units_on_that_shops_shelf(int $quantity): void
    {
        $this->theOrderHoldsTwoUnitsOfAnotherSellersProduct();

        $response = $this->submitEdit([$this->item(self::OTHER_SELLERS_PRODUCT, quantity: $quantity)]);

        $this->assertSame(50, $this->stockOf(self::OTHER_SELLERS_PRODUCT));
        $this->assertSame([[self::OTHER_SELLERS_PRODUCT, 2]], $this->linesOfTheOrder());
        $this->assertNotFound($response, code: 'product');
    }

    public function test_the_lines_an_edit_names_for_one_held_product_of_another_seller_together_cannot_take_more_than_the_order_holds(): void
    {
        $this->theOrderHoldsTwoUnitsOfAnotherSellersProduct();

        $response = $this->submitEdit([$this->item(self::OTHER_SELLERS_PRODUCT, quantity: 2), $this->item(self::OTHER_SELLERS_PRODUCT, quantity: 1)]);

        $this->assertSame(50, $this->stockOf(self::OTHER_SELLERS_PRODUCT));
        $this->assertNotFound($response, code: 'product');
    }

    public function test_an_edit_cannot_move_the_held_units_of_another_sellers_product_to_a_variant_the_order_does_not_hold(): void
    {
        $this->theOrderHoldsTwoUnitsOfAnotherSellersProduct();
        DB::table('order_details')->where('id', self::OWN_LINE)->update(['variant' => 'red']);

        $kept = $this->submitEdit([['variant' => 'red'] + $this->item(self::OTHER_SELLERS_PRODUCT, quantity: 2)]);
        $moved = $this->submitEdit([['variant' => 'blue'] + $this->item(self::OTHER_SELLERS_PRODUCT, quantity: 2)]);

        $this->assertSame(200, $kept->getStatusCode());
        $this->assertNotFound($moved, code: 'product');
    }

    public function test_validating_an_edit_that_takes_more_of_another_sellers_held_product_is_refused_and_prices_nothing(): void
    {
        $this->theOrderHoldsTwoUnitsOfAnotherSellersProduct();

        $response = $this->sellerApi('POST', 'orders/edit-order-validation', ['order_id' => (string) self::OWN_ORDER, 'products' => json_encode([$this->item(self::OTHER_SELLERS_PRODUCT, quantity: 3)])]);

        $this->assertNull($response->json('order_amount'), 'the order was priced with the product');
        $this->assertNotFound($response, code: 'product');
    }

    // ---- an order the app does not offer the edit for ----

    /** @return iterable<string, array{0: string, 1: string}> what makes the app not offer the edit, and the reason the edit is refused with */
    public static function whatTheAppDoesNotOfferTheEditFor(): iterable
    {
        $status = 'Order can only be edited when the order status is Pending or Confirmed.';

        yield 'a processing order' => ['processing', $status];
        yield 'a delivered order' => ['delivered', $status];
        yield 'a canceled order' => ['canceled', $status];
        yield 'an order that is not a default order' => ['point_of_sale', $status];
        yield 'sellers who may not edit orders' => ['sellers_may_not_edit', 'Vendors are not allowed to edit orders now.'];
        yield 'an offline payment that is not confirmed' => ['offline_payment_unpaid', 'Please confirm the offline payment information before editing this order.'];
        yield 'an offline due amount that is not paid' => ['due_amount_unpaid', 'Please confirm the offline payment information of the due amount before editing this order.'];
        yield 'only digital products' => ['only_digital', 'Orders containing only digital products cannot be edited.'];
    }

    #[DataProvider('whatTheAppDoesNotOfferTheEditFor')]
    public function test_an_edit_the_app_does_not_offer_is_refused_by_the_server_and_writes_nothing(string $state, string $reason): void
    {
        $this->theOrderIs($state);
        $orderBefore = DB::table('orders')->where('id', self::OWN_ORDER)->first();
        $editsBefore = DB::table('order_edit_histories')->count();

        $response = $this->submitEdit([$this->item(self::OWN_PRODUCT, quantity: 3)]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['error', translate($reason)], [$response->json('status'), $response->json('message')]);
        $this->assertSame([50, 50, 50], $this->stockOfEachProduct());
        $this->assertEquals($orderBefore, DB::table('orders')->where('id', self::OWN_ORDER)->first());
        $this->assertSame($editsBefore, DB::table('order_edit_histories')->count(), 'an edit was recorded');
    }

    /** @return iterable<string, array{0: string}> */
    public static function statusesTheAppOffersTheEditFor(): iterable
    {
        yield 'pending' => ['pending'];
        yield 'confirmed' => ['confirmed'];
    }

    #[DataProvider('statusesTheAppOffersTheEditFor')]
    public function test_an_order_the_app_offers_the_edit_for_is_still_saved_as_edited(string $status): void
    {
        DB::table('orders')->where('id', self::OWN_ORDER)->update(['order_status' => $status]);

        $response = $this->submitEdit([$this->item(self::OWN_PRODUCT, quantity: 3)]);

        $this->assertSame('success', $response->json('status'));
        $this->assertSame([[self::OWN_PRODUCT, 3]], $this->linesOfTheOrder());
        $this->assertSame([49, 50, 50], $this->stockOfEachProduct());
    }

    public function test_the_shared_edit_lets_the_admin_edit_an_order_sellers_may_not_edit_and_refuses_the_admin_a_delivered_one(): void
    {
        $editor = new class {
            use OrderEditManager;
        };
        $editedLines = fn (Order $order) => app(OrderEditService::class)->getFormatAPIProductsForEditOrder(
            request: ['products' => [$this->item(self::OWN_PRODUCT, quantity: 3)]], order: $order, colors: collect(),
        )['product_list'];
        $edit = fn (string $editBy) => $editor->generateEditOrder(
            order: ($order = Order::findOrFail(self::OWN_ORDER)), editedOrder: $editedLines($order), data: ['edit_by' => $editBy, 'edited_user_id' => 1, 'edited_user_name' => 'Editor'],
        );
        $this->theOrderIs('sellers_may_not_edit');

        $refusedForTheSeller = $edit('seller');
        $savedForTheAdmin = $edit('admin');
        $this->theOrderIs('delivered');
        $refusedForTheAdmin = $edit('admin');

        $this->assertSame(['error', 'success', 'error'], [$refusedForTheSeller['status'], $savedForTheAdmin['status'], $refusedForTheAdmin['status']]);
        $this->assertSame(1, DB::table('order_edit_histories')->count());
        $this->assertSame('admin', DB::table('order_edit_histories')->value('edit_by'));
    }

    public function test_the_shared_edit_holds_an_edit_that_does_not_say_who_edits_to_the_sellers_rule(): void
    {
        $editor = new class {
            use OrderEditManager;
        };
        $this->theOrderIs('sellers_may_not_edit');
        $order = Order::findOrFail(self::OWN_ORDER);
        $editedLines = app(OrderEditService::class)->getFormatAPIProductsForEditOrder(
            request: ['products' => [$this->item(self::OWN_PRODUCT, quantity: 3)]], order: $order, colors: collect(),
        )['product_list'];

        $result = $editor->generateEditOrder(order: $order, editedOrder: $editedLines);

        $this->assertSame(['error', translate('Vendors are not allowed to edit orders now.')], [$result['status'], $result['message']]);
        $this->assertSame([50, 50, 50], $this->stockOfEachProduct());
    }

    // ---- a save that fails halfway ----

    /** @param Closure(Closure): mixed $failsALateWrite */
    #[DataProvider('lateWritesThatFail')]
    public function test_a_save_of_the_app_that_fails_halfway_leaves_the_order_its_lines_its_stock_and_its_records_as_they_were(Closure $failsALateWrite): void
    {
        $this->theOrderHasTaxRowsRewardsAndATransaction();
        $before = $this->whatTheSaveWrites();
        $notifications = $this->listenForTheEditsNotifications();
        $failsALateWrite(static fn () => throw new \RuntimeException('a late write of the save failed'));

        $response = $this->submitEdit([$this->item(self::OWN_PRODUCT, quantity: 3)]);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame($before, $this->whatTheSaveWrites());
        $this->assertSame([], $notifications->levels, 'a notification was sent for an edit that was not saved');
    }

    /** @param Closure(Closure): mixed $failsALateWrite */
    #[DataProvider('lateWritesThatFail')]
    public function test_a_save_of_the_shared_edit_that_fails_halfway_for_the_admin_leaves_the_order_as_it_was_and_throws(Closure $failsALateWrite): void
    {
        $editor = new class {
            use OrderEditManager;
        };
        $this->theOrderHasTaxRowsRewardsAndATransaction();
        $order = Order::findOrFail(self::OWN_ORDER);
        $editedLines = app(OrderEditService::class)->getFormatAPIProductsForEditOrder(
            request: ['products' => [$this->item(self::OWN_PRODUCT, quantity: 3)]], order: $order, colors: collect(),
        )['product_list'];
        $before = $this->whatTheSaveWrites();
        $failsALateWrite(static fn () => throw new \RuntimeException('a late write of the save failed'));

        try {
            $editor->generateEditOrder(order: $order, editedOrder: $editedLines, data: ['edit_by' => 'admin', 'edited_user_id' => 1, 'edited_user_name' => 'Admin']);
            $this->fail('the edit did not fail');
        } catch (\RuntimeException $failure) {
            $this->assertSame('a late write of the save failed', $failure->getMessage());
        }

        $this->assertSame($before, $this->whatTheSaveWrites());
    }

    public function test_the_notifications_of_a_saved_edit_are_sent_after_its_writes_are_committed(): void
    {
        $notifications = $this->listenForTheEditsNotifications();

        $response = $this->submitEdit([$this->item(self::OWN_PRODUCT, quantity: 3)]);

        $this->assertSame('success', $response->json('status'));
        $this->assertNotSame([], $notifications->levels, 'no notification was sent');
        $this->assertSame(array_fill(0, count($notifications->levels), 0), $notifications->levels, 'a notification was sent inside the transaction of the save');
    }

    // ---- the in-house shop's own orders ----

    public function test_an_in_house_order_may_be_edited_with_in_house_products_only_and_not_with_a_sellers_who_shares_its_seller_id(): void
    {
        DB::table('order_details')->where('id', self::IN_HOUSE_LINE)->update(['product_id' => self::IN_HOUSE_PRODUCT]);
        $inHouseOrder = Order::with('details')->findOrFail(self::IN_HOUSE_ORDER);

        $this->assertTrue($this->isAllowedOnOrder(self::IN_HOUSE_PRODUCT, $inHouseOrder));
        $this->assertFalse($this->isAllowedOnOrder(self::OWN_PRODUCT, $inHouseOrder), 'a product of seller 1 on the order of the in-house shop, whose seller_id is also 1');
        $this->assertFalse($this->isAllowedOnOrder(self::OTHER_SELLERS_PRODUCT, $inHouseOrder));
    }

    // ---- the edit every caller goes through ----

    /** @return iterable<string, array{0: int}> */
    public static function productsOfOtherShops(): iterable
    {
        yield 'another seller product' => [self::OTHER_SELLERS_PRODUCT];
        yield 'the in-house product sharing the seller id' => [self::IN_HOUSE_PRODUCT];
    }

    #[DataProvider('productsOfOtherShops')]
    public function test_the_shared_edit_refuses_the_lines_it_is_given_for_another_shops_product_and_writes_nothing(int $productId): void
    {
        $editor = new class {
            use OrderEditManager;
        };
        $order = Order::with('details')->findOrFail(self::OWN_ORDER);
        $editedLines = app(OrderEditService::class)->getFormatAPIProductsForEditOrder(
            request: ['products' => [$this->item(self::OWN_PRODUCT, quantity: 2), $this->item($productId, quantity: 3)]], order: $order, colors: collect(),
        )['product_list'];

        $summary = $editor->generateEditOrderSummary(order: $order, editedOrder: $editedLines);
        $result = $editor->generateEditOrder(order: $order, editedOrder: $editedLines, data: ['edit_by' => 'seller', 'edited_user_id' => self::SELLER, 'edited_user_name' => 'Seller']);

        $this->assertOrderUntouched();
        $this->assertSame(['error', 'error'], [$summary['status'], $result['status']]);
    }

    public function test_the_shared_edit_takes_a_line_of_the_orders_own_product_and_no_line_without_a_known_product(): void
    {
        $order = Order::with('details')->findOrFail(self::OWN_ORDER);
        $service = app(OrderEditService::class);

        $this->assertTrue($service->areEditedLinesAllowedOnOrder(order: $order, editedLines: [['product_id' => self::OWN_PRODUCT], ['product_id' => '10']]));
        $this->assertFalse($service->areEditedLinesAllowedOnOrder(order: $order, editedLines: [['product_id' => 999]]));
        $this->assertFalse($service->areEditedLinesAllowedOnOrder(order: $order, editedLines: [['product_id' => null]]));
        $this->assertFalse($service->areEditedLinesAllowedOnOrder(order: $order, editedLines: [['qty' => 1]]));
    }

    // ---- an order that holds a line without a product id ----

    public function test_the_shared_edit_takes_a_line_the_order_holds_without_a_product_id_as_it_takes_any_held_line(): void
    {
        $this->theOrderAlsoHoldsTwoUnitsWithoutAProductId();
        $order = Order::with('details')->findOrFail(self::OWN_ORDER);
        $service = app(OrderEditService::class);

        $this->assertTrue($service->areEditedLinesAllowedOnOrder(order: $order, editedLines: [['product_id' => null, 'variant' => '', 'qty' => 2]]), 'kept');
        $this->assertTrue($service->areEditedLinesAllowedOnOrder(order: $order, editedLines: [['product_id' => null, 'variant' => null, 'qty' => 1]]), 'reduced');
        $this->assertTrue($service->areEditedLinesAllowedOnOrder(order: $order, editedLines: [['product_id' => null, 'variant' => '', 'qty' => 0]]), 'removed');
        $this->assertFalse($service->areEditedLinesAllowedOnOrder(order: $order, editedLines: [['product_id' => null, 'variant' => '', 'qty' => 3]]), 'increased');
        $this->assertFalse($service->areEditedLinesAllowedOnOrder(order: $order, editedLines: [['product_id' => null, 'variant' => 'red', 'qty' => 1]]), 'moved to a variant the order does not hold');
    }

    /** @param array<string, mixed> $copyOfTheProduct */
    #[DataProvider('copiesOfTheProductOfALineWithoutAProduct')]
    public function test_an_edit_of_an_order_that_holds_a_line_without_a_product_id_can_still_hold_more_of_the_orders_own_product_and_the_line_is_kept_as_it_is(array $copyOfTheProduct): void
    {
        $this->theOrderAlsoHoldsTwoUnitsWithoutAProductId(copyOfTheProduct: $copyOfTheProduct);
        $lineBefore = $this->theLineWithoutAProduct();

        $response = $this->submitEdit([$this->item(self::OWN_PRODUCT, quantity: 3)]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('success', $response->json('status'));
        $this->assertEqualsCanonicalizing([[self::OWN_PRODUCT, 3], [0, 2]], $this->linesOfTheOrder());
        $this->assertEquals($lineBefore, $this->theLineWithoutAProduct(), 'the line was changed');
        $this->assertSame([49, 50, 50], $this->stockOfEachProduct());
        $this->assertTheOrderIsWorthWhatItsLinesSay();
    }

    /** @param array<string, mixed> $copyOfTheProduct */
    #[DataProvider('copiesOfTheProductOfALineWithoutAProduct')]
    public function test_validating_an_edit_of_an_order_that_holds_a_line_without_a_product_id_prices_the_line_with_the_others_and_writes_nothing(array $copyOfTheProduct): void
    {
        $this->theOrderAlsoHoldsTwoUnitsWithoutAProductId(copyOfTheProduct: $copyOfTheProduct);
        $before = $this->whatTheSaveWrites();

        $response = $this->sellerApi('POST', 'orders/edit-order-validation', ['order_id' => (string) self::OWN_ORDER, 'products' => json_encode([$this->item(self::OWN_PRODUCT, quantity: 3)])]);

        $this->assertSame('success', $response->json('status'));
        $this->assertEqualsWithDelta(500.0, (float) $response->json('order_amount'), 0.001);
        $this->assertSame($before, $this->whatTheSaveWrites());
    }

    public function test_an_edit_keeps_a_line_without_a_product_id_that_carries_a_discount_exactly_as_the_order_stored_it(): void
    {
        $this->theOrderAlsoHoldsTwoUnitsWithoutAProductId(discount: 20);
        $lineBefore = $this->theLineWithoutAProduct();

        $response = $this->submitEdit([$this->item(self::OWN_PRODUCT, quantity: 3)]);

        $this->assertSame('success', $response->json('status'));
        $this->assertEquals($lineBefore, $this->theLineWithoutAProduct(), 'the line was changed');
        $this->assertTheOrderIsWorthWhatItsLinesSay();
        $this->assertEqualsWithDelta(480.0, (float) DB::table('orders')->where('id', self::OWN_ORDER)->value('order_amount'), 0.001, 'three units of the own product and two of the line, less its discount');
    }

    public function test_an_edit_of_an_order_whose_line_without_a_product_id_carries_tax_is_refused_by_the_validation_and_the_save_and_writes_nothing(): void
    {
        $this->theOrderAlsoHoldsTwoUnitsWithoutAProductId(tax: 7);
        $before = $this->whatTheSaveWrites();

        $validation = $this->sellerApi('POST', 'orders/edit-order-validation', ['order_id' => (string) self::OWN_ORDER, 'products' => json_encode([$this->item(self::OWN_PRODUCT, quantity: 3)])]);
        $submission = $this->submitEdit([$this->item(self::OWN_PRODUCT, quantity: 3)]);

        $this->assertSame(['error', translate('order_item_of_a_removed_product_carries_tax')], [$validation->json('status'), $validation->json('message')]);
        $this->assertEqualsWithDelta(500.0, (float) $validation->json('order_amount'), 0.001, 'the refusal does not name the order\'s own total');
        $this->assertSame(['error', translate('order_item_of_a_removed_product_carries_tax')], [$submission->json('status'), $submission->json('message')]);
        $this->assertSame($before, $this->whatTheSaveWrites());
    }

    public function test_an_edit_that_names_the_id_zero_is_refused_even_when_the_order_holds_a_line_without_a_product_id(): void
    {
        $this->theOrderAlsoHoldsTwoUnitsWithoutAProductId();
        $before = $this->whatTheSaveWrites();

        $response = $this->submitEdit([['id' => 0] + $this->item(self::OWN_PRODUCT, quantity: 2)]);

        $this->assertNotFound($response, code: 'product');
        $this->assertSame($before, $this->whatTheSaveWrites());
    }

    /** @param array<string, mixed> $copyOfTheProduct */
    #[DataProvider('copiesOfTheProductOfALineWithoutAProduct')]
    public function test_the_shared_edit_keeps_the_line_without_a_product_id_for_the_admin_and_writes_the_others(array $copyOfTheProduct): void
    {
        $editor = new class {
            use OrderEditManager;
        };
        $this->theOrderAlsoHoldsTwoUnitsWithoutAProductId(copyOfTheProduct: $copyOfTheProduct);
        $lineBefore = $this->theLineWithoutAProduct();
        $order = Order::with('details')->findOrFail(self::OWN_ORDER);
        $adminsSession = app(OrderEditService::class)->addOrderDetailsInSession(order: $order);
        $adminsSession[self::OWN_LINE_KEY]['qty'] = 3;

        $result = $editor->generateEditOrder(order: $order, editedOrder: $adminsSession, data: ['edit_by' => 'admin', 'edited_user_id' => 1, 'edited_user_name' => 'Admin']);

        $this->assertSame('success', $result['status']);
        $this->assertEquals($lineBefore, $this->theLineWithoutAProduct(), 'the line was changed');
        $this->assertEqualsCanonicalizing([[self::OWN_PRODUCT, 3], [0, 2]], $this->linesOfTheOrder());
        $this->assertTheOrderIsWorthWhatItsLinesSay();
    }

    /** @return iterable<string, array{0: string}> what an edit of the admin's session may do to the line without a product id */
    public static function editsThatChangeTheLineWithoutAProduct(): iterable
    {
        yield 'removes it' => ['removed'];
        yield 'reduces it' => ['reduced'];
        yield 'changes its price' => ['another_price'];
    }

    #[DataProvider('editsThatChangeTheLineWithoutAProduct')]
    public function test_the_shared_edit_refuses_an_edit_of_the_admin_that_changes_the_line_without_a_product_id_and_writes_nothing(string $change): void
    {
        $editor = new class {
            use OrderEditManager;
        };
        $this->theOrderAlsoHoldsTwoUnitsWithoutAProductId();
        $order = Order::with('details')->findOrFail(self::OWN_ORDER);
        $adminsSession = app(OrderEditService::class)->addOrderDetailsInSession(order: $order);
        match ($change) {
            'removed' => $adminsSession = array_diff_key($adminsSession, [self::LINE_WITHOUT_PRODUCT_KEY => true]),
            'reduced' => $adminsSession[self::LINE_WITHOUT_PRODUCT_KEY]['qty'] = 1,
            'another_price' => $adminsSession[self::LINE_WITHOUT_PRODUCT_KEY]['price'] = 1,
        };
        $before = $this->whatTheSaveWrites();

        $result = $editor->generateEditOrder(order: $order, editedOrder: $adminsSession, data: ['edit_by' => 'admin', 'edited_user_id' => 1, 'edited_user_name' => 'Admin']);

        $this->assertSame(['error', translate('order_item_of_a_removed_product_cannot_be_changed')], [$result['status'], $result['message']]);
        $this->assertSame($before, $this->whatTheSaveWrites());
    }

    public function test_the_summary_does_not_tax_a_line_without_a_product_id_again(): void
    {
        $editor = new class {
            use OrderEditManager;
        };
        Cache::put('tax_system_type_vendor_with_vat', [
            'SystemTaxVat' => ['is_active' => 1, 'tax_ids' => [1], 'additionalData' => []], 'SystemTaxVatType' => 'order_wise', 'is_included' => 0,
            'productWiseTax' => false, 'categoryWiseTax' => false, 'taxVats' => collect([['id' => 1, 'name' => 'VAT', 'tax_rate' => 10]]),
        ], 3600);
        $this->theOrderAlsoHoldsTwoUnitsWithoutAProductId();
        $order = Order::with('details')->findOrFail(self::OWN_ORDER);

        $summary = $editor->generateEditOrderSummary(order: $order, editedOrder: $this->theEditedLinesHoldingOfTheOwnProduct(order: $order, quantity: 3));

        $this->assertSame('success', $summary['status']);
        $this->assertEqualsWithDelta(30.0, $summary['tax_summary']['total_tax_amount'], 0.001, 'the tax of the own product only: the line without a product carries none');
        $this->assertEqualsWithDelta(530.0, $summary['order_amount'], 0.001);
        $taxesOfTheLines = collect($summary['tax_summary']['applied_tax_cart_list'])->groupBy(static fn (array $line): string => empty($line['product_id']) ? 'without_a_product' : 'own_product');
        $this->assertNotEmpty($taxesOfTheLines['own_product'][0]['applied_tax_ids'], 'the tax system names no tax for the own product, so this test says nothing');
        $this->assertSame([], $taxesOfTheLines['without_a_product'][0]['applied_tax_ids'], 'the line without a product is entered under a tax it does not carry');
    }

    public function test_the_summary_counts_a_line_without_a_product_id_in_the_coupon_and_the_shipping_like_any_other_line(): void
    {
        $editor = new class {
            use OrderEditManager;
        };
        Schema::table('coupons', function (Blueprint $table) {
            $table->decimal('discount', 16, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('min_purchase', 16, 2)->default(0);
            $table->decimal('max_discount', 16, 2)->default(0);
        });
        DB::table('coupons')->insert([
            'code' => 'TEN', 'coupon_type' => 'discount_on_purchase', 'coupon_bearer' => 'seller', 'seller_id' => self::SELLER, 'customer_id' => 0, 'start_date' => now()->subDay()->toDateString(),
            'expire_date' => now()->addMonth()->toDateString(), 'status' => 1, 'discount' => 10, 'discount_type' => 'percentage', 'max_discount' => 1000,
        ]);
        DB::table('business_settings')->insert(['type' => 'shipping_method', 'value' => 'inhouse_shipping']);
        Cache::forget(CACHE_BUSINESS_SETTINGS_TABLE);
        DB::table('orders')->where('id', self::OWN_ORDER)->update(['coupon_code' => 'TEN', 'shipping_cost' => 20]);
        $this->theOrderAlsoHoldsTwoUnitsWithoutAProductId();
        $order = Order::with('details')->findOrFail(self::OWN_ORDER);

        $summary = $editor->generateEditOrderSummary(order: $order, editedOrder: $this->theEditedLinesHoldingOfTheOwnProduct(order: $order, quantity: 3));

        $this->assertSame('success', $summary['status']);
        $this->assertEqualsWithDelta(500.0, $summary['coupon_summary']['total_cart_amount'], 0.001, 'the lines are the own product and the line without a product');
        $this->assertEqualsWithDelta(50.0, $summary['coupon_summary']['discount'], 0.001);
        $this->assertEqualsWithDelta(470.0, $summary['order_amount'], 0.001, 'the lines, less the coupon, plus the order\'s shipping');
    }

    public function test_an_edit_that_names_no_product_is_still_refused_when_the_order_holds_a_line_without_a_product_id(): void
    {
        $this->theOrderAlsoHoldsTwoUnitsWithoutAProductId();

        $response = $this->submitEdit([['id' => null] + $this->item(self::OWN_PRODUCT, quantity: 2)]);

        $this->assertNotFound($response, code: 'product');
        $this->assertSame([[self::OWN_PRODUCT, 2], [0, 2]], $this->linesOfTheOrder());
        $this->assertSame([50, 50, 50], $this->stockOfEachProduct());
    }

    /** @return iterable<string, array{0: string}> */
    public static function refusalsOfTheSummary(): iterable
    {
        yield 'another shop product' => ['another_shops_product'];
        yield 'a line that is out of stock' => ['out_of_stock'];
    }

    #[DataProvider('refusalsOfTheSummary')]
    public function test_a_refused_summary_still_carries_the_total_of_the_order_for_the_pages_that_print_it(string $refusal): void
    {
        $editor = new class {
            use OrderEditManager;
        };
        DB::table('products')->where('id', self::OWN_PRODUCT)->update(['current_stock' => 0]);
        DB::table('order_details')->where('id', self::OWN_LINE)->update(['qty' => 0]);
        $order = Order::with('details')->findOrFail(self::OWN_ORDER);
        $line = ['another_shops_product' => ['product_id' => self::OTHER_SELLERS_PRODUCT, 'variant' => '', 'qty' => 1], 'out_of_stock' => ['product_id' => self::OWN_PRODUCT, 'variant' => '', 'qty' => 0]][$refusal];

        $summary = $editor->generateEditOrderSummary(order: $order, editedOrder: [$line]);

        $this->assertSame('error', $summary['status']);
        $this->assertEqualsWithDelta(500.0, $summary['order_amount'], 0.001);
    }

    public function test_the_shared_edit_of_an_in_house_order_takes_in_house_products_only(): void
    {
        DB::table('order_details')->where('id', self::IN_HOUSE_LINE)->update(['product_id' => self::IN_HOUSE_PRODUCT]);
        $inHouseOrder = Order::with('details')->findOrFail(self::IN_HOUSE_ORDER);
        $service = app(OrderEditService::class);

        $this->assertTrue($service->areEditedLinesAllowedOnOrder(order: $inHouseOrder, editedLines: [['product_id' => self::IN_HOUSE_PRODUCT]]));
        $this->assertFalse($service->areEditedLinesAllowedOnOrder(order: $inHouseOrder, editedLines: [['product_id' => self::OWN_PRODUCT]]), 'a product of seller 1 on the order of the in-house shop, whose seller_id is also 1');
        $this->assertFalse($service->areEditedLinesAllowedOnOrder(order: $inHouseOrder, editedLines: [['product_id' => self::OTHER_SELLERS_PRODUCT]]));
    }

    // ---- helpers ----

    private function theOrderHasTaxRowsRewardsAndATransaction(): void
    {
        DB::table('order_taxes')->insert(['order_id' => self::OWN_ORDER, 'tax_for' => 'tax_for_orders', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('order_details_rewards')->insert([
            'order_id' => self::OWN_ORDER, 'product_id' => self::OWN_PRODUCT, 'order_details_id' => self::OWN_LINE, 'reward_type' => 'loyalty_point', 'reward_amount' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_transactions')->insert([
            'order_id' => self::OWN_ORDER, 'seller_id' => self::SELLER, 'seller_is' => 'seller', 'order_amount' => 500, 'seller_amount' => 500, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Makes the seller's own order one the app does not offer the edit for. */
    private function theOrderIs(string $state): void
    {
        match ($state) {
            'processing', 'delivered', 'canceled' => DB::table('orders')->where('id', self::OWN_ORDER)->update(['order_status' => $state]),
            'point_of_sale' => DB::table('orders')->where('id', self::OWN_ORDER)->update(['order_type' => 'POS']),
            'sellers_may_not_edit' => $this->sellersMayNotEditOrders(),
            'offline_payment_unpaid' => DB::table('orders')->where('id', self::OWN_ORDER)->update(['payment_method' => 'offline_payment', 'payment_status' => 'unpaid']),
            'due_amount_unpaid' => $this->theLastEditLeftAnOfflineDueAmountUnpaid(),
            'only_digital' => DB::table('order_details')->where('id', self::OWN_LINE)->update(['product_details' => json_encode(['product_type' => 'digital'] + json_decode((string) DB::table('order_details')->where('id', self::OWN_LINE)->value('product_details'), true))]),
        };
    }

    private function sellersMayNotEditOrders(): void
    {
        DB::table('business_settings')->where('type', 'vendor_can_edit_order')->update(['value' => '0']);
        Cache::forget(CACHE_BUSINESS_SETTINGS_TABLE);
    }

    private function theLastEditLeftAnOfflineDueAmountUnpaid(): void
    {
        DB::table('orders')->where('id', self::OWN_ORDER)->update(['edited_status' => 1, 'edit_due_amount' => 100]);
        DB::table('order_edit_histories')->insert([
            'order_id' => self::OWN_ORDER, 'edit_by' => 'seller', 'order_amount' => 500, 'order_due_amount' => 100, 'order_due_payment_method' => 'offline_payment', 'order_due_payment_status' => 'unpaid',
        ]);
    }

    private function theOrderAlsoHoldsTwoUnitsOfAnotherOwnProduct(): void
    {
        DB::table('products')->insert(['id' => self::ANOTHER_OWN_PRODUCT, 'name' => 'Another own product'] + (array) DB::table('products')->where('id', self::OWN_PRODUCT)->first());

        $ownLine = (array) DB::table('order_details')->where('id', self::OWN_LINE)->first();
        DB::table('order_details')->insert([
            'id' => self::ANOTHER_OWN_LINE,
            'product_id' => self::ANOTHER_OWN_PRODUCT,
            'product_details' => json_encode(['id' => self::ANOTHER_OWN_PRODUCT, 'name' => 'Another own item'] + json_decode($ownLine['product_details'], true)),
        ] + $ownLine);
    }

    private function theOrderHoldsTwoUnitsOfAnotherSellersProduct(): void
    {
        DB::table('order_details')->where('id', self::OWN_LINE)->update(['product_id' => self::OTHER_SELLERS_PRODUCT]);
    }

    /** @param array<string, mixed> $copyOfTheProduct what the copy of the product the row keeps says, where it is not what the own product says */
    private function theOrderAlsoHoldsTwoUnitsWithoutAProductId(float $tax = 0, array $copyOfTheProduct = [], float $discount = 0): void
    {
        DB::table('order_details')->insert([
            'order_id' => self::OWN_ORDER, 'seller_id' => self::SELLER, 'product_id' => null, 'qty' => 2, 'price' => 100, 'tax' => $tax, 'discount' => $discount, 'variation' => '[]', 'variant' => '',
            'product_details' => json_encode(['id' => null, 'name' => 'Product that is gone'] + $copyOfTheProduct + (array) DB::table('products')->where('id', self::OWN_PRODUCT)->first()),
        ]);
    }

    /** @return array<string, mixed> the line without a product id, as the order stores it */
    private function theLineWithoutAProduct(): array
    {
        return (array) DB::table('order_details')->whereNull('product_id')->first();
    }

    private function assertTheOrderIsWorthWhatItsLinesSay(): void
    {
        $lines = DB::table('order_details')->where('order_id', self::OWN_ORDER)->get();

        $this->assertEqualsWithDelta(
            $lines->sum(static fn (object $line): float => (float) $line->price * (float) $line->qty - (float) $line->discount + (float) $line->tax),
            (float) DB::table('orders')->where('id', self::OWN_ORDER)->value('order_amount'),
            0.001,
            'the order is not worth what its lines say',
        );
    }

    /**
     * The lines of an edit that holds the quantity of the own product the app names and keeps every line without a product as the order's own session holds it.
     *
     * @return array<string, array<string, mixed>>
     */
    private function theEditedLinesHoldingOfTheOwnProduct(Order $order, int $quantity): array
    {
        $service = app(OrderEditService::class);
        $linesWithoutAProduct = array_filter($service->addOrderDetailsInSession(order: $order), static fn (array $line): bool => empty($line['product_id']));

        return $service->getFormatAPIProductsForEditOrder(request: ['products' => [$this->item(self::OWN_PRODUCT, quantity: $quantity)]], order: $order, colors: collect())['product_list'] + $linesWithoutAProduct;
    }

    /** @param list<array<string, mixed>> $items */
    private function submitEdit(array $items, string $encoding = 'json'): TestResponse
    {
        return $this->sellerApi('POST', 'orders/edit-order-submit', ['order_id' => (string) self::OWN_ORDER, 'products' => $encoding === 'json' ? json_encode($items) : $items]);
    }

    /** One entry of the seller app's edit cart (OrderEditCartModel::toJson). @return array<string, mixed> */
    private function item(int $productId, int $quantity): array
    {
        return [
            'id' => $productId, 'price' => 100, 'discount_amount' => 0, 'quantity' => $quantity, 'tax_amount' => 0, 'variant' => null,
            'tax_model' => 'exclude', 'is_order_product' => false, 'color' => null, 'choice' => [],
        ];
    }

    private function isAllowedOnOrder(int $productId, Order $order): bool
    {
        return app(OrderEditService::class)->areRequestedProductsAllowedOnOrder(request: ['products' => [$this->item($productId, quantity: 1)]], order: $order);
    }

    private function assertNotFound(TestResponse $response, string $code): void
    {
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame($code, $response->json('errors.0.code'));
    }

    /** What a refused edit leaves behind: nothing. */
    private function assertOrderUntouched(): void
    {
        $this->assertSame([50, 50, 50], $this->stockOfEachProduct());
        $this->assertSame([[self::OWN_PRODUCT, 2]], $this->linesOfTheOrder());
        $this->assertEqualsWithDelta(500.0, (float) DB::table('orders')->where('id', self::OWN_ORDER)->value('order_amount'), 0.001);
        $this->assertSame(0, DB::table('order_edit_histories')->count());
    }

    /** @return list<array{0: int, 1: int}> product and quantity of every line of the seller's own order */
    private function linesOfTheOrder(): array
    {
        return DB::table('order_details')->where('order_id', self::OWN_ORDER)->orderBy('id')->get()
            ->map(fn ($line) => [(int) $line->product_id, (int) $line->qty])->all();
    }

    private function stockOf(int $productId): int
    {
        return (int) DB::table('products')->where('id', $productId)->value('current_stock');
    }

    /** @return array{0: int, 1: int, 2: int} stock of the own, the other seller's and the in-house product */
    private function stockOfEachProduct(): array
    {
        return array_map(fn (int $productId) => $this->stockOf($productId), [self::OWN_PRODUCT, self::OTHER_SELLERS_PRODUCT, self::IN_HOUSE_PRODUCT]);
    }

    /** What the real edit writers read and write beyond what an order's relations need. */
    private function createTablesTheEditWritersUse(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['discount_type', 'coupon_code', 'coupon_discount_bearer', 'tax_type', 'tax_model', 'extra_discount_type', 'free_delivery_bearer'] as $column) {
                $table->string($column)->nullable();
            }
            foreach (['admin_commission', 'edit_due_amount', 'edit_return_amount', 'total_tax_amount', 'shipping_cost', 'extra_discount'] as $column) {
                $table->decimal($column, 16, 2)->default(0);
            }
            $table->boolean('is_shipping_free')->default(false);
            $table->boolean('edited_status')->default(false);
        });
        Schema::table('order_details', function (Blueprint $table) {
            $table->string('tax_model')->nullable();
            $table->string('discount_type')->nullable();
            $table->string('payment_status')->nullable();
            $table->unsignedBigInteger('shipping_method_id')->nullable();
            $table->dateTime('refund_started_at')->nullable();
        });
        Schema::table('products', function (Blueprint $table) {
            $table->string('slug')->nullable();
            $table->boolean('status')->default(true);
            $table->boolean('request_status')->default(true);
            $table->string('discount_type')->nullable();
            $table->decimal('discount', 16, 2)->default(0);
            $table->decimal('tax', 16, 2)->default(0);
            $table->integer('minimum_order_qty')->default(1);
            $table->unsignedBigInteger('category_id')->nullable();
            $table->decimal('shipping_cost', 16, 2)->default(0);
            $table->boolean('multiply_qty')->default(false);
        });
        $this->createSellerApiTable('order_edit_histories', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id');
            $table->string('edit_by')->nullable();
            $table->unsignedBigInteger('edited_user_id')->nullable();
            $table->string('edited_user_name')->nullable();
            $table->decimal('order_amount', 16, 2)->default(0);
            $table->decimal('order_due_amount', 16, 2)->default(0);
            $table->string('order_due_payment_method')->nullable();
            $table->string('order_due_payment_status')->nullable();
            $table->decimal('order_return_amount', 16, 2)->default(0);
            $table->string('order_return_payment_status')->nullable();
            $table->unsignedBigInteger('u_id')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('order_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is')->nullable();
            $table->decimal('order_amount', 16, 2)->default(0);
            $table->decimal('seller_amount', 16, 2)->default(0);
            $table->decimal('admin_commission', 16, 2)->default(0);
            $table->decimal('delivery_charge', 16, 2)->default(0);
            $table->decimal('tax', 16, 2)->default(0);
            $table->timestamps();
        });
        $this->createSellerApiTable('order_details_rewards', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('order_details_id')->nullable();
            $table->boolean('reward_delivered')->default(false);
            $table->string('reward_type')->nullable();
            $table->decimal('reward_amount', 16, 2)->default(0);
            $table->timestamps();
        });
        $this->createSellerApiTable('order_taxes', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('tax_for')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('shipping_types', function (Blueprint $table) {
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('shipping_type')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('shipping_methods', fn (Blueprint $table) => $table->string('title')->nullable());
        $this->createSellerApiTable('coupons', function (Blueprint $table) {
            $table->string('code')->nullable();
            $table->string('coupon_type')->nullable();
            $table->string('coupon_bearer')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->date('start_date')->nullable();
            $table->date('expire_date')->nullable();
            $table->integer('status')->default(1);
            $table->timestamps();
        });
    }
}
