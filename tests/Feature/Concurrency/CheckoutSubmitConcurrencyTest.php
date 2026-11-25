<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\Concurrency\ConcurrentRunner;

/**
 * D1-14: two submits of ONE customer checkout, carrying one `Idempotency-Key`, at the same instant, in their own PHP processes, against a
 * real engine. One order, one stock take, and both submits answered with that order.
 *
 * Both processes read the cart and pass every check before either writes, which is the ordering a sequential test cannot produce. What
 * separates them is the claim: one insert meets the unique key, waits on the row the other has not committed yet, and is then answered
 * with the orders that submit placed.
 *
 * Needs a real MySQL or MariaDB: SQLite in memory is private to one process.
 */
class CheckoutSubmitConcurrencyTest extends ApiAddressTestCase
{
    private const KEY = 'one-submit-two-taps';

    protected function setUp(): void
    {
        parent::setUp();
        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }

        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
        (require database_path('migrations/2026_12_27_100100_create_checkout_idempotency_keys_table.php'))->up();
    }

    public function test_two_simultaneous_submits_carrying_one_key_place_one_order(): void
    {
        $customer = $this->makeCustomer();
        $this->putOneProductInTheCart(callerId: $customer->id, stock: 10);
        DB::table('carts')->update(['is_checked' => 1, 'product_type' => 'physical']);
        $this->makeLegacyAddress(customerId: $customer->id);
        $submit = ['customer' => $customer->id, 'key' => self::KEY];

        $reports = ConcurrentRunner::run('checkout.submit_with_a_key', array_fill(0, 2, $submit));

        $this->assertCount(2, array_unique(array_column($reports, 'pid')), 'two different processes');
        $this->assertTrue(ConcurrentRunner::ranAtTheSameTime($reports), 'the submits ran at the same time');
        foreach ($reports as $index => $report) {
            $this->assertTrue($report['ok'], "submit {$index} let an exception escape: " . ($report['error'] ?? '') . ' ' . ($report['message'] ?? ''));
        }

        $answers = array_column($reports, 'result');
        $this->assertSame([200, 200], array_column($answers, 'status'), 'both taps are answered, neither with an error');
        $this->assertSame($answers[0]['body']['order_ids'], $answers[1]['body']['order_ids'], 'and with the same order');
        $this->assertSame(1, DB::table('orders')->count(), 'one submit, one order');
        $this->assertSame(1, (int) DB::table('order_details')->sum('qty'), 'and the stock was taken once');
        $this->assertSame(9, (int) DB::table('products')->where('id', 1)->value('current_stock'));
        $this->assertSame(1, DB::table('checkout_idempotency_keys')->count(), 'one key, one row');
    }
}
