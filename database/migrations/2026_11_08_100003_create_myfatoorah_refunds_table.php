<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refunds asked of MyFatoorah, and what became of them.
 *
 * A MyFatoorah refund is a REQUEST: `POST /v3/refunds` answers with Status "Pending" and their finance
 * team executes it later. Treating that answer as "refunded" would tell a customer their money is back
 * when it is not, so `status` keeps the two apart and only a REFUND_STATUS_CHANGED webhook (or a
 * refund-details read) moves a row to `executed`.
 *
 * `external_identifier` is unique: it is sent to MyFatoorah as ExternalIdentifier, so a double-submitted
 * approval collides here instead of refunding twice. The total already requested against an attempt is
 * what guards the paid amount — that check and this insert happen in one transaction under a row lock.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('myfatoorah_refunds')) {
            return;
        }

        Schema::create('myfatoorah_refunds', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('payment_attempt_id')->index();

            // The store's own refund record, when this refund came from the existing refund workflow
            // rather than from the gateway monitor. Nullable: an admin may refund a payment directly.
            $table->unsignedBigInteger('refund_request_id')->nullable()->index();
            $table->unsignedBigInteger('order_id')->nullable()->index();

            // Our idempotency key for the refund itself.
            $table->string('external_identifier', 64)->unique();

            // MyFatoorah's identifiers, filled from the create-refund response.
            $table->string('myfatoorah_refund_id', 64)->nullable()->index();
            $table->string('myfatoorah_reference', 64)->nullable();
            $table->string('myfatoorah_invoice_id', 64)->nullable();

            $table->decimal('amount', 24, 2);
            $table->char('currency', 3);

            /*
             * requested  — we have asked MyFatoorah and they accepted the request
             * pending    — MyFatoorah is holding it for review (their "Pending")
             * executed   — the money has actually gone back
             * rejected   — MyFatoorah declined it
             * failed     — the call itself failed; nothing was asked
             */
            $table->string('status', 16)->default('requested')->index();

            // Who did what, for audit. Admin ids, not names.
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('comment', 255)->nullable();

            $table->string('last_error_code', 32)->nullable();
            $table->string('last_error_message', 255)->nullable();

            $table->timestamp('requested_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('myfatoorah_refunds');
    }
};
