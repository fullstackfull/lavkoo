<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the channel has refunded of a channel order line, in the order's currency: `refunded_total` is the money taken back from the line's revenue (goods and shipping,
 * without tax) and `refunded_tax` the tax that went back with it. Both NULL until a refund is read (a line that only has returned units is valued from them). They are
 * written from the channel's refund events, set (not added to) per refund, so reading a refund twice leaves them right. Two nullable columns on this module's own table;
 * additive and guarded, so a re-run is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('external_order_lines')) {
            return;
        }
        foreach (['refunded_total', 'refunded_tax'] as $column) {
            if (!Schema::hasColumn('external_order_lines', $column)) {
                Schema::table('external_order_lines', function (Blueprint $table) use ($column) {
                    $table->decimal($column, 20, 6)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['refunded_tax', 'refunded_total'] as $column) {
            if (Schema::hasColumn('external_order_lines', $column)) {
                Schema::table('external_order_lines', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
