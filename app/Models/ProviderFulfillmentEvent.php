<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every provider event we ever received, applied or not: the shipment's status history and the
 * webhook de-duplication ledger in one table (`dedupe_key` is unique).
 */
class ProviderFulfillmentEvent extends Model
{
    protected $table = 'provider_fulfillment_events';

    protected $guarded = [];

    protected $casts = [
        'provider_fulfillment_id' => 'integer',
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'payload' => 'array',
    ];

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(ProviderFulfillment::class, 'provider_fulfillment_id');
    }

    /**
     * Provider event times carry milliseconds (the column is timestamp(3)) and events can land within the same second. The
     * model's default date format would drop the fraction when saving, so two events 400 ms apart would compare as equal and
     * an older one could overwrite a newer one.
     */
    protected function occurredAt(): Attribute
    {
        return Attribute::make(set: static fn (mixed $value): ?string => $value === null ? null : Carbon::parse($value)->format('Y-m-d H:i:s.v'));
    }
}
