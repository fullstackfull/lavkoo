<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A share of a cost entry assigned to one item (order line, shipment item, product). The shares of an entry add up to exactly its amount. */
class OperationalCostAllocation extends Model
{
    protected $table = 'operational_cost_allocations';

    protected $guarded = [];

    protected $casts = [
        'entry_id' => 'integer',
        'target_id' => 'integer',
        'product_id' => 'integer',
        'basis_value' => 'decimal:6',
        'share' => 'decimal:10',
        'amount' => 'decimal:6',
    ];
}
