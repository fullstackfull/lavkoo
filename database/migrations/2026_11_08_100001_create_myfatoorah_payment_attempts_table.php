<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per attempt to pay one `payment_requests` row through MyFatoorah.
 *
 * Why a separate table instead of columns on `payment_requests`: that table is shared by every gateway
 * and its `is_paid`/`transaction_id` pair carries no amount, no currency and no attempt history. A
 * customer who fails 3-D Secure and tries again produces two MyFatoorah invoices for one order, and
 * both have to stay individually verifiable and individually reconcilable — otherwise a late webhook
 * for the first invoice cannot be told apart from one for the second.
 *
 * The money-safety guarantees live in this table's unique keys, not in application code:
 *
 *   external_identifier  unique  — our own key, sent to MyFatoorah as Order.ExternalIdentifier and
 *                                  echoed back in every webhook. Two concurrent "start a payment"
 *                                  requests for the same payment_request and attempt number collide
 *                                  here instead of creating two invoices.
 *   payment_id           unique  — MyFatoorah's own id for the money. Makes it impossible for two
 *                                  attempts to claim the same payment, which is how a tampered
 *                                  callback would try to pay order B with order A's money.
 *   settled_at                   — set exactly once, inside the same transaction that flips
 *                                  payment_requests.is_paid. The success hook (which creates the
 *                                  order, decrements stock and books the shipment) is dispatched only
 *                                  by whoever wins that write, so ten identical webhooks create one order.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('myfatoorah_payment_attempts')) {
            return;
        }

        Schema::create('myfatoorah_payment_attempts', function (Blueprint $table): void {
            $table->id();

            // The payment_requests row this attempt pays. Not a foreign key: payment_requests uses a
            // char(36) uuid and is written by a trait we do not own, and a cascade from there would be
            // able to delete the only record that a payment happened.
            $table->string('payment_request_id', 36)->index();
            $table->unsignedSmallInteger('attempt_number')->default(1);

            // Our idempotency key. Also the only thing a webhook needs to find this row.
            $table->string('external_identifier', 64)->unique();

            // MyFatoorah's identifiers. invoice_id groups the attempts at their end; payment_id is the money.
            $table->string('invoice_id', 64)->nullable()->index();
            $table->string('payment_id', 64)->nullable()->unique();

            // What we asked to be charged, frozen at creation. Verification compares against THESE, never
            // against a number that arrives with a callback.
            $table->decimal('expected_amount', 24, 2);
            $table->char('expected_currency', 3);

            // What MyFatoorah says it actually took. Null until a successful verification.
            $table->decimal('captured_amount', 24, 2)->nullable();
            $table->char('captured_currency', 3)->nullable();

            $table->string('status', 24)->default('created')->index();
            $table->string('mode', 8);                       // test | live, frozen per attempt
            $table->string('platform', 16)->nullable();       // web | app, for the admin monitor
            $table->text('payment_url')->nullable();          // the hosted page we sent the customer to

            // Where the customer should end up. Kept per attempt so a callback cannot redirect elsewhere.
            $table->text('return_url')->nullable();

            // Verification bookkeeping, used by reconciliation and shown in the admin monitor.
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->unsignedInteger('verification_attempts')->default(0);
            $table->timestamp('expires_at')->nullable();

            /*
             * Settlement is two steps because the second one cannot be undone by a rollback: marking the
             * payment paid is a database write, but the success hook creates an order, decrements stock
             * and books a shipment with a courier. They cannot be in one transaction — a courier call
             * does not roll back — so they are two marks:
             *
             *   settled_at         the attempt won the right to run the hook, and payment_requests.is_paid
             *                      flipped in the same transaction. Set exactly once.
             *   hook_completed_at  the hook finished. Null while it is running or if it died.
             *
             * An attempt with settled_at set and hook_completed_at null is work that was interrupted, and
             * the recovery command finds it by exactly that pair. It is the one state that can lose an
             * order after taking the money, so it is queryable rather than inferred.
             */
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('hook_completed_at')->nullable();
            $table->unsignedSmallInteger('hook_attempts')->default(0);
            $table->string('hook_error', 255)->nullable();

            // Safe-to-display diagnostics only: MyFatoorah's error code and message, never a payload,
            // never a card number, never a token.
            $table->string('last_error_code', 32)->nullable();
            $table->string('last_error_message', 255)->nullable();

            // A redacted summary of the last verification (status, method name, masked card, reference
            // ids). Written by the verifier, which strips everything else before it gets here.
            $table->json('last_response_summary')->nullable();

            $table->timestamps();

            // Reconciliation's working set: in-flight attempts, oldest verification first.
            $table->index(['status', 'last_verified_at'], 'mf_attempts_sweep_idx');
            // The recovery sweep's working set: paid and settled, but the hook never finished.
            $table->index(['settled_at', 'hook_completed_at'], 'mf_attempts_recovery_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('myfatoorah_payment_attempts');
    }
};
