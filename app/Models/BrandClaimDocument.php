<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of evidence behind a brand claim.
 *
 * `file_path` is a bare filename on the private disk, never a URL. Trademark certificates and
 * letters of authority are commercially sensitive documents, and serving them goes through an
 * ownership-checked route — the same rule the KYC documents already follow.
 */
class BrandClaimDocument extends Model
{
    public const TYPE_TRADEMARK_CERTIFICATE = 'trademark_certificate';
    public const TYPE_AUTHORIZATION_LETTER = 'authorization_letter';
    public const TYPE_INVOICE = 'invoice';
    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_TRADEMARK_CERTIFICATE,
        self::TYPE_AUTHORIZATION_LETTER,
        self::TYPE_INVOICE,
        self::TYPE_OTHER,
    ];

    protected $fillable = [
        'brand_claim_id',
        'seller_id',
        'document_type',
        'file_path',
        'original_name',
        'reference',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(BrandClaim::class, 'brand_claim_id');
    }
}
