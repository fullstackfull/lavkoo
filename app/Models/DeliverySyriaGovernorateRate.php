<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A destination governorate and its per-kilo shipping price, as synced from the Delivery Syria courier
 * account (optional integration). Keyed by the courier's own `code` so a re-sync updates in place.
 */
class DeliverySyriaGovernorateRate extends Model
{
    protected $fillable = [
        'code', 'governorate', 'base_cost', 'synced_at',
    ];

    protected $casts = [
        'code' => 'integer',
        'base_cost' => 'decimal:2',
        'synced_at' => 'datetime',
    ];
}
