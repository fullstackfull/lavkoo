<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One `JSON_LISTINGS_FEED` submission, and the row that stops the same batch being queued at Amazon twice.
 *
 * `submission_key` is unique, which is the whole defence: **Amazon's Feeds API documents no idempotency for `createFeed`.**
 * Its published request body is `feedType`, `marketplaceIds`, `inputFeedDocumentId` and an optional `feedOptions` — no client
 * key, no deduplication, nothing that refuses a second submission of the same document.
 *
 * ## State says what happened; the claim columns say who is driving
 *
 * The two are deliberately separate. `claim_token` and `lease_until` are about this installation's workers; `state` is only
 * ever about what is known to have happened at Amazon. A worker that dies holding a claim releases the row when its lease
 * runs out, and the row's state is exactly where the dead worker left it — which is the point.
 *
 * ## `MAY_HAVE_SUBMITTED` is the state the whole design turns on
 *
 * It is written BEFORE `createFeed` is called. A process that dies waiting for that reply leaves a row saying a feed may be
 * queued at Amazon carrying up to 25,000 price and quantity changes, and the only safe move from there is to LOOK. Never to
 * submit again.
 *
 * @property int $id
 * @property string $submission_key
 * @property string|null $feed_document_id
 * @property string|null $amazon_feed_id
 * @property string|null $result_document_id
 * @property string $state
 * @property int $attempts
 * @property array<int|string, string>|null $message_map
 */
class AmazonFeedSubmission extends Model
{
    /** Nothing has been sent. */
    public const PENDING = 'pending';

    /** `createFeedDocument` answered: the document id and the presigned URL's five-minute deadline are known. */
    public const DOCUMENT_CREATED = 'document_created';

    /** The bytes are at Amazon's URL. **Nothing is queued yet** — an upload is not a submission. */
    public const UPLOADED = 'uploaded';

    /**
     * **THE FENCE.** Written before `createFeed`, so a reply we never received cannot be mistaken for a call never made.
     *
     * A row in this state is not resubmitted by anything. It is identified by reading Amazon's own list of feeds, and if that
     * read cannot settle it, it goes to a person.
     */
    public const MAY_HAVE_SUBMITTED = 'may_have_submitted';

    /** Amazon gave a `feedId`. It is being polled until a terminal status. */
    public const SUBMITTED = 'submitted';

    /** The processing report was fetched and read. Terminal. */
    public const READ = 'read';

    /**
     * Amazon cancelled it before processing began. Terminal, and the ONE failure a machine may submit again from.
     *
     * Amazon's own words: "the feed was cancelled before it started processing. If you want to submit the feed again, start
     * again at Step 1." Nothing was applied, so there is nothing to double.
     */
    public const CANCELLED = 'cancelled';

    /** Refused before anything reached Amazon — a document that would not build, an upload that was rejected. Terminal. */
    public const FAILED = 'failed';

    /**
     * **FATAL, or a report nobody could read. Some, none or all of it happened.**
     *
     * Amazon states of FATAL: "Some, none, or all of the operations within the feed might have completed successfully." Over
     * a feed of price and quantity changes that is an unknown outcome across every SKU it carried, and no machine resolves
     * it. It waits for a person, and it is one of the sources the attention screen collects.
     */
    public const NEEDS_REVIEW = 'needs_review';

    /** States from which a worker still has something to do. */
    public const UNFINISHED = [self::PENDING, self::DOCUMENT_CREATED, self::UPLOADED, self::SUBMITTED];

    /** States nothing will move again by itself. */
    public const TERMINAL = [self::READ, self::CANCELLED, self::FAILED, self::NEEDS_REVIEW];

    protected $table = 'amazon_feed_submissions';

    protected $guarded = [];

    protected $casts = [
        'account_id' => 'integer',
        'attempts' => 'integer',
        'message_count' => 'integer',
        'accepted_count' => 'integer',
        'failed_count' => 'integer',
        'warning_count' => 'integer',
        'message_map' => 'array',
        'summary' => 'array',
        'claimed_at' => 'datetime',
        'lease_until' => 'datetime',
        'document_created_at' => 'datetime',
        'document_expires_at' => 'datetime',
        'upload_url_expires_at' => 'datetime',
        'uploaded_at' => 'datetime',
        'submitted_at' => 'datetime',
        'available_at' => 'datetime',
        'needs_review_since' => 'datetime',
    ];

    /**
     * Rows a worker may work: due, and in a state that still has something to do.
     *
     * `MAY_HAVE_SUBMITTED` is **not** among them. A row behind the fence is never picked up by the ordinary cycle, because
     * everything the ordinary cycle could do to it would be a second submission. It is resolved by its own identification
     * path or by a person.
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->whereIn('state', self::UNFINISHED)
            ->where(fn (Builder $inner): Builder => $inner->whereNull('available_at')->orWhere('available_at', '<=', now()));
    }

    /** Rows whose claim has lapsed, so a new worker may take them without joining a live one. */
    public function scopeUnclaimed(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner): Builder => $inner->whereNull('lease_until')->orWhere('lease_until', '<=', now()));
    }

    /**
     * The SKU a reported `messageId` is about, or null when the report names one we never sent.
     *
     * Null is an answer, not a miss: a report that blames a messageId this submission never carried is not a report about
     * this submission, and the caller must refuse rather than attribute the failure to some SKU of its own choosing.
     */
    public function skuOf(int $messageId): ?string
    {
        $map = $this->message_map ?? [];

        return isset($map[$messageId]) ? (string) $map[$messageId] : (isset($map[(string) $messageId]) ? (string) $map[(string) $messageId] : null);
    }

    /** @return list<int> every messageId this submission carried */
    public function sentMessageIds(): array
    {
        return array_map('intval', array_keys($this->message_map ?? []));
    }

    /** Whether Amazon's two-day life for a `feedDocumentId` has run out, so a new document is needed. */
    public function documentHasExpired(): bool
    {
        return $this->document_expires_at !== null && $this->document_expires_at->isPast();
    }

    /** Whether the five minutes Amazon gives the presigned upload URL have run out. */
    public function uploadUrlHasExpired(): bool
    {
        return $this->upload_url_expires_at === null || $this->upload_url_expires_at->isPast();
    }

    public static function newClaimToken(): string
    {
        return (string) Str::uuid();
    }
}
