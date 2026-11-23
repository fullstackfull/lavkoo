<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsPosSaleWorld;
use Tests\Support\Concurrency\ConcurrentRunner;

/**
 * Two submits of one seller app sale, with the same Idempotency-Key, at the same instant, against a real MySQL.
 *
 * Both pass the balance check before either writes, so both reach the key claim. The second waits on the key the first claimed, and once the
 * first commits it is answered with that sale's order: one order, one debit, one stock take, and the same answer to both.
 *
 * Needs a real MySQL or MariaDB: SQLite in memory is private to one process, so there is nothing to race.
 */
class PosSaleIdempotencyConcurrencyTest extends ApiAddressTestCase
{
    use BuildsPosSaleWorld;

    protected function setUp(): void
    {
        parent::setUp();
        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }
    }

    public function test_two_simultaneous_submits_of_one_sale_with_one_key_place_one_order_and_debit_once(): void
    {
        $customer = $this->buildPosSaleWorld(sellerId: 5);
        $line = ['id' => 1, 'quantity' => 2, 'price' => 30.0, 'discounted_price' => 30.0, 'discount' => 0, 'applied_tax_amount' => 0, 'variant' => null, 'variation' => [], 'coupon_discount' => 0, 'extra_discount' => 0];
        $sale = ['seller' => 5, 'customer' => $customer->id, 'key' => 'sale-race', 'lines' => [$line], 'paid' => 60.0];

        $reports = ConcurrentRunner::run('pos.seller_sale', array_fill(0, 2, $sale));

        $this->assertCount(2, array_unique(array_column($reports, 'pid')), 'two different processes');
        $this->assertTrue(ConcurrentRunner::ranAtTheSameTime($reports), 'the processes ran at the same time');
        foreach ($reports as $index => $report) {
            $this->assertTrue($report['ok'], "process {$index} failed: " . ($report['message'] ?? ''));
        }
        $results = array_column($reports, 'result');
        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertSame($results[0]['body']['order_id'], $results[1]['body']['order_id'], 'both answers are the same order');
        $this->assertSame([1, 1], [DB::table('orders')->count(), DB::table('wallet_transactions')->count()]);
        $this->assertSame(40.0, (float) DB::table('users')->where('id', $customer->id)->value('wallet_balance'));
        $this->assertSame([1 => 8, 2 => 10], DB::table('products')->orderBy('id')->pluck('current_stock', 'id')->map(fn ($stock): int => (int) $stock)->all());
    }
}
