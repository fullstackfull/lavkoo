<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The stock of one SKU at one location, as that location's source of truth reported it (or a person typed it), with a quality
 * that says whether it may be published. An estimate is never stored here as if it were stock.
 */
class InventoryLocationBalance extends Model
{
    public const CONFIRMED = 'confirmed';
    public const MANUAL = 'manual';
    public const STALE = 'stale';
    public const UNKNOWN = 'unknown';

    protected $table = 'inventory_location_balances';

    protected $guarded = [];

    protected $casts = [
        'location_id' => 'integer',
        'product_id' => 'integer',
        'available' => 'integer',
        'reserved' => 'integer',
        'unfulfillable' => 'integer',
        'inbound' => 'integer',
        'total' => 'integer',
        'source_synced_at' => 'datetime',
        'meta' => 'array',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }
}
