<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * The two dormant inventory tables and rows in them, for the tests of what the platform reports about promised and divided stock.
 *
 * The tables are built by their REAL migrations rather than by a shape inferred here, so that a column these figures read cannot drift away from the
 * one the application creates. Both migrations are additive and guarded by `hasTable`, so running them adds nothing a world already has.
 */
trait BuildsInventoryHolds
{
    protected function buildInventoryTables(): void
    {
        (require database_path('migrations/2026_11_07_100200_create_inventory_and_routing_tables.php'))->up();
        (require database_path('migrations/2026_11_10_100000_add_stock_allocation.php'))->up();
    }

    /** One hold of stock for an order. Only a `reserved` one holds anything: `committed` and `released` are the two correct ends of a hold's life. */
    protected function hold(int $product, int $quantity, string $state = 'reserved'): void
    {
        DB::table('inventory_reservations')->insert([
            'location_id' => 1,
            'product_id' => $product,
            'variant_key' => '',
            'quantity' => $quantity,
            'reservation_key' => 'hold-' . DB::table('inventory_reservations')->count() . '-' . $product . '-' . $state,
            'state' => $state,
            'reserved_at' => '2026-11-16 10:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** One pool of a product's stock, with what the allocator could not satisfy written on it. */
    protected function pool(int $product, int $deficit): void
    {
        DB::table('inventory_pools')->insert([
            'location_id' => 1,
            'product_id' => $product,
            'variant_key' => 'v' . DB::table('inventory_pools')->count(),
            'deficit' => $deficit,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
