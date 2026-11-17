<?php

namespace Tests\Feature\Security;

use App\Models\Admin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsPanelOrderEditTables;

/**
 * The vendor panel's order edit takes the order from the signed-in seller, but the product from the request: the modal, the variant price and the
 * add-to-edit handlers read any product by its id, and the edit then writes it as a line of the seller's own order (carrying the order's seller id),
 * reprices the order from it and takes its stock. A seller put another seller's product, or the in-house shop's, on their order and took that shop's
 * stock; the seller app's order edit was closed the same way before.
 *
 * An edit may only name products of the order's own shop (or products the order already holds). The rule is the shared edit's, not each handler's:
 * a product that got into the edit's session by another way is refused when the edit is saved.
 */
class VendorOrderEditProductsTest extends VendorPanelTestCase
{
    use BuildsPanelOrderEditTables;

    private const ORDER = 100001;
    private const SESSION_KEY = 'SELLER_SESSION_FOR_EDIT_ORDER_100001';
    private const HELD_LINE_KEY = '20-single-variant';
    private const OWN_LINE_KEY = '10-single-variant';
    private const LINE_WITHOUT_PRODUCT_KEY = '-single-variant';
    private const BLUE_LINE_KEY = '20-Blue';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTheTablesTheOrderEditUses();
        $this->createTheTablesTheModalReads();
        $this->seedAnOwnOrderHoldingTwoUnitsOfTheOwnProduct();
    }

    // ---- adding a product to the edit ----

    /** @return array<string, array{int}> a product the order's shop does not own, and one that does not exist */
    public static function productsThatAreNotTheOrdersShops(): array
    {
        return [
            'another seller product' => [self::OTHER_SELLERS_PRODUCT],
            'the in-house product sharing the seller id' => [self::IN_HOUSE_PRODUCT],
            'a product that does not exist' => [999],
        ];
    }

    #[DataProvider('productsThatAreNotTheOrdersShops')]
    public function test_a_product_that_is_not_the_orders_shops_is_refused_by_the_add_and_the_saved_edit_writes_nothing(int $productId): void
    {
        $added = $this->addToTheEdit(productId: $productId, quantity: 3);
        $saved = $this->saveTheEdit();

        $this->assertTheOrderHoldsWhatItHeld();
        $added->assertOk()->assertJson(['status' => 'error']);
        $saved->assertOk()->assertJson(['status' => 'success']);
    }

    public function test_the_seller_still_adds_their_own_product_to_the_edit_and_saves_it(): void
    {
        $this->addToTheEdit(productId: self::OWN_PRODUCT, quantity: 3)->assertOk()->assertJson(['status' => 'success']);
        $this->saveTheEdit()->assertOk()->assertJson(['status' => 'success']);

        $this->assertSame([[self::OWN_PRODUCT, 3]], $this->linesOfTheOrder());
        $this->assertSame([49, 50, 50], $this->stockOfEachProduct());
    }

    // ---- looking at a product from the edit ----

    #[DataProvider('productsThatAreNotTheOrdersShops')]
    public function test_the_modal_and_the_variant_price_of_a_product_that_is_not_the_orders_shops_are_not_found(int $productId): void
    {
        foreach (['edit-order-product-modal-view', 'edit-order-product-variant-price'] as $route) {
            $this->signInAs(self::SELLER)->postJson('/vendor/orders/' . $route, ['order_id' => self::ORDER, 'product_id' => $productId])->assertNotFound();
        }
    }

    public function test_the_modal_and_the_variant_price_of_the_sellers_own_product_still_open(): void
    {
        foreach (['edit-order-product-modal-view' => 'htmlView', 'edit-order-product-variant-price' => 'product_quick_view_details'] as $route => $key) {
            $response = $this->signInAs(self::SELLER)->postJson('/vendor/orders/' . $route, ['order_id' => self::ORDER, 'product_id' => self::OWN_PRODUCT]);

            $response->assertOk();
            $this->assertStringContainsString('Own product', (string) $response->json($key));
        }
    }

    public function test_the_modal_and_the_variant_price_of_a_product_the_order_already_holds_still_open_even_if_it_is_no_longer_the_sellers(): void
    {
        $this->theOrderHoldsTwoUnitsOfAnotherSellersProduct();

        foreach (['edit-order-product-modal-view' => 'htmlView', 'edit-order-product-variant-price' => 'product_quick_view_details'] as $route => $key) {
            $response = $this->signInAs(self::SELLER)->postJson('/vendor/orders/' . $route, ['order_id' => self::ORDER, 'product_id' => self::OTHER_SELLERS_PRODUCT]);

            $response->assertOk();
            $this->assertStringContainsString('Other sellers product', (string) $response->json($key));
        }
    }

    // ---- the saved edit, whatever put the product in it ----

    #[DataProvider('productsThatAreNotTheOrdersShops')]
    public function test_a_saved_edit_whose_session_holds_a_product_that_is_not_the_orders_shops_writes_nothing(int $productId): void
    {
        $this->addToTheEdit(productId: self::OWN_PRODUCT, quantity: 2)->assertOk();
        $this->putALineOfProductIntoTheSession(productId: $productId, quantity: 3);

        $response = $this->saveTheEdit();

        $this->assertTheOrderHoldsWhatItHeld();
        $this->assertSame(0, DB::table('order_edit_histories')->count(), 'an edit was recorded');
        $response->assertOk()->assertJson(['status' => 'error']);
    }

    // ---- another shop's product the order already holds ----

    public function test_an_order_that_holds_another_sellers_product_can_reduce_the_line_and_the_units_go_back_to_that_shop(): void
    {
        $this->theOrderHoldsTwoUnitsOfAnotherSellersProduct();

        $updated = $this->updateTheQuantityInTheEdit(key: self::HELD_LINE_KEY, quantity: 1);
        $saved = $this->saveTheEdit();

        $this->assertSame([[self::OTHER_SELLERS_PRODUCT, 1]], $this->linesOfTheOrder());
        $this->assertSame(51, $this->stockOf(self::OTHER_SELLERS_PRODUCT));
        $updated->assertOk()->assertJson(['status' => 'success']);
        $saved->assertOk()->assertJson(['status' => 'success']);
    }

    public function test_an_order_that_holds_another_sellers_product_can_keep_the_line_when_it_is_named_again_with_the_quantity_it_holds(): void
    {
        $this->theOrderHoldsTwoUnitsOfAnotherSellersProduct();

        $this->addToTheEdit(productId: self::OTHER_SELLERS_PRODUCT, quantity: 2)->assertOk()->assertJson(['status' => 'success']);
        $this->saveTheEdit()->assertOk()->assertJson(['status' => 'success']);

        $this->assertSame([[self::OTHER_SELLERS_PRODUCT, 2]], $this->linesOfTheOrder());
        $this->assertSame(50, $this->stockOf(self::OTHER_SELLERS_PRODUCT));
    }

    #[DataProvider('quantitiesTheHeldLineMayNotTake')]
    public function test_an_order_that_holds_another_sellers_product_cannot_take_more_of_it_through_the_quantity_update_or_the_add(int $quantity): void
    {
        $this->theOrderHoldsTwoUnitsOfAnotherSellersProduct();

        $updated = $this->updateTheQuantityInTheEdit(key: self::HELD_LINE_KEY, quantity: $quantity);
        $added = $this->addToTheEdit(productId: self::OTHER_SELLERS_PRODUCT, quantity: $quantity);
        $this->saveTheEdit()->assertOk();

        $this->assertSame([[self::OTHER_SELLERS_PRODUCT, 2]], $this->linesOfTheOrder());
        $this->assertSame(50, $this->stockOf(self::OTHER_SELLERS_PRODUCT));
        $updated->assertOk()->assertJson(['status' => 'error']);
        $added->assertOk()->assertJson(['status' => 'error']);
    }

    #[DataProvider('quantitiesTheHeldLineMayNotTake')]
    public function test_a_saved_edit_whose_session_takes_more_of_another_sellers_held_product_writes_nothing(int $quantity): void
    {
        $this->theOrderHoldsTwoUnitsOfAnotherSellersProduct();
        $this->updateTheQuantityInTheEdit(key: self::HELD_LINE_KEY, quantity: 2)->assertOk();
        $session = session(self::SESSION_KEY);
        $session['product_list'][self::HELD_LINE_KEY]['qty'] = $quantity;
        $this->withSession([self::SESSION_KEY => $session]);

        $response = $this->saveTheEdit();

        $this->assertSame([[self::OTHER_SELLERS_PRODUCT, 2]], $this->linesOfTheOrder());
        $this->assertSame(50, $this->stockOf(self::OTHER_SELLERS_PRODUCT));
        $this->assertSame(0, DB::table('order_edit_histories')->count(), 'an edit was recorded');
        $response->assertOk()->assertJson(['status' => 'error']);
    }

    // ---- the variant of a held line ----

    public function test_an_add_of_another_variant_of_a_held_line_of_another_sellers_product_is_refused_and_takes_nothing_of_that_shop(): void
    {
        $this->theOrderHoldsTwoUnitsOfAnotherSellersProduct();
        $this->anotherSellersProductHasABlueVariation();

        $added = $this->addToTheEdit(productId: self::OTHER_SELLERS_PRODUCT, quantity: 2, variant: 'Blue', choices: ['choice_1' => 'Blue']);
        $saved = $this->saveTheEdit();

        $added->assertOk()->assertJson(['status' => 'error']);
        $saved->assertOk()->assertJson(['status' => 'success']);
        $this->assertTheOtherSellersBlueStockIsUntouched();
        $this->assertSame([[self::OTHER_SELLERS_PRODUCT, 2]], $this->linesOfTheOrder());
    }

    public function test_a_held_line_of_another_sellers_product_that_was_reduced_to_nothing_cannot_come_back_as_another_variant(): void
    {
        $this->theOrderHoldsTwoUnitsOfAnotherSellersProduct();
        $this->anotherSellersProductHasABlueVariation();

        $reduced = $this->updateTheQuantityInTheEdit(key: self::HELD_LINE_KEY, quantity: 0);
        $added = $this->addToTheEdit(productId: self::OTHER_SELLERS_PRODUCT, quantity: 2, variant: 'Blue', choices: ['choice_1' => 'Blue']);
        $saved = $this->saveTheEdit();

        $reduced->assertOk()->assertJson(['status' => 'success']);
        $added->assertOk()->assertJson(['status' => 'error']);
        $saved->assertOk();
        $this->assertTheOtherSellersBlueStockIsUntouched();
        $this->assertSame([[self::OTHER_SELLERS_PRODUCT, 0]], $this->linesOfTheOrder());
    }

    public function test_a_quantity_update_of_a_line_that_is_another_variant_of_a_held_line_of_another_sellers_product_is_refused(): void
    {
        $this->theOrderHoldsTwoUnitsOfAnotherSellersProduct();
        $this->anotherSellersProductHasABlueVariation();
        $this->theSessionHoldsTheHeldLineAsTheBlueVariant();

        $updated = $this->updateTheQuantityInTheEdit(key: self::BLUE_LINE_KEY, quantity: 2);

        $updated->assertOk()->assertJson(['status' => 'error']);
    }

    public function test_a_saved_edit_whose_session_holds_another_variant_of_a_held_line_of_another_sellers_product_writes_nothing(): void
    {
        $this->theOrderHoldsTwoUnitsOfAnotherSellersProduct();
        $this->anotherSellersProductHasABlueVariation();
        $this->theSessionHoldsTheHeldLineAsTheBlueVariant();

        $response = $this->saveTheEdit();

        $response->assertOk()->assertJson(['status' => 'error']);
        $this->assertTheOtherSellersBlueStockIsUntouched();
        $this->assertSame([[self::OTHER_SELLERS_PRODUCT, 2]], $this->linesOfTheOrder());
        $this->assertSame(0, DB::table('order_edit_histories')->count(), 'an edit was recorded');
    }

    // ---- the products an edit may look at ----

    public function test_the_modal_and_the_variant_price_of_an_own_product_that_is_not_on_the_order_open(): void
    {
        $this->insertProduct(id: 13, userId: self::SELLER, addedBy: 'seller', name: 'Own product not on the order', overrides: ['current_stock' => 50, 'discount_type' => 'percent', 'discount' => 0, 'tax' => 0]);

        foreach (['edit-order-product-modal-view' => 'htmlView', 'edit-order-product-variant-price' => 'product_quick_view_details'] as $route => $key) {
            $response = $this->signInAs(self::SELLER)->postJson('/vendor/orders/' . $route, ['order_id' => self::ORDER, 'product_id' => 13]);

            $response->assertOk();
            $this->assertStringContainsString('Own product not on the order', (string) $response->json($key));
        }
    }

    /** @return iterable<string, array{0: mixed, 1: bool}> how a request may spell the id of the seller's own product, and whether it names it */
    public static function spellingsOfTheOwnProductsId(): iterable
    {
        yield 'a whole number' => [self::OWN_PRODUCT, true];
        yield 'a whole number as text' => ['10', true];
        yield 'a whole number with trailing text' => ['10abc', false];
        yield 'a whole number written as an exponent' => ['1e1', false];
    }

    #[DataProvider('spellingsOfTheOwnProductsId')]
    public function test_the_modal_and_the_variant_price_open_a_product_only_for_an_id_that_is_a_whole_number(mixed $productId, bool $opens): void
    {
        foreach (['edit-order-product-modal-view', 'edit-order-product-variant-price'] as $route) {
            $response = $this->signInAs(self::SELLER)->postJson('/vendor/orders/' . $route, ['order_id' => self::ORDER, 'product_id' => $productId]);

            $opens ? $response->assertOk() : $response->assertNotFound();
        }
    }

    // ---- the lines an order holds twice ----

    public function test_an_order_that_holds_a_line_of_another_sellers_product_in_two_rows_can_keep_the_sum_of_them_but_not_more(): void
    {
        $this->theOrderHoldsTwoUnitsOfAnotherSellersProduct();
        DB::table('order_details')->insert([
            'id' => 2, 'order_id' => self::ORDER, 'seller_id' => self::SELLER, 'product_id' => self::OTHER_SELLERS_PRODUCT, 'qty' => 1, 'price' => 100, 'variation' => '[]',
            'product_details' => json_encode(DB::table('products')->where('id', self::OTHER_SELLERS_PRODUCT)->first()),
        ]);

        $moreThanBothRows = $this->updateTheQuantityInTheEdit(key: self::HELD_LINE_KEY, quantity: 4);
        $bothRows = $this->updateTheQuantityInTheEdit(key: self::HELD_LINE_KEY, quantity: 3);
        $saved = $this->saveTheEdit();

        $moreThanBothRows->assertOk()->assertJson(['status' => 'error']);
        $bothRows->assertOk()->assertJson(['status' => 'success']);
        $saved->assertOk()->assertJson(['status' => 'success']);
        $this->assertSame([[self::OTHER_SELLERS_PRODUCT, 3]], $this->linesOfTheOrder());
        $this->assertSame(50, $this->stockOf(self::OTHER_SELLERS_PRODUCT));
    }

    // ---- an order that holds a line without a product id ----

    public function test_the_order_page_of_an_order_that_holds_a_line_without_a_product_id_opens(): void
    {
        $this->theOrderAlsoHoldsAUnitWithoutAProductId();

        $response = $this->openTheOrderPage();

        $response->assertOk();
        $this->assertMatchesRegularExpression('/^total:300$/m', (string) $response->getContent());
    }

    public function test_the_seller_can_still_add_more_of_their_own_product_to_an_order_that_holds_a_line_without_a_product_id_and_save_it(): void
    {
        $this->theOrderAlsoHoldsAUnitWithoutAProductId();

        $added = $this->addToTheEdit(productId: self::OWN_PRODUCT, quantity: 3);
        $saved = $this->saveTheEdit();

        $added->assertOk()->assertJson(['status' => 'success']);
        $this->assertStringStartsWith('400', (string) $added->json('edit_order_total_amount'));
        $saved->assertOk()->assertJson(['status' => 'success']);
        $this->assertSame([[self::OWN_PRODUCT, 3]], $this->linesOfTheOrder());
        $this->assertSame([49, 50, 50], $this->stockOfEachProduct());
        $this->assertSame(1, DB::table('order_edit_histories')->count());
    }

    /** @return iterable<string, array{0: int}> */
    public static function quantitiesTheLineWithoutAProductMayKeep(): iterable
    {
        yield 'the quantity it holds' => [1];
        yield 'none' => [0];
    }

    #[DataProvider('quantitiesTheLineWithoutAProductMayKeep')]
    public function test_an_order_that_holds_a_line_without_a_product_id_can_keep_or_reduce_that_line(int $quantity): void
    {
        $this->theOrderAlsoHoldsAUnitWithoutAProductId();

        $updated = $this->updateTheQuantitiesInTheEdit([self::OWN_LINE_KEY => 2, self::LINE_WITHOUT_PRODUCT_KEY => $quantity]);
        $saved = $this->saveTheEdit();

        $updated->assertOk()->assertJson(['status' => 'success']);
        $saved->assertOk()->assertJson(['status' => 'success']);
        $this->assertSame([[self::OWN_PRODUCT, 2]], $this->linesOfTheOrder());
        $this->assertSame([50, 50, 50], $this->stockOfEachProduct());
    }

    public function test_an_order_that_holds_a_line_without_a_product_id_cannot_take_more_of_it(): void
    {
        $this->theOrderAlsoHoldsAUnitWithoutAProductId();

        $updated = $this->updateTheQuantitiesInTheEdit([self::OWN_LINE_KEY => 2, self::LINE_WITHOUT_PRODUCT_KEY => 2]);

        $updated->assertOk()->assertJson(['status' => 'error']);
        $this->assertSame(1, session(self::SESSION_KEY)['product_list'][self::LINE_WITHOUT_PRODUCT_KEY]['qty']);
    }

    public function test_the_line_without_a_product_id_can_be_removed_from_the_edit(): void
    {
        $this->theOrderAlsoHoldsAUnitWithoutAProductId();

        $removed = $this->signInAs(self::SELLER)->postJson('/vendor/orders/edit-order-product-remove', ['order_id' => self::ORDER, 'product_id' => null, 'variant' => '']);
        $saved = $this->saveTheEdit();

        $removed->assertOk()->assertJson(['status' => 'success']);
        $this->assertStringStartsWith('200', (string) $removed->json('edit_order_total_amount'));
        $saved->assertOk()->assertJson(['status' => 'success']);
        $this->assertSame([[self::OWN_PRODUCT, 2]], $this->linesOfTheOrder());
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function idsThatNameNoProduct(): iterable
    {
        yield 'no id' => [null];
        yield 'zero' => [0];
        yield 'text' => ['abc'];
        yield 'trailing garbage' => ['10abc'];
        yield 'an exponent' => ['1e1'];
    }

    #[DataProvider('idsThatNameNoProduct')]
    public function test_an_add_that_names_no_product_is_refused_even_when_the_order_holds_a_line_without_a_product_id(mixed $productId): void
    {
        $this->theOrderAlsoHoldsAUnitWithoutAProductId();

        $added = $this->signInAs(self::SELLER)->postJson('/vendor/orders/edit-order-product-add', [
            'order_id' => self::ORDER, 'product_id' => $productId, 'quantity' => 1, 'variant' => '',
        ]);

        $added->assertOk()->assertJson(['status' => 'error']);
        $this->assertCount(2, session(self::SESSION_KEY)['product_list']);
    }

    // ---- a summary the edit refuses ----

    public function test_the_order_page_opens_when_the_edit_summary_is_refused(): void
    {
        // a line of no units of a product with none on the shelf: the summary refuses it as out of stock
        DB::table('products')->where('id', self::OWN_PRODUCT)->update(['current_stock' => 0]);
        DB::table('order_details')->where('id', 1)->update(['qty' => 0]);

        $response = $this->openTheOrderPage();

        $response->assertOk();
        $this->assertMatchesRegularExpression('/^total:200$/m', (string) $response->getContent());
    }

    /** @return iterable<string, array{string, array<string, mixed>}> every route that prices the edit's session after it has done its own work */
    public static function routesThatPriceTheEditsSession(): iterable
    {
        yield 'the price of a product' => ['edit-order-product-variant-price', ['product_id' => self::OWN_PRODUCT]];
        yield 'an add' => ['edit-order-product-add', ['product_id' => self::OWN_PRODUCT, 'quantity' => 2, 'variant' => '']];
        yield 'a removal' => ['edit-order-product-remove', ['product_id' => self::OWN_PRODUCT, 'variant' => '']];
        yield 'a quantity update' => ['edit-order-product-list-update', ['products' => [self::OWN_LINE_KEY => ['qty' => 2], '20-single-variant' => ['qty' => 3]]]];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('routesThatPriceTheEditsSession')]
    public function test_a_route_that_prices_an_edit_whose_session_holds_another_shops_product_answers_the_orders_own_total_and_not_a_crash(string $route, array $payload): void
    {
        $this->addToTheEdit(productId: self::OWN_PRODUCT, quantity: 2)->assertOk();
        $this->putALineOfProductIntoTheSession(productId: self::OTHER_SELLERS_PRODUCT, quantity: 3);

        $response = $this->signInAs(self::SELLER)->postJson('/vendor/orders/' . $route, ['order_id' => self::ORDER] + $payload);

        $response->assertOk();
        $this->assertStringStartsWith('200', (string) $response->json('edit_order_total_amount'));
        $this->assertTheOrderHoldsWhatItHeld();
    }

    // ---- an order the page does not offer the edit for ----

    /** @return iterable<string, array{0: string}> */
    public static function statusesTheEditIsNotOfferedFor(): iterable
    {
        foreach (['processing', 'out_for_delivery', 'delivered', 'returned', 'failed', 'canceled'] as $status) {
            yield $status => [$status];
        }
    }

    #[DataProvider('statusesTheEditIsNotOfferedFor')]
    public function test_an_edit_of_an_order_in_a_status_the_page_does_not_offer_the_edit_for_is_refused_and_writes_nothing(string $status): void
    {
        DB::table('orders')->where('id', self::ORDER)->update(['order_status' => $status]);

        $this->assertTheEditIsRefusedAndWritesNothing(reason: 'Order can only be edited when the order status is Pending or Confirmed.');
    }

    public function test_an_edit_of_an_order_that_is_not_a_default_order_is_refused_and_writes_nothing(): void
    {
        DB::table('orders')->where('id', self::ORDER)->update(['order_type' => 'POS']);

        $this->assertTheEditIsRefusedAndWritesNothing(reason: 'Order can only be edited when the order status is Pending or Confirmed.');
    }

    public function test_an_edit_while_the_admin_does_not_let_sellers_edit_orders_is_refused_and_writes_nothing(): void
    {
        $this->theAdminSwitchesTheSellersEditsOff();

        $this->assertTheEditIsRefusedAndWritesNothing(reason: 'Vendors are not allowed to edit orders now.');
    }

    public function test_an_edit_of_an_order_whose_offline_payment_is_not_confirmed_is_refused_and_writes_nothing(): void
    {
        DB::table('orders')->where('id', self::ORDER)->update(['payment_method' => 'offline_payment', 'payment_status' => 'unpaid']);

        $this->assertTheEditIsRefusedAndWritesNothing(reason: 'Please confirm the offline payment information before editing this order.');
    }

    public function test_an_edit_of_an_order_whose_last_edit_left_an_offline_due_amount_unpaid_is_refused_and_writes_nothing(): void
    {
        $this->theLastEditLeftAnOfflineDueAmountUnpaid();

        $this->assertTheEditIsRefusedAndWritesNothing(reason: 'Please confirm the offline payment information of the due amount before editing this order.');
    }

    public function test_an_edit_of_an_order_that_holds_only_digital_products_is_refused_and_writes_nothing(): void
    {
        DB::table('order_details')->where('id', 1)->update(['product_details' => json_encode(['product_type' => 'digital'] + (array) DB::table('products')->where('id', self::OWN_PRODUCT)->first())]);

        $this->assertTheEditIsRefusedAndWritesNothing(reason: 'Orders containing only digital products cannot be edited.');
    }

    /** @return iterable<string, array{0: string}> */
    public static function statusesTheEditIsOfferedFor(): iterable
    {
        yield 'pending' => ['pending'];
        yield 'confirmed' => ['confirmed'];
    }

    #[DataProvider('statusesTheEditIsOfferedFor')]
    public function test_an_order_the_page_offers_the_edit_for_is_still_saved_as_edited(string $status): void
    {
        DB::table('orders')->where('id', self::ORDER)->update(['order_status' => $status]);

        $this->addToTheEdit(productId: self::OWN_PRODUCT, quantity: 3)->assertOk();
        $saved = $this->saveTheEdit();

        $saved->assertOk()->assertJson(['status' => 'success']);
        $this->assertSame([[self::OWN_PRODUCT, 3]], $this->linesOfTheOrder());
        $this->assertSame([49, 50, 50], $this->stockOfEachProduct());
        $this->assertSame(1, DB::table('order_edit_histories')->count());
    }

    public function test_the_admins_edit_of_an_order_the_page_offers_the_edit_for_is_saved_whether_or_not_sellers_may_edit(): void
    {
        $this->theAdminSwitchesTheSellersEditsOff();

        $saved = $this->saveTheEditAsAnAdmin();

        $saved->assertOk()->assertJson(['status' => 'success']);
        $this->assertSame(1, DB::table('order_edit_histories')->where('edit_by', 'admin')->count());
    }

    #[DataProvider('statusesTheEditIsNotOfferedFor')]
    public function test_the_admins_edit_of_an_order_in_a_status_the_page_does_not_offer_the_edit_for_is_refused_and_writes_nothing(string $status): void
    {
        DB::table('orders')->where('id', self::ORDER)->update(['order_status' => $status]);

        $saved = $this->saveTheEditAsAnAdmin();

        $saved->assertOk()->assertJson(['status' => 'error', 'message' => translate('Order can only be edited when the order status is Pending or Confirmed.')]);
        $this->assertSame(0, DB::table('order_edit_histories')->count(), 'an edit was recorded');
        $this->assertSame(0, (int) DB::table('orders')->where('id', self::ORDER)->value('edited_status'));
    }

    // ---- helpers ----

    private function theAdminSwitchesTheSellersEditsOff(): void
    {
        DB::table('business_settings')->where('type', 'vendor_can_edit_order')->update(['value' => '0']);
        Cache::forget(CACHE_BUSINESS_SETTINGS_TABLE);
    }

    private function theLastEditLeftAnOfflineDueAmountUnpaid(): void
    {
        DB::table('orders')->where('id', self::ORDER)->update(['edited_status' => 1, 'edit_due_amount' => 100]);
        DB::table('order_edit_histories')->insert([
            'order_id' => self::ORDER, 'edit_by' => 'seller', 'order_amount' => 300, 'order_due_amount' => 100, 'order_due_payment_method' => 'offline_payment',
            'order_due_payment_status' => 'unpaid', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The seller puts more of their own product on the edit (the routes of the edit's session only change the session) and saves it: the save is refused and leaves everything as it was. */
    private function assertTheEditIsRefusedAndWritesNothing(string $reason): void
    {
        $editsBefore = DB::table('order_edit_histories')->count();
        $this->addToTheEdit(productId: self::OWN_PRODUCT, quantity: 9)->assertOk();

        $saved = $this->saveTheEdit();

        $saved->assertOk()->assertJson(['status' => 'error', 'message' => translate($reason)]);
        $this->assertTheOrderHoldsWhatItHeld();
        $this->assertSame($editsBefore, DB::table('order_edit_histories')->count(), 'an edit was recorded');
    }

    private function saveTheEditAsAnAdmin(): TestResponse
    {
        return $this->actingAs(Admin::findOrFail(1), 'admin')->postJson('/admin/orders/edit-order-generate', ['order_id' => self::ORDER]);
    }

    private function theOrderAlsoHoldsAUnitWithoutAProductId(): void
    {
        DB::table('order_details')->insert([
            'id' => 2, 'order_id' => self::ORDER, 'seller_id' => self::SELLER, 'product_id' => null, 'qty' => 1, 'price' => 100, 'variation' => '[]',
            'product_details' => json_encode(['id' => null, 'name' => 'Product that is gone'] + (array) DB::table('products')->where('id', self::OWN_PRODUCT)->first()),
        ]);
    }

    private function openTheOrderPage(): TestResponse
    {
        $this->createTheTablesTheOrderDetailsPageReads();

        return $this->signInAs(self::SELLER)->get('/vendor/orders/details/' . self::ORDER);
    }

    private function theOrderHoldsTwoUnitsOfAnotherSellersProduct(): void
    {
        DB::table('order_details')->where('id', 1)->update(['product_id' => self::OTHER_SELLERS_PRODUCT]);
    }

    private function updateTheQuantityInTheEdit(string $key, int $quantity): TestResponse
    {
        return $this->updateTheQuantitiesInTheEdit([$key => $quantity]);
    }

    /** @param array<string, int> $quantities the quantity of each line the edit keeps, by the line's key: a line that is not named leaves the edit */
    private function updateTheQuantitiesInTheEdit(array $quantities): TestResponse
    {
        return $this->signInAs(self::SELLER)->postJson('/vendor/orders/edit-order-product-list-update', [
            'order_id' => self::ORDER, 'products' => array_map(static fn (int $quantity): array => ['qty' => $quantity], $quantities),
        ]);
    }

    /** The edit's session, as a handler that took the product by another way would have left it. */
    private function putALineOfProductIntoTheSession(int $productId, int $quantity): void
    {
        $session = session(self::SESSION_KEY);
        $line = array_merge(reset($session['product_list']), ['product_id' => $productId, 'qty' => $quantity]);
        $session['product_list'][$productId . '-single-variant'] = $line;

        $this->withSession([self::SESSION_KEY => $session]);
    }

    /** @param array<string, string> $choices the choice the variant is made of, as the product's choice options name it */
    private function addToTheEdit(int $productId, int $quantity, string $variant = '', array $choices = []): TestResponse
    {
        return $this->signInAs(self::SELLER)->postJson('/vendor/orders/edit-order-product-add', [
            'order_id' => self::ORDER, 'product_id' => $productId, 'quantity' => $quantity, 'variant' => $variant,
        ] + $choices);
    }

    private function anotherSellersProductHasABlueVariation(): void
    {
        DB::table('products')->where('id', self::OTHER_SELLERS_PRODUCT)->update([
            'variation' => json_encode([['type' => 'Blue', 'price' => 100, 'sku' => 'blue', 'qty' => 30]]),
            'choice_options' => json_encode([['name' => 'choice_1', 'title' => 'Colour', 'options' => ['Blue']]]),
        ]);
    }

    /** The edit's session as a handler that took the line by another way would have left it: the units the order holds, named as a variant it does not hold. */
    private function theSessionHoldsTheHeldLineAsTheBlueVariant(): void
    {
        $this->updateTheQuantityInTheEdit(key: self::HELD_LINE_KEY, quantity: 2)->assertOk();
        $session = session(self::SESSION_KEY);
        $session['product_list'] = [self::BLUE_LINE_KEY => array_merge($session['product_list'][self::HELD_LINE_KEY], ['variant' => 'Blue'])];

        $this->withSession([self::SESSION_KEY => $session]);
    }

    private function assertTheOtherSellersBlueStockIsUntouched(): void
    {
        $this->assertSame(30, json_decode((string) DB::table('products')->where('id', self::OTHER_SELLERS_PRODUCT)->value('variation'), true)[0]['qty'], 'units of the Blue variation were taken');
    }

    private function saveTheEdit(): TestResponse
    {
        return $this->signInAs(self::SELLER)->postJson('/vendor/orders/edit-order-generate', ['order_id' => self::ORDER]);
    }

    /** What a refused product leaves behind: the order as it was, and every shop's stock where it was. */
    private function assertTheOrderHoldsWhatItHeld(): void
    {
        $this->assertSame([[self::OWN_PRODUCT, 2]], $this->linesOfTheOrder());
        $this->assertSame([50, 50, 50], $this->stockOfEachProduct());
        $this->assertEqualsWithDelta(200.0, (float) DB::table('orders')->where('id', self::ORDER)->value('order_amount'), 0.001);
    }

    /** @return iterable<string, array{0: int}> */
    public static function quantitiesTheHeldLineMayNotTake(): iterable
    {
        yield 'one more' => [3];
        yield 'a huge quantity' => [999];
        yield 'a negative quantity, which would put units on that shop\'s shelf' => [-999];
    }

    private function stockOf(int $productId): int
    {
        return (int) DB::table('products')->where('id', $productId)->value('current_stock');
    }

    /** @return list<array{0: int, 1: int}> product and quantity of every line of the seller's own order */
    private function linesOfTheOrder(): array
    {
        return DB::table('order_details')->where('order_id', self::ORDER)->orderBy('id')->get()
            ->map(static fn (object $line): array => [(int) $line->product_id, (int) $line->qty])->all();
    }

    /** @return array{0: int, 1: int, 2: int} stock of the own, the other seller's and the in-house product */
    private function stockOfEachProduct(): array
    {
        return array_map(fn (int $productId): int => $this->stockOf($productId), [self::OWN_PRODUCT, self::OTHER_SELLERS_PRODUCT, self::IN_HOUSE_PRODUCT]);
    }

    private function seedAnOwnOrderHoldingTwoUnitsOfTheOwnProduct(): void
    {
        DB::table('users')->insert(['id' => 7, 'f_name' => 'Customer']);
        DB::table('currencies')->insert(['id' => 1, 'name' => 'US Dollar', 'symbol' => '$', 'code' => 'USD', 'exchange_rate' => 1]);
        DB::table('business_settings')->insert([
            ['type' => 'system_default_currency', 'value' => '1'], ['type' => 'currency_model', 'value' => 'single_currency'], ['type' => 'vendor_can_edit_order', 'value' => '1'],
        ]);
        // The application read the settings when it booted, from whatever the database held then.
        Cache::flush();
        // The prices of the fixture include their tax, so the edit needs no tax setup.
        Cache::put('tax_system_type_vendor_with_vat', [
            'SystemTaxVat' => ['is_active' => 0, 'tax_ids' => [], 'additionalData' => []], 'SystemTaxVatType' => 'order_wise', 'is_included' => 1,
            'productWiseTax' => false, 'categoryWiseTax' => false, 'taxVats' => collect([]),
        ], 3600);
        DB::table('products')->update(['current_stock' => 50, 'discount_type' => 'percent', 'discount' => 0, 'tax' => 0]);
        DB::table('orders')->insert([
            'id' => self::ORDER, 'customer_id' => 7, 'seller_is' => 'seller', 'seller_id' => self::SELLER, 'order_status' => 'pending', 'payment_method' => 'cash_on_delivery',
            'order_amount' => 200, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_details')->insert([
            'id' => 1, 'order_id' => self::ORDER, 'seller_id' => self::SELLER, 'product_id' => self::OWN_PRODUCT, 'qty' => 2, 'price' => 100, 'variation' => '[]',
            'product_details' => json_encode(DB::table('products')->where('id', self::OWN_PRODUCT)->first()),
        ]);
    }

    /** What the modal of a product reads beyond what the edit itself does. */
    private function createTheTablesTheModalReads(): void
    {
        Schema::create('tags', static function (Blueprint $table): void {
            $table->id();
            $table->string('tag')->nullable();
            $table->timestamps();
        });
        Schema::create('product_tag', static function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('tag_id');
        });
        foreach (['authors', 'publishing_houses'] as $tableName) {
            Schema::create($tableName, static function (Blueprint $table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->timestamps();
            });
        }
        Schema::create('digital_product_authors', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('author_id');
            $table->timestamps();
        });
        Schema::create('digital_product_publishing_houses', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('publishing_house_id');
            $table->timestamps();
        });
    }
}
