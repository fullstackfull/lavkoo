<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\Support\Loyalty\LoyaltyTables;
use Tests\Support\Wallet\WalletTables;
use Tests\TestCase;

/**
 * Several processes change one customer's loyalty points at the same instant, on a real MySQL or MariaDB.
 *
 * A points write reads the balance, computes the new one, and writes it back. Overlapping writes that read the same balance
 * lose each other's change. A conversion also has to check the balance while it holds the row, so two conversions cannot both
 * spend the same points.
 */
class LoyaltyConcurrencyTest extends TestCase
{
    private const CUSTOMER_ID = 1;
    private const OPENING_POINTS = 100.0;
    private const CREDIT_WRITERS = 10;
    private const ORDER_AMOUNT = 100.0;
    private const ORDER_POINTS_EARNED = 10.0;
    private const CONVERSION_WRITERS = 4;
    private const POINTS_PER_CONVERSION = 60.0;

    protected function setUp(): void
    {
        parent::setUp();
        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }
        LoyaltyTables::drop();
        WalletTables::drop();
        WalletTables::create();
        LoyaltyTables::create();
        WalletTables::setSetting('wallet_status', '1');
        WalletTables::setSetting('loyalty_point_exchange_rate', '100');
        LoyaltyTables::enableLoyaltyPoints();
        WalletTables::seedCustomer(self::CUSTOMER_ID, 0.0);
        DB::table('users')->where('id', self::CUSTOMER_ID)->update(['loyalty_point' => self::OPENING_POINTS]);
    }

    protected function tearDown(): void
    {
        if (ConcurrentRunner::available()) {
            LoyaltyTables::drop();
            WalletTables::drop();
        }
        parent::tearDown();
    }

    public function test_simultaneous_order_credits_through_the_repository_all_reach_the_balance(): void
    {
        $this->assertConcurrentCreditsLand('loyalty.repository');
    }

    public function test_simultaneous_order_credits_through_customer_manager_all_reach_the_balance(): void
    {
        $this->assertConcurrentCreditsLand('loyalty.customer_manager');
    }

    public function test_simultaneous_conversions_spend_the_points_once_and_no_more(): void
    {
        $reports = ConcurrentRunner::run('loyalty.convert', $this->writes('loyalty.convert', self::CONVERSION_WRITERS, self::POINTS_PER_CONVERSION));

        $this->assertSame([], array_values(array_filter($reports, fn (array $report): bool => !$report['ok'])), 'a worker failed');
        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the conversions did not overlap, so the test proves nothing');
        $this->assertSame(1, count(array_filter($reports, fn (array $report): bool => $report['result'] === true)), 'exactly one conversion should succeed');
        $this->assertSame(1, DB::table('wallet_transactions')->count(), 'only the successful conversion may credit the wallet');
        $this->assertSame(1, DB::table('loyalty_point_transactions')->where('transaction_type', 'point_to_wallet')->count());
        $this->assertSame(
            self::OPENING_POINTS - self::POINTS_PER_CONVERSION,
            LoyaltyTables::loyaltyPointsOf(self::CUSTOMER_ID),
            'the points balance must reflect the one conversion that succeeded',
        );
    }

    private function assertConcurrentCreditsLand(string $operation): void
    {
        $reports = ConcurrentRunner::run($operation, $this->writes($operation, self::CREDIT_WRITERS, self::ORDER_AMOUNT));

        $this->assertSame([], array_values(array_filter($reports, fn (array $report): bool => !$report['ok'])), 'a worker failed');
        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the writes did not overlap, so the test proves nothing');
        $this->assertSame(self::CREDIT_WRITERS, DB::table('loyalty_point_transactions')->count(), 'every write should leave one ledger row');
        $this->assertEqualsWithDelta(
            self::OPENING_POINTS + self::CREDIT_WRITERS * self::ORDER_POINTS_EARNED,
            LoyaltyTables::loyaltyPointsOf(self::CUSTOMER_ID),
            0.0001,
            'the balance lost credits that the ledger recorded',
        );
    }

    /** @return list<array<string, int|float|string>> */
    private function writes(string $operation, int $count, float $amount): array
    {
        $arguments = [];
        for ($i = 0; $i < $count; $i++) {
            $arguments[] = [
                'customer_id' => self::CUSTOMER_ID,
                'amount' => $amount,
                'points' => $amount,
                'reference' => "{$operation}-{$i}",
            ];
        }

        return $arguments;
    }
}
