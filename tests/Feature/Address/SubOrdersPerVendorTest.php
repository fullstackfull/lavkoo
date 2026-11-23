<?php

namespace Tests\Feature\Address;

use App\Services\Address\AddressSchema;
use Illuminate\Support\Facades\DB;

/**
 * A basket holding two vendors' products becomes one order per vendor, under one order_group_id (D1-12).
 *
 * The split is what makes a marketplace a marketplace: each vendor gets an order it owns, can see and is
 * paid for, and the customer keeps one purchase. OrderManager::generateOrder does it by looping over the
 * cart's groups -- one group per vendor -- and writing an order per group with a single order_group_id
 * minted before the loop. None of that had a test, so nothing said what the shape of a two-vendor
 * purchase is, and nothing would notice if the loop started sharing a line or splitting the group id.
 *
 * It lives beside the order-placement tests because they already have the only harness in this
 * repository that drives a real checkout: the real route, the real cart, the real address.
 */
class SubOrdersPerVendorTest extends ApiAddressTestCase
{
    private const COD = '/api/v1/customer/order/place';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
    }

    /**
     * A second product, belonging to a real seller rather than the in-house shop, in its own cart group.
     *
     * One group per vendor is how the cart records whose products they are, and it is what generateOrder
     * splits on -- so a test that put both products in ONE group would be testing nothing.
     */
    private function alsoInTheCartFromSeller(int $customerId, int $sellerId, int $productId, float $price, int $stock = 10): void
    {
        // An approved seller with a shop: the checkout reads the vendor behind the line, so a cart row
        // naming a seller that does not exist is dropped before it can become an order.
        DB::table('sellers')->insert(['id' => $sellerId, 'f_name' => 'Vendor ' . $sellerId, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shops')->insert(['seller_id' => $sellerId, 'name' => 'Shop ' . $sellerId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shipping_types')->insert(['seller_id' => $sellerId, 'shipping_type' => 'order_wise', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('products')->insert([
            'id' => $productId, 'name' => 'Vendor product ' . $productId, 'added_by' => 'seller',
            'user_id' => $sellerId, 'unit_price' => $price, 'current_stock' => $stock,
        ]);

        DB::table('carts')->insert([
            'customer_id' => $customerId, 'is_guest' => false,
            'cart_group_id' => 'group-seller-' . $sellerId,
            'product_id' => $productId, 'quantity' => 1, 'price' => $price,
            'seller_id' => $sellerId, 'seller_is' => 'seller',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_basket_of_two_vendors_becomes_one_order_per_vendor_under_one_order_group_id(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->makeCustomer();
        $address = $this->makeStructuredAddress($this->ids, $customer->id, overrides: ['zip' => '12211']);

        $this->putOneProductInTheCart($customer->id);                                   // the in-house shop, 100.00
        $this->alsoInTheCartFromSeller($customer->id, sellerId: 7, productId: 2, price: 250.00);

        $response = $this->asCustomer($customer)
            ->getJson(self::COD . '?address_id=' . $address->id . '&billing_address_id=' . $address->id);

        $response->assertOk();

        $orders = DB::table('orders')->orderBy('id')->get();
        $this->assertCount(2, $orders, 'a two-vendor basket did not become two orders');

        // ONE purchase. The group id is minted once, before the loop, so both orders carry the same one
        // -- which is what lets a customer see one purchase and an operator find its other half.
        $this->assertCount(1, $orders->pluck('order_group_id')->unique(), 'the two orders are not under one order_group_id');
        $this->assertNotEmpty($orders->first()->order_group_id);

        // Each order is the vendor's own.
        $bySeller = $orders->keyBy(fn ($o) => $o->seller_is . ':' . (int) $o->seller_id);
        $this->assertSame(['admin:1', 'seller:7'], $bySeller->keys()->sort()->values()->all());

        // And each order's lines are only that vendor's products -- a line in the wrong order would put
        // one vendor's goods on another vendor's invoice and in another vendor's earnings.
        foreach ($orders as $order) {
            $lines = DB::table('order_details')->where('order_id', $order->id)->get();
            $this->assertCount(1, $lines, 'an order of a two-vendor basket has the wrong number of lines');
            // order_details carries seller_id and NOT seller_is: whether a line is the in-house shop's
            // or a vendor's is held on the ORDER, and only the id is repeated on the line. So the id is
            // what a line can be checked against here.
            $this->assertSame((int) $order->seller_id, (int) $lines->first()->seller_id);
        }

        // The money of each order is its own vendor's line, not the basket total.
        $this->assertSame(
            ['admin:1' => '100.00', 'seller:7' => '250.00'],
            $bySeller->map(fn ($o) => number_format((float) $o->order_amount, 2, '.', ''))->sortKeys()->all(),
            'an order carries the whole basket rather than its own vendor line'
        );

        // Both orders are returned to the customer, so neither half is invisible to the app.
        $this->assertSame($orders->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
            collect($response->json('order_ids'))->map(fn ($id) => (int) $id)->sort()->values()->all());
    }

    public function test_a_single_vendor_basket_is_still_one_order(): void
    {
        // The control. If the split happened per LINE rather than per vendor, this is the test that says
        // so -- two products of one vendor in one group must stay one order.
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->makeCustomer();
        $address = $this->makeStructuredAddress($this->ids, $customer->id, overrides: ['zip' => '12211']);

        $this->putOneProductInTheCart($customer->id);
        DB::table('products')->insert(['id' => 3, 'name' => 'Second in-house product', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 40, 'current_stock' => 10]);
        DB::table('carts')->insert([
            'customer_id' => $customer->id, 'is_guest' => false,
            'cart_group_id' => 'group-' . $customer->id . 'c',   // the SAME group: one vendor
            'product_id' => 3, 'quantity' => 1, 'price' => 40, 'seller_id' => 1, 'seller_is' => 'admin',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->asCustomer($customer)
            ->getJson(self::COD . '?address_id=' . $address->id . '&billing_address_id=' . $address->id)
            ->assertOk();

        $this->assertSame(1, DB::table('orders')->count(), 'one vendor became more than one order');
        $this->assertSame(2, DB::table('order_details')->count(), 'the second line of the same vendor was lost');
        $this->assertSame('140.00', number_format((float) DB::table('orders')->value('order_amount'), 2, '.', ''));
    }

    public function test_the_stock_of_each_vendors_product_is_taken_once(): void
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->makeCustomer();
        $address = $this->makeStructuredAddress($this->ids, $customer->id, overrides: ['zip' => '12211']);

        $this->putOneProductInTheCart($customer->id, stock: 10);
        $this->alsoInTheCartFromSeller($customer->id, sellerId: 7, productId: 2, price: 250.00, stock: 5);

        $this->asCustomer($customer)
            ->getJson(self::COD . '?address_id=' . $address->id . '&billing_address_id=' . $address->id)
            ->assertOk();

        // One unit of each, from its own product -- not two from one, and not one twice.
        $this->assertSame(9, (int) DB::table('products')->where('id', 1)->value('current_stock'));
        $this->assertSame(4, (int) DB::table('products')->where('id', 2)->value('current_stock'));
    }

    public function test_the_cart_is_emptied_of_both_vendors_groups(): void
    {
        // A group left behind would be charged again at the next checkout.
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->makeCustomer();
        $address = $this->makeStructuredAddress($this->ids, $customer->id, overrides: ['zip' => '12211']);

        $this->putOneProductInTheCart($customer->id);
        $this->alsoInTheCartFromSeller($customer->id, sellerId: 7, productId: 2, price: 250.00);
        $this->assertSame(2, DB::table('carts')->count());

        $this->asCustomer($customer)
            ->getJson(self::COD . '?address_id=' . $address->id . '&billing_address_id=' . $address->id)
            ->assertOk();

        $this->assertSame(0, DB::table('carts')->count(), 'a cart group survived the checkout it was charged for');
    }
}
