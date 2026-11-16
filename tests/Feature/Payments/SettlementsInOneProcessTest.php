<?php

namespace Tests\Feature\Payments;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\KeepsOrderDebugLogClean;

/**
 * The scheduled recovery of lost payment callbacks settles many payments in one process, on one request. A guest settled after a registered
 * customer was read as that customer: the guest's own cart was never looked at, no order was built, and the payment was still marked applied.
 */
class SettlementsInOneProcessTest extends ApiAddressTestCase
{
    use KeepsOrderDebugLogClean;

    private const GUEST_ID = 9001;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
        $this->rememberOrderDebugLog();

        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Paracetamol', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 100, 'current_stock' => 10],
            ['id' => 2, 'name' => 'Ibuprofen', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 50, 'current_stock' => 10],
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreOrderDebugLog();

        parent::tearDown();
    }

    private function cartRow(int $customerId, bool $isGuest, int $productId, float $price): void
    {
        DB::table('carts')->insert([
            'customer_id' => $customerId, 'is_guest' => $isGuest, 'cart_group_id' => 'group-' . $customerId, 'product_id' => $productId, 'quantity' => 1, 'price' => $price,
            'seller_id' => 1, 'seller_is' => 'admin', 'is_checked' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $stored what the payment stored when it started */
    private function settle(array $stored): void
    {
        digital_payment_success([
            'is_paid' => 1, 'payment_method' => 'ssl_commerz', 'transaction_id' => 'TXN-' . uniqid(),
            'additional_data' => json_encode($stored + ['order_note' => null, 'coupon_code' => null, 'payment_mode' => 'web', 'payment_request_from' => 'web', 'new_customer_id' => 0]),
        ]);
    }

    /** @return list<int> the products each order was made of, in order */
    private function orderedProducts(): array
    {
        return DB::table('order_details')->orderBy('order_id')->pluck('product_id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_a_guest_settled_after_a_registered_customer_still_gets_the_guests_own_order(): void
    {
        $registered = $this->makeCustomer();
        $this->cartRow($registered->id, false, productId: 1, price: 100);
        $this->cartRow(self::GUEST_ID, true, productId: 2, price: 50);
        $address = $this->makeLegacyAddress(self::GUEST_ID, isGuest: true);

        $this->settle(['customer_id' => $registered->id, 'is_guest' => 0, 'is_guest_in_order' => 0]);
        $this->settle(['customer_id' => self::GUEST_ID, 'is_guest' => 1, 'is_guest_in_order' => 1, 'address_id' => $address->id, 'billing_address_id' => $address->id]);

        $this->assertSame([1, 2], $this->orderedProducts(), 'each payer got an order for the cart they paid for');
        $this->assertSame(0, DB::table('carts')->count(), 'both carts were emptied');
        $this->assertSame(1, DB::table('orders')->where('is_guest', 1)->count());
    }

    public function test_a_registered_customer_settled_after_a_guest_still_gets_the_customers_own_order(): void
    {
        $registered = $this->makeCustomer();
        $this->cartRow($registered->id, false, productId: 1, price: 100);
        $this->cartRow(self::GUEST_ID, true, productId: 2, price: 50);
        $address = $this->makeLegacyAddress(self::GUEST_ID, isGuest: true);

        $this->settle(['customer_id' => self::GUEST_ID, 'is_guest' => 1, 'is_guest_in_order' => 1, 'address_id' => $address->id, 'billing_address_id' => $address->id]);
        $this->settle(['customer_id' => $registered->id, 'is_guest' => 0, 'is_guest_in_order' => 0]);

        $this->assertSame([2, 1], $this->orderedProducts());
        $this->assertSame(0, DB::table('carts')->count());
    }

    public function test_two_registered_customers_settled_one_after_the_other_each_get_their_own_order(): void
    {
        $first = $this->makeCustomer();
        $second = $this->makeCustomer();
        $this->cartRow($first->id, false, productId: 1, price: 100);
        $this->cartRow($second->id, false, productId: 2, price: 50);

        $this->settle(['customer_id' => $first->id, 'is_guest' => 0, 'is_guest_in_order' => 0]);
        $this->settle(['customer_id' => $second->id, 'is_guest' => 0, 'is_guest_in_order' => 0]);

        $this->assertSame([1, 2], $this->orderedProducts());
    }
}
