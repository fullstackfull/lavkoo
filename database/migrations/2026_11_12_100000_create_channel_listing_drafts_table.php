<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Putting a product of the store ON a channel. One new table of the module's own; nothing is added to a platform table and no foreign key is created.
 *
 * `channel_listing_drafts` is one intention per (account, product, variant): the channel-shaped values a person completed, what the channel said it requires, what our own
 * validation found, and — once it was sent — the channel's submission id and what the channel made of it (accepted, or rejected with its reasons). The product itself is never
 * written: a draft holds the channel's fields, not ours.
 *
 * The row is the idempotency key too. Its unique index means one intention per product and variant on one account, so a second run finds the first instead of making a second
 * listing; and `product_mapping_id` is filled in only once the listing really exists at the channel, which is what tells a later publish that there is nothing to create.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('channel_listing_drafts')) {
            return;
        }

        Schema::create('channel_listing_drafts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->string('sales_channel', 32);
            $table->unsignedBigInteger('product_id');
            $table->string('variant_key', 191)->default('');
            // Set when the listing exists at the channel: from then on this draft updates that listing instead of creating one.
            $table->unsignedBigInteger('product_mapping_id')->nullable()->index();
            // draft | validated | submitted | accepted | rejected | withdrawn
            $table->string('state', 16)->default('draft');
            // The channel's own product type (Amazon's productType, Trendyol's category) the fields were completed for.
            $table->string('product_type', 191)->nullable();
            // What the channel is to be sent: its field names, its values. Never the store's product row.
            $table->json('payload')->nullable();
            // What the channel said it requires when the fields were last checked, and when it said it.
            $table->json('requirements')->nullable();
            $table->timestamp('requirements_read_at')->nullable();
            // What our own validation found: a list of field names and why each is refused. Empty means ready to send.
            $table->json('problems')->nullable();
            // The channel's identifier for the submission (Amazon's submissionId, Trendyol's batchRequestId), and what it last said about it.
            $table->string('submission_id', 191)->nullable();
            $table->string('channel_status', 64)->nullable();
            $table->json('channel_problems')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            // Who last asked for it to go out, so that a dispatch nobody asked for cannot happen.
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->string('refusal_code', 64)->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'product_id', 'variant_key'], 'cld_account_product_variant_unique');
            $table->index(['state', 'submitted_at'], 'cld_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_listing_drafts');
    }
};
