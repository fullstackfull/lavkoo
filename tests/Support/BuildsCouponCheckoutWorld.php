<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\Storefront\CouponCountry;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

/**
 * The customer API checkout of a platform as it ships today, for the tests that pin what a coupon does to a cart: the checkout world of
 * BuildsSixStorefrontCheckoutWorld with the storefront switches off, the coupon and order columns the real tables have, one shipping cost of 25 for each
 * group of a cart, one approved vendor with one product, and the two routes a customer reaches a coupon through (apply and place order). For a test case that
 * extends ApiAddressTestCase and uses BuildsSixStorefrontCheckoutWorld, BuildsStorefrontCart, BuildsStorefrontCatalog and BuildsStorefrontCouponWorld.
 */
trait BuildsCouponCheckoutWorld
{
    protected const APPLY_ROUTE = '/api/v1/coupon/apply';

    protected const PLACE_ORDER_ROUTE = '/api/v1/customer/order/place';

    protected const IN_HOUSE_PRODUCT = 1;

    protected const IN_HOUSE_PRICE = 100;

    protected const SMALL_IN_HOUSE_PRODUCT = 3;

    protected const SMALL_IN_HOUSE_PRICE = 30;

    protected const VENDOR = 7;

    protected const VENDOR_PRODUCT = 8;

    protected const VENDOR_PRICE = 60;

    protected const SHIPPING_COST = 25;

    protected function buildCouponCheckoutWorld(): void
    {
        $this->buildSixStorefrontCheckoutWorld();
        $this->setSetting(type: 'minimum_order_amount_status', value: '0');
        $this->giveTheCouponAndOrderTablesTheirRealColumns();
        $this->useOneShippingCostForTheWholeOrder();
        $this->giveTheVendorAProduct();
        $this->runThePlatformAsItShips();
    }

    /** The coupons table of the checkout world only knows what the cart reads, and an order keeps its amounts to the fraction the real table does. */
    private function giveTheCouponAndOrderTablesTheirRealColumns(): void
    {
        Schema::table(table: 'coupons', callback: function (Blueprint $table) {
            $table->string('added_by')->default('admin');
            $table->string('coupon_bearer')->default('inhouse');
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('customer_id')->default(0);
            $table->string('title')->nullable();
            $table->decimal('min_purchase', 40, 20)->default(0);
            $table->decimal('max_discount', 40, 20)->default(0);
            $table->decimal('discount', 40, 20)->default(0);
            $table->string('discount_type')->default('amount');
            $table->integer('limit')->nullable();
        });
        $this->runCouponCountryMigration();

        (require database_path('migrations/2025_07_24_104352_create_order_details_rewards_table.php'))->up();
        Schema::table(table: 'orders', callback: function (Blueprint $table) {
            $table->decimal('discount_amount', 40, 20)->nullable()->change();
            $table->decimal('order_amount', 40, 20)->default(0)->change();
        });
    }

    /** The shop's own shipping with one cost per order: the only mode in which the cost a customer was quoted stays on the cart, and the vendor's shipping follows it. */
    private function useOneShippingCostForTheWholeOrder(): void
    {
        $this->setSetting(type: 'shipping_method', value: 'inhouse_shipping');
        DB::table('shipping_types')->insert(['seller_id' => 0, 'shipping_type' => 'order_wise']);
    }

    private function giveTheVendorAProduct(): void
    {
        DB::table('sellers')->insert(['id' => self::VENDOR, 'f_name' => 'Vendor', 'status' => 'approved']);
        DB::table('shops')->insert(['seller_id' => self::VENDOR, 'author_type' => 'vendor', 'name' => 'Vendor shop']);
        DB::table('products')->insert([
            'id' => self::VENDOR_PRODUCT, 'name' => 'Vendor pack', 'slug' => 'vendor-pack', 'added_by' => 'seller', 'user_id' => self::VENDOR, 'code' => 'SKU-V', 'variation' => '[]',
            'images' => '[]', 'thumbnail' => 'x.png', 'category_ids' => '[]', 'discount' => 0, 'discount_type' => 'flat', 'choice_options' => '[]', 'tax' => 0, 'tax_type' => 'percent',
            'tax_model' => 'exclude', 'current_stock' => 10, 'unit_price' => self::VENDOR_PRICE, 'storefront_country' => 'SA',
        ]);
    }

    /** The checkout world is built for six storefronts; what a coupon does is what it does with no storefront switch on, which is how the platform ships. */
    private function runThePlatformAsItShips(): void
    {
        config(['gcc.storefronts.enabled' => false, 'gcc.storefronts.currency.enabled' => false]);
        StorefrontCatalog::flush();
        CouponCountry::flush();
        Cache::forget('active_currency_list');
    }

    /** Puts one unit of each product in the customer's cart as the customer does, and quotes the shipping of each cart group of the customer once. */
    protected function fillTheCart(User $customer, int ...$productIds): void
    {
        foreach ($productIds as $productId) {
            $this->asCustomer($customer)->postJson(uri: '/api/v1/cart/add', data: ['id' => $productId, 'quantity' => 1])->assertOk()->assertJsonPath('status', 1);
        }

        foreach (DB::table('carts')->where('customer_id', $customer->id)->pluck('cart_group_id')->unique() as $group) {
            if (!DB::table('cart_shippings')->where('cart_group_id', $group)->exists()) {
                DB::table('cart_shippings')->insert(['cart_group_id' => $group, 'shipping_method_id' => 1, 'shipping_cost' => self::SHIPPING_COST]);
            }
        }
    }

    /** One request to the coupon route of the customer API. A real request carries nothing over from the one before it, which this process would otherwise give it through the session. */
    protected function applyTheCoupon(User $customer, string $code): TestResponse
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        session()->flush();

        return $this->asCustomer($customer)->getJson(uri: self::APPLY_ROUTE . "?code={$code}");
    }

    protected function placeTheOrder(User $customer, ?string $code = null): TestResponse
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        session()->flush();

        return $this->asCustomer($customer)->getJson(uri: self::PLACE_ORDER_ROUTE . ($code === null ? '' : "?coupon_code={$code}"));
    }

    /** @return list<object> the orders of the customer, oldest first */
    protected function ordersOf(User $customer): array
    {
        return DB::table('orders')->where('customer_id', $customer->id)->orderBy('id')->get()->all();
    }

    protected function assertCouponApplied(float $discount, TestResponse $response, string $type = 'discount_on_purchase'): void
    {
        $response->assertOk();
        $this->assertEqualsWithDelta($discount, $response->json('coupon_discount'), 0.000001);
        $this->assertSame($type, $response->json('coupon_type'));
    }

    protected function assertCouponRefused(string $message, TestResponse $response): void
    {
        $response->assertStatus(202);
        $this->assertSame($message, $response->json());
    }
}
