<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reasoned change to a product's stock (Phase 3, Stage C).
 */
class StockMovement extends Model
{
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_RECEIPT = 'receipt';
    public const TYPE_SALE = 'sale';
    public const TYPE_RETURN = 'return';
    public const TYPE_TRANSFER = 'transfer';

    /** Reasons a manual adjustment can carry. */
    public const REASONS = ['count_correction', 'damage', 'loss', 'theft', 'found', 'expiry', 'other'];

    protected $fillable = [
        'product_id', 'seller_id', 'type', 'qty_change', 'balance_after',
        'reason', 'reference_type', 'reference_id', 'note', 'created_by', 'created_by_type',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'seller_id' => 'integer',
        'qty_change' => 'integer',
        'balance_after' => 'integer',
        'reference_id' => 'integer',
        'created_by' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * A movement belongs to a seller when it carries the seller's id and its product, if there still is one, is not somebody else's: the in-house
     * shop's products carry user_id 1 too, so the stamp alone is not enough. A product that is gone, or whose owner was never recorded, takes nothing
     * away from the seller's own history.
     */
    public function scopeOfSeller($query, int|string $sellerId): void
    {
        $query->where('seller_id', $sellerId)
            ->whereDoesntHave('product', fn ($product) => $product->whereNot(fn ($ownProduct) => $ownProduct->ofSeller(sellerId: $sellerId)));
    }
}
