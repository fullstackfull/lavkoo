<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One decision about one item of one customer's return — and the row that keeps it from being made twice.
 *
 * **Trendyol documents no idempotency for either decision.** Its own pages describe `claims/{claimId}/items/approve` and
 * `claims/{claimId}/issue` with no client-supplied key, no rejection of a repeated request, and nothing about what a second
 * identical call does. So what a second approval does to a customer's refund is not knowable from the documentation, and this
 * table exists so that the question never has to be answered: a decision is recorded before it is sent and recorded once.
 *
 * Two columns carry the whole guarantee:
 *
 *  - `(account_id, claim_id, claim_item_id)` is UNIQUE. One item of one return gets ONE decision, ever. It cannot be approved
 *    twice, and it cannot be approved and also rejected -- which matters more than the double-approval, because the two
 *    requests go to different endpoints and nothing at Trendyol would connect them.
 *  - `state` is written to `may_have_sent` BEFORE the request leaves. A run that dies between the socket write and the reply
 *    leaves a row that says a customer may already have been refunded, and no machine decides again from there.
 *
 * It holds no money and no buyer. The refund is Trendyol's to compute and pay; what is kept here is which item was decided,
 * how, by whom, and what Trendyol said.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('channel_return_decisions')) {
            return;
        }

        Schema::create('channel_return_decisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id')->default(0)->index();
            $table->unsignedBigInteger('account_id')->index();
            $table->string('channel', 24)->index();
            // Trendyol's claim id is a STRING, not a number, and its claim items are strings too (the documented example is a
            // UUID). Stored as they came: a number here would have truncated them.
            $table->string('claim_id', 120);
            $table->string('claim_item_id', 120);
            // What the claim is about, for a screen and for the ledger to find its order again. Never a buyer.
            $table->string('external_order_id', 120)->nullable()->index();
            $table->string('external_line_id', 120)->nullable();

            // approve -> the units come back and the customer is refunded by Trendyol
            // reject  -> the return is refused, which Trendyol requires a reason and a description for
            $table->string('decision', 16);
            // Only for a rejection, and ONLY an id read from `GET /order/claim-issue-reasons`. Trendyol's example list is not
            // described as complete, so a reason is matched against what the instance answered and never invented.
            $table->unsignedBigInteger('reason_id')->nullable();
            $table->string('reason_name', 191)->nullable();
            $table->string('description', 500)->nullable();

            // pending       -> decided here, nothing sent
            // may_have_sent -> **WRITTEN BEFORE THE REQUEST.** The customer may already have been refunded or refused, so no
            //                  machine decides again. Only proof that the request did not leave lifts it.
            // done          -> Trendyol took the decision
            // needs_review  -> it went out and nobody can say what became of it. A person looks; nothing retries.
            // failed        -> refused before anything left, or Trendyol answered a refusal. The reason is recorded.
            $table->string('state', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->string('claimed_by', 64)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable()->comment('the admin who made the decision, never a machine');
            $table->string('failure_code', 120)->nullable();
            $table->text('failure_detail')->nullable();
            $table->timestamps();

            // THE guarantee: one decision per claim item per account.
            $table->unique(['account_id', 'claim_id', 'claim_item_id'], 'crd_item_unique');
            $table->index(['state', 'available_at', 'id'], 'crd_due_idx');
            $table->index(['account_id', 'claim_id'], 'crd_claim_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_return_decisions');
    }
};
