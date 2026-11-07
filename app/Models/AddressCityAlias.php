<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Another way people write a city: an administrator-confirmed link from a spelling to exactly one city. */
class AddressCityAlias extends Model
{
    protected $table = 'address_city_aliases';

    protected $guarded = [];

    public function city(): BelongsTo
    {
        return $this->belongsTo(AddressCity::class, 'city_id');
    }
}
