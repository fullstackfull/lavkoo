<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The company that sells in one storefront country (GCC-13). One row for each country; a row is changed in place and an order keeps a frozen copy. */
class LegalEntity extends Model
{
    protected $table = 'legal_entities';

    protected $fillable = [
        'country',
        'legal_name',
        'trade_name',
        'tax_registration_number',
        'commercial_registration_number',
        'invoice_series_key',
        'address',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];
}
