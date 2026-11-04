<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of a product sits in one warehouse (Phase 3, Stage C).
 */
class WarehouseStock extends Model
{
    protected $table = 'warehouse_stock';

    protected $fillable = ['warehouse_id', 'product_id', 'quantity'];

    protected $casts = [
        'warehouse_id' => 'integer',
        'product_id' => 'integer',
        'quantity' => 'integer',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
