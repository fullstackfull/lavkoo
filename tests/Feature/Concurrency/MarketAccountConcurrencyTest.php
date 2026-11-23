<?php

namespace Tests\Feature\Concurrency;

use App\Enums\Wallet\MarketBalanceKind;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMarketAccountSchema;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\TestCase;

/**
 * Two writers crediting one customer's market account at the same instant, in separate PHP processes, against a real MySQL or MariaDB
 * (GCC-15, E-03). The movement that matters is the FIRST one a market ever sees: the account is not there, both writers have to open it,
 * and under REPEATABLE READ a locking read that finds nothing holds the gap of the unique key on (customer, market) rather than a record,
 * so two inserts into each other's gap are a deadlock and one credit is lost. Opening the account before locking it leaves nothing to
 * deadlock over: the second writer waits for the first and adds to what it wrote.
 *
 * Needs a real engine. SQLite in memory is private to one process, and `lockForUpdate` is a no-op there, so neither half of this is
 * visible on it.
 */
class MarketAccountConcurrencyTest extends TestCase
{
    use BuildsMarketAccountSchema;

    private const CUSTOMER = 1;

    /** @return array<string, array{MarketBalanceKind}> */
    public static function balances(): array
    {
        return ['the wallet' => [MarketBalanceKind::Wallet], 'the loyalty points' => [MarketBalanceKind::Loyalty]];
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }

        $this->buildMarketAccountSchema();
        $this->runMarketAccountMigration();
        $this->withMarketAccountsOn();
        $this->seedCustomer(self::CUSTOMER, walletBalance: 250.5, loyaltyPoints: 80);
    }

    /** @param list<array<string, mixed>> $reports */
    private function assertAllWorkersSucceeded(array $reports): void
    {
        foreach ($reports as $index => $report) {
            $this->assertTrue($report['ok'], "worker {$index} failed: " . ($report['error'] ?? '') . ' ' . ($report['message'] ?? ''));
        }
    }

    /** @return list<array<string, mixed>> */
    private function creditAtOnce(MarketBalanceKind $kind, string $market, float $amount, int $writers = 2): array
    {
        return ConcurrentRunner::run('market_accounts.credit', array_fill(0, $writers, [
            'kind' => $kind->value,
            'customer' => self::CUSTOMER,
            'market' => $market,
            'amount' => $amount,
        ]));
    }

    /** @param list<array<string, mixed>> $reports @return list<float> what each writer reported the account held after its own credit */
    private function amountsWritten(array $reports): array
    {
        $amounts = array_map('floatval', array_column(array_column($reports, 'result'), 'amount'));
        sort($amounts);

        return $amounts;
    }

    private function heldInMarket(MarketBalanceKind $kind, string $market): float
    {
        return (float) DB::table($kind->accountsTable())->where(['customer_id' => self::CUSTOMER, 'market' => $market])->value($kind->amountColumn());
    }

    #[DataProvider('balances')]
    public function test_two_first_credits_to_one_market_at_once_both_land_in_one_account(MarketBalanceKind $kind): void
    {
        $reports = $this->creditAtOnce($kind, market: 'KW', amount: 100);

        $this->assertAllWorkersSucceeded($reports);
        $this->assertTrue(ConcurrentRunner::ranAtTheSameTime($reports), 'the processes ran at the same time');
        $this->assertSame([true, true], array_column(array_column($reports, 'result'), 'applied'), 'neither credit was refused or killed');
        $this->assertSame(1, DB::table($kind->accountsTable())->count(), 'two writers opened one account');
        $this->assertSame(200.0, $this->heldInMarket($kind, 'KW'), 'neither credit was lost');
        $this->assertSame([100.0, 200.0], $this->amountsWritten($reports), 'one writer saw what the other had written');
    }

    #[DataProvider('balances')]
    public function test_four_first_credits_to_one_market_at_once_all_land(MarketBalanceKind $kind): void
    {
        $reports = $this->creditAtOnce($kind, market: 'KW', amount: 25, writers: 4);

        $this->assertAllWorkersSucceeded($reports);
        $this->assertSame(1, DB::table($kind->accountsTable())->count());
        $this->assertSame(100.0, $this->heldInMarket($kind, 'KW'));
        $this->assertSame([25.0, 50.0, 75.0, 100.0], $this->amountsWritten($reports), 'the four writers went one after another');
    }

    #[DataProvider('balances')]
    public function test_credits_to_an_account_that_is_already_open_all_land(MarketBalanceKind $kind): void
    {
        $this->creditAtOnce($kind, market: 'KW', amount: 100);

        $reports = $this->creditAtOnce($kind, market: 'KW', amount: 50, writers: 4);

        $this->assertAllWorkersSucceeded($reports);
        $this->assertSame(400.0, $this->heldInMarket($kind, 'KW'), 'the ordinary case: every credit after the first');
    }

    public function test_two_markets_of_one_customer_are_credited_without_waiting_for_each_other(): void
    {
        $reports = ConcurrentRunner::run('market_accounts.credit', [
            ['kind' => MarketBalanceKind::Wallet->value, 'customer' => self::CUSTOMER, 'market' => 'KW', 'amount' => 100],
            ['kind' => MarketBalanceKind::Wallet->value, 'customer' => self::CUSTOMER, 'market' => 'AE', 'amount' => 70],
        ]);

        $this->assertAllWorkersSucceeded($reports);
        $this->assertSame([100.0, 70.0], [$this->heldInMarket(MarketBalanceKind::Wallet, 'KW'), $this->heldInMarket(MarketBalanceKind::Wallet, 'AE')]);
    }
}
