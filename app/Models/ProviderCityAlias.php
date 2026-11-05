<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An administrator-approved link from a city string our checkout produced to a provider city id. */
class ProviderCityAlias extends Model
{
    protected $table = 'provider_city_aliases';

    protected $guarded = [];
}
