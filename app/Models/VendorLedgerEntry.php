<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One immutable financial event on a vendor's ledger (Phase 3, Stage B).
 *
 * Never edited. A correction is a new entry, because an edited ledger is not a ledger.
 */
class VendorLedgerEntry extends Model
{
    public const TYPE_ORDER_EARNING = 'order_earning';
    public const TYPE_COMMISSION_CHARGE = 'commission_charge';
    public const TYPE_REFUND = 'refund';
    public const TYPE_RETURN_ADJUSTMENT = 'return_adjustment';
    public const TYPE_SHIPPING_FEE = 'shipping_fee';
    public const TYPE_PENALTY = 'penalty';
    public const TYPE_BONUS = 'bonus';
    public const TYPE_MANUAL_ADJUSTMENT = 'manual_adjustment';
    public const TYPE_PAYOUT = 'payout';

    public const STATUS_PENDING = 'pending';
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'seller_id', 'seller_is', 'entry_type', 'debit', 'credit', 'balance_after',
        'status', 'available_at', 'currency', 'reference_type', 'reference_id',
        'description', 'created_by', 'created_by_type', 'settlement_id',
    ];

    protected $casts = [
        'seller_id' => 'integer',
        'debit' => 'float',
        'credit' => 'float',
        'balance_after' => 'float',
        'available_at' => 'datetime',
    ];

    /** What this entry did to the balance: credit adds, debit subtracts. */
    public function getSignedAmountAttribute(): float
    {
        return round($this->credit - $this->debit, 4);
    }

    public function scopeForSeller(Builder $query, int|string $sellerId, string $sellerIs = 'seller'): Builder
    {
        return $query->where(['seller_id' => $sellerId, 'seller_is' => $sellerIs]);
    }
}
