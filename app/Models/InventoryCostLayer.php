<?php

namespace App\Models;

use App\Models\Scopes\StaffCountryProductRowScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A batch of units that came in at one cost at one location. A sale takes its cost from the oldest open layer (first in, first
 * out); the layer's remaining quantity falls and the cost taken is frozen in an `InventoryCostConsumption`.
 */
class InventoryCostLayer extends Model
{
    public const OPEN = 'open';
    public const DEPLETED = 'depleted';
    public const VOID = 'void';

    protected $table = 'inventory_cost_layers';

    protected $guarded = [];

    protected $casts = [
        'seller_id' => 'integer',
        'inventory_location_id' => 'integer',
        'product_id' => 'integer',
        'source_id' => 'integer',
        'source_from' => 'integer',
        'source_to' => 'integer',
        'supplier_id' => 'integer',
        'received_at' => 'datetime',
        'quantity_received' => 'integer',
        'quantity_remaining' => 'integer',
        'unit_cost' => 'decimal:6',
        'landed_unit_cost' => 'decimal:6',
        'original_unit_cost' => 'decimal:6',
        'fx_rate' => 'decimal:10',
    ];

    public function consumptions(): HasMany
    {
        return $this->hasMany(InventoryCostConsumption::class, 'layer_id');
    }

    /**
     * A cost layer is what one product cost us, so a restricted employee reads only their own countries' products' costs (GCC-14c, slice 6).
     * Inert for every request that binds no country.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::addGlobalScope(new StaffCountryProductRowScope());
    }
}
