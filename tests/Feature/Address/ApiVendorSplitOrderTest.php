<?php

namespace Tests\Feature\Address;

use App\Models\User;
use App\Utils\OrderManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * A cart that holds the products of two sellers is one cart group per seller, and the checkout turns it into one order per seller: each order
 * owned by its seller and holding only that seller's lines, the same order group id on both, the same currency, and amounts that add up to what
 * the cart said. The order is placed through the real route of the app (cash on delivery), so nothing here is a call into the order writer.
 *
 * The currency of an order is not a column of its own on main: the one an order row names is the one the cash-on-delivery route records beside
 * the "bring change" amount (bring_change_amount_currency), the same for every order of the request. Not covered: the currency of the
 * commission snapshot of each order line (order_item_commissions), whose table this test world does not build.
 */
class ApiVendorSplitOrderTest extends ApiAddressTestCase
{
    private const PLACE_ORDER = '/api/v1/customer/order/place';

    private const FIRST_SELLER = 11;

    private const SECOND_SELLER = 12;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipInStrictModeWhileTheCouponCountIsABareGroupBy();
        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
        DB::table('business_settings')->insert(['type' => 'shipping_method', 'value' => 'inhouse_shipping']);
        DB::table('shipping_types')->insert(['seller_id' => 0, 'shipping_type' => 'order_wise']);

        $this->customer = $this->makeCustomer();
        // Each product is priced at what its cart row carries. The checkout refreshes a cart row's price from the product's own price before it
        // totals anything (ProductManager::updateProductPriceInCartList), so a product priced differently from its cart row is a cart that
        // charges the product's price, not the row's: the prices are kept in step here so the arithmetic below is the cart's and not a fixture's.
        $this->addSellerWithProducts(sellerId: self::FIRST_SELLER, pricedProducts: [21 => 40, 23 => 25]);
        $this->addSellerWithProducts(sellerId: self::SECOND_SELLER, pricedProducts: [22 => 70]);
        $this->putInTheCart(sellerId: self::FIRST_SELLER, productId: 21, cartGroupId: 'cart-of-seller-11', quantity: 2, price: 40, shipping: 10);
        $this->putInTheCart(sellerId: self::FIRST_SELLER, productId: 23, cartGroupId: 'cart-of-seller-11', quantity: 1, price: 25);
        $this->putInTheCart(sellerId: self::SECOND_SELLER, productId: 22, cartGroupId: 'cart-of-seller-12', quantity: 1, price: 70, shipping: 15);
    }

    /** A checkout reads the use count of a coupon with a bare GROUP BY, which a strict SQL server refuses (error 1055, ONLY_FULL_GROUP_BY); where OrderManager has countCouponUses() the query is not there and the test runs. */
    private function skipInStrictModeWhileTheCouponCountIsABareGroupBy(): void
    {
        if (getenv('DB_STRICT_TESTS') === '1' && !method_exists(OrderManager::class, 'countCouponUses')) {
            $this->markTestSkipped('The use count of a coupon is a bare GROUP BY that a strict server refuses (ONLY_FULL_GROUP_BY).');
        }
    }

    /** @param array<int, float> $pricedProducts the id of each of the seller's products, and the price it sells at */
    private function addSellerWithProducts(int $sellerId, array $pricedProducts): void
    {
        DB::table('sellers')->insert(['id' => $sellerId, 'f_name' => 'Seller ' . $sellerId, 'status' => 'approved']);
        DB::table('shops')->insert(['seller_id' => $sellerId, 'author_type' => 'vendor', 'name' => 'Shop of seller ' . $sellerId]);
        foreach ($pricedProducts as $productId => $price) {
            DB::table('products')->insert(['id' => $productId, 'name' => 'Product ' . $productId, 'added_by' => 'seller', 'user_id' => $sellerId, 'unit_price' => $price, 'current_stock' => 10]);
        }
    }

    private function putInTheCart(int $sellerId, int $productId, string $cartGroupId, int $quantity, float $price, ?float $shipping = null): void
    {
        DB::table('carts')->insert([
            'customer_id' => $this->customer->id, 'is_guest' => false, 'cart_group_id' => $cartGroupId, 'product_id' => $productId, 'quantity' => $quantity, 'price' => $price,
            'seller_id' => $sellerId, 'seller_is' => 'seller', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($shipping !== null) {
            DB::table('cart_shippings')->insert(['cart_group_id' => $cartGroupId, 'shipping_method_id' => 1, 'shipping_cost' => $shipping]);
        }
    }

    private function placeTheOrder(): TestResponse
    {
        return $this->asCustomer($this->customer)->getJson(self::PLACE_ORDER)->assertOk();
    }

    /** @return Collection<int, object> the orders that were written, the first seller's first */
    private function orders(): Collection
    {
        return DB::table('orders')->orderBy('seller_id')->get();
    }

    public function test_a_cart_of_two_sellers_becomes_one_order_per_seller_each_holding_only_the_lines_of_its_seller(): void
    {
        $response = $this->placeTheOrder();

        $orders = $this->orders();
        $this->assertCount(2, $orders, 'one order per seller, and not one per cart line');
        $this->assertSame([self::FIRST_SELLER, self::SECOND_SELLER], $orders->pluck('seller_id')->map(static fn ($id): int => (int) $id)->all());
        $this->assertSame(['seller', 'seller'], $orders->pluck('seller_is')->all());
        $this->assertSame($orders->pluck('id')->map(static fn ($id): int => (int) $id)->all(), $response->json('order_ids'));
        $linesOfEachOrder = $orders->map(static fn (object $order): array => DB::table('order_details')->where('order_id', $order->id)->orderBy('product_id')->pluck('product_id')->map(static fn ($id): int => (int) $id)->all());
        $this->assertSame([[21, 23], [22]], $linesOfEachOrder->all());
        $this->assertSame(0, DB::table('carts')->count(), 'the cart is emptied');
    }

    public function test_the_orders_of_one_cart_share_one_order_group_id(): void
    {
        $this->placeTheOrder();

        $groupIds = $this->orders()->pluck('order_group_id');
        $this->assertCount(2, $groupIds);
        $this->assertNotEmpty($groupIds->first());
        $this->assertCount(1, $groupIds->unique(), 'both orders carry the same order group id');
    }

    public function test_the_orders_of_one_cart_are_in_one_currency(): void
    {
        $this->placeTheOrder();

        $this->assertSame(['SAR', 'SAR'], $this->orders()->pluck('bring_change_amount_currency')->all());
    }

    public function test_the_amounts_of_the_orders_add_up_to_the_amount_of_the_cart(): void
    {
        $cartTotal = (float) DB::table('carts')->selectRaw('sum(price * quantity) as total')->value('total') + (float) DB::table('cart_shippings')->sum('shipping_cost');
        $this->assertEquals(200, $cartTotal, 'the cart: 2 x 40 and 25 of the first seller with 10 of shipping, 70 of the second with 15 of shipping');

        $this->placeTheOrder();

        $orders = $this->orders();
        $this->assertEquals([115, 85], $orders->pluck('order_amount')->all());
        $this->assertEquals([10, 15], $orders->pluck('shipping_cost')->all());
        $this->assertEquals($cartTotal, $orders->sum('order_amount'));
    }
}
