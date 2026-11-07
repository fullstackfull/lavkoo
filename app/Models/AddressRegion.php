<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One of the country's administrative regions; the first level of every structured address. */
class AddressRegion extends Model
{
    protected $table = 'address_regions';

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'source_updated_at' => 'datetime',
    ];

    public function cities(): HasMany
    {
        return $this->hasMany(AddressCity::class, 'region_id');
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
