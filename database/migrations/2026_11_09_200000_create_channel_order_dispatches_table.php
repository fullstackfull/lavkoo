<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The application's own record of turning ONE channel order into a provider shipment: who claimed the attempt, how many attempts, why one was blocked, and which internal order came out of it.
 * One row per channel order (unique), claimed before anything is read or written, so that two runs can never both create the order. It holds no buyer detail: reasons are codes.
 * Additive and guarded; no foreign keys to system tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('channel_order_dispatches')) {
            return;
        }

        Schema::create('channel_order_dispatches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mapping_id')->unique();
            $table->unsignedBigInteger('account_id')->index();
            // claimed | blocked | materialized | accepted | failed | cancelled
            $table->string('state', 16)->index();
            $table->string('provider', 32)->nullable();
            // Set while an attempt is running; an attempt that never finished (a crash) is given up after sales_channels.dispatch.claim_minutes.
            $table->string('claim_token', 40)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index();
            // Why the order is not being shipped yet: a stable code, never text about a person.
            $table->string('reason_code', 80)->nullable();
            $table->unsignedBigInteger('internal_order_id')->nullable()->unique();
            $table->timestamp('materialized_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            // When the order ended without a shipment that is still wanted (cancelled at the channel, or the fulfilment failed): the clock the buyer's details are kept against.
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('buyer_data_purged_at')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            // manual: a person asked; auto: the scheduled run did.
            $table->string('trigger', 8)->default('manual');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_order_dispatches');
    }
};
