<?php

namespace Tests\Feature\SalesChannels;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\TestCase;

/**
 * The module's migrations are additive, idempotent and reversible, and they leave the store's own tables and data alone. Runs on whichever database the suite
 * is pointed at (SQLite locally, MySQL in CI).
 */
class MigrationsTest extends TestCase
{
    use BuildsFulfillmentSchema;

    private const MIGRATIONS = [
        '2026_11_07_100000_create_operational_cost_tables.php',
        '2026_11_07_100100_create_sales_channel_tables.php',
        '2026_11_07_100200_create_inventory_and_routing_tables.php',
        '2026_11_08_100000_add_state_before_dispute_to_operational_cost_entries.php',
    ];

    private const NEW_TABLES = [
        'operational_cost_contracts', 'operational_cost_contract_revisions', 'operational_cost_rules', 'operational_cost_entries', 'operational_cost_allocations',
        'operational_cost_invoices', 'operational_cost_invoice_items', 'operational_cost_reconciliations', 'inventory_cost_layers', 'inventory_cost_consumptions',
        'sales_channel_accounts', 'sales_channel_product_mappings', 'external_order_mappings', 'external_order_lines', 'sales_channel_sync_states', 'sales_channel_api_logs',
        'sales_channel_events', 'inventory_locations', 'inventory_location_balances', 'inventory_source_policies', 'inventory_reservations', 'fulfillment_routing_rules',
        'fulfillment_routing_decisions',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCoreSchema();
        $this->runFulfillmentMigrations();
    }

    private function up(): void
    {
        foreach (self::MIGRATIONS as $file) {
            (require database_path('migrations/' . $file))->up();
        }
    }

    private function down(): void
    {
        foreach (array_reverse(self::MIGRATIONS) as $file) {
            (require database_path('migrations/' . $file))->down();
        }
    }

    /** @return array<string, list<string>> every table of THIS database with its columns (a server also holds other databases, which must not count) */
    private function schema(): array
    {
        $tables = [];
        foreach (Schema::getTables(schema: Schema::getCurrentSchemaListing()) as $table) {
            $tables[$table['name']] = Schema::getColumnListing($table['name']);
        }
        ksort($tables);

        return $tables;
    }

    public function test_up_adds_only_new_tables_and_alters_no_table_that_was_there(): void
    {
        DB::table('products')->insert(['id' => 1, 'name' => 'Existing', 'code' => 'E1', 'added_by' => 'admin', 'product_type' => 'physical', 'unit_price' => 10, 'current_stock' => 5, 'variation' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $before = $this->schema();

        $this->up();

        $after = $this->schema();
        $this->assertSame($this->sorted(self::NEW_TABLES), $this->sorted(array_diff(array_keys($after), array_keys($before))), 'Exactly the module\'s own tables were added.');
        foreach ($before as $table => $columns) {
            $this->assertSame($columns, $after[$table], "{$table} was altered");
        }
        $this->assertSame('Existing', DB::table('products')->where('id', 1)->value('name'));
        $this->assertSame(5, (int) DB::table('products')->where('id', 1)->value('current_stock'));
    }

    /** @param iterable<string> $values @return list<string> */
    private function sorted(iterable $values): array
    {
        $list = [...$values];
        sort($list);

        return $list;
    }

    public function test_up_is_idempotent(): void
    {
        $this->up();
        $before = $this->schema();

        $this->up();

        $this->assertSame($before, $this->schema());
    }

    public function test_down_removes_exactly_what_up_added_and_keeps_the_store_data(): void
    {
        DB::table('products')->insert(['id' => 1, 'name' => 'Existing', 'code' => 'E1', 'added_by' => 'admin', 'product_type' => 'physical', 'unit_price' => 10, 'current_stock' => 5, 'variation' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $before = $this->schema();
        $this->up();

        $this->down();

        $this->assertSame($before, $this->schema(), 'Rolling back leaves the database exactly as it was before the migrations.');
        $this->assertSame('Existing', DB::table('products')->where('id', 1)->value('name'));
    }

    public function test_the_migrations_can_run_again_after_a_rollback(): void
    {
        $this->up();
        $this->down();
        $this->up();

        foreach (self::NEW_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertTrue(Schema::hasColumn('operational_cost_entries', 'state_before_dispute'));
    }

    public function test_the_last_migration_alone_only_adds_one_nullable_column_and_removes_only_it(): void
    {
        foreach (array_slice(self::MIGRATIONS, 0, 3) as $file) {
            (require database_path('migrations/' . $file))->up();
        }
        $columns = Schema::getColumnListing('operational_cost_entries');
        DB::table('operational_cost_entries')->insert([
            'dedupe_key' => 'd', 'slot_key' => 's', 'subject_type' => 'order', 'subject_id' => 1, 'period_date' => '2026-11-10', 'cost_component' => 'cogs', 'cost_class' => 'product_cost',
            'state' => 'actual', 'amount' => '5.000000', 'currency' => 'SAR', 'source' => 'rule',
        ]);
        $last = require database_path('migrations/' . self::MIGRATIONS[3]);

        $last->up();
        $this->assertSame(['state_before_dispute'], array_values(array_diff(Schema::getColumnListing('operational_cost_entries'), $columns)));
        $this->assertNull(DB::table('operational_cost_entries')->value('state_before_dispute'), 'Existing entries stay as they were.');

        $last->down();
        $this->assertSame($columns, Schema::getColumnListing('operational_cost_entries'));
        $this->assertSame(1, DB::table('operational_cost_entries')->count(), 'The rows survive.');
    }

    public function test_the_guards_against_a_second_row_exist_as_database_constraints(): void
    {
        $this->up();
        $unique = static fn (string $table): array => collect(Schema::getIndexes($table))->filter(fn ($index) => $index['unique'])->map(fn ($index) => implode(',', $index['columns']))->all();

        $this->assertContains('dedupe_key', $unique('operational_cost_entries'));
        $this->assertContains('provider,shipment_reference', $unique('external_order_mappings'));
        $this->assertContains('dedupe_key', $unique('sales_channel_events'));
    }
}
