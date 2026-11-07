<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dividing one physical stock between the channels that offer it. One new table, and a few nullable columns on the module's own tables: nothing is added to a platform table,
 * no foreign key is created, and every column that is added leaves today's behaviour as it is until somebody sets it.
 *
 *  - `inventory_pools`: one row per pool (a product, or one variant of it, at one location). It is the row the allocation is serialised on (taken `FOR UPDATE` by the single
 *    writer), and it holds the pool's own settings (the storefront's reserve and a safety buffer), a version that moves whenever the division changes, the last net and deficit,
 *    and the moment an order last changed what the pool is promised.
 *  - `stock_share_mode`, `stock_share_value`, `stock_priority` on listings and on source policies: how a listing takes its part of the pool.
 *  - `allocation` on listings: the last division of the pool, as it concerns this listing. A column of its own: the snapshot is rewritten as a whole by the publisher, the
 *    follow-up of a publish and the listing sync, and a division written into it could be lost to any of them.
 *  - `sales_channel` and `account_id` on reservations, with the index a pool reads them by: which channel promised how many units, and how many in the last hours.
 */
return new class extends Migration
{
    private const SHARE_TABLES = ['sales_channel_product_mappings', 'inventory_source_policies'];

    private const RESERVATION_INDEX = 'ir_pool_state_idx';

    public function up(): void
    {
        if (!Schema::hasTable('inventory_pools')) {
            Schema::create('inventory_pools', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('location_id');
                $table->unsignedBigInteger('product_id');
                $table->string('variant_key', 191)->default('');
                // fixed (units) | percent (of the net): what is kept for the storefront. NULL: the configured default.
                $table->string('storefront_reserve_mode', 8)->nullable();
                $table->unsignedInteger('storefront_reserve_value')->nullable();
                $table->unsignedInteger('safety_buffer')->nullable();
                // Moves whenever the division of the pool changes; a publisher that planned on an older one plans again before it sends.
                $table->unsignedBigInteger('version')->default(0);
                // What the division came to, without the version and the time: the version moves only when this does.
                $table->string('fingerprint', 32)->nullable();
                $table->unsignedInteger('last_net')->nullable();
                $table->unsignedInteger('deficit')->default(0);
                // Computations that still have to come back without a deficit before an increase may be sent again.
                $table->unsignedSmallInteger('deficit_hold_cycles')->default(0);
                // An order changed what the pool is promised and the division has not been looked at since.
                $table->timestamp('dirty_at')->nullable();
                $table->timestamp('last_computed_at')->nullable();
                $table->timestamps();

                $table->unique(['location_id', 'product_id', 'variant_key'], 'ipool_location_product_variant_unique');
                $table->index('dirty_at', 'ipool_dirty_idx');
            });
        }

        foreach (self::SHARE_TABLES as $table) {
            $this->addColumn($table, 'stock_share_mode', fn (Blueprint $blueprint) => $blueprint->string('stock_share_mode', 8)->nullable());
            $this->addColumn($table, 'stock_share_value', fn (Blueprint $blueprint) => $blueprint->unsignedInteger('stock_share_value')->nullable());
            $this->addColumn($table, 'stock_priority', fn (Blueprint $blueprint) => $blueprint->unsignedSmallInteger('stock_priority')->nullable());
        }
        $this->addColumn('sales_channel_product_mappings', 'allocation', fn (Blueprint $blueprint) => $blueprint->json('allocation')->nullable());
        $this->addColumn('inventory_reservations', 'sales_channel', fn (Blueprint $blueprint) => $blueprint->string('sales_channel', 24)->nullable());
        $this->addColumn('inventory_reservations', 'account_id', fn (Blueprint $blueprint) => $blueprint->unsignedBigInteger('account_id')->nullable());

        if (Schema::hasTable('inventory_reservations') && !Schema::hasIndex('inventory_reservations', self::RESERVATION_INDEX)) {
            Schema::table('inventory_reservations', function (Blueprint $table) {
                $table->index(['location_id', 'product_id', 'variant_key', 'state', 'reserved_at'], self::RESERVATION_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('inventory_reservations') && Schema::hasIndex('inventory_reservations', self::RESERVATION_INDEX)) {
            Schema::table('inventory_reservations', fn (Blueprint $table) => $table->dropIndex(self::RESERVATION_INDEX));
        }
        $this->dropColumns('inventory_reservations', ['sales_channel', 'account_id']);
        $this->dropColumns('sales_channel_product_mappings', ['allocation']);
        foreach (self::SHARE_TABLES as $table) {
            $this->dropColumns($table, ['stock_share_mode', 'stock_share_value', 'stock_priority']);
        }
        Schema::dropIfExists('inventory_pools');
    }

    private function addColumn(string $table, string $column, Closure $define): void
    {
        if (Schema::hasTable($table) && !Schema::hasColumn($table, $column)) {
            Schema::table($table, $define);
        }
    }

    /** @param list<string> $columns */
    private function dropColumns(string $table, array $columns): void
    {
        foreach ($columns as $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($column));
            }
        }
    }
};
