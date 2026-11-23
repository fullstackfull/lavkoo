<?php

namespace Tests\Feature\Storefront;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BuildsLedgerWorld;
use Tests\TestCase;

/**
 * E-05: the migration that lets a wallet or loyalty ledger row say what stands behind it. It is expand-only: nullable columns appended to two tables, nothing renamed,
 * nothing dropped, no backfill, safe to run twice, and a rollback that refuses to forget what the rows recorded.
 */
class LedgerAttributionMigrationTest extends TestCase
{
    use BuildsLedgerWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildLedgerWorld(attributed: false);
    }

    protected function tearDown(): void
    {
        $this->dropLedgerWorld();

        parent::tearDown();
    }

    private function legacyRow(): array
    {
        return ['user_id' => 1, 'transaction_id' => '9f2c1d3e-0000-4000-8000-000000000001', 'transaction_type' => 'order_place', 'reference' => 'order payment', 'created_at' => now(), 'updated_at' => now()];
    }

    /** @return list<string> the columns the migration adds to a ledger: a loyalty point has no currency */
    private static function columnsOf(string $table): array
    {
        return $table === 'wallet_transactions' ? self::LEDGER_COLUMNS : array_values(array_diff(self::LEDGER_COLUMNS, ['currency_code']));
    }

    /** @return array<string, array{string, string}> every column of every ledger the migration adds */
    public static function everyColumn(): array
    {
        $cases = [];

        foreach (['wallet_transactions', 'loyalty_point_transactions'] as $table) {
            foreach (self::columnsOf(table: $table) as $column) {
                $cases["{$table}.{$column}"] = [$table, $column];
            }
        }

        return $cases;
    }

    public function test_the_wallet_ledger_gets_seven_nullable_columns_and_the_loyalty_ledger_six_because_a_point_has_no_currency(): void
    {
        $this->ledgerMigration()->up();

        foreach (self::LEDGER_COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn('wallet_transactions', $column), "wallet_transactions.{$column}");
            $this->assertSame($column !== 'currency_code', Schema::hasColumn('loyalty_point_transactions', $column), "loyalty_point_transactions.{$column}");
        }
    }

    public function test_a_row_made_before_keeps_every_new_column_empty_and_a_row_of_the_old_shape_can_still_be_written(): void
    {
        DB::table('wallet_transactions')->insert($this->legacyRow() + ['credit' => 5]);
        DB::table('loyalty_point_transactions')->insert($this->legacyRow() + ['credit' => 5]);

        $this->ledgerMigration()->up();
        DB::table('wallet_transactions')->insert(['transaction_id' => '9f2c1d3e-0000-4000-8000-000000000002', 'created_at' => now(), 'updated_at' => now()]);

        foreach (['wallet_transactions', 'loyalty_point_transactions'] as $table) {
            foreach (DB::table($table)->get() as $row) {
                $this->assertSame([], array_filter(array_intersect_key((array) $row, array_flip(self::LEDGER_COLUMNS)), static fn ($value) => $value !== null), "{$table} row {$row->id}");
            }
        }
        $this->assertSame(2, DB::table('wallet_transactions')->count());
    }

    public function test_the_migration_runs_twice_and_adds_nothing_the_second_time(): void
    {
        $this->ledgerMigration()->up();
        $columns = [Schema::getColumnListing('wallet_transactions'), Schema::getColumnListing('loyalty_point_transactions')];

        $this->ledgerMigration()->up();

        $this->assertSame($columns, [Schema::getColumnListing('wallet_transactions'), Schema::getColumnListing('loyalty_point_transactions')]);
    }

    public function test_a_column_that_is_already_there_is_kept_and_the_others_are_still_added(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->string('source_type', 32)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
        });

        $this->ledgerMigration()->up();

        foreach (self::LEDGER_COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn('wallet_transactions', $column), $column);
        }
    }

    public function test_a_ledger_that_does_not_exist_is_skipped_and_not_created(): void
    {
        Schema::dropIfExists('loyalty_point_transactions');

        $this->ledgerMigration()->up();

        $this->assertFalse(Schema::hasTable('loyalty_point_transactions'));
        $this->assertTrue(Schema::hasColumn('wallet_transactions', 'source_type'));
    }

    public function test_the_country_is_compared_byte_for_byte_like_the_orders_country(): void
    {
        $this->ledgerMigration()->up();
        DB::table('wallet_transactions')->insert(['transaction_id' => '9f2c1d3e-0000-4000-8000-000000000003', 'storefront_country' => 'KW']);

        $this->assertSame(1, DB::table('wallet_transactions')->where('storefront_country', 'KW')->count());
        $this->assertSame(0, DB::table('wallet_transactions')->where('storefront_country', 'kw')->count(), 'a lower-case code is another value');
    }

    public function test_the_rollback_drops_only_the_columns_of_this_migration_and_keeps_every_other_column_and_row(): void
    {
        $this->ledgerMigration()->up();
        DB::table('wallet_transactions')->insert($this->legacyRow() + ['credit' => 5, 'payment_method' => 'wallet']);
        $before = array_values(array_diff(Schema::getColumnListing('wallet_transactions'), self::LEDGER_COLUMNS));

        $this->ledgerMigration()->down();

        $this->assertSame($before, Schema::getColumnListing('wallet_transactions'));
        $this->assertSame(['order payment', 'wallet'], [DB::table('wallet_transactions')->value('reference'), DB::table('wallet_transactions')->value('payment_method')]);
        foreach (self::LEDGER_COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('wallet_transactions', $column), $column);
            $this->assertFalse(Schema::hasColumn('loyalty_point_transactions', $column), $column);
        }
    }

    #[DataProvider('everyColumn')]
    public function test_the_rollback_is_refused_while_any_row_carries_a_record_in_any_of_the_columns(string $table, string $column): void
    {
        $this->ledgerMigration()->up();
        DB::table($table)->insert(['transaction_id' => '9f2c1d3e-0000-4000-8000-000000000004', $column => in_array($column, ['actor_id', 'legal_entity_id'], true) ? 7 : 'KW']);

        try {
            $this->ledgerMigration()->down();
            $this->fail('the rollback has to be refused');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Rolling this migration back would forget it', $exception->getMessage());
        }

        foreach ($this->columnsOf(table: $table) as $each) {
            $this->assertTrue(Schema::hasColumn($table, $each), "nothing was dropped: {$each}");
        }

        DB::table($table)->update([$column => null]);
        $this->ledgerMigration()->down();
        $this->assertFalse(Schema::hasColumn($table, $column));
    }

    public function test_the_rollback_of_a_database_that_never_ran_the_migration_does_nothing(): void
    {
        $before = Schema::getColumnListing('wallet_transactions');

        $this->ledgerMigration()->down();

        $this->assertSame($before, Schema::getColumnListing('wallet_transactions'));
    }
}
