<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every webhook delivery MyFatoorah makes, stored before it is acted on.
 *
 * MyFatoorah retries a webhook up to five times, and its own documentation says two events may arrive
 * for one transaction. The endpoint therefore does no work beyond writing a row here: `dedupe_key` is
 * unique, so the second delivery of an event loses the insert and is answered 200 without touching an
 * order. That is what makes "the same webhook ten times" harmless, and it is enforced by the index
 * rather than by a check-then-act in PHP, which two simultaneous deliveries would both pass.
 *
 * The body is NOT stored. A payload carries a cardholder name and a masked PAN, and we have no reason
 * to keep either; what is kept is the handful of fields the state machine needs plus a digest, which is
 * enough to prove two deliveries were identical without retaining their contents.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('myfatoorah_webhook_events')) {
            return;
        }

        Schema::create('myfatoorah_webhook_events', function (Blueprint $table): void {
            $table->id();

            // Event.Code / Event.Name from the envelope (1 = PAYMENT_STATUS_CHANGED, 2 = REFUND_STATUS_CHANGED...).
            $table->unsignedSmallInteger('event_code')->nullable();
            $table->string('event_name', 64)->nullable();
            $table->string('event_reference', 64)->nullable();
            $table->timestamp('event_created_at')->nullable();

            // The identifiers we match on. external_identifier is ours and is the reliable one.
            $table->string('external_identifier', 64)->nullable()->index();
            $table->string('invoice_id', 64)->nullable()->index();
            $table->string('payment_id', 64)->nullable()->index();
            $table->string('invoice_status', 24)->nullable();
            $table->string('transaction_status', 24)->nullable();

            /*
             * What makes a delivery "the same delivery". Built from the event name and the identifying
             * fields plus both statuses: a genuine status change (PENDING then PAID) produces a
             * different key and is processed, while a retry of an event we already have does not.
             */
            $table->string('dedupe_key', 191)->unique();

            // Proof two deliveries were byte-identical, without keeping the bytes.
            $table->char('payload_digest', 64);

            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();

            // What acting on this event did: settled | already_settled | ignored_stale | pending |
            // no_attempt | mismatch | refund_updated | unsupported. Diagnostics for the admin monitor.
            $table->string('process_result', 32)->nullable()->index();
            $table->string('process_note', 255)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('myfatoorah_webhook_events');
    }
};
