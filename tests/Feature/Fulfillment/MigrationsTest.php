<?php

namespace Tests\Feature\Fulfillment;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\TestCase;

/**
 * The feature's two migrations are additive, idempotent and fully reversible, and they leave the store's
 * own data untouched. Runs on whichever database the suite is pointed at (SQLite locally, MySQL in CI).
 */
class MigrationsTest extends TestCase
{
    use BuildsFulfillmentSchema;

    private const MIGRATIONS = [
        '2026_11_05_100000_create_provider_fulfillment_tables.php',
        '2026_11_05_100100_add_fulfillment_provider_to_products_table.php',
    ];

    private const NEW_TABLES = [
        'provider_fulfillments', 'provider_fulfillment_items', 'provider_fulfillment_events', 'provider_api_logs',
        'provider_sync_states', 'provider_product_mappings', 'provider_catalog_items', 'provider_price_changes',
        'provider_cities', 'provider_city_aliases', 'product_provider_changes',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCoreSchema();
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

    public function test_up_adds_only_new_tables_and_one_nullable_column_and_changes_no_existing_row(): void
    {
        DB::table('products')->insert(['id' => 1, 'name' => 'Existing', 'code' => 'E1', 'added_by' => 'admin', 'product_type' => 'physical', 'unit_price' => 10, 'current_stock' => 5, 'variation' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $columnsBefore = Schema::getColumnListing('products');
        $ordersColumnsBefore = Schema::getColumnListing('orders');

        $this->up();

        foreach (self::NEW_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertSame(['fulfillment_provider'], array_values(array_diff(Schema::getColumnListing('products'), $columnsBefore)));
        $this->assertSame($ordersColumnsBefore, Schema::getColumnListing('orders'), 'orders is not touched');
        $this->assertNull(DB::table('products')->where('id', 1)->value('fulfillment_provider'), 'existing products stay in-house');
        $this->assertSame('Existing', DB::table('products')->where('id', 1)->value('name'));
    }

    public function test_up_is_idempotent(): void
    {
        $this->up();
        $this->up();

        $this->assertTrue(Schema::hasTable('provider_fulfillments'));
        $this->assertTrue(Schema::hasColumn('products', 'fulfillment_provider'));
    }

    public function test_the_provider_column_is_indexed(): void
    {
        $this->up();

        $indexes = collect(Schema::getIndexes('products'))->pluck('name')->all();
        $this->assertContains('products_fulfillment_provider_idx', $indexes);
    }

    public function test_down_removes_exactly_what_up_added_and_keeps_the_store_data(): void
    {
        DB::table('products')->insert(['id' => 1, 'name' => 'Existing', 'code' => 'E1', 'added_by' => 'admin', 'product_type' => 'physical', 'unit_price' => 10, 'current_stock' => 5, 'variation' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $columnsBefore = Schema::getColumnListing('products');
        $this->up();
        DB::table('products')->where('id', 1)->update(['fulfillment_provider' => 'torod_point']);

        $this->down();

        foreach (self::NEW_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} should be gone");
        }
        $this->assertSame($columnsBefore, Schema::getColumnListing('products'));
        $this->assertSame('Existing', DB::table('products')->where('id', 1)->value('name'), 'rolling back loses no store data');
        $this->assertSame(5, (int) DB::table('products')->where('id', 1)->value('current_stock'));
    }

    public function test_the_shared_integration_settings_table_survives_a_rollback(): void
    {
        $this->up();
        DB::table('integration_settings')->insert(['provider' => 'chatwoot', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->down();

        $this->assertTrue(Schema::hasTable('integration_settings'), 'other integrations keep their settings');
        $this->assertSame(1, DB::table('integration_settings')->where('provider', 'chatwoot')->count());
    }

    public function test_the_migrations_can_run_again_after_a_rollback(): void
    {
        $this->up();
        $this->down();
        $this->up();

        $this->assertTrue(Schema::hasTable('provider_fulfillment_items'));
        $this->assertTrue(Schema::hasColumn('products', 'fulfillment_provider'));
    }

    public function test_the_ownership_and_idempotency_guards_exist_as_database_constraints(): void
    {
        $this->up();

        $unique = static fn (string $table): array => collect(Schema::getIndexes($table))->filter(fn ($index) => $index['unique'])->map(fn ($index) => implode(',', $index['columns']))->all();

        $this->assertContains('order_id,provider,generation', $unique('provider_fulfillments'));
        $this->assertContains('idempotency_key', $unique('provider_fulfillments'));
        $this->assertContains('order_detail_id', $unique('provider_fulfillment_items'));
        $this->assertContains('dedupe_key', $unique('provider_fulfillment_events'));
        $this->assertContains('provider,external_id,external_variant_id', $unique('provider_product_mappings'));
    }
}
