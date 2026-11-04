<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per reminder actually sent (Phase 2.4).
 *
 * Deliberately not related to Cart by a foreign key: the cart rows are deleted when the order is
 * placed, and the ledger has to survive exactly that moment.
 */
class AbandonedCartReminder extends Model
{
    protected $fillable = [
        'cart_group_id',
        'customer_id',
        'is_guest',
        'email',
        'stage',
        'cart_value',
        'item_count',
        'sent_at',
        'clicked_at',
        'recovered_at',
        'recovered_order_id',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'is_guest' => 'boolean',
        'stage' => 'integer',
        'cart_value' => 'float',
        'item_count' => 'integer',
        'sent_at' => 'datetime',
        'clicked_at' => 'datetime',
        'recovered_at' => 'datetime',
    ];

    public function scopeRecovered($query)
    {
        return $query->whereNotNull('recovered_at');
    }
}
