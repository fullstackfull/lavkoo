<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\Support\Wallet\WalletTables;
use Tests\TestCase;

/**
 * Several processes credit one customer wallet at the same instant, on a real MySQL or MariaDB.
 *
 * A wallet write reads the balance, works out the new one and writes it back. With no row lock held across the read and the
 * write, two credits can read the same balance and the later one overwrites the earlier: the ledger records both and the wallet
 * keeps one. Every credit here goes through App\Services\Customer\CustomerWalletWriter, which is the only thing that writes a
 * customer's balance.
 */
class WalletConcurrencyTest extends TestCase
{
    private const CUSTOMER_ID = 1;
    private const OPENING_BALANCE = 350.0;
    private const WRITES = 10;
    private const AMOUNT = 100.0;

    protected function setUp(): void
    {
        parent::setUp();
        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }
        WalletTables::reset();
        WalletTables::setSetting('wallet_status', '1');
        WalletTables::setSetting('loyalty_point_exchange_rate', '100');
        WalletTables::seedCustomer(self::CUSTOMER_ID, self::OPENING_BALANCE);
    }

    protected function tearDown(): void
    {
        if (ConcurrentRunner::available()) {
            WalletTables::drop();
        }
        parent::tearDown();
    }

    /**
     * TWO credits that are both certain to have reached the row before either could write it.
     *
     * This is the case the defect was reported on, and it is arranged rather than raced: a third process holds the customer's
     * row and does not let go until the engine reports that both credits are waiting on a lock. So whatever the scheduler does,
     * the two credits contend, and the assertion below means the same thing on every run.
     *
     * Against the four unlocked copies this read 450.00 — 350 opening and one credit of 100, with the other credit's row on the
     * ledger and its money nowhere.
     */
    public function test_two_credits_arranged_to_collide_both_reach_the_wallet(): void
    {
        $reports = ConcurrentRunner::run('wallet.credit_once_locked', [
            ['op' => 'wallet.hold_the_row', 'customer_id' => self::CUSTOMER_ID, 'writers' => 2],
            $this->credit('arranged-first'),
            $this->credit('arranged-second'),
        ]);

        $this->assertNoWorkerFailed($reports);
        $this->assertSame(2, $reports[0]['result']['credits_held_at_the_row'], 'both credits should have been stopped at the row');
        foreach ([1, 2] as $credit) {
            $this->assertTrue($reports[$credit]['result']['credited'], 'a credit reported failure');
            $this->assertGreaterThan(0.0, $reports[$credit]['result']['held_for'], 'a credit did not wait for the row to be held');
        }

        $this->assertSame(2, DB::table('wallet_transactions')->count(), 'each credit should leave one ledger row');
        $this->assertEqualsWithDelta(
            self::OPENING_BALANCE + 2 * self::AMOUNT,
            WalletTables::walletBalance(self::CUSTOMER_ID),
            0.0001,
            'the wallet lost a credit that the ledger recorded',
        );
    }

    /** The same invariant over ten credits released together: whatever order they land in, the wallet holds all of them. */
    public function test_ten_simultaneous_credits_all_reach_the_wallet(): void
    {
        $arguments = [];
        for ($i = 0; $i < self::WRITES; $i++) {
            $arguments[] = $this->credit("simultaneous-{$i}");
        }

        $reports = ConcurrentRunner::run('wallet.credit', $arguments);

        $this->assertNoWorkerFailed($reports);
        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the credits were not alive at the same time');
        $this->assertSame(self::WRITES, DB::table('wallet_transactions')->count(), 'every credit should leave one ledger row');
        $this->assertEqualsWithDelta(
            self::OPENING_BALANCE + self::WRITES * self::AMOUNT,
            WalletTables::walletBalance(self::CUSTOMER_ID),
            0.0001,
            'the wallet lost credits that the ledger recorded',
        );
    }

    /** The ledger row of every credit says the balance the account held after it, and the last one says the balance it holds now. */
    public function test_the_ledger_rows_of_simultaneous_credits_climb_to_the_final_balance(): void
    {
        $arguments = [];
        for ($i = 0; $i < self::WRITES; $i++) {
            $arguments[] = $this->credit("climbing-{$i}");
        }

        $this->assertNoWorkerFailed(ConcurrentRunner::run('wallet.credit', $arguments));

        $balances = DB::table('wallet_transactions')->orderBy('id')->pluck('balance')->map(fn ($balance): float => (float) $balance)->all();
        $expected = [];
        for ($i = 1; $i <= self::WRITES; $i++) {
            $expected[] = self::OPENING_BALANCE + $i * self::AMOUNT;
        }

        $this->assertEqualsWithDelta($expected, $balances, 0.0001, 'the ledger does not read as one climbing account');
        $this->assertEqualsWithDelta(end($expected), WalletTables::walletBalance(self::CUSTOMER_ID), 0.0001);
    }

    /** @return array{customer_id: int, amount: float, transaction_type: string, reference: string} */
    private function credit(string $reference): array
    {
        return [
            'customer_id' => self::CUSTOMER_ID,
            'amount' => self::AMOUNT,
            'transaction_type' => 'add_fund_by_admin',
            'reference' => $reference,
        ];
    }

    /** @param list<array{ok: bool, error?: string, message?: string}> $reports */
    private function assertNoWorkerFailed(array $reports): void
    {
        foreach ($reports as $index => $report) {
            $this->assertTrue($report['ok'], "worker {$index} failed: " . ($report['error'] ?? '') . ' ' . ($report['message'] ?? ''));
        }
    }
}
