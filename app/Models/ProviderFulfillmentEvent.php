<?php

namespace App\Models;

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
}
