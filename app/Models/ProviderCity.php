<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A destination city the provider supports, cached from the provider's own city list.
 *
 * `country` says which of the carrier's lists the row belongs to (H1-11), `''` being the platform-wide list that every installation
 * imported before a carrier served a second market. Which list a lookup reads is `App\Services\Fulfillment\ProviderCityCountry`'s
 * rule and lives there, because a carrier numbering its cities from 1 in each country gives two lists the same external ids.
 */
class ProviderCity extends Model
{
    protected $table = 'provider_cities';

    protected $guarded = [];

    protected $casts = [
        'raw' => 'array',
        'is_selected' => 'boolean',
        'synced_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'missing_since' => 'datetime',
    ];
}
