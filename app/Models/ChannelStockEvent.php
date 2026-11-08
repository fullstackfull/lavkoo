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
    public const DONE = 'done';
    /** Tried as often as the owner allows and still failing: kept for a person, never dropped, and never silently "done". */
    public const DEAD = 'dead';

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
    ];

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
