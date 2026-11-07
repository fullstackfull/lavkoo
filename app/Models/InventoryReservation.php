<?php

namespace App\Models;

use App\Models\Concerns\StoresLocalTime;
use Illuminate\Database\Eloquent\Model;

/** Stock promised to one order line at one location. `reservation_key` makes the promise happen once however often the order is seen. */
class InventoryReservation extends Model
{
    use StoresLocalTime;

    public const RESERVED = 'reserved';
    public const COMMITTED = 'committed';
    public const RELEASED = 'released';

    protected $table = 'inventory_reservations';

    protected $guarded = [];

    protected $casts = [
        'location_id' => 'integer',
        'product_id' => 'integer',
        'quantity' => 'integer',
        'external_order_mapping_id' => 'integer',
        'order_id' => 'integer',
        'account_id' => 'integer',
        'reserved_at' => 'datetime',
        'committed_at' => 'datetime',
        'released_at' => 'datetime',
        'applied_to_platform_at' => 'datetime',
    ];
}
