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
    /** The channel confirms a listing for our SKU exists. It is not a promise that the listing is sellable: what the channel reports is in `channel_listing_statuses`. */
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
        'submitted_payload' => 'array',
        'published_payload' => 'array',
        'withheld_attributes' => 'array',
        'regulatory_clearance' => 'array',
        'channel_listing_statuses' => 'array',
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

    /** Has a person recorded the clearance a regulated product needs: a reference and who recorded it? */
    public function hasRecordedClearance(): bool
    {
        $clearance = (array) ($this->regulatory_clearance ?? []);

        return trim((string) ($clearance['reference'] ?? '')) !== '' && (int) ($clearance['by'] ?? 0) > 0;
    }

    /**
     * What an update has to send: the fields whose value differs from what the channel has CONFIRMED it holds (`published_payload`), not from what was merely sent to it. A
     * submission the channel took and then refused leaves nothing published, so the field is sent again; and a field whose confirmed value already equals the new one is not
     * sent at all.
     *
     * Nothing is ever removed — a field the draft no longer names keeps the value the channel holds, because a partial update must not be a way of emptying a listing.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function changedAttributes(array $attributes): array
    {
        $confirmed = (array) ($this->published_payload ?? []);
        $changed = [];
        foreach ($attributes as $field => $value) {
            if (!array_key_exists($field, $confirmed) || $confirmed[$field] !== $value) {
                $changed[$field] = $value;
            }
        }

        return $changed;
    }

    /**
     * What the channel will hold once it has applied the submission now going out: what it has confirmed, with the fields just sent on top. `array_merge`, not `+`: the NEW
     * value of a field that is sent again has to win, or a second change to the same field would be remembered as the first one.
     *
     * @param array<string, mixed> $sent
     * @return array<string, mixed>
     */
    public function payloadAfterSending(array $sent): array
    {
        return array_merge((array) ($this->published_payload ?? []), $sent);
    }

    public function scopeWaitingForTheChannel(Builder $query): Builder
    {
        return $query->where('state', self::SUBMITTED);
    }
}
