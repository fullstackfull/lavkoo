<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A refund asked of MyFatoorah.
 *
 * `requested`/`pending` mean the money has NOT moved yet — MyFatoorah's finance team executes refunds
 * after review — so nothing in the store may tell a customer they have been refunded until `executed`.
 */
class MyFatoorahRefund extends Model
{
    protected $table = 'myfatoorah_refunds';

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'requested_at' => 'datetime',
        'executed_at' => 'datetime',
    ];

    public const STATUS_REQUESTED = 'requested';
    public const STATUS_PENDING = 'pending';
    public const STATUS_EXECUTED = 'executed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';

    /** Statuses that count against the amount still available to refund. */
    public const COUNTS_AGAINST_PAID = [self::STATUS_REQUESTED, self::STATUS_PENDING, self::STATUS_EXECUTED];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(MyFatoorahPaymentAttempt::class, 'payment_attempt_id');
    }

    /** Has the money actually gone back to the customer? */
    public function isExecuted(): bool
    {
        return $this->status === self::STATUS_EXECUTED;
    }
}
