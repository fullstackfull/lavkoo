<?php

namespace Tests\Feature\Wallet;

use App\Enums\Wallet\MarketBalanceKind;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BuildsMarketAccountSchema;
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

    private const LEDGERS = ['wallet_transactions', 'loyalty_point_transactions'];

    private const ACCOUNTS = ['customer_market_wallet_accounts', 'customer_market_loyalty_accounts'];

    private const ADDED_COLUMNS = ['storefront_country', 'currency_code', 'legal_entity'];

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

    /** @return array<string, array<string, mixed>> every column of a table by name, with the facts a change of shape would alter */
    private function columns(string $table): array
    {
        $facts = [];

        foreach (Schema::getColumns($table) as $column) {
            $facts[$column['name']] = [
                'type' => $column['type'],
                'nullable' => $column['nullable'],
                'default' => $column['default'],
                'auto_increment' => $column['auto_increment'] ?? false,
            ];
        }

        return $facts;
    }

    /** @return list<array<string, mixed>> */
    private function rowsOf(string $table): array
    {
        return array_map(static fn (object $row): array => (array) $row, DB::table($table)->orderBy('id')->get()->all());
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
        $before = $this->columns($ledger);

        $this->runMarketAccountMigration();

        $after = $this->columns($ledger);
        $added = array_diff(array_keys($after), array_keys($before));

        $this->assertSame(self::ADDED_COLUMNS, array_values($added), 'the three attribution columns and nothing else');
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

    /** @return array<string, mixed> the shape of every table the migration touches, and every row in it */
    private function schemaAndRows(): array
    {
        $state = [];

        foreach (['users', ...self::LEDGERS, ...self::ACCOUNTS] as $table) {
            $state[$table] = Schema::hasTable($table) ? ['columns' => $this->columns($table), 'rows' => $this->rowsOf($table)] : null;
        }

        return $state;
    }
}
