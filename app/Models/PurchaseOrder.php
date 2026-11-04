<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A purchase order to a supplier (Phase 3, Stage C).
 */
class PurchaseOrder extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ORDERED = 'ordered';
    public const STATUS_PARTIALLY_RECEIVED = 'partially_received';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'reference', 'supplier_id', 'seller_id', 'status', 'currency',
        'subtotal', 'total', 'expected_at', 'ordered_at', 'received_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'supplier_id' => 'integer',
        'seller_id' => 'integer',
        'subtotal' => 'decimal:2',
        'total' => 'decimal:2',
        'expected_at' => 'date',
        'ordered_at' => 'datetime',
        'received_at' => 'datetime',
        'created_by' => 'integer',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /** A PO can be edited only while it is still a draft. */
    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /** Stock can be received only once the order has actually been placed and is not finished. */
    public function canReceive(): bool
    {
        return in_array($this->status, [self::STATUS_ORDERED, self::STATUS_PARTIALLY_RECEIVED], true);
    }

    public function scopeOwnedBy($query, int|string|null $sellerId)
    {
        return $sellerId === null ? $query->whereNull('seller_id') : $query->where('seller_id', $sellerId);
    }
}
