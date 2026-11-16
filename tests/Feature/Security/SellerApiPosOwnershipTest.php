<?php

namespace Tests\Feature\Security;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSellerApiWorld;
use Tests\TestCase;

/**
 * The seller app's point of sale prices a cart and then sells it. Both steps take product ids from the app, so each must sell only the
 * caller's own products: another seller's product, and the in-house shop's product whose user_id equals this seller's id, are neither
 * priced nor sold, and no stock is taken from them. A sale that names one product that is not the caller's is refused as a whole, and a
 * refused sale writes nothing: a customer whose wallet pays for it is not charged.
 */
class SellerApiPosOwnershipTest extends TestCase
{
    use BuildsSellerApiWorld;

    private const OWN_PRODUCT = 10;
    private const OTHER_SELLERS_PRODUCT = 20;
    private const IN_HOUSE_PRODUCT = 30;
    private const CUSTOMER = 7;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSellerApiWorld();
        $this->createPosTables();
        $this->seedProducts();
        $this->seedCustomerWithWallet();

        Cache::put('tax_system_type_vendor_with_vat', [
            'SystemTaxVat' => null, 'SystemTaxVatType' => 'order_wise', 'is_included' => 1, 'productWiseTax' => false, 'categoryWiseTax' => false, 'taxVats' => collect([]),
        ], 3600);
    }

    protected function tearDown(): void
    {
        $this->tearDownSellerApiWorld();

        parent::tearDown();
    }

    // ---- placing the order ----

    public function test_a_sale_cannot_sell_and_take_stock_from_another_sellers_product(): void
    {
        $response = $this->placeOrder([$this->line(self::OTHER_SELLERS_PRODUCT, quantity: 40)]);

        $this->assertSame([50, 50, 50], $this->stockOfEachProduct());
        $this->assertSame([0, 0], $this->orderAndLineCounts());
        $this->assertTrue($response->json('product_not_available'));
    }

    public function test_a_sale_cannot_sell_and_take_stock_from_the_in_house_product_sharing_the_sellers_id(): void
    {
        $response = $this->placeOrder([$this->line(self::IN_HOUSE_PRODUCT, quantity: 40)]);

        $this->assertSame([50, 50, 50], $this->stockOfEachProduct());
        $this->assertSame([0, 0], $this->orderAndLineCounts());
        $this->assertTrue($response->json('product_not_available'));
    }

    public function test_a_sale_naming_an_own_and_another_sellers_product_sells_neither(): void
    {
        $response = $this->placeOrder([$this->line(self::OWN_PRODUCT, quantity: 2), $this->line(self::OTHER_SELLERS_PRODUCT, quantity: 2)]);

        $this->assertSame([50, 50, 50], $this->stockOfEachProduct());
        $this->assertSame([0, 0], $this->orderAndLineCounts());
        $this->assertTrue($response->json('product_not_available'));
    }

    public function test_the_seller_can_still_sell_their_own_product(): void
    {
        $response = $this->placeOrder([$this->line(self::OWN_PRODUCT, quantity: 3)]);

        $this->assertSame(200, $response->getStatusCode());
        $orderId = $response->json('order_id');
        $this->assertNotNull($orderId);
        $this->assertSame([47, 50, 50], $this->stockOfEachProduct());
        $this->assertSame([1, 1], $this->orderAndLineCounts());
        $order = DB::table('orders')->where('id', $orderId)->first();
        $this->assertSame([self::SELLER, 'seller', 'POS'], [(int) $order->seller_id, $order->seller_is, $order->order_type]);
        $line = DB::table('order_details')->where('order_id', $orderId)->first();
        $this->assertSame([self::OWN_PRODUCT, self::SELLER, 3], [(int) $line->product_id, (int) $line->seller_id, (int) $line->qty]);
    }

    // ---- the quantity the stock allows ----

    /** @return iterable<string, array{string}> */
    public static function paymentMethods(): iterable
    {
        yield 'cash' => ['cash'];
        yield 'the customer\'s wallet' => ['wallet'];
    }

    #[DataProvider('paymentMethods')]
    public function test_a_sale_of_exactly_the_stock_on_hand_is_sold_and_one_more_is_refused(string $paymentMethod): void
    {
        DB::table('products')->where('id', self::OWN_PRODUCT)->update(['current_stock' => 3]);

        $refused = $this->placeOrderPaidBy($paymentMethod, [$this->line(self::OWN_PRODUCT, quantity: 4)]);

        $this->assertTrue($refused->json('product_not_available'));
        $this->assertSame([3, 50, 50], $this->stockOfEachProduct());
        $this->assertSame([0, 0], $this->orderAndLineCounts());
        $this->assertSame(0, DB::table('wallet_transactions')->count(), 'a wallet debit was recorded');

        $sold = $this->placeOrderPaidBy($paymentMethod, [$this->line(self::OWN_PRODUCT, quantity: 3)]);

        $this->assertSame(200, $sold->getStatusCode());
        $this->assertNotNull($sold->json('order_id'));
        $this->assertSame([0, 50, 50], $this->stockOfEachProduct());
        $this->assertSame([1, 1], $this->orderAndLineCounts());
    }

    // ---- the shapes of the cart the app sends ----

    /** @return iterable<string, array{array<string, mixed>}> a sale with no cart the app has priced */
    public static function salesWithoutAPricedCart(): iterable
    {
        yield 'no modified_cart' => [[]];
        yield 'a null modified_cart' => [['modified_cart' => null]];
        yield 'an empty modified_cart' => [['modified_cart' => []]];
    }

    /** @param array<string, mixed> $cart */
    #[DataProvider('salesWithoutAPricedCart')]
    public function test_a_cash_sale_without_a_priced_cart_is_answered_and_writes_no_line_and_takes_no_stock(array $cart): void
    {
        $response = $this->sellerApi('POST', 'pos/place-order', ['customer_id' => 0, 'payment_method' => 'cash', 'paid_amount' => 500, 'cart' => [$this->line(self::OWN_PRODUCT, quantity: 2)]] + $cart);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($response->json('order_id'));
        $this->assertSame([1, 0], $this->orderAndLineCounts());
        $this->assertSame([50, 50, 50], $this->stockOfEachProduct());
    }

    public function test_an_element_of_the_priced_cart_that_is_not_a_line_is_skipped(): void
    {
        $response = $this->placeOrder(['not a line', $this->line(self::OWN_PRODUCT, quantity: 2)]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($response->json('order_id'));
        $this->assertSame([1, 1], $this->orderAndLineCounts());
        $this->assertSame([48, 50, 50], $this->stockOfEachProduct());
    }

    // ---- a sale paid from the customer's wallet ----

    public function test_a_wallet_sale_naming_another_sellers_product_is_refused_before_the_customer_is_charged(): void
    {
        $response = $this->placeWalletOrder([$this->line(self::OTHER_SELLERS_PRODUCT, quantity: 2)]);

        $this->assertWalletUntouched();
        $this->assertSame([50, 50, 50], $this->stockOfEachProduct());
        $this->assertSame([0, 0], $this->orderAndLineCounts());
        $this->assertTrue($response->json('product_not_available'));
    }

    public function test_a_wallet_sale_naming_the_in_house_product_sharing_the_sellers_id_is_refused_before_the_customer_is_charged(): void
    {
        $response = $this->placeWalletOrder([$this->line(self::IN_HOUSE_PRODUCT, quantity: 2)]);

        $this->assertWalletUntouched();
        $this->assertSame([0, 0], $this->orderAndLineCounts());
        $this->assertTrue($response->json('product_not_available'));
    }

    public function test_a_wallet_sale_naming_an_own_and_another_sellers_product_is_refused_before_the_customer_is_charged(): void
    {
        $response = $this->placeWalletOrder([$this->line(self::OWN_PRODUCT, quantity: 2), $this->line(self::OTHER_SELLERS_PRODUCT, quantity: 2)], paidAmount: 400);

        $this->assertWalletUntouched();
        $this->assertSame([50, 50, 50], $this->stockOfEachProduct());
        $this->assertSame([0, 0], $this->orderAndLineCounts());
        $this->assertTrue($response->json('product_not_available'));
    }

    public function test_a_wallet_sale_of_more_than_the_stock_is_refused_before_the_customer_is_charged(): void
    {
        DB::table('products')->where('id', self::OWN_PRODUCT)->update(['current_stock' => 1]);

        $response = $this->placeWalletOrder([$this->line(self::OWN_PRODUCT, quantity: 2)]);

        $this->assertWalletUntouched();
        $this->assertSame([1, 50, 50], $this->stockOfEachProduct());
        $this->assertSame([0, 0], $this->orderAndLineCounts());
        $this->assertTrue($response->json('product_not_available'));
    }

    public function test_the_seller_can_still_sell_their_own_product_for_the_customers_wallet_which_is_debited_once(): void
    {
        $response = $this->placeWalletOrder([$this->line(self::OWN_PRODUCT, quantity: 2)]);

        $this->assertSame(200, $response->getStatusCode());
        $orderId = $response->json('order_id');
        $this->assertNotNull($orderId);
        $this->assertEqualsWithDelta(800.0, $this->walletBalanceOfTheCustomer(), 0.001);
        $transactions = DB::table('wallet_transactions')->get();
        $this->assertCount(1, $transactions);
        $this->assertSame([self::CUSTOMER, 'order_place_in_pos'], [(int) $transactions[0]->user_id, $transactions[0]->reference]);
        $this->assertEqualsWithDelta(200.0, (float) $transactions[0]->debit, 0.001);
        $order = DB::table('orders')->where('id', $orderId)->first();
        $this->assertSame([self::SELLER, self::CUSTOMER, 'wallet', 'POS'], [(int) $order->seller_id, (int) $order->customer_id, $order->payment_method, $order->order_type]);
        $this->assertSame([48, 50, 50], $this->stockOfEachProduct());
        $this->assertSame([1, 1], $this->orderAndLineCounts());
    }

    public function test_a_wallet_sale_the_balance_cannot_pay_still_asks_for_balance_and_writes_nothing(): void
    {
        DB::table('users')->where('id', self::CUSTOMER)->update(['wallet_balance' => 100]);

        $response = $this->placeWalletOrder([$this->line(self::OWN_PRODUCT, quantity: 2)]);

        $this->assertTrue($response->json('need_balance'));
        $this->assertEqualsWithDelta(100.0, $this->walletBalanceOfTheCustomer(), 0.001);
        $this->assertSame(0, DB::table('wallet_transactions')->count());
        $this->assertSame([0, 0], $this->orderAndLineCounts());
    }

    // ---- pricing the cart ----

    public function test_the_cart_of_another_sellers_product_is_not_priced(): void
    {
        $response = $this->sellerApi('POST', 'pos/get-tax-amount', $this->cart([self::OTHER_SELLERS_PRODUCT]));

        $this->assertNull($response->json('modified_cart'), 'the other seller\'s product was priced');
        $this->assertNotFound($response, code: 'product');
    }

    public function test_the_cart_of_the_in_house_product_sharing_the_sellers_id_is_not_priced(): void
    {
        $response = $this->sellerApi('POST', 'pos/get-tax-amount', $this->cart([self::IN_HOUSE_PRODUCT]));

        $this->assertNull($response->json('modified_cart'), 'the in-house product was priced');
        $this->assertNotFound($response, code: 'product');
    }

    public function test_the_seller_can_still_price_a_cart_of_their_own_product(): void
    {
        $response = $this->sellerApi('POST', 'pos/get-tax-amount', $this->cart([self::OWN_PRODUCT]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([self::OWN_PRODUCT], array_column($response->json('modified_cart'), 'id'));
        $this->assertEqualsWithDelta(100.0, (float) $response->json('modified_cart.0.price'), 0.001);
    }

    // ---- helpers ----

    private function assertNotFound(TestResponse $response, string $code): void
    {
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame($code, $response->json('errors.0.code'));
    }

    /** @param list<array<string, mixed>|string> $lines */
    private function placeOrder(array $lines): TestResponse
    {
        return $this->sellerApi('POST', 'pos/place-order', ['customer_id' => 0, 'payment_method' => 'cash', 'paid_amount' => 500, 'cart' => $lines, 'modified_cart' => $lines]);
    }

    /** @param list<array<string, mixed>> $lines */
    private function placeOrderPaidBy(string $paymentMethod, array $lines): TestResponse
    {
        return $paymentMethod === 'wallet' ? $this->placeWalletOrder($lines) : $this->placeOrder($lines);
    }

    /** @param list<array<string, mixed>> $lines */
    private function placeWalletOrder(array $lines, float $paidAmount = 200): TestResponse
    {
        return $this->sellerApi('POST', 'pos/place-order', ['customer_id' => self::CUSTOMER, 'payment_method' => 'wallet', 'paid_amount' => $paidAmount, 'cart' => $lines, 'modified_cart' => $lines]);
    }

    private function assertWalletUntouched(): void
    {
        $this->assertEqualsWithDelta(1000.0, $this->walletBalanceOfTheCustomer(), 0.001, 'the customer was charged');
        $this->assertSame(0, DB::table('wallet_transactions')->count(), 'a wallet debit was recorded');
    }

    private function walletBalanceOfTheCustomer(): float
    {
        return (float) DB::table('users')->where('id', self::CUSTOMER)->value('wallet_balance');
    }

    /** @return array<string, mixed> */
    private function line(int $productId, int $quantity): array
    {
        return [
            'id' => $productId, 'quantity' => $quantity, 'variant' => null, 'variant_key' => null, 'digital_variation_price' => 0,
            'price' => 100, 'discounted_price' => 100, 'discount' => 0, 'applied_tax_amount' => 0, 'variation' => [],
        ];
    }

    /**
     * @param list<int> $productIds
     * @return array<string, mixed>
     */
    private function cart(array $productIds): array
    {
        return [
            'cart' => array_map(fn (int $productId) => $this->line($productId, quantity: 1), $productIds),
            'coupon_discount_amount' => 0, 'extra_discount' => 0, 'extra_discount_type' => 'flat',
        ];
    }

    /** @return array{0: int, 1: int, 2: int} stock of the own, the other seller's and the in-house product */
    private function stockOfEachProduct(): array
    {
        return array_map(
            fn (int $productId) => (int) DB::table('products')->where('id', $productId)->value('current_stock'),
            [self::OWN_PRODUCT, self::OTHER_SELLERS_PRODUCT, self::IN_HOUSE_PRODUCT],
        );
    }

    /** @return array{0: int, 1: int} orders and order lines written */
    private function orderAndLineCounts(): array
    {
        return [DB::table('orders')->count(), DB::table('order_details')->count()];
    }

    private function createPosTables(): void
    {
        $this->createSellerApiTable('products', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('added_by')->nullable();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('code')->nullable();
            $table->boolean('status')->default(true);
            $table->boolean('request_status')->default(true);
            $table->string('product_type')->default('physical');
            $table->string('digital_product_type')->nullable();
            $table->text('images')->nullable();
            $table->string('thumbnail')->nullable();
            $table->decimal('unit_price', 16, 2)->default(0);
            $table->decimal('purchase_price', 16, 2)->default(0);
            $table->integer('current_stock')->default(0);
            $table->text('category_ids')->nullable();
            $table->text('colors')->nullable();
            $table->text('attributes')->nullable();
            $table->text('choice_options')->nullable();
            $table->text('variation')->nullable();
            $table->string('discount_type')->nullable();
            $table->decimal('discount', 16, 2)->default(0);
            $table->decimal('tax', 16, 2)->default(0);
            $table->string('tax_model')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->integer('minimum_order_qty')->default(1);
            $table->timestamps();
        });
        $this->createSellerApiTable('translations', function (Blueprint $table) {
            $table->string('translationable_type');
            $table->unsignedBigInteger('translationable_id');
            $table->string('locale');
            $table->string('key');
            $table->text('value')->nullable();
        });
        $this->createSellerApiTable('storages', function (Blueprint $table) {
            $table->string('data_type');
            $table->unsignedBigInteger('data_id');
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('reviews', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->integer('rating')->default(5);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        $this->createSellerApiTable('digital_product_variations', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id');
            $table->string('variant_key')->nullable();
            $table->string('file')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('stock_clearance_products', function (Blueprint $table) {
            $table->string('added_by')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->integer('is_active')->default(1);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('setup_id')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('stock_clearance_setups', function (Blueprint $table) {
            $table->string('setup_by')->nullable();
            $table->integer('is_active')->default(1);
            $table->dateTime('duration_start_date')->nullable();
            $table->dateTime('duration_end_date')->nullable();
            $table->string('offer_active_time')->nullable();
            $table->string('offer_active_range_start')->nullable();
            $table->string('offer_active_range_end')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('shops', function (Blueprint $table) {
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('categories', function (Blueprint $table) {
            $table->string('name')->nullable();
            $table->unsignedBigInteger('parent_id')->default(0);
            $table->integer('position')->default(0);
            $table->timestamps();
        });
        $this->createSellerApiTable('taxes', function (Blueprint $table) {
            $table->string('name')->nullable();
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        $this->createSellerApiTable('taxables', function (Blueprint $table) {
            $table->unsignedBigInteger('tax_id')->nullable();
            $table->unsignedBigInteger('taxable_id');
            $table->string('taxable_type');
            $table->string('tax_for')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('colors', function (Blueprint $table) {
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('users', function (Blueprint $table) {
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->decimal('wallet_balance', 16, 2)->default(0);
            $table->timestamps();
        });
        $this->createSellerApiTable('wallet_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('transaction_id')->nullable();
            $table->string('reference')->nullable();
            $table->string('transaction_type')->nullable();
            $table->string('payment_method')->nullable();
            $table->decimal('credit', 16, 2)->default(0);
            $table->decimal('debit', 16, 2)->default(0);
            $table->decimal('balance', 16, 2)->default(0);
            $table->decimal('admin_bonus', 16, 2)->default(0);
            $table->timestamps();
        });
        $this->createSellerApiTable('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('shipping_address')->nullable();
            foreach (['customer_type', 'payment_status', 'order_status', 'seller_is', 'payment_method', 'order_type', 'shipping_responsibility', 'verification_code', 'extra_discount_type', 'tax_type', 'coupon_code', 'discount_type', 'coupon_discount_bearer'] as $column) {
                $table->string($column)->nullable();
            }
            $table->text('shipping_address_data')->nullable();
            $table->boolean('checked')->default(false);
            foreach (['extra_discount', 'order_amount', 'init_order_amount', 'total_tax_amount', 'paid_amount', 'discount_amount'] as $column) {
                $table->decimal($column, 16, 2)->default(0);
            }
            $table->timestamps();
        });
        $this->createSellerApiTable('order_details', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->longText('product_details')->nullable();
            $table->integer('qty')->default(1);
            $table->decimal('price', 16, 2)->default(0);
            $table->decimal('tax', 16, 2)->default(0);
            $table->string('tax_model')->nullable();
            $table->decimal('discount', 16, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->string('delivery_status')->nullable();
            $table->string('payment_status')->nullable();
            $table->string('variant')->nullable();
            $table->text('variation')->nullable();
            $table->timestamps();
        });
    }

    private function seedCustomerWithWallet(): void
    {
        DB::table('users')->insert(['id' => self::CUSTOMER, 'f_name' => 'Customer', 'l_name' => 'Wallet', 'wallet_balance' => 1000]);
        DB::table('business_settings')->insert(['type' => 'wallet_status', 'value' => '1']);
    }

    private function seedProducts(): void
    {
        $product = fn (int $id, int $userId, string $addedBy, string $name) => DB::table('products')->insert([
            'id' => $id, 'user_id' => $userId, 'added_by' => $addedBy, 'name' => $name, 'slug' => 'product-' . $id, 'code' => 'CODE' . $id,
            'images' => '[]', 'thumbnail' => 'thumb.png', 'unit_price' => 100, 'current_stock' => 50,
            'category_ids' => '[]', 'colors' => '[]', 'attributes' => '[]', 'choice_options' => '[]', 'variation' => '[]',
            'discount_type' => 'flat', 'discount' => 0,
        ]);
        $product(self::OWN_PRODUCT, self::SELLER, 'seller', 'Own product');
        $product(self::OTHER_SELLERS_PRODUCT, self::OTHER_SELLER, 'seller', 'Other sellers product');
        $product(self::IN_HOUSE_PRODUCT, self::IN_HOUSE_ID, 'admin', 'In-house product');
    }
}
