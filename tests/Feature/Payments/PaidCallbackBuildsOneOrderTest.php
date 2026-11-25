<?php

namespace Tests\Feature\Payments;

use App\Utils\OrderManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\KeepsOrderDebugLogClean;

/**
 * D1-328: one paid payment builds one order, even when two deliveries of its callback are being handled at the same instant.
 *
 * The settlement hook refuses a payment whose order exists, and that check is a READ. Two deliveries of one paid callback both pass a read
 * before either writes, and both then build a full order: two orders for one payment, the stock taken twice, the commission charged twice.
 * The claim is now made inside the transaction the order is written in, on the payment's own row.
 *
 * What a one-process test can prove is everything but the race: that the claim is made where it is made, by the primary key and not by the
 * reference, that it stops a second order for a reference that already has one, that it is a no-op for every checkout with no gateway
 * reference, and that nothing is logged as a lost payment when the guard is what stopped the build. The race itself is
 * tests/Feature/Concurrency/PaidCallbackConcurrencyTest, which needs a real engine.
 */
class PaidCallbackBuildsOneOrderTest extends ApiAddressTestCase
{
    use KeepsOrderDebugLogClean;

    private const REFERENCE = 'TXN-PAID-ONCE';

    /** `payment_requests.id` is a uuid on this platform, not an integer: the payment module writes one per payment. */
    private const PAYMENT_ROW = '0f2a7d6c-8e41-4d0b-9c7a-21b55e9f3a10';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
        $this->rememberOrderDebugLog();
        $this->storeThePaymentRow();
    }

    protected function tearDown(): void
    {
        $this->restoreOrderDebugLog();

        parent::tearDown();
    }

    /** The payment a gateway is calling back about, in the table the payment module owns (the harness builds it). */
    private function storeThePaymentRow(): void
    {
        DB::table('payment_requests')->insert([
            'id' => self::PAYMENT_ROW, 'payment_method' => 'ssl_commerz', 'transaction_id' => self::REFERENCE, 'payment_amount' => 100,
            'currency_code' => 'SAR', 'is_paid' => 1, 'additional_data' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function cartRow(int $customerId, int $productId, float $price): void
    {
        DB::table('carts')->insert([
            'customer_id' => $customerId, 'is_guest' => false, 'cart_group_id' => 'group-' . $customerId . '-' . $productId, 'product_id' => $productId,
            'quantity' => 1, 'price' => $price, 'seller_id' => 1, 'seller_is' => 'admin', 'is_checked' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The order writer as the settlement hook calls it: paid, carrying the gateway's reference and the payment's own row. */
    private function settleThePayment(object $customer, array $overrides = []): array
    {
        request()->merge(['user' => $customer]);
        request()->setUserResolver(fn () => $customer);

        return OrderManager::generateOrder(data: $overrides + [
            'is_guest' => 0, 'customer_id' => $customer->id, 'order_status' => 'confirmed', 'payment_method' => 'ssl_commerz',
            'payment_status' => 'paid', 'transaction_ref' => self::REFERENCE, 'payment_record_id' => self::PAYMENT_ROW,
            'requestObj' => request(), 'enforce_address_rules' => false, 'stock_policy' => 'allow_oversell',
        ]);
    }

    /** @return list<string> every statement run while the callback settled */
    private function statementsOf(callable $work): array
    {
        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $work();

        return $statements;
    }

    public function test_a_paid_settlement_builds_its_order(): void
    {
        $customer = $this->makeCustomer();
        DB::table('products')->insert(['id' => 1, 'name' => 'Paracetamol', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 100, 'current_stock' => 10]);
        $this->cartRow($customer->id, productId: 1, price: 100);

        $orderIds = $this->settleThePayment($customer);

        $this->assertCount(1, $orderIds);
        $this->assertSame(self::REFERENCE, DB::table('orders')->where('id', $orderIds[0])->value('transaction_ref'));
        $this->assertSame('paid', DB::table('orders')->where('id', $orderIds[0])->value('payment_status'));
    }

    public function test_a_second_settlement_of_one_reference_builds_nothing_even_with_a_full_cart(): void
    {
        $customer = $this->makeCustomer();
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Paracetamol', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 100, 'current_stock' => 10],
            ['id' => 2, 'name' => 'Ibuprofen', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 50, 'current_stock' => 10],
        ]);
        $this->cartRow($customer->id, productId: 1, price: 100);
        $this->settleThePayment($customer);

        // The cart was refilled, as it is when the shopper went back and added something before the gateway called again.
        $this->cartRow($customer->id, productId: 2, price: 50);
        $secondDelivery = $this->settleThePayment($customer);

        $this->assertSame([], $secondDelivery, 'the second delivery of one payment builds nothing');
        $this->assertSame(1, DB::table('orders')->count(), 'one payment, one order');
        $this->assertSame([1], DB::table('order_details')->pluck('product_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(10, (int) DB::table('products')->where('id', 2)->value('current_stock'), 'and the stock of the refilled line was never taken');
    }

    public function test_the_claim_locks_the_payment_by_its_primary_key_and_never_by_the_reference(): void
    {
        $customer = $this->makeCustomer();
        DB::table('products')->insert(['id' => 1, 'name' => 'Paracetamol', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 100, 'current_stock' => 10]);
        $this->cartRow($customer->id, productId: 1, price: 100);

        $statements = $this->statementsOf(fn () => $this->settleThePayment($customer));

        $onThePaymentTable = array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'payment_requests')));
        $this->assertNotSame([], $onThePaymentTable, 'the payment row is read, which is what the lock is taken on');
        foreach ($onThePaymentTable as $sql) {
            $this->assertStringNotContainsString(
                'transaction_id',
                $sql,
                'the lock must be taken by the primary key: transaction_id carries no index this repository declares, and locking by it '
                . 'would lock every row the scan touches and serialise every settlement on the platform',
            );
        }
    }

    public function test_a_checkout_with_no_gateway_reference_never_reads_the_payment_table(): void
    {
        $customer = $this->makeCustomer();
        DB::table('products')->insert(['id' => 1, 'name' => 'Paracetamol', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 100, 'current_stock' => 10]);
        $this->cartRow($customer->id, productId: 1, price: 100);
        $address = $this->makeLegacyAddress($customer->id);

        $statements = $this->statementsOf(function () use ($customer, $address) {
            request()->merge(['user' => $customer]);
            request()->setUserResolver(fn () => $customer);
            OrderManager::generateOrder(data: [
                'is_guest' => 0, 'customer_id' => $customer->id, 'order_status' => 'pending', 'payment_method' => 'cash_on_delivery',
                'payment_status' => 'unpaid', 'transaction_ref' => '', 'requestObj' => request(), 'address_id' => $address->id,
            ]);
        });

        $this->assertSame(1, DB::table('orders')->count(), 'the cash-on-delivery order is placed as it always was');
        $this->assertSame(
            [],
            array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'payment_requests'))),
            'a checkout that carries no gateway reference is not what this guards, and pays nothing for it',
        );
    }

    public function test_a_paid_settlement_whose_payment_row_is_gone_still_builds_its_order(): void
    {
        DB::table('payment_requests')->where('id', self::PAYMENT_ROW)->delete();
        $customer = $this->makeCustomer();
        DB::table('products')->insert(['id' => 1, 'name' => 'Paracetamol', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 100, 'current_stock' => 10]);
        $this->cartRow($customer->id, productId: 1, price: 100);

        $this->assertCount(1, $this->settleThePayment($customer), 'a payment with no row to lock keeps exactly the behaviour it had');
    }

    public function test_a_payment_whose_order_already_exists_is_not_logged_as_a_lost_payment(): void
    {
        $customer = $this->makeCustomer();
        DB::table('products')->insert(['id' => 1, 'name' => 'Paracetamol', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 100, 'current_stock' => 10]);
        $this->cartRow($customer->id, productId: 1, price: 100);
        $this->cartRow($customer->id, productId: 1, price: 100);
        Log::spy();

        digital_payment_success([
            'id' => self::PAYMENT_ROW, 'is_paid' => 1, 'payment_method' => 'ssl_commerz', 'transaction_id' => self::REFERENCE,
            'additional_data' => json_encode([
                'customer_id' => $customer->id, 'is_guest' => 0, 'is_guest_in_order' => 0, 'address_id' => 0, 'billing_address_id' => 0,
                'order_note' => null, 'coupon_code' => null, 'payment_mode' => 'web', 'new_customer_id' => 0,
            ]),
        ]);
        $this->assertGreaterThan(0, DB::table('orders')->count(), 'the first delivery built the order');

        digital_payment_success([
            'id' => self::PAYMENT_ROW, 'is_paid' => 1, 'payment_method' => 'ssl_commerz', 'transaction_id' => self::REFERENCE,
            'additional_data' => json_encode([
                'customer_id' => $customer->id, 'is_guest' => 0, 'is_guest_in_order' => 0, 'address_id' => 0, 'billing_address_id' => 0,
                'order_note' => null, 'coupon_code' => null, 'payment_mode' => 'web', 'new_customer_id' => 0,
            ]),
        ]);

        Log::shouldNotHaveReceived('critical');
    }
}
