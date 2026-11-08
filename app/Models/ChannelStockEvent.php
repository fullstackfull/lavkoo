<?php

namespace App\Models;

use App\Models\Concerns\StoresLocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One change that could change what a channel should be shown, recorded in the same transaction as the change itself.
 *
 * It carries no figure and no quantity: it names a pool and says why, and the figures are read afresh when it is worked. So a row that is worked late is not a stale quantity
 * being sent — it is a fresh one being sent late.
 *
 * Four facts, never conflated. The row being RECORDED is one thing (`created_at`, written in the transaction of the change). The pool having been RECOMPUTED is another
 * (`recomputed_at`, and `storefront_written_at` for our own number). The publisher having been ASKED, and what it answered, is a third (`outcome`, `publish_result`,
 * `publish_detail`). And what the CHANNEL confirmed is a fourth — which is not here at all: it is the listing's own `publish_state`, because a submission a channel merely
 * took is not an applied quantity, and only the listing knows which it is.
 *
 * @property int $location_id
 * @property int $product_id
 * @property string $variant_key
 * @property string $reason
 * @property string $state
 */
class ChannelStockEvent extends Model
{
    use StoresLocalTime;

    /** Waiting to be worked. */
    public const PENDING = 'pending';
    /** A run holds it. A run that died leaves this behind, and it is taken back after `claim_timeout_minutes`. */
    public const CLAIMED = 'claimed';
    /** A pass ATTENDED to it. Not "a channel accepted it", and not "a quantity was published": `outcome` says what the pass came to. */
    public const WORKED = 'worked';
    /** Tried as often as the owner allows and still failing: kept for a person, never dropped, and never called worked. */
    public const DEAD = 'dead';

    /** There was nothing for this pass to do with it (no listing offers the pool; the storefront's number was already written by the change itself). */
    public const NOTHING_TO_DO = 'nothing_to_do';
    /** The pool was divided again and the storefront's number is right; no channel was asked, because none offers the pool. */
    public const RECOMPUTED_ONLY = 'recomputed_only';
    /** The publisher was asked and ran. What each listing's quantity came to is the listing's own `publish_state`, not this. */
    public const SENT = 'sent';
    /** The publisher was asked and refused before anything left this host (a switch, an approval). A settled answer — and NOT a successful publish. */
    public const NOT_SENT = 'not_sent';

    /** @var list<string> */
    public const OUTCOMES = [self::NOTHING_TO_DO, self::RECOMPUTED_ONLY, self::SENT, self::NOT_SENT];

    /** Why the pool has to be worked out again. */
    public const ORDER_IMPORTED = 'order_imported';
    public const ORDER_CHANGED = 'order_changed';
    public const ORDER_CANCELLED = 'order_cancelled';
    public const UNITS_RETURNED = 'units_returned';
    public const RESERVATION_CHANGED = 'reservation_changed';
    public const PROVIDER_FIGURE = 'provider_figure';
    public const LISTING_SETTINGS = 'listing_settings';
    public const POOL_SETTINGS = 'pool_settings';
    public const MANUAL = 'manual';

    /** Every reason, for the screens and for a test that keeps the words complete. */
    public const REASONS = [
        self::ORDER_IMPORTED, self::ORDER_CHANGED, self::ORDER_CANCELLED, self::UNITS_RETURNED, self::RESERVATION_CHANGED,
        self::PROVIDER_FIGURE, self::LISTING_SETTINGS, self::POOL_SETTINGS, self::MANUAL,
    ];

    protected $table = 'channel_stock_events';

    protected $guarded = [];

    protected $casts = [
        'location_id' => 'integer',
        'product_id' => 'integer',
        'subject_id' => 'integer',
        'attempts' => 'integer',
        'rewrites_storefront_stock' => 'boolean',
        'available_at' => 'datetime',
        'claimed_at' => 'datetime',
        'worked_at' => 'datetime',
        'recomputed_at' => 'datetime',
        'storefront_written_at' => 'datetime',
    ];

    /** Was it really divided again? The question a person asks when a quantity looks wrong and the row says `worked`. */
    public function wasRecomputed(): bool
    {
        return $this->recomputed_at !== null;
    }

    /** Did the publisher run for it? A refusal before anything left this host is not this. */
    public function wasSent(): bool
    {
        return $this->outcome === self::SENT;
    }

    /** Waiting, and due: a row put off after a failure is not due until its time. */
    public function scopeDue(Builder $query, ?string $now = null): Builder
    {
        return $query->where('state', self::PENDING)
            ->where(static fn (Builder $inner) => $inner->whereNull('available_at')->orWhere('available_at', '<=', $now ?? now()));
    }

    /** Rows a run claimed and never finished: that run died. */
    public function scopeAbandoned(Builder $query, int $minutes): Builder
    {
        return $query->where('state', self::CLAIMED)->where('claimed_at', '<', now()->subMinutes(max(1, $minutes)));
    }
}
