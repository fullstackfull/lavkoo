<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One version of a legal document for one GCC storefront country and locale. A storefront sells only with the newest effective, approved
 * text for every required document type and locale. Rows are kept as history, so nothing here is deleted to make a text disappear.
 */
class StorefrontLegalDocument extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    protected $table = 'storefront_legal_documents';

    protected $casts = [
        'version' => 'integer',
        'created_by' => 'integer',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
        'published_at' => 'datetime',
        'effective_from' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}
