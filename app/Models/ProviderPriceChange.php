<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** History of supplier cost / suggested retail / selling price movements driven by a provider sync. */
class ProviderPriceChange extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'provider_price_changes';

    protected $guarded = [];

    protected $casts = [
        'product_id' => 'integer',
        'mapping_id' => 'integer',
        'old_value' => 'decimal:6',
        'new_value' => 'decimal:6',
        'created_at' => 'datetime',
    ];
}
