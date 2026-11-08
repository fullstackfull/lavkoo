<?php

namespace App\Models;

use App\Models\Concerns\StoresLocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One intention to put a product of the store on a channel: the channel's own fields for it, what our validation found, and what the channel made of it.
 *
 * It is never a product and never a listing. The product stays the store's (nothing here writes `products`), and the listing exists only once the channel has accepted the
 * submission — from then on `product_mapping_id` points at the listing row and this draft updates it instead of creating another.
 *
 * @property int $account_id
 * @property int $product_id
 * @property string $variant_key
 * @property string $state
 * @property array|null $payload
 * @property array|null $problems
 */
class ChannelListingDraft extends Model
{
    use StoresLocalTime;

    /** A person is still completing the channel's fields. */
    public const DRAFT = 'draft';
    /** Every field the channel requires is there: it may be sent. */
    public const VALIDATED = 'validated';
    /** Sent, and the channel has not finished with it. */
    public const SUBMITTED = 'submitted';
    /** The channel took it; the listing exists. */
    public const ACCEPTED = 'accepted';
    /** The channel refused it; `channel_problems` says why, and a corrected draft is sent again — never as a new product. */
    public const REJECTED = 'rejected';
    /** A person stopped it. */
    public const WITHDRAWN = 'withdrawn';

    protected $table = 'channel_listing_drafts';

    protected $guarded = [];

    protected $casts = [
        'account_id' => 'integer',
        'product_id' => 'integer',
        'product_mapping_id' => 'integer',
        'requested_by' => 'integer',
        'payload' => 'array',
        'requirements' => 'array',
        'problems' => 'array',
        'channel_problems' => 'array',
        'requirements_read_at' => 'datetime',
        'submitted_at' => 'datetime',
        'checked_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(SalesChannelAccount::class, 'account_id');
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(SalesChannelProductMapping::class, 'product_mapping_id');
    }

    /** Does the channel already hold a listing this draft stands for? Then nothing is created; it is an update. */
    public function updatesAnExistingListing(): bool
    {
        return $this->product_mapping_id !== null;
    }

    public function isWaitingForTheChannel(): bool
    {
        return $this->state === self::SUBMITTED;
    }

    public function scopeWaitingForTheChannel(Builder $query): Builder
    {
        return $query->where('state', self::SUBMITTED);
    }
}
