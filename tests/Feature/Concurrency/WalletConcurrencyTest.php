<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\Support\Wallet\WalletTables;
use Tests\TestCase;

/**
 * Several processes write one customer wallet at the same instant, on a real MySQL or MariaDB.
 *
 * A wallet write reads the balance, computes the new one, and writes it back. With no row lock held across the read and the
 * write, two writes can read the same balance and the later one overwrites the earlier. The ledger then records both writes
 * while the wallet keeps only one of them.
 */
class WalletConcurrencyTest extends TestCase
{
    private const CUSTOMER_ID = 1;
    private const OPENING_BALANCE = 100.0;
    private const WRITES = 10;
    private const AMOUNT = 1.0;

    protected function setUp(): void
    {
        parent::setUp();
        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }
        WalletTables::drop();
        WalletTables::create();
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
     * @return array<string, array{0: string, 1: string, 2: float}> the operation, the transaction type it writes, and the change to
     *                                                              the wallet per write, in units of AMOUNT
     */
    public static function writePaths(): array
    {
        return [
            'repository order payment' => ['wallet.repository', 'order_place', -1.0],
            'customer manager order payment' => ['wallet.customer_manager', 'order_place', -1.0],
            'customer trait order payment' => ['wallet.customer_trait', 'order_place', -1.0],
            'order manager referral credit' => ['wallet.order_manager', 'add_fund_by_admin', 1.0],
        ];
    }

    #[DataProvider('writePaths')]
    public function test_simultaneous_writes_all_reach_the_wallet(string $operation, string $transactionType, float $changePerWrite): void
    {
        $arguments = [];
        for ($i = 0; $i < self::WRITES; $i++) {
            $arguments[] = [
                'customer_id' => self::CUSTOMER_ID,
                'amount' => self::AMOUNT,
                'transaction_type' => $transactionType,
                'reference' => "{$operation}-{$i}",
            ];
        }

        $reports = ConcurrentRunner::run($operation, $arguments);

        $this->assertSame([], array_values(array_filter($reports, fn (array $report): bool => !$report['ok'])), 'a worker failed');
        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the writes did not overlap, so the test proves nothing');
        $this->assertSame(self::WRITES, DB::table('wallet_transactions')->count(), 'every write should leave one ledger row');

        $expected = self::OPENING_BALANCE + self::WRITES * $changePerWrite * self::AMOUNT;
        $this->assertEqualsWithDelta(
            $expected,
            WalletTables::walletBalance(self::CUSTOMER_ID),
            0.0001,
            'the wallet lost writes that the ledger recorded',
        );
    }
}
