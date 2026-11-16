<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Services\OrderEditService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSellerApiOrders;
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

    private const OWN_PRODUCT = 10;
    private const OTHER_SELLERS_PRODUCT = 20;
    private const IN_HOUSE_PRODUCT = 30;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSellerApiWorld();
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

    // ---- the in-house shop's own orders ----

    public function test_an_in_house_order_may_be_edited_with_in_house_products_only_and_not_with_a_sellers_who_shares_its_seller_id(): void
    {
        DB::table('order_details')->where('id', self::IN_HOUSE_LINE)->update(['product_id' => self::IN_HOUSE_PRODUCT]);
        $inHouseOrder = Order::with('details')->findOrFail(self::IN_HOUSE_ORDER);

        $this->assertTrue($this->isAllowedOnOrder(self::IN_HOUSE_PRODUCT, $inHouseOrder));
        $this->assertFalse($this->isAllowedOnOrder(self::OWN_PRODUCT, $inHouseOrder), 'a product of seller 1 on the order of the in-house shop, whose seller_id is also 1');
        $this->assertFalse($this->isAllowedOnOrder(self::OTHER_SELLERS_PRODUCT, $inHouseOrder));
    }

    // ---- helpers ----

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
