<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cost in dispute leaves the totals; when the dispute is settled in the charge's favour it has to come back as what it was (an approved actual cost, or one an
 * invoice reconciled). The state it had is kept here, outside the columns that are frozen once an entry is approved. Additive and guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operational_cost_entries') && !Schema::hasColumn('operational_cost_entries', 'state_before_dispute')) {
            Schema::table('operational_cost_entries', function (Blueprint $table) {
                $table->string('state_before_dispute', 16)->nullable()->after('state');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('operational_cost_entries', 'state_before_dispute')) {
            Schema::table('operational_cost_entries', function (Blueprint $table) {
                $table->dropColumn('state_before_dispute');
            });
        }
    }
};
