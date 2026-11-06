<?php

use App\Services\Fulfillment\Support\LegacyColumnWidener;
use Illuminate\Database\Migrations\Migration;

/**
 * order_details.delivery_status holds the order status the platform last wrote for a line. OrderManager::getStockUpdateOnOrderStatusChange
 * (and its twin OrderRepository::updateStockOnOrderStatusChange) write the new order status into it when an order is cancelled, returned
 * or failed and again when such an order is re-opened; the order screens write "delivered". "out_for_delivery" is 16 characters long, so
 * on the pilot server's varchar(15) column, re-opening an order straight into it stores "out_for_deliver" without any error (the
 * connection is not strict) and the platform's own comparisons with the real word then fail. The integration neither reads nor writes
 * this column; widening it removes a latent defect of the platform's own schema.
 *
 * Additive and idempotent: only the width changes (nullability, default, collation and indexes are restated as they are), no row is
 * written, a column that is already wide enough is left alone, and it does nothing on any database but MySQL and MariaDB.
 */
return new class extends Migration
{
    public function up(): void
    {
        LegacyColumnWidener::widenString(table: 'order_details', column: 'delivery_status', length: 30);
    }

    public function down(): void
    {
        // Narrowing the column again could cut off statuses that are in use, so it stays as wide as it is.
    }
};
