<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\Support\Wallet\WalletTables;
use Tests\TestCase;

/**
 * Simultaneous debits against one wallet, on a real MySQL or MariaDB. Each balance check is made while the customer's row is
 * locked, so overlapping debits cannot both pass against the same balance.
 */
class WalletOverdraftConcurrencyTest extends TestCase
{
    private const CUSTOMER_ID = 1;
    private const OPENING_BALANCE = 100.0;

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

    /** @return array<string, array{0: string, 1: string}> the operation and the transaction type it debits */
    public static function debitPaths(): array
    {
        return [
            'repository order payment' => ['wallet.repository', 'order_place'],
            'customer manager order payment' => ['wallet.customer_manager', 'order_place'],
            'customer trait order payment' => ['wallet.customer_trait', 'order_place'],
            'order manager order payment' => ['wallet.order_manager', 'order_place'],
            'customer manager due payment' => ['wallet.customer_manager', 'due_payment_for_order'],
        ];
    }

    #[DataProvider('debitPaths')]
    public function test_two_simultaneous_debits_of_sixty_let_exactly_one_through(string $operation, string $transactionType): void
    {
        $reports = ConcurrentRunner::run($operation, $this->debits($operation, $transactionType, 2, 60.0));

        $this->assertSame([], array_values(array_filter($reports, fn (array $report): bool => !$report['ok'])), 'a worker failed');
        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the debits did not overlap, so the test proves nothing');
        $this->assertSame(1, $this->successes($reports), 'exactly one debit may succeed');
        $this->assertSame(40.0, WalletTables::walletBalance(self::CUSTOMER_ID));
        $this->assertSame(1, DB::table('wallet_transactions')->count());
    }

    #[DataProvider('debitPaths')]
    public function test_ten_simultaneous_debits_of_fifteen_never_take_the_balance_below_zero(string $operation, string $transactionType): void
    {
        $reports = ConcurrentRunner::run($operation, $this->debits($operation, $transactionType, 10, 15.0));

        $this->assertSame([], array_values(array_filter($reports, fn (array $report): bool => !$report['ok'])), 'a worker failed');
        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the debits did not overlap, so the test proves nothing');
        $this->assertSame(6, $this->successes($reports), 'only six debits of fifteen fit in one hundred');
        $this->assertSame(10.0, WalletTables::walletBalance(self::CUSTOMER_ID));
        $this->assertSame(6, DB::table('wallet_transactions')->count());
    }

    /** @param array<int, array<string, mixed>> $reports */
    private function successes(array $reports): int
    {
        return count(array_filter($reports, fn (array $report): bool => $report['result'] === true));
    }

    /** @return list<array<string, int|float|string>> */
    private function debits(string $operation, string $transactionType, int $count, float $amount): array
    {
        $arguments = [];
        for ($i = 0; $i < $count; $i++) {
            $arguments[] = [
                'customer_id' => self::CUSTOMER_ID,
                'amount' => $amount,
                'transaction_type' => $transactionType,
                'reference' => "{$operation}-{$i}",
            ];
        }

        return $arguments;
    }
}
