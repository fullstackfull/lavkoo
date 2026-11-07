<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an invoice line was compared with: NULL is the estimate of the same service (every comparison so far); `statements` is the total the channel's own statements gave for the
 * same fee and period, for a marketplace invoice whose line is a monthly total with no order on it. One nullable column on this module's own table; additive and guarded, so a
 * re-run is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operational_cost_reconciliations') && !Schema::hasColumn('operational_cost_reconciliations', 'basis')) {
            Schema::table('operational_cost_reconciliations', function (Blueprint $table) {
                $table->string('basis', 16)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('operational_cost_reconciliations', 'basis')) {
            Schema::table('operational_cost_reconciliations', function (Blueprint $table) {
                $table->dropColumn('basis');
            });
        }
    }
};
