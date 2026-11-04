<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A supplier the platform or a seller procures from (Phase 3, Stage C).
 */
class Supplier extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'seller_id', 'name', 'contact_name', 'email', 'phone', 'tax_number',
        'address', 'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'seller_id' => 'integer',
        'created_by' => 'integer',
    ];

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /** Admin/platform suppliers (seller_id null) or a specific seller's — never both. */
    public function scopeOwnedBy($query, int|string|null $sellerId)
    {
        return $sellerId === null ? $query->whereNull('seller_id') : $query->where('seller_id', $sellerId);
    }
}
