<?php

namespace Tests\Feature\Wallet;

use App\Enums\Wallet\MarketBalanceKind;
use App\Services\Fulfillment\Readiness\Compatibility\CompatibilityCheck;
use App\Services\Fulfillment\Readiness\Compatibility\ReadOnlyProbe;
use App\Services\Wallet\Reconciliation\MarketAccountReconciler;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsMarketAccountSchema;
use Tests\TestCase;

/**
 * The per-market half of the balance reconciliation (E-06, E-07): per market, what its accounts hold against the net of the ledger rows
 * that name it, and how much of each ledger still names no market. While the market accounts key is off it reports nothing at all, so the
 * reconcile report says exactly what it says today.
 */
class MarketAccountReconcilerTest extends TestCase
{
    use BuildsMarketAccountSchema;

    private const TOLERANCE = 0.000001;

    private ReadOnlyProbe $probe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMarketAccountSchema();
        $this->seedCustomer(1, walletBalance: 250.5, loyaltyPoints: 80);
        $this->probe = new ReadOnlyProbe(DB::connection());
    }

    /** @return list<CompatibilityCheck> */
    private function checks(): array
    {
        return $this->probe->run(fn (ReadOnlyProbe $probe): array => app(MarketAccountReconciler::class)->checks(probe: $probe, tolerance: self::TOLERANCE));
    }

    /** @param list<CompatibilityCheck> $checks */
    private function named(array $checks, string $section, string $name): CompatibilityCheck
    {
        foreach ($checks as $check) {
            if ($check->section === $section && $check->name === $name) {
                return $check;
            }
        }

        $this->fail("no check '{$name}' in section '{$section}'; there were: " . implode(', ', array_map(static fn (CompatibilityCheck $check): string => "{$check->section}/{$check->name}", $checks)));
    }

    private function openAccount(MarketBalanceKind $kind, string $market, string $currencyCode, float $amount): void
    {
        DB::table($kind->accountsTable())->insert([
            'customer_id' => 1,
            'market' => $market,
            'currency_code' => $currencyCode,
            $kind->amountColumn() => $amount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---- while the key is off ------------------------------------------------------------------------------------------------

    public function test_it_reports_nothing_at_all_while_the_key_is_off(): void
    {
        $this->runMarketAccountMigration();
        $this->openAccount(MarketBalanceKind::Wallet, market: 'KW', currencyCode: 'KWD', amount: 100);

        $this->assertSame([], $this->checks());
    }

    // ---- while the key is on -------------------------------------------------------------------------------------------------

    public function test_before_the_migration_it_says_so_and_fails_nothing(): void
    {
        $this->withMarketAccountsOn();

        $checks = $this->checks();

        foreach (MarketBalanceKind::cases() as $kind) {
            $check = $this->named($checks, $kind->value . ' accounts by market', 'the accounts can be compared');
            $this->assertSame(CompatibilityCheck::INFO, $check->status);
            $this->assertStringContainsString('the expand migration has not run', $check->detail);
        }

        $this->assertSame([], array_values(array_filter($checks, static fn (CompatibilityCheck $check): bool => $check->failed())));
    }

    public function test_a_market_whose_accounts_hold_what_its_ledger_moved_passes(): void
    {
        $this->runMarketAccountMigration();
        $this->withMarketAccountsOn();
        $this->openAccount(MarketBalanceKind::Wallet, market: 'KW', currencyCode: 'KWD', amount: 70.5);
        $this->recordMovement('wallet_transactions', customerId: 1, market: 'KW', credit: 100);
        $this->recordMovement('wallet_transactions', customerId: 1, market: 'KW', debit: 29.5);

        $check = $this->named($this->checks(), 'wallet accounts by market', 'market KW: the accounts hold what their ledger moved');

        $this->assertSame(CompatibilityCheck::PASS, $check->status);
        $this->assertSame(['held' => '70.5', 'moved' => '70.5', 'difference' => '0'], $check->facts);
    }

    public function test_a_market_whose_accounts_do_not_hold_what_its_ledger_moved_fails_with_the_difference(): void
    {
        $this->runMarketAccountMigration();
        $this->withMarketAccountsOn();
        $this->openAccount(MarketBalanceKind::Wallet, market: 'KW', currencyCode: 'KWD', amount: 100);
        $this->recordMovement('wallet_transactions', customerId: 1, market: 'KW', credit: 70.5);

        $check = $this->named($this->checks(), 'wallet accounts by market', 'market KW: the accounts hold what their ledger moved');

        $this->assertSame(CompatibilityCheck::FAIL, $check->status);
        $this->assertSame('29.5', $check->facts['difference']);
    }

    public function test_a_market_with_nothing_recorded_passes(): void
    {
        $this->runMarketAccountMigration();
        $this->withMarketAccountsOn();

        foreach (['SA', 'AE', 'KW', 'QA', 'BH', 'OM'] as $market) {
            $this->assertSame(CompatibilityCheck::PASS, $this->named($this->checks(), 'wallet accounts by market', "market {$market}: the accounts hold what their ledger moved")->status);
        }
    }

    public function test_one_markets_ledger_rows_are_never_counted_against_another_markets_accounts(): void
    {
        $this->runMarketAccountMigration();
        $this->withMarketAccountsOn();
        $this->openAccount(MarketBalanceKind::Wallet, market: 'KW', currencyCode: 'KWD', amount: 100);
        $this->recordMovement('wallet_transactions', customerId: 1, market: 'KW', credit: 100);
        $this->recordMovement('wallet_transactions', customerId: 1, market: 'SA', credit: 40);

        $checks = $this->checks();

        $this->assertSame(CompatibilityCheck::PASS, $this->named($checks, 'wallet accounts by market', 'market KW: the accounts hold what their ledger moved')->status);
        $this->assertSame(CompatibilityCheck::FAIL, $this->named($checks, 'wallet accounts by market', 'market SA: the accounts hold what their ledger moved')->status, 'the Saudi ledger row has no Saudi account behind it');
        $this->assertSame('-40', $this->named($checks, 'wallet accounts by market', 'market SA: the accounts hold what their ledger moved')->facts['difference']);
    }

    public function test_the_ledger_rows_that_name_no_market_are_counted_not_judged(): void
    {
        $this->runMarketAccountMigration();
        $this->withMarketAccountsOn();
        $this->recordMovement('wallet_transactions', customerId: 1, market: null, credit: 300);
        $this->recordMovement('wallet_transactions', customerId: 1, market: null, debit: 49.5);

        $check = $this->named($this->checks(), 'wallet accounts by market', 'ledger rows that name no market');

        $this->assertSame(CompatibilityCheck::INFO, $check->status);
        $this->assertSame(['rows' => 2], $check->facts);
        $this->assertStringContainsString('net 250.5', $check->detail);
    }

    public function test_a_ledger_row_naming_a_market_this_platform_does_not_serve_is_warned_about(): void
    {
        $this->runMarketAccountMigration();
        $this->withMarketAccountsOn();
        DB::table('wallet_transactions')->insert(['user_id' => 1, 'transaction_id' => 'b0000000-0000-4000-8000-000000000001', 'credit' => 10, 'debit' => 0, 'balance' => 10, 'storefront_country' => 'US', 'created_at' => now(), 'updated_at' => now()]);

        $checks = $this->checks();
        $clean = $this->named($checks, 'loyalty accounts by market', 'ledger rows that name a market this platform does not serve');
        $dirty = $this->named($checks, 'wallet accounts by market', 'ledger rows that name a market this platform does not serve');

        $this->assertSame(CompatibilityCheck::PASS, $clean->status);
        $this->assertSame(CompatibilityCheck::WARN, $dirty->status);
        $this->assertSame(['rows' => 1], $dirty->facts);
    }

    public function test_the_wallet_and_the_loyalty_ledger_are_reconciled_apart(): void
    {
        $this->runMarketAccountMigration();
        $this->withMarketAccountsOn();
        $this->openAccount(MarketBalanceKind::Wallet, market: 'KW', currencyCode: 'KWD', amount: 100);
        $this->recordMovement('wallet_transactions', customerId: 1, market: 'KW', credit: 100);
        $this->openAccount(MarketBalanceKind::Loyalty, market: 'KW', currencyCode: 'KWD', amount: 60);
        $this->recordMovement('loyalty_point_transactions', customerId: 1, market: 'KW', credit: 20);

        $checks = $this->checks();

        $this->assertSame(CompatibilityCheck::PASS, $this->named($checks, 'wallet accounts by market', 'market KW: the accounts hold what their ledger moved')->status);
        $this->assertSame(CompatibilityCheck::FAIL, $this->named($checks, 'loyalty accounts by market', 'market KW: the accounts hold what their ledger moved')->status);
        $this->assertSame('40', $this->named($checks, 'loyalty accounts by market', 'market KW: the accounts hold what their ledger moved')->facts['difference']);
    }

    public function test_it_only_reads(): void
    {
        $this->runMarketAccountMigration();
        $this->withMarketAccountsOn();
        $this->openAccount(MarketBalanceKind::Wallet, market: 'KW', currencyCode: 'KWD', amount: 100);
        $before = [DB::table('users')->get()->toArray(), DB::table('customer_market_wallet_accounts')->get()->toArray()];

        $this->checks();

        $this->assertSame([], $this->probe->writeStatements(), 'nothing but reads and the session lock');
        $this->assertEquals($before, [DB::table('users')->get()->toArray(), DB::table('customer_market_wallet_accounts')->get()->toArray()]);
    }
}
