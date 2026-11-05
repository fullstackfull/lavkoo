<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A destination city the provider supports, cached from the provider's own city list. */
class ProviderCity extends Model
{
    protected $table = 'provider_cities';

    protected $guarded = [];

    protected $casts = [
        'raw' => 'array',
        'synced_at' => 'datetime',
    ];
}
