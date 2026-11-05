<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product exactly as a provider presented it, kept raw. Staging area between "the provider says
 * this exists" and "this is a product in our store": the import reads from here, and the raw payload
 * is what an administrator inspects when verifying a field mapping.
 */
class ProviderCatalogItem extends Model
{
    public const STATUS_NEW = 'new';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_IGNORED = 'ignored';
    public const STATUS_ERROR = 'error';

    protected $table = 'provider_catalog_items';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'stock' => 'integer',
        'cost' => 'decimal:4',
        'retail_price' => 'decimal:4',
        'product_id' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'imported_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
