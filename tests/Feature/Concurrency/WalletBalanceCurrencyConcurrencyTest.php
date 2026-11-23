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
 * Before PR 9 (gcc/p0-wallet-concurrency-lock) the balance check read the wallet without a lock, so both requests passed it before either debited, and
 * either the first order emptied the cart and the second request died on it (an HTTP 500, or a 403 "out of stock"), or both orders were written from the
 * cart before either deleted it, both were paid, and the second debit took the wallet below zero. Both outcomes were seen on MySQL 8.0.
 * PR 9 is merged in this tree and locks the customer's balance for the debit, so the test states the fixed behaviour: one order is placed, the other is
 * refused, and the wallet is never below zero.
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
    public function test_two_simultaneous_wallet_orders_for_the_whole_balance_place_one_and_never_overdraw_the_wallet(): void
    {
        $customer = $this->shopperWithCart(walletBalance: '10.01', price: '10.01');

        $reports = ConcurrentRunner::run('wallet.place_api_wallet_order', array_fill(0, 2, ['customer' => $customer->id]));

        $this->assertCount(2, array_unique(array_column($reports, 'pid')), 'two different processes');
        $this->assertTrue(ConcurrentRunner::ranAtTheSameTime($reports), 'the processes ran at the same time');
        foreach ($reports as $index => $report) {
            $this->assertTrue($report['ok'], "process {$index} failed: " . ($report['message'] ?? ''));
        }

        $results = array_column($reports, 'result');
        $placed = array_values(array_filter($results, static fn (array $result): bool => $result['status'] === 200));
        $refused = array_values(array_filter($results, static fn (array $result): bool => $result['status'] !== 200));

        $this->assertCount(1, $placed, 'one order is placed');
        $this->assertCount(1, $refused, 'the other is refused');
        $this->assertSame(
            ['orders' => 1, 'paid_orders' => 1, 'wallet_transactions' => 1, 'debited' => self::ORDER_DEBIT_USD, 'wallet_balance' => '0.000000'],
            $this->walletLedgerOf(customerId: $customer->id),
        );
        $this->assertSame(9, (int) DB::table('products')->where('id', 1)->value('current_stock'));
        $refusal = [$refused[0]['status'], $refused[0]['body']['message'] ?? null];
        $this->assertTrue(
            $refusal[0] === 500 || in_array($refusal, [
                [403, 'inefficient balance in your wallet to pay for this order'],
                [403, 'The following items in your cart are currently out of stock'],
            ], true),
            'The other order is refused with a 403, or ends in the 500 a request gets when the winner has already emptied the cart (the message is the exception text '
            . 'when debug is on: "Undefined array key 0" in Helpers::sales_commission_before_order): ' . json_encode($refusal),
        );
    }
}
