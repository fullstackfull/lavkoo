<?php

namespace App\Models;

use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\StockSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links one store product (or one variant of it) to the provider's own identifiers.
 *
 * Provider identifiers live here, never on the product: the external id is the provider's number, not
 * ours, and must never be confused with `products.id`. A mapping only routes an order once it is
 * `approved`.
 */
class ProviderProductMapping extends Model
{
    protected $table = 'provider_product_mappings';

    protected $guarded = [];

    protected $casts = [
        'product_id' => 'integer',
        'status' => MappingStatus::class,
        'stock_source' => StockSource::class,
        'provider_stock' => 'integer',
        'provider_stock_synced_at' => 'datetime',
        'supplier_cost' => 'decimal:4',
        'supplier_retail_price' => 'decimal:4',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
        'meta' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', MappingStatus::Approved->value);
    }

    public function isApproved(): bool
    {
        return $this->status === MappingStatus::Approved;
    }
}
