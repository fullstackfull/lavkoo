<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a person (or, when the owner switched it on, the application) decided about the units of a channel order line that came back: put back on the shelf at their original
 * cost, or written off as damaged (their cost stays in the profit). One row per line and return event (a Trendyol claim, or a return a person recorded), so a repeated
 * submit decides nothing twice. A new table of this module's own, guarded so a re-run is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('operational_cost_return_decisions')) {
            Schema::create('operational_cost_return_decisions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('seller_id')->default(0)->index();
                // channel_order_line
                $table->string('target_type', 24);
                $table->unsignedBigInteger('target_id');
                // claim:<claim id> for a return the channel reported, manual:<token> for one a person recorded.
                $table->string('return_event', 120);
                $table->unsignedInteger('units_restocked')->default(0);
                $table->unsignedInteger('units_written_off')->default(0);
                $table->unsignedBigInteger('inventory_location_id')->nullable();
                // What the restock produced: the cost layer the units went back into and the ledger entry that reversed their cost of goods.
                $table->unsignedBigInteger('layer_id')->nullable();
                $table->unsignedBigInteger('entry_id')->nullable();
                $table->boolean('automatic')->default(false);
                $table->unsignedBigInteger('decided_by')->nullable();
                $table->string('note', 500)->nullable();
                $table->dateTime('decided_at');
                $table->timestamps();

                $table->unique(['target_type', 'target_id', 'return_event'], 'ocrd_target_event_unique');
                $table->index(['target_type', 'target_id'], 'ocrd_target_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_cost_return_decisions');
    }
};
