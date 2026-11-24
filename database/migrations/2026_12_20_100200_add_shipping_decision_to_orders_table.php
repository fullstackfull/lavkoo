<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the shipping engine decided about an order, kept on the order (H1-06).
 *
 * A decision that is recomputed is a decision that changes. A permission withdrawn next month, a carrier that stops serving a route, a
 * switch turned off -- any of them would make an order that was correctly accepted look as though it never should have been, and an
 * operator reading it a year later would have no way to tell what was true on the day. So the answer is written down once, as it was given.
 *
 * Two nullable columns: whether the order crossed a border, and the reasons that were considered. Every order that exists keeps both empty,
 * which is read as the local order it is -- the platform has taken no other kind. Appended without a position, so MySQL 8 and MariaDB 11.4
 * add them without rebuilding the table.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('orders', 'shipping_kind')) {
            Schema::table('orders', function (Blueprint $table): void {
                // 'local' or 'cross_border', as EligibilityDecision names them.
                $table->string('shipping_kind', 16)->nullable();
            });
        }

        if (!Schema::hasColumn('orders', 'shipping_decision')) {
            Schema::table('orders', function (Blueprint $table): void {
                // The whole decision as it was made, reasons included, so what was considered is readable and not merely its outcome.
                $table->json('shipping_decision')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['shipping_kind', 'shipping_decision'] as $column) {
            if (Schema::hasColumn('orders', $column)) {
                Schema::table('orders', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
