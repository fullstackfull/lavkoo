<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Audit row for every change of a product's fulfillment provider. Append-only. */
class ProductProviderChange extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'product_provider_changes';

    protected $guarded = [];

    protected $casts = [
        'product_id' => 'integer',
        'actor_id' => 'integer',
        'context' => 'array',
        'created_at' => 'datetime',
    ];
}
