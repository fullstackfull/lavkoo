<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One approval request against any subject (Phase 3 — spec item 82).
 */
class ApprovalRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'reference', 'workflow', 'subject_type', 'subject_id', 'status',
        'amount', 'currency', 'required_approvals', 'approvals_count',
        'requested_by_type', 'requested_by', 'request_note', 'payload',
        'decided_at', 'decision_note',
    ];

    protected $casts = [
        'amount' => 'float',
        'required_approvals' => 'integer',
        'approvals_count' => 'integer',
        'requested_by' => 'integer',
        'payload' => 'array',
        'decided_at' => 'datetime',
    ];

    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
