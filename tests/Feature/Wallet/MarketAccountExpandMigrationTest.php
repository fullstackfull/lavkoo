<?php

namespace Tests\Feature\Wallet;

use App\Enums\Wallet\MarketBalanceKind;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BuildsMarketAccountSchema;
use Tests\Support\CapturesTableState;
use Tests\TestCase;

/**
 * The expand migration of the per-market wallet and loyalty accounts (E-07, first criterion): it adds only nullable columns and empty
 * tables, changes no existing row, runs twice and rolls back.
 *
 * It runs against the two ledgers as the platform's own migrations leave them, on whichever engine the suite is pointed at.
 */
class MarketAccountExpandMigrationTest extends TestCase
{
    use BuildsMarketAccountSchema;
    use CapturesTableState;

    private const LEDGERS = ['wallet_transactions', 'loyalty_point_transactions'];

    private const ACCOUNTS = ['customer_market_wallet_accounts', 'customer_market_loyalty_accounts'];

    /** Every column the migration adds to a ledger. The rest of a ledger row's attribution belongs to E-05 (see the migration's docblock). */
    private const ADDED_COLUMNS = ['storefront_country'];

    /** The E-05 migration that owns a ledger row's attribution wherever it has run. */
    private const ATTRIBUTION_MIGRATION = '2026_12_05_100000_add_attribution_to_wallet_and_loyalty_ledgers';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMarketAccountSchema();
    }

    /** @return array<string, array{string}> */
    public static function ledgers(): array
    {
        return ['the wallet ledger' => ['wallet_transactions'], 'the loyalty ledger' => ['loyalty_point_transactions']];
    }

    /** @return array<string, array{string, string}> */
    public static function accountTables(): array
    {
        return [
            'the wallet accounts' => ['customer_market_wallet_accounts', 'balance'],
            'the loyalty accounts' => ['customer_market_loyalty_accounts', 'points'],
        ];
    }

    private function seedTheStoreAsItIsToday(): void
    {
        $this->seedCustomer(1, walletBalance: 250.5, loyaltyPoints: 80);
        $this->seedCustomer(2, walletBalance: 0, loyaltyPoints: 0);

        foreach (self::LEDGERS as $ledger) {
            DB::table($ledger)->insert([
                ['user_id' => 1, 'transaction_id' => 'a0000000-0000-4000-8000-000000000001', 'credit' => 300, 'debit' => 0, 'balance' => 300, 'transaction_type' => 'add_fund', 'created_at' => now(), 'updated_at' => now()],
                ['user_id' => 1, 'transaction_id' => 'a0000000-0000-4000-8000-000000000002', 'credit' => 0, 'debit' => 49.5, 'balance' => 250.5, 'transaction_type' => 'order_place', 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    // ---- only nullable columns and empty tables ------------------------------------------------------------------------------

    #[DataProvider('ledgers')]
    public function test_every_column_the_migration_adds_to_a_ledger_is_nullable_with_no_default(string $ledger): void
    {
        $before = $this->columnsOf($ledger);

        $this->runMarketAccountMigration();

        $after = $this->columnsOf($ledger);
        $added = array_diff(array_keys($after), array_keys($before));

        $this->assertSame(self::ADDED_COLUMNS, array_values($added), 'the market column and nothing else');
        $this->assertSame($before, array_intersect_key($after, $before), 'no column that was there is changed');

        foreach (self::ADDED_COLUMNS as $column) {
            $this->assertTrue($after[$column]['nullable'], "{$column} is nullable");
            // MySQL and MariaDB report the absent default of a nullable column as the word NULL, SQLite as nothing at all.
            $this->assertContains($after[$column]['default'], [null, 'NULL'], "{$column} has no default but null");
        }

        DB::table($ledger)->insert(['user_id' => 1, 'transaction_id' => 'c0000000-0000-4000-8000-000000000001', 'credit' => 1, 'debit' => 0, 'balance' => 1]);
        $written = (array) DB::table($ledger)->orderByDesc('id')->first();

        foreach (self::ADDED_COLUMNS as $column) {
            $this->assertNull($written[$column], "a writer that says nothing about {$column} leaves it empty");
        }
    }

    #[DataProvider('accountTables')]
    public function test_an_account_table_is_created_empty(string $accounts): void
    {
        $this->assertFalse(Schema::hasTable($accounts), 'it is not there before the migration');

        $this->runMarketAccountMigration();

        $this->assertTrue(Schema::hasTable($accounts));
        $this->assertSame(0, DB::table($accounts)->count(), 'nothing is backfilled');
    }

    #[DataProvider('accountTables')]
    public function test_an_account_is_unique_per_customer_and_market(string $accounts, string $amountColumn): void
    {
        $this->runMarketAccountMigration();

        $row = ['customer_id' => 7, 'market' => 'KW', 'currency_code' => 'KWD', $amountColumn => 0, 'created_at' => now(), 'updated_at' => now()];
        DB::table($accounts)->insert($row);

        $this->expectException(QueryException::class);
        DB::table($accounts)->insert($row);
    }

    #[DataProvider('accountTables')]
    public function test_one_customer_may_hold_an_account_in_every_market(string $accounts, string $amountColumn): void
    {
        $this->runMarketAccountMigration();

        foreach (['SA' => 'SAR', 'AE' => 'AED', 'KW' => 'KWD', 'QA' => 'QAR', 'BH' => 'BHD', 'OM' => 'OMR'] as $market => $currency) {
            DB::table($accounts)->insert(['customer_id' => 7, 'market' => $market, 'currency_code' => $currency, $amountColumn => 0, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->assertSame(6, DB::table($accounts)->where('customer_id', 7)->count());
    }

    // ---- no existing row is changed ------------------------------------------------------------------------------------------

    public function test_not_one_existing_row_is_read_differently_after_the_migration(): void
    {
        $this->seedTheStoreAsItIsToday();
        $before = ['users' => $this->rowsOf('users'), ...array_combine(self::LEDGERS, array_map($this->rowsOf(...), self::LEDGERS))];

        $this->runMarketAccountMigration();

        $this->assertEquals(
            $before,
            ['users' => $this->rowsOf('users'), ...array_combine(self::LEDGERS, array_map(
                fn (string $ledger): array => array_map(
                    static fn (array $row): array => array_diff_key($row, array_flip(self::ADDED_COLUMNS)),
                    $this->rowsOf($ledger),
                ),
                self::LEDGERS,
            ))],
        );
    }

    public function test_the_attribution_columns_are_empty_on_every_row_that_existed(): void
    {
        $this->seedTheStoreAsItIsToday();

        $this->runMarketAccountMigration();

        foreach (self::LEDGERS as $ledger) {
            foreach (self::ADDED_COLUMNS as $column) {
                $this->assertSame(0, DB::table($ledger)->whereNotNull($column)->count(), "{$ledger}.{$column} is empty");
            }
        }
    }

    // ---- it runs twice -------------------------------------------------------------------------------------------------------

    public function test_running_it_again_changes_nothing_and_does_not_fail(): void
    {
        $this->seedTheStoreAsItIsToday();
        $this->runMarketAccountMigration();
        DB::table('customer_market_wallet_accounts')->insert(['customer_id' => 1, 'market' => 'KW', 'currency_code' => 'KWD', 'balance' => 12.5, 'created_at' => now(), 'updated_at' => now()]);
        $before = $this->schemaAndRows();

        $this->runMarketAccountMigration();

        $this->assertEquals($before, $this->schemaAndRows());
    }

    // ---- it rolls back -------------------------------------------------------------------------------------------------------

    public function test_it_rolls_back_to_exactly_the_database_it_found(): void
    {
        $this->seedTheStoreAsItIsToday();
        $before = $this->schemaAndRows();

        $this->runMarketAccountMigration();
        $this->rollBackMarketAccountMigration();

        $this->assertEquals($before, $this->schemaAndRows());

        foreach (self::ACCOUNTS as $accounts) {
            $this->assertFalse(Schema::hasTable($accounts), "{$accounts} is gone");
        }
    }

    public function test_rolling_back_is_refused_while_a_customer_holds_a_market_account(): void
    {
        $this->seedTheStoreAsItIsToday();
        $this->runMarketAccountMigration();
        DB::table('customer_market_wallet_accounts')->insert(['customer_id' => 1, 'market' => 'KW', 'currency_code' => 'KWD', 'balance' => 12.5, 'created_at' => now(), 'updated_at' => now()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('would forget their balances');

        try {
            $this->rollBackMarketAccountMigration();
        } finally {
            $this->assertTrue(Schema::hasTable('customer_market_wallet_accounts'), 'nothing is dropped before the refusal');
            $this->assertSame(12.5, (float) DB::table('customer_market_wallet_accounts')->value('balance'));
        }
    }

    #[DataProvider('ledgers')]
    public function test_rolling_back_is_refused_while_a_ledger_row_names_its_market(string $ledger): void
    {
        $this->runMarketAccountMigration();
        $this->seedCustomer(1, walletBalance: 30);
        $this->recordMovement($ledger, customerId: 1, market: 'KW', credit: 30);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('would forget which market they were');

        try {
            $this->rollBackMarketAccountMigration();
        } finally {
            $this->assertTrue(Schema::hasColumn($ledger, MarketBalanceKind::MARKET_COLUMN), 'nothing is dropped before the refusal');
        }
    }

    // ---- where the E-05 attribution migration has run -------------------------------------------------------------------------

    public function test_it_adds_nothing_to_a_ledger_the_attribution_migration_has_already_attributed(): void
    {
        $this->recordTheAttributionMigration();
        $before = array_map($this->columnsOf(...), array_combine(self::LEDGERS, self::LEDGERS));

        $this->runMarketAccountMigration();

        $this->assertSame($before, array_map($this->columnsOf(...), array_combine(self::LEDGERS, self::LEDGERS)), 'the ledgers are left exactly as that migration left them');
    }

    public function test_rolling_back_leaves_every_ledger_column_the_attribution_migration_owns(): void
    {
        $this->recordTheAttributionMigration();
        $this->runMarketAccountMigration();
        $this->seedCustomer(1, walletBalance: 100);
        // A Saudi top-up as that stream records one: the default storefront leaves the market empty and the currency filled.
        DB::table('wallet_transactions')->insert(['user_id' => 1, 'transaction_id' => 'd0000000-0000-4000-8000-000000000001', 'credit' => 100, 'debit' => 0, 'balance' => 100, 'transaction_type' => 'add_fund', 'storefront_country' => null, 'currency_code' => 'SAR', 'legal_entity_id' => 4, 'created_at' => now(), 'updated_at' => now()]);

        $this->rollBackMarketAccountMigration();

        foreach (self::ACCOUNTS as $accounts) {
            $this->assertFalse(Schema::hasTable($accounts), "{$accounts} is gone: this migration's own tables are dropped");
        }

        foreach (self::LEDGERS as $ledger) {
            $this->assertTrue(Schema::hasColumn($ledger, MarketBalanceKind::MARKET_COLUMN), "{$ledger} keeps the column that migration owns");
        }

        $row = (array) DB::table('wallet_transactions')->first();
        $this->assertSame('SAR', $row['currency_code'], 'the recorded currency of a real wallet movement is still there');
        $this->assertSame(4, (int) $row['legal_entity_id']);
    }

    // ---- the indexes the accounts are read by ---------------------------------------------------------------------------------

    #[DataProvider('accountTables')]
    public function test_an_account_table_carries_the_indexes_named_after_it(string $accounts): void
    {
        $this->runMarketAccountMigration();

        $names = array_map(static fn (array $index): string => (string) $index['name'], Schema::getIndexes($accounts));

        $this->assertContains($accounts . '_customer_market_unique', $names);
        $this->assertContains($accounts . '_market_idx', $names);
    }

    /**
     * The database of a platform that has run the E-05 ledger attribution migration (gcc/p1-wallet-ledger-attribution): its columns on both
     * ledgers, and its name in the `migrations` table, which is how this migration knows the ledger columns are not its to drop.
     */
    private function recordTheAttributionMigration(): void
    {
        Schema::create('migrations', function (Blueprint $table): void {
            $table->id();
            $table->string('migration');
            $table->integer('batch');
        });
        DB::table('migrations')->insert(['migration' => self::ATTRIBUTION_MIGRATION, 'batch' => 1]);

        foreach (self::LEDGERS as $ledger) {
            Schema::table($ledger, function (Blueprint $table) use ($ledger): void {
                $table->char(MarketBalanceKind::MARKET_COLUMN, 2)->nullable();
                $table->unsignedBigInteger('legal_entity_id')->nullable();

                if ($ledger === 'wallet_transactions') {
                    $table->string('currency_code', 8)->nullable();
                }
            });
        }
    }

    /** @return array<string, mixed> the shape of every table the migration touches, the indexes on it and every row in it */
    private function schemaAndRows(): array
    {
        return $this->stateOf(tables: ['users', ...self::LEDGERS, ...self::ACCOUNTS]);
    }
}
