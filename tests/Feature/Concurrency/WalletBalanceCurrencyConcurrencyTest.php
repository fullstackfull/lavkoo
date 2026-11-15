<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsWalletCheckoutWorld;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Two REST v1 wallet orders for the whole balance, from one customer, at the same instant, in a SAR store, against a real MySQL.
 *
 * The balance check reads the wallet without a lock, so both requests pass it before either debits. What happens next depends on timing:
 * either the first order empties the cart and the second request dies on it (an HTTP 500, or a 403 "out of stock"), or both orders are written
 * from the cart before either deletes it, both are paid, and the second debit takes the wallet below zero. Both outcomes were seen on MySQL 8.0.
 * PR 9 (gcc/p0-wallet-concurrency-lock) locks the customer's balance for the debit and is the fix for this.
 *
 * Needs a real MySQL or MariaDB: SQLite in memory is private to one process, so there is nothing to race.
 */
class WalletBalanceCurrencyConcurrencyTest extends TestCase
{
    use BuildsWalletCheckoutWorld;
    use RestoresLanguageFiles;

    private const ORDER_DEBIT_USD = '10.010000';

    protected function setUp(): void
    {
        parent::setUp();
        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }

        $this->rememberLanguageFiles();
        Http::preventStrayRequests();
        $this->buildWalletCheckoutWorld();
        $this->useStoreCurrency(code: 'SAR', rate: '3.75', decimals: 2);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** The balance is exactly what one 10.01 USD order debits, in whole cents, in a SAR store. */
    public function test_two_simultaneous_wallet_orders_for_the_whole_balance_both_pass_the_check_and_can_currently_overdraw_the_wallet(): void
    {
        $customer = $this->shopperWithCart(walletBalance: '10.01', price: '10.01');

        $reports = ConcurrentRunner::run('wallet.place_api_wallet_order', array_fill(0, 2, ['customer' => $customer->id]));

        $this->assertCount(2, array_unique(array_column($reports, 'pid')), 'two different processes');
        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the processes ran at the same time');
        foreach ($reports as $index => $report) {
            $this->assertTrue($report['ok'], "process {$index} failed: " . ($report['message'] ?? ''));
        }

        $results = array_column($reports, 'result');
        $refusedForBalance = array_filter($results, static fn (array $result): bool => ($result['body']['message'] ?? '') === 'inefficient balance in your wallet to pay for this order');
        $this->assertSame([], $refusedForBalance, 'neither request was stopped by the balance check');

        $placed = count(array_filter($results, static fn (array $result): bool => $result['status'] === 200));
        $outcomes = [
            1 => ['orders' => 1, 'paid_orders' => 1, 'wallet_transactions' => 1, 'debited' => self::ORDER_DEBIT_USD, 'wallet_balance' => '0.000000'],
            2 => ['orders' => 2, 'paid_orders' => 2, 'wallet_transactions' => 2, 'debited' => '20.020000', 'wallet_balance' => '-' . self::ORDER_DEBIT_USD],
        ];
        $this->assertArrayHasKey($placed, $outcomes, "{$placed} orders were placed");
        $this->assertSame($outcomes[$placed], $this->walletLedgerOf(customerId: $customer->id));
        $this->assertSame(10 - $placed, (int) DB::table('products')->where('id', 1)->value('current_stock'));
        if ($placed === 1) {
            $loser = array_values(array_filter($results, static fn (array $result): bool => $result['status'] !== 200))[0];
            $this->assertContains([$loser['status'], $loser['body']['message'] ?? null], [[500, 'Server Error'], [403, 'The following items in your cart are currently out of stock']]);
        }
    }
}
