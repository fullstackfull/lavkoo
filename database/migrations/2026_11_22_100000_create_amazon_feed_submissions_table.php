<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per `JSON_LISTINGS_FEED` submission — and it exists because **Amazon documents no idempotency for `createFeed`**.
 *
 * Nothing in `CreateFeedSpecification` carries a client key, nothing rejects a second submission of the same document, and
 * nothing says a feed may not be submitted twice. The whole published request body is `feedType`, `marketplaceIds`,
 * `inputFeedDocumentId` and an optional `feedOptions`. So the fence is ours or there is none.
 *
 * ## The five calls, and why each boundary is a row written to disk
 *
 * The cycle is `createFeedDocument` -> PUT the bytes -> `createFeed` -> `getFeed` until terminal -> `getFeedDocument` ->
 * download. Every one of those is a separate network call, and the process can die between any two of them. Each step
 * therefore records what it is ABOUT to do before it does it, and what came back after:
 *
 *  - `feed_document_id` is written the moment `createFeedDocument` answers, because that identifier is the only way back to a
 *    document already paid for. Amazon states it "expires after two days", which `document_expires_at` carries.
 *  - `upload_url_expires_at` carries the five minutes Amazon gives the presigned URL. A worker that comes back later must
 *    create a new document rather than PUT to a dead URL and read the failure as a rejected feed.
 *  - **`state` becomes `may_have_submitted` BEFORE `createFeed` is called.** This is the fence. A process that dies waiting
 *    for that response leaves a row that says a feed may be queued at Amazon, and the recovery path is to LOOK (`getFeeds`),
 *    never to submit again. Without the fence, the same batch of up to 25,000 price and quantity changes is submitted twice.
 *  - `amazon_feed_id` is written the instant Amazon gives one, so a row that dies before its first poll still knows what to
 *    ask about. Amazon's `getFeed` only serves feeds "created within the last 28 days", so a row that loses its feed id past
 *    that window loses the answer for good.
 *
 * ## `message_map` is not a convenience
 *
 * The processing report keys everything it says by `messageId`. It records no SKU anywhere a failure is absent, and it
 * records **no per-message success at all** — Amazon's `summary` counts and its `issues` name failures. So the accepted set is
 * what was sent less what was blamed, and that subtraction is impossible without the map from `messageId` to SKU. A
 * submission whose map was lost is a submission whose answer cannot be read, which is why the map is stored with the row and
 * not held in a worker's memory.
 *
 * ## What this table deliberately does NOT hold
 *
 * Not the feed body, and not the report. The upload URL and the report URL are each good for five minutes and the content is
 * a seller's own catalogue data; the row keeps identifiers, counts and outcomes so the work can be found and audited, and the
 * documents themselves are built, sent, read and dropped.
 *
 * And nothing here records that a price or quantity is LIVE. `messagesAccepted` is Amazon's own count of messages "that were
 * accepted, the associated updates **are being applied**" — its wording, present tense. Acceptance of a submission is not
 * application of a change, so `accepted_count` means accepted and a listing read is what says what Amazon is showing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('amazon_feed_submissions')) {
            return;
        }

        Schema::create('amazon_feed_submissions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id')->index();
            $table->string('seller_id', 64)->nullable();
            $table->string('feed_type', 64)->default('JSON_LISTINGS_FEED')->index();
            $table->string('marketplace_id', 24)->nullable();

            // What this submission is FOR, of which account -- the key that makes a second attempt at the same batch a
            // collision in the database rather than a second feed at Amazon. It is ours; Amazon offers nothing of the kind.
            $table->string('submission_key', 191)->unique();

            // Ownership, kept apart from state. These columns say WHO is driving the row; `state` says only what is known to
            // have happened at Amazon. A worker claims by writing its token under a conditional update, and the lease is what
            // lets a row be picked up again after a worker dies without a second worker joining a live one.
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('lease_until')->nullable()->index();

            // Amazon's own identifiers, each written the moment it is known.
            $table->string('feed_document_id', 191)->nullable()->index();
            $table->timestamp('document_created_at')->nullable();
            $table->timestamp('document_expires_at')->nullable()->comment('Amazon: the feedDocumentId expires after two days');
            $table->timestamp('upload_url_expires_at')->nullable()->comment('Amazon: the presigned URL expires after 5 minutes');
            $table->timestamp('uploaded_at')->nullable();
            $table->string('amazon_feed_id', 120)->nullable()->index();
            $table->timestamp('submitted_at')->nullable();
            $table->string('result_document_id', 191)->nullable();
            $table->string('processing_status', 24)->nullable()->comment('Amazon processingStatus, verbatim');

            // pending            -> nothing has been sent
            // document_created   -> createFeedDocument answered; the id and the URL's deadline are known
            // uploaded           -> the bytes are at Amazon's URL, and NOTHING is queued yet
            // may_have_submitted -> **THE FENCE.** createFeed was about to be called, or was called and we never heard back
            // submitted          -> Amazon gave a feedId; it is being polled
            // read               -> the processing report was fetched and read
            // cancelled          -> Amazon cancelled it BEFORE processing: nothing was applied, by Amazon's own statement
            // failed             -> the submission was refused before anything was queued, with the reason recorded
            // needs_review       -> FATAL, or a report we could not read. **Some, none or all of it happened.** A person's.
            $table->string('state', 24)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('needs_review_since')->nullable();

            // The map the result cannot be read without: messageId -> SKU, as it was sent.
            $table->json('message_map')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            // Accepted, which is Amazon's word and NOT "applied". Nothing in this table says a price is live.
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->json('summary')->nullable()->comment('the report summary counts, as Amazon gave them');

            $table->string('failure_code', 120)->nullable();
            $table->text('failure_detail')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'state'], 'afs_account_state_idx');
            $table->index(['state', 'available_at', 'id'], 'afs_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amazon_feed_submissions');
    }
};
