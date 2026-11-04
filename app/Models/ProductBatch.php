<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A dated batch of a product (Phase 3, Stage C).
 */
class ProductBatch extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DEPLETED = 'depleted';

    protected $fillable = [
        'product_id', 'seller_id', 'batch_number', 'expiry_date', 'quantity', 'initial_quantity',
        'status', 'reference_type', 'reference_id', 'note', 'created_by',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'seller_id' => 'integer',
        'expiry_date' => 'date',
        'quantity' => 'integer',
        'initial_quantity' => 'integer',
        'reference_id' => 'integer',
        'created_by' => 'integer',
    ];

    /** Expired once its date has passed — derived, so it is true even if no sweep has run. */
    public function isExpired(?\DateTimeInterface $now = null): bool
    {
        return $this->expiry_date !== null
            && $this->expiry_date->endOfDay()->lessThan($now ?? now());
    }

    /** Still holding stock and not yet expired. */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)->where('quantity', '>', 0);
    }
}
