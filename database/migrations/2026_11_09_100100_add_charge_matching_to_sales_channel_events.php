<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One charge reported by the API, by a statement a person imported and by an invoice must be one cost. `charge_key` is the canonical form of what a charge is (the channel
 * account, the fee type, the currency and the amount to six decimals, so 25, 25.0 and 25.00 are the same), looked up when a manual row meets an entry the API gave;
 * `duplicate_of_id` names the API event a manual row was recognised as a repeat of, so one API event is never claimed by two rows. Two nullable columns on this module's own
 * table; additive and guarded, so a re-run is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sales_channel_events')) {
            return;
        }
        if (!Schema::hasColumn('sales_channel_events', 'charge_key')) {
            Schema::table('sales_channel_events', function (Blueprint $table) {
                $table->string('charge_key', 64)->nullable();
                $table->index('charge_key', 'sce_charge_key_idx');
            });
        }
        if (!Schema::hasColumn('sales_channel_events', 'duplicate_of_id')) {
            Schema::table('sales_channel_events', function (Blueprint $table) {
                $table->unsignedBigInteger('duplicate_of_id')->nullable();
                $table->index('duplicate_of_id', 'sce_duplicate_of_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_channel_events', 'duplicate_of_id')) {
            Schema::table('sales_channel_events', function (Blueprint $table) {
                $table->dropIndex('sce_duplicate_of_idx');
                $table->dropColumn('duplicate_of_id');
            });
        }
        if (Schema::hasColumn('sales_channel_events', 'charge_key')) {
            Schema::table('sales_channel_events', function (Blueprint $table) {
                $table->dropIndex('sce_charge_key_idx');
                $table->dropColumn('charge_key');
            });
        }
    }
};
