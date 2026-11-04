<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A global SEO title/description template with placeholders, per entity type + language.
 */
class SeoTemplate extends Model
{
    protected $fillable = [
        'entity_type', 'language_code', 'title_template', 'description_template', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
