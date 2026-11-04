<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An audited change to a seller's bank details, with the cooling window it opened (Phase 3, Stage B).
 */
class VendorBankChangeLog extends Model
{
    protected $fillable = [
        'seller_id', 'previous', 'current', 'cooling_until',
        'changed_by', 'changed_by_type', 'ip_address',
    ];

    protected $casts = [
        'seller_id' => 'integer',
        'previous' => 'array',
        'current' => 'array',
        'cooling_until' => 'datetime',
    ];
}
