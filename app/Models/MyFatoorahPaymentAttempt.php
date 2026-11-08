<?php

namespace App\Models;

use App\Enums\MyFatoorah\AttemptStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One attempt to pay one `payment_requests` row through MyFatoorah.
 *
 * @property string $payment_request_id
 * @property string $external_identifier
 * @property string|null $invoice_id
 * @property string|null $payment_id
 * @property string $status
 */
class MyFatoorahPaymentAttempt extends Model
{
    protected $table = 'myfatoorah_payment_attempts';

    protected $guarded = ['id'];

    protected $casts = [
        'expected_amount' => 'decimal:2',
        'captured_amount' => 'decimal:2',
        'attempt_number' => 'integer',
        'verification_attempts' => 'integer',
        'last_response_summary' => 'array',
        'verified_at' => 'datetime',
        'last_verified_at' => 'datetime',
        'settled_at' => 'datetime',
        'hook_started_at' => 'datetime',
        'hook_completed_at' => 'datetime',
        'hook_attempts' => 'integer',
        'live_slot' => 'integer',
        'expires_at' => 'datetime',
    ];

    /** Is a process inside this attempt's success hook right now? */
    public function hookLeaseIsLive(): bool
    {
        return $this->hook_started_at !== null
            && $this->hook_completed_at === null
            && $this->hook_started_at->addMinutes(max(1, (int) config('myfatoorah.hook_lease_minutes', 15)))->isFuture();
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(MyFatoorahRefund::class, 'payment_attempt_id');
    }

    public function paymentRequest(): ?PaymentRequest
    {
        return PaymentRequest::find($this->payment_request_id);
    }

    public function statusEnum(): AttemptStatus
    {
        return AttemptStatus::tryFrom((string) $this->status) ?? AttemptStatus::Created;
    }

    /** Has this attempt's success hook already been run? The one question settlement must answer. */
    public function isSettled(): bool
    {
        return $this->settled_at !== null;
    }

    /** Attempts reconciliation should chase: not finished, and not older than the configured window. */
    public function scopeInFlight(Builder $query): Builder
    {
        return $query->whereIn('status', [
            AttemptStatus::Created->value,
            AttemptStatus::Redirected->value,
            AttemptStatus::Pending->value,
        ]);
    }

    /**
     * Total already asked of MyFatoorah for this attempt, counting requests that are still being
     * reviewed. A refund that failed outright asked for nothing and is excluded.
     */
    public function refundedOrPendingTotal(): string
    {
        return (string) $this->refunds()
            ->whereIn('status', ['requested', 'pending', 'executed'])
            ->sum('amount');
    }
}
