<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One version of a legal document for one GCC storefront country. A storefront sells only with approved, published text for every
 * required document type.
 */
class StorefrontLegalDocument extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    protected $table = 'storefront_legal_documents';

    protected $casts = [
        'approved_at' => 'datetime',
        'published_at' => 'datetime',
    ];
}
