<?php

namespace Tests\Feature\Security;

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

    // ---- helpers ----

    private function theOrderHoldsTwoUnitsOfAnotherSellersProduct(): void
    {
        DB::table('order_details')->where('id', 1)->update(['product_id' => self::OTHER_SELLERS_PRODUCT]);
    }

    private function updateTheQuantityInTheEdit(string $key, int $quantity): TestResponse
    {
        return $this->signInAs(self::SELLER)->postJson('/vendor/orders/edit-order-product-list-update', [
            'order_id' => self::ORDER, 'products' => [$key => ['qty' => $quantity]],
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

    private function addToTheEdit(int $productId, int $quantity): TestResponse
    {
        return $this->signInAs(self::SELLER)->postJson('/vendor/orders/edit-order-product-add', [
            'order_id' => self::ORDER, 'product_id' => $productId, 'quantity' => $quantity, 'variant' => '',
        ]);
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
        DB::table('business_settings')->insert([['type' => 'system_default_currency', 'value' => '1'], ['type' => 'currency_model', 'value' => 'single_currency']]);
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
