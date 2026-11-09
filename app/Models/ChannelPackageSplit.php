<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One request to cut one shipment package into several.
 *
 * **Trendyol documents no idempotency for any of its four split endpoints**, and nothing about what a repeated identical
 * request does — while making the split asynchronous, so the package's own status does not say "already cut" until some time
 * after it was. This row is what stops a second cut in that window.
 *
 * Unlike a return decision, a fenced split has somewhere to go: the package can be READ BACK, and the original turning
 * `UnPacked` is Trendyol's own proof. So `may_have_sent` here is a question with an answer, and `sendable()` lets a run pick
 * the row up again to ask it — never to send again.
 *
 * @property int $id
 * @property string $package_id
 * @property string $kind
 * @property array $plan
 * @property string $state
 * @property int $attempts
 * @property int $checks
 */
class ChannelPackageSplit extends Model
{
    /** Recorded here; nothing has been sent. */
    public const PENDING = 'pending';

    /**
     * **The package may already be cut — written BEFORE the request leaves.**
     *
     * No run sends from this state. A run may only read the package back and look for the proof.
     */
    public const MAY_HAVE_SENT = 'may_have_sent';

    /** The package was read back and really is split. */
    public const CONFIRMED = 'confirmed';

    /** It went out, and reading the package back still does not say. A person looks; nothing sends again. */
    public const NEEDS_REVIEW = 'needs_review';

    /** Refused before anything left, or the channel answered a refusal. */
    public const FAILED = 'failed';

    protected $table = 'channel_package_splits';

    protected $guarded = [];

    protected $casts = [
        'seller_id' => 'integer',
        'account_id' => 'integer',
        'keep_previous_status' => 'boolean',
        'expected_packages' => 'integer',
        'attempts' => 'integer',
        'checks' => 'integer',
        'requested_by' => 'integer',
        'plan' => 'array',
        'new_package_ids' => 'array',
        'available_at' => 'datetime',
        'claimed_at' => 'datetime',
        'sent_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    /**
     * Rows a run may pick up: due, not held by a live run, and with something left to do.
     *
     * `may_have_sent` IS among them, and that is the whole difference from `ChannelReturnDecision::sendable()`. There a fenced
     * row has nothing it can ask and waits for a person. Here it has: the package's own status is the answer, so a fenced row
     * is picked up to be CHECKED. What a run may do with it is decided by the state, not by having claimed it.
     */
    public function scopeSendable(Builder $query): Builder
    {
        $cutoff = now()->subMinutes(max(1, (int) config('sales_channels.splits.claim_minutes', 10)));

        return $query->whereIn('state', [self::PENDING, self::MAY_HAVE_SENT])
            ->where(fn (Builder $inner): Builder => $inner->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->where(fn (Builder $inner): Builder => $inner->whereNull('claimed_by')->orWhere('claimed_at', '<', $cutoff));
    }

    /** True once the request may have reached the channel: from here nothing is ever sent again for this row. */
    public function mayHaveReachedTheChannel(): bool
    {
        return in_array($this->state, [self::MAY_HAVE_SENT, self::NEEDS_REVIEW], true);
    }

    public function isSettled(): bool
    {
        return in_array($this->state, [self::CONFIRMED, self::FAILED], true);
    }

    /**
     * Whether this row stops another split of the same package being recorded.
     *
     * Everything except a clean failure does. A row that failed before anything left the host is the one case where the
     * package is provably untouched, so a person may ask again.
     */
    public function blocksAnotherSplit(): bool
    {
        return $this->state !== self::FAILED;
    }
}
