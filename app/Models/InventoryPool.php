<?php

namespace App\Models;

use App\Models\Concerns\StoresLocalTime;
use Illuminate\Database\Eloquent\Model;

/**
 * One pool: a product (or one variant of it) at one location, whose stock several listings offer at once. The row is what the division of the pool is serialised on, and holds
 * the pool's own settings (the reserve kept for the storefront, a safety buffer) and what the last division found.
 *
 * @property int $id
 * @property int $location_id
 * @property int $product_id
 * @property string $variant_key
 * @property int $version
 */
class InventoryPool extends Model
{
    use StoresLocalTime;

    protected $table = 'inventory_pools';

    protected $guarded = [];

    protected $casts = [
        'location_id' => 'integer',
        'product_id' => 'integer',
        'storefront_reserve_value' => 'integer',
        'safety_buffer' => 'integer',
        'version' => 'integer',
        'last_net' => 'integer',
        'deficit' => 'integer',
        'deficit_hold_cycles' => 'integer',
        'dirty_at' => 'datetime',
        'last_computed_at' => 'datetime',
    ];
}
