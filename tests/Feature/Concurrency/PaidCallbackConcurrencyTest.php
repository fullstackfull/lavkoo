<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\Concurrency\ConcurrentRunner;

/**
 * D1-328: two deliveries of ONE paid gateway callback, at the same instant, in their own PHP processes, against a real engine. One order.
 *
 * The settlement hook refuses a payment whose order exists, and that check is a read. Before this guard, two deliveries both passed the
 * read before either wrote and both built a full order: two orders for one payment, the stock taken twice, the commission charged twice,
 * and nothing in the record saying which one the customer paid for. The workers here go straight to the order writer, so the hook's read
 * cannot answer the question for them and the only thing between them and two orders is the claim made inside the order's transaction.
 *
 * Needs a real MySQL or MariaDB: `lockForUpdate` does nothing on SQLite, and SQLite in memory is private to one process anyway.
 */
class PaidCallbackConcurrencyTest extends ApiAddressTestCase
{
    private const REFERENCE = 'TXN-RACE-ONE';

    private const PAYMENT_ROW = '7c1d9a04-55b2-4a7e-8f3d-2e6b0c914fab';

    protected function setUp(): void
    {
        parent::setUp();
        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }

        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
    }

    public function test_two_simultaneous_deliveries_of_one_paid_callback_build_one_order(): void
    {
        $customer = $this->makeCustomer();
        $this->putOneProductInTheCart(callerId: $customer->id, stock: 10);
        $addressId = $this->makeLegacyAddress(customerId: $customer->id)->id;
        DB::table('payment_requests')->insert([
            'id' => self::PAYMENT_ROW, 'payment_method' => 'ssl_commerz', 'transaction_id' => self::REFERENCE, 'payment_amount' => 100,
            'currency_code' => 'SAR', 'is_paid' => 1, 'additional_data' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $delivery = ['customer' => $customer->id, 'address' => $addressId, 'reference' => self::REFERENCE, 'payment' => self::PAYMENT_ROW];

        $reports = ConcurrentRunner::run('paid_settlement.settle', array_fill(0, 2, $delivery));

        $this->assertCount(2, array_unique(array_column($reports, 'pid')), 'two different processes');
        $this->assertTrue(ConcurrentRunner::ranAtTheSameTime($reports), 'the deliveries ran at the same time');
        foreach ($reports as $index => $report) {
            $this->assertTrue($report['ok'], "delivery {$index} let an exception escape: " . ($report['error'] ?? '') . ' ' . ($report['message'] ?? ''));
        }

        $built = array_filter(array_column($reports, 'result'), static fn (array $orderIds): bool => $orderIds !== []);
        $this->assertCount(1, $built, 'exactly one delivery built the orders of this payment');
        $this->assertSame(1, DB::table('orders')->count(), 'one payment, one order');
        $this->assertSame(1, DB::table('orders')->where('transaction_ref', self::REFERENCE)->count());
        $this->assertSame(1, (int) DB::table('order_details')->sum('qty'), 'and the stock was taken once');
        $this->assertSame(9, (int) DB::table('products')->where('id', 1)->value('current_stock'));
    }
}
