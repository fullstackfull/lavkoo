<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One customer's acceptance of one legal text in one storefront. A row is written once and never updated, so a later version of the
 * text does not change what was accepted.
 */
class StorefrontLegalAcceptance extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'storefront_legal_acceptances';

    protected $fillable = [
        'storefront_country',
        'legal_entity',
        'document_type',
        'locale',
        'legal_document_id',
        'document_version',
        'document_hash',
        'customer_id',
        'guest_hash',
        'order_id',
        'accepted_at',
    ];

    protected $casts = [
        'legal_document_id' => 'integer',
        'document_version' => 'integer',
        'customer_id' => 'integer',
        'order_id' => 'integer',
        'accepted_at' => 'datetime',
    ];
}
