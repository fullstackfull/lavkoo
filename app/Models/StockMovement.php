<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
