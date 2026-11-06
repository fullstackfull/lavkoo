<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A city, town or village the store ships to. Its id is the key every address and every provider mapping uses; its names are
 * only labels, and two cities may well share one.
 */
class AddressCity extends Model
{
    protected $table = 'address_cities';

    protected $guarded = [];

    protected $casts = [
        'region_id' => 'integer',
        'is_active' => 'boolean',
        'latitude' => 'float',
        'longitude' => 'float',
        'source_updated_at' => 'datetime',
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(AddressRegion::class, 'region_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(AddressCityAlias::class, 'city_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function name(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'en' ? $this->name_en : $this->name_ar;
    }
}
