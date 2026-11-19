<?php

namespace Tests\Feature\Wallet;

use App\Enums\Wallet\MarketBalanceKind;
use App\Enums\Wallet\MarketBalanceRefusal;
use App\Services\Wallet\CustomerMarketBalances;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMarketAccountSchema;
use Tests\TestCase;

/**
 * What the market accounts key means while it is off, which is how it ships (GCC-15; E-01's fourth criterion, E-07): a balance is the one
 * the `users` row has always held, whatever market is asked for, and not one query goes near an account table — not even the question
 * whether it is there. A credit or a debit is refused as dormant, because the writers of that one balance are still its only authority.
 *
 * The same holds when the key is on but the expand migration has not run: the code ships before its migration and must not fail then.
 */
class MarketAccountDormancyTest extends TestCase
{
    use BuildsMarketAccountSchema;

    private const SAUDI_WALLET = 250.5;

    private const SAUDI_POINTS = 80.0;

    private CustomerMarketBalances $balances;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMarketAccountSchema();
        $this->seedCustomer(1, walletBalance: self::SAUDI_WALLET, loyaltyPoints: self::SAUDI_POINTS);
        $this->balances = app(CustomerMarketBalances::class);
    }

    /** @return array<string, array{MarketBalanceKind, float}> */
    public static function balancesAndWhatTheUsersRowHolds(): array
    {
        return [
            'the wallet' => [MarketBalanceKind::Wallet, self::SAUDI_WALLET],
            'the loyalty points' => [MarketBalanceKind::Loyalty, self::SAUDI_POINTS],
        ];
    }

    /** @return list<string> every statement the connection ran while the work was done */
    private function statementsOf(callable $work): array
    {
        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        $work();

        return $statements;
    }

    /** @param list<string> $statements */
    private function assertNothingNewWasQueried(array $statements): void
    {
        foreach (['customer_market_wallet_accounts', 'customer_market_loyalty_accounts'] as $accounts) {
            $this->assertSame([], array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, $accounts))), "no statement reads {$accounts}");
        }

        $this->assertSame(
            [],
            array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'sqlite_master') || str_contains($sql, 'information_schema') || str_starts_with($sql, 'pragma'))),
            'the database is never asked whether the account tables are there',
        );
    }

    // ---- the key is off ------------------------------------------------------------------------------------------------------

    #[DataProvider('balancesAndWhatTheUsersRowHolds')]
    public function test_every_market_reads_the_one_balance_the_users_row_holds(MarketBalanceKind $kind, float $held): void
    {
        $this->runMarketAccountMigration();

        foreach (['SA', 'AE', 'KW', 'QA', 'BH', 'OM'] as $market) {
            $this->assertSame($held, $this->balances->amount(kind: $kind, customerId: 1, market: $market), "market {$market}");
        }
    }

    #[DataProvider('balancesAndWhatTheUsersRowHolds')]
    public function test_a_read_touches_no_account_table_even_once_the_migration_has_run(MarketBalanceKind $kind): void
    {
        $this->runMarketAccountMigration();

        $statements = $this->statementsOf(fn () => $this->balances->amount(kind: $kind, customerId: 1, market: 'KW'));

        $this->assertNotSame([], $statements, 'the one balance is still read');
        $this->assertNothingNewWasQueried($statements);
    }

    #[DataProvider('balancesAndWhatTheUsersRowHolds')]
    public function test_a_credit_and_a_debit_are_refused_as_dormant_and_write_nothing(MarketBalanceKind $kind, float $held): void
    {
        $this->runMarketAccountMigration();

        $statements = $this->statementsOf(function () use ($kind): void {
            foreach ([$this->balances->credit(kind: $kind, customerId: 1, market: 'KW', amount: 10), $this->balances->debit(kind: $kind, customerId: 1, market: 'KW', amount: 10)] as $change) {
                $this->assertFalse($change->applied);
                $this->assertSame(MarketBalanceRefusal::Dormant, $change->refusal);
            }
        });

        $this->assertNothingNewWasQueried($statements);
        $this->assertSame([], array_values(array_filter($statements, static fn (string $sql): bool => str_starts_with($sql, 'update') || str_starts_with($sql, 'insert'))), 'nothing is written');
        $this->assertSame($held, (float) DB::table('users')->where('id', 1)->value($kind->legacyColumn()), 'the one balance is untouched');
        $this->assertSame(0, DB::table($kind->accountsTable())->count(), 'no account is opened');
    }

    public function test_the_saudi_wallet_and_ledger_are_exactly_what_they_were(): void
    {
        $this->runMarketAccountMigration();
        $this->recordMovement('wallet_transactions', customerId: 1, market: null, credit: 300);
        $this->recordMovement('wallet_transactions', customerId: 1, market: null, debit: 49.5);
        $ledger = DB::table('wallet_transactions')->orderBy('id')->get()->toArray();

        $this->balances->credit(kind: MarketBalanceKind::Wallet, customerId: 1, market: 'SA', amount: 100);

        $this->assertSame(self::SAUDI_WALLET, (float) DB::table('users')->where('id', 1)->value('wallet_balance'));
        $this->assertSame(self::SAUDI_WALLET, $this->balances->amount(kind: MarketBalanceKind::Wallet, customerId: 1, market: 'SA'));
        $this->assertEquals($ledger, DB::table('wallet_transactions')->orderBy('id')->get()->toArray(), 'no ledger row is added or changed');
    }

    public function test_the_key_is_off_in_the_configuration_this_repository_ships(): void
    {
        $this->assertFalse((bool) config('gcc.storefronts.market_accounts.enabled'));
        $this->assertFalse(app(CustomerMarketBalances::class)->isEnabled());
    }

    public function test_the_key_alone_is_not_enough_while_storefronts_are_off(): void
    {
        config(['gcc.storefronts.enabled' => false, 'gcc.storefronts.market_accounts.enabled' => true]);

        $this->assertFalse($this->balances->isEnabled());
    }

    // ---- the key is on, the migration has not run ----------------------------------------------------------------------------

    #[DataProvider('balancesAndWhatTheUsersRowHolds')]
    public function test_before_the_migration_a_read_answers_from_the_users_row_and_a_write_is_dormant(MarketBalanceKind $kind, float $held): void
    {
        $this->withMarketAccountsOn();

        $this->assertFalse(Schema::hasTable($kind->accountsTable()), 'the table is not there yet');
        $this->assertSame($held, $this->balances->amount(kind: $kind, customerId: 1, market: 'KW'));

        $change = $this->balances->credit(kind: $kind, customerId: 1, market: 'KW', amount: 10);

        $this->assertFalse($change->applied);
        $this->assertSame(MarketBalanceRefusal::Dormant, $change->refusal);
        $this->assertSame($held, (float) DB::table('users')->where('id', 1)->value($kind->legacyColumn()));
    }
}
