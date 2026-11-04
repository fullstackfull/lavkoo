<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One submitted seller KYC document with its own review lifecycle (Phase 3, Stage A).
 */
class SellerVerificationDocument extends Model
{
    protected $table = 'seller_verification_documents';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'seller_id', 'document_type', 'document_number', 'file_path',
        'status', 'rejection_reason', 'reviewed_by', 'reviewed_at', 'expires_at', 'notes',
    ];

    /** Admin review internals and the private-disk filename — never serialized to the API. */
    protected $hidden = ['notes', 'reviewed_by', 'file_path'];

    protected $appends = ['has_file'];

    protected $casts = [
        'seller_id' => 'integer',
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
        'expires_at' => 'date',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'seller_id');
    }

    /** An approved document still counts only while it has not passed its expiry. */
    public function isEffectivelyApproved(?\DateTimeInterface $now = null): bool
    {
        if ($this->status !== self::STATUS_APPROVED) {
            return false;
        }
        if ($this->expires_at === null) {
            return true;
        }

        return $this->expires_at->endOfDay()->greaterThanOrEqualTo($now ?? now());
    }

    public function getHasFileAttribute(): bool
    {
        return !empty($this->file_path);
    }
}
