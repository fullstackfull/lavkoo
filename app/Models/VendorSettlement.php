<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One settlement period for one vendor (Phase 3, Stage B).
 *
 * After approval the totals are frozen; corrections become new ledger entries that the next
 * settlement picks up.
 */
class VendorSettlement extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_CALCULATED = 'calculated';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';

    /** Once in one of these, the totals must not move. */
    public const LOCKED_STATUSES = [self::STATUS_APPROVED, self::STATUS_PAID];

    protected $fillable = [
        'reference', 'seller_id', 'seller_is', 'period_start', 'period_end',
        'opening_balance', 'total_credits', 'total_debits', 'net_amount', 'closing_balance',
        'entry_count', 'currency', 'status', 'calculated_at',
        'approved_by', 'approved_at', 'paid_at', 'payout_reference', 'note',
    ];

    protected $casts = [
        'seller_id' => 'integer',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'opening_balance' => 'float',
        'total_credits' => 'float',
        'total_debits' => 'float',
        'net_amount' => 'float',
        'closing_balance' => 'float',
        'entry_count' => 'integer',
        'calculated_at' => 'datetime',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(VendorLedgerEntry::class, 'settlement_id');
    }

    public function isLocked(): bool
    {
        return in_array($this->status, self::LOCKED_STATUSES, true);
    }
}
