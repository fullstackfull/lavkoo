<?php

namespace Tests\Feature\Concurrency;

use App\Services\Payments\WalletTopUpRegister;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsWalletTopUpWorld;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\TestCase;

/**
 * One payment into the wallet, handed over by six processes at the same instant, against a real MySQL.
 *
 * What holds the six apart is a unique key that all of them write inside their own transaction, and that cannot be tested in
 * the in-memory SQLite of the rest of the suite: it is private to one process. The second test is the control. It runs the
 * same six deliveries without the register and shows them all being credited, so a pass of the first is the register at work
 * and not six processes that never met.
 */
class WalletTopUpConcurrencyTest extends TestCase
{
    use BuildsWalletTopUpWorld;

    private const DELIVERIES = 6;

    private const OPENING_BALANCE = 10.0;

    private const BONUS = 5.0;

    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();

        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }

        $this->buildWalletTopUpWorld();
        $this->installTheTopUpRegister();
        $this->aTopUpBonus(bonus: self::BONUS, fromAmount: 50);
        $this->customer = $this->aCustomer(balance: self::OPENING_BALANCE);
    }

    public function test_six_simultaneous_deliveries_of_one_payment_credit_the_wallet_once(): void
    {
        $this->handOverAtTheSameInstant(payment: $this->aPaidTopUp(customer: $this->customer, amount: 100)->id);

        $this->assertSame(1, DB::table('wallet_transactions')->count(), 'one ledger row for one payment');
        $this->assertSame(self::OPENING_BALANCE + 100 + self::BONUS, $this->walletBalanceOf($this->customer));
        $this->assertSame(1, DB::table(WalletTopUpRegister::TABLE)->count());
        $this->assertSame((int) DB::table('wallet_transactions')->value('id'), (int) DB::table(WalletTopUpRegister::TABLE)->value('wallet_transaction_id'));
    }

    public function test_without_the_register_the_same_six_deliveries_are_all_credited(): void
    {
        Schema::drop(WalletTopUpRegister::TABLE);

        $this->handOverAtTheSameInstant(payment: $this->aPaidTopUp(customer: $this->customer, amount: 100)->id);

        $this->assertSame(self::DELIVERIES, DB::table('wallet_transactions')->count(), 'the control: every delivery wrote a credit');
    }

    private function handOverAtTheSameInstant(string $payment): void
    {
        $reports = ConcurrentRunner::run('wallet.hand_over_top_up', array_fill(0, self::DELIVERIES, ['payment_request' => $payment]));

        $this->assertCount(self::DELIVERIES, array_unique(array_column($reports, 'pid')), 'six different processes');
        $this->assertTrue(ConcurrentRunner::ranAtTheSameTime($reports), 'the processes ran at the same time');
        foreach ($reports as $index => $report) {
            $this->assertTrue($report['ok'], "process {$index} failed: " . ($report['message'] ?? ''));
        }
    }
}
