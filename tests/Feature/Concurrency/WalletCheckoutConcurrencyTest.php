<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\Support\Wallet\WalletTables;

/**
 * Simultaneous wallet checkouts of one customer, on a real MySQL or MariaDB. Each checkout locks the customer's row before the stock
 * rows and debits inside the order transaction, so overlapping checkouts the balance cannot cover all commit no more orders than it
 * pays for.
 */
class WalletCheckoutConcurrencyTest extends ApiAddressTestCase
{
    private const OPENING_BALANCE = 100.0;

    private const ORDER_TOTAL = 60.0;

    protected function setUp(): void
    {
        parent::setUp();
        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }

        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
        WalletTables::createLedger();
        WalletTables::setSetting('wallet_status', '1');
    }

    public function test_two_simultaneous_checkouts_of_sixty_from_one_hundred_place_exactly_one_order(): void
    {
        $customer = $this->makeCustomer(['wallet_balance' => self::OPENING_BALANCE]);
        $this->putOneProductInTheCart($customer->id);
        DB::table('carts')->where('customer_id', $customer->id)->update(['price' => self::ORDER_TOTAL]);
        DB::table('products')->where('id', 1)->update(['unit_price' => self::ORDER_TOTAL]);
        $checkout = ['customer_id' => $customer->id, 'amount' => self::ORDER_TOTAL];

        $reports = ConcurrentRunner::run('wallet.checkout', [$checkout, $checkout]);

        $this->assertSame([], array_values(array_filter($reports, fn (array $report): bool => !$report['ok'])), 'a worker failed: ' . json_encode($reports));
        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the checkouts did not overlap, so the test proves nothing');
        $results = array_column($reports, 'result');
        sort($results);
        $this->assertSame(['placed', 'refused'], $results);
        $this->assertSame(1, DB::table('orders')->count(), 'exactly one order');
        $this->assertSame(1, DB::table('order_details')->count());
        $this->assertSame(9, (int) DB::table('products')->where('id', 1)->value('current_stock'), 'one unit left the shelf');
        $this->assertSame(self::OPENING_BALANCE - self::ORDER_TOTAL, (float) DB::table('users')->where('id', $customer->id)->value('wallet_balance'));
        $this->assertSame(1, DB::table('wallet_transactions')->count());
    }
}
