<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One decision about one item of one customer's return.
 *
 * `(account_id, claim_id, claim_item_id)` is unique, so an item of a return is decided ONCE: never approved twice, and never
 * approved and also rejected — which is the more dangerous of the two, because the two decisions go to different endpoints and
 * nothing at the channel would connect them.
 *
 * **Trendyol documents no idempotency for either decision**, so what a second approval does to a customer's refund cannot be
 * known from its pages. This row is how the question stops being asked.
 *
 * @property int $id
 * @property string $claim_id
 * @property string $claim_item_id
 * @property string $decision
 * @property string $state
 * @property int $attempts
 */
class ChannelReturnDecision extends Model
{
    /** The units come back and the channel refunds the customer. */
    public const APPROVE = 'approve';

    /** The return is refused. Trendyol requires a reason id and a description for this, and the reason comes from its list. */
    public const REJECT = 'reject';

    /** Decided here; nothing has been sent. */
    public const PENDING = 'pending';

    /**
     * **A decision may already have reached the channel — written BEFORE the request leaves.**
     *
     * From that instant the customer may have been refunded or refused, and no machine decides again. The same discipline the
     * noon shipments and the Odoo postings carry, for the same reason: a run that dies between the socket write and the reply
     * must not leave a row that reads as though nothing happened.
     */
    public const MAY_HAVE_SENT = 'may_have_sent';

    /** The channel took the decision. */
    public const DONE = 'done';

    /** It went out and nobody can say what became of it. A person looks; nothing retries. */
    public const NEEDS_REVIEW = 'needs_review';

    /** Refused before anything left, or the channel answered a refusal. */
    public const FAILED = 'failed';

    protected $table = 'channel_return_decisions';

    protected $guarded = [];

    protected $casts = [
        'seller_id' => 'integer',
        'account_id' => 'integer',
        'reason_id' => 'integer',
        'attempts' => 'integer',
        'decided_by' => 'integer',
        'available_at' => 'datetime',
        'claimed_at' => 'datetime',
        'sent_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    /**
     * Rows a run may send: due, not held by a live run, and in a state that still has something to do.
     *
     * `may_have_sent` is NOT among them, and that is the difference from every other outbox here. Elsewhere a fenced row may
     * still be ASKED about, because the channel can be queried by a reference. Trendyol publishes no way to ask what became of
     * one claim-item decision, so a fenced row has nothing to ask and nothing safe to do: it waits for a person.
     */
    public function scopeSendable(Builder $query): Builder
    {
        $cutoff = now()->subMinutes(max(1, (int) config('sales_channels.returns.claim_minutes', 10)));

        return $query->whereIn('state', [self::PENDING])
            ->where(fn (Builder $inner): Builder => $inner->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->where(fn (Builder $inner): Builder => $inner->whereNull('claimed_by')->orWhere('claimed_at', '<', $cutoff));
    }

    /** True once a decision may have reached the channel: from here no machine decides again. */
    public function mayHaveReachedTheChannel(): bool
    {
        return in_array($this->state, [self::MAY_HAVE_SENT, self::NEEDS_REVIEW], true);
    }

    public function isSettled(): bool
    {
        return in_array($this->state, [self::DONE, self::FAILED], true);
    }
}
