<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Which location a channel's quantity comes from, who owns the price, and whether either may be pushed; per account or per product. */
class InventorySourcePolicy extends Model
{
    public const SCOPE_ACCOUNT = 'account';
    public const SCOPE_PRODUCT = 'product';

    protected $table = 'inventory_source_policies';

    protected $guarded = [];

    protected $casts = [
        'account_id' => 'integer',
        'product_id' => 'integer',
        'stock_location_id' => 'integer',
        'safety_buffer' => 'integer',
        'max_publish_quantity' => 'integer',
        'stock_share_value' => 'integer',
        'stock_priority' => 'integer',
        'is_active' => 'boolean',
    ];
}
