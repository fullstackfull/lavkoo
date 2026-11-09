<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The application's own record of telling a channel that goods shipped — ONE ROW PER SHIPMENT REFERENCE, unique, claimed before anything is sent.
 *
 * It exists because the channel's own answer is not enough on its own. noon publishes no idempotency rule for `integration_shipment_nr`, so a repeated send is not known to be
 * harmless; deriving the same reference makes a retry ASK about the same shipment, and nothing more. Two processes can therefore both read "noon holds no such shipment" and
 * both send — one shipment of goods, two vans — unless something outside both of them says who owns the attempt. That is this table: the unique key is the mutual exclusion,
 * and the state is this system's own memory of whether a send ever left, which survives a cache flush and does not depend on the channel being reachable.
 *
 * States: claimed (an attempt is running) | not_sent (it ended with PROOF nothing left, so it may be tried again) | sent (a send may have reached the channel: never repeated
 * automatically) | confirmed (the channel reported the shipment) | needs_review (a send may have left and the channel does not show it: a person decides).
 *
 * It holds no buyer detail and no tracking number: a reference, an order number, a state and a reason code. Additive and guarded; no foreign keys to system tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('channel_shipment_sends')) {
            return;
        }

        Schema::create('channel_shipment_sends', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->string('channel', 16);
            // The channel's own identifier for this exact shipment, derived from the order and its units: noon's `integration_shipment_nr`.
            $table->string('reference', 191);
            $table->string('external_order_id', 191)->index();
            // claimed | not_sent | sent | confirmed | needs_review
            $table->string('state', 16)->index();
            // Set while an attempt is running; an attempt that never finished (a crash) is given up after sales_channels.shipment_sends.claim_minutes.
            $table->string('claim_token', 40)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            // Why this shipment is not reported as taken: a stable code, never text about a person.
            $table->string('reason_code', 80)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            // THE guarantee against a second van. One shipment reference per account, enforced by the engine rather than by
            // the order in which two processes happen to read.
            $table->unique(['account_id', 'reference'], 'channel_shipment_sends_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_shipment_sends');
    }
};
