<?php

namespace Tests\Feature\Concurrency;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\Support\KeepsTranslateOffLanguageFiles;
use Tests\Support\Wallet\OrderDueTables;
use Tests\Support\Wallet\WalletTables;
use Tests\TestCase;

/**
 * Simultaneous wallet payments of the same edited order's due, on a real MySQL or MariaDB. The order row is locked and its due read
 * again under the lock, so only the first payment finds anything to pay. The wallet holds enough for both, so only the order lock can
 * stop the second debit.
 */
class OrderEditDueConcurrencyTest extends TestCase
{
    use KeepsTranslateOffLanguageFiles;

    private const CUSTOMER_ID = 1;
    private const ORDER_ID = 100001;
    private const ORDER_AMOUNT = 200.0;
    private const DUE = 60.0;
    private const OPENING_BALANCE = 500.0;

    protected function setUp(): void
    {
        parent::setUp();
        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }
        OrderDueTables::drop();
        OrderDueTables::create();
        WalletTables::seedCustomer(self::CUSTOMER_ID, self::OPENING_BALANCE);
        OrderDueTables::seedEditedOrder(self::ORDER_ID, self::CUSTOMER_ID, self::ORDER_AMOUNT, self::DUE);
        $this->keepTranslateOffLanguageFiles();
    }

    protected function tearDown(): void
    {
        if (ConcurrentRunner::available()) {
            $this->assertLanguageFilesUntouched();
            OrderDueTables::drop();
        }
        parent::tearDown();
    }

    /** @return array<string, array{0: int}> */
    public static function submissions(): array
    {
        return ['two submissions' => [2], 'five submissions' => [5]];
    }

    #[DataProvider('submissions')]
    public function test_simultaneous_payments_of_the_full_due_debit_the_wallet_exactly_once(int $submissions): void
    {
        $arguments = array_fill(0, $submissions, ['order_id' => self::ORDER_ID, 'customer_id' => self::CUSTOMER_ID]);

        $reports = ConcurrentRunner::run('wallet.pay_edit_due', $arguments);

        $this->assertSame([], array_values(array_filter($reports, fn (array $report): bool => !$report['ok'])), 'a worker failed');
        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the payments did not overlap, so the test proves nothing');
        $this->assertSame(1, count(array_filter($reports, fn (array $report): bool => $report['result'] === true)), 'exactly one payment may succeed');
        $this->assertSame([
            'due' => 0.0, 'order_amount' => self::ORDER_AMOUNT + self::DUE, 'payment_status' => 'paid', 'history_status' => 'paid',
            'admin_pending' => OrderDueTables::ADMIN_PENDING + self::DUE, 'ledger_rows' => 1,
        ], OrderDueTables::state(self::ORDER_ID));
        $this->assertSame(self::OPENING_BALANCE - self::DUE, WalletTables::walletBalance(self::CUSTOMER_ID));
    }
}
