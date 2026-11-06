<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** The cost one sold (or transferred, or written-off) line took from one layer, frozen at that moment. Reversed, never edited or deleted. */
class InventoryCostConsumption extends Model
{
    protected $table = 'inventory_cost_consumptions';

    protected $guarded = [];

    protected $casts = [
        'layer_id' => 'integer',
        'consumer_id' => 'integer',
        'quantity' => 'integer',
        'unit_cost' => 'decimal:6',
        'landed_unit_cost' => 'decimal:6',
        'total_cost' => 'decimal:6',
        'consumed_at' => 'datetime',
        'reversed_at' => 'datetime',
        'entry_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $consumption): void {
            foreach (['layer_id', 'consumer_type', 'consumer_id', 'quantity', 'unit_cost', 'landed_unit_cost', 'total_cost', 'currency', 'consumed_at'] as $attribute) {
                if ($consumption->isDirty($attribute)) {
                    throw new LogicException("A consumed cost is frozen: '{$attribute}' cannot change.");
                }
            }
        });

        static::deleting(static function (): void {
            throw new LogicException('Consumed costs are reversed, never deleted.');
        });
    }
}
