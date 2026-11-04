<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stock location (Phase 3, Stage C).
 */
class Warehouse extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'seller_id', 'name', 'code', 'address', 'is_default', 'status', 'created_by',
    ];

    protected $casts = [
        'seller_id' => 'integer',
        'is_default' => 'boolean',
        'created_by' => 'integer',
    ];

    public function stock(): HasMany
    {
        return $this->hasMany(WarehouseStock::class);
    }

    public function scopeOwnedBy($query, int|string|null $sellerId)
    {
        return $sellerId === null ? $query->whereNull('seller_id') : $query->where('seller_id', $sellerId);
    }
}
