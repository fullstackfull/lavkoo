<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A seller's claim to sell under a brand, and where a person got to with it.
 *
 * `approved` means somebody looked at the documents and said yes. It is never computed, never
 * inferred from having uploaded something, and never granted by time passing — a marketplace that
 * auto-approves brand claims has a brand registry in name only.
 */
class BrandClaim extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';

    /** What a seller may still change. Once submitted, editing is withdrawing and starting again. */
    public const EDITABLE_STATUSES = [self::STATUS_DRAFT, self::STATUS_REJECTED];

    /** Waiting on the marketplace. */
    public const PENDING_STATUSES = [self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW];

    public const TYPE_OWNER = 'owner';
    public const TYPE_AUTHORIZED_RESELLER = 'authorized_reseller';
    public const TYPE_DISTRIBUTOR = 'distributor';

    public const TYPES = [self::TYPE_OWNER, self::TYPE_AUTHORIZED_RESELLER, self::TYPE_DISTRIBUTOR];

    protected $fillable = [
        'brand_id',
        'seller_id',
        'claim_type',
        'status',
        'statement',
        'expires_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(BrandClaimDocument::class, 'brand_claim_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    public function isPending(): bool
    {
        return in_array($this->status, self::PENDING_STATUSES, true);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE_STATUSES, true);
    }

    /**
     * Does this claim currently entitle the seller to list under the brand?
     *
     * Expiry is checked here rather than trusted from the status, so a letter of authority that ran
     * out last night stops entitling anybody this morning without waiting for a sweep to notice.
     */
    public function entitles(?\DateTimeInterface $now = null): bool
    {
        if ($this->status !== self::STATUS_APPROVED) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->greaterThan($now ?? now());
    }
}
