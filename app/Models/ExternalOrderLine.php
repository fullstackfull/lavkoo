<?php

namespace App\Models;

use App\Models\Concerns\StoresLocalTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $mapping_id
 * @property string $external_line_id
 * @property int $quantity
 */
class ExternalOrderLine extends Model
{
    use StoresLocalTime;

    protected $table = 'external_order_lines';

    protected $guarded = [];

    protected $casts = [
        'mapping_id' => 'integer',
        'quantity' => 'integer',
        'quantity_cancelled' => 'integer',
        'quantity_returned' => 'integer',
        'unit_price' => 'decimal:6',
        'line_total' => 'decimal:6',
        'discount_total' => 'decimal:6',
        'tax_total' => 'decimal:6',
        'shipping_total' => 'decimal:6',
        'weight_kg' => 'decimal:3',
        'product_id' => 'integer',
        'inventory_location_id' => 'integer',
        'snapshot' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ExternalOrderMapping::class, 'mapping_id');
    }

    /** Units still to be fulfilled: ordered, less cancelled. Returned units were fulfilled first, so they are not subtracted. */
    public function activeQuantity(): int
    {
        return max(0, $this->quantity - $this->quantity_cancelled);
    }
}
