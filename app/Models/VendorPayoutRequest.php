<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A seller's request to be paid, over the ledger (Phase 3, Stage B).
 */
class VendorPayoutRequest extends Model
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PAID = 'paid';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';

    /**
     * Every state a payout can be in, in the order it moves through them.
     *
     * Declared as a set rather than only as individual constants because the Developer Portal
     * publishes it: a client branching on payout status needs the whole list, and a list assembled
     * by hand in a document is one that goes stale the first time a state is added.
     *
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_REQUESTED, self::STATUS_UNDER_REVIEW, self::STATUS_APPROVED,
        self::STATUS_PROCESSING, self::STATUS_PAID, self::STATUS_REJECTED, self::STATUS_FAILED,
    ];

    /** Statuses in which the amount is still reserved and can be released back. */
    public const OPEN_STATUSES = [
        self::STATUS_REQUESTED, self::STATUS_UNDER_REVIEW, self::STATUS_APPROVED, self::STATUS_PROCESSING,
    ];

    protected $fillable = [
        'reference', 'seller_id', 'seller_is', 'amount', 'currency', 'status',
        'method', 'method_details', 'reserve_entry_id', 'payout_entry_id',
        'reviewed_by', 'reviewed_at', 'review_note', 'paid_at', 'payment_reference',
        'payout_currency', 'payout_amount', 'exchange_rate',
    ];

    /** Admin review internals — never serialized to the seller-facing API. */
    protected $hidden = ['reviewed_by', 'review_note', 'reserve_entry_id', 'payout_entry_id'];

    protected $casts = [
        'seller_id' => 'integer',
        'amount' => 'float',
        'method_details' => 'array',
        'reviewed_at' => 'datetime',
        'paid_at' => 'datetime',
        'payout_amount' => 'float',
        'exchange_rate' => 'float',
    ];

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
