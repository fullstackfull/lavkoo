<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A storefront theme (Phase 1.1). Owns an ordered history of versions; exactly one theme is
 * active, and each theme has at most one published version at a time.
 *
 * While the storefront themes are switched on (gcc.storefronts.themes.enabled) "exactly one" is
 * one per storefront: a theme belongs to the storefront its `storefront_country` names, or to the
 * default storefront when that is NULL. Which theme a storefront serves is answered by
 * App\Services\Theme\StorefrontThemeResolver and nowhere else.
 */
class Theme extends Model
{
    protected $fillable = [
        'name', 'slug', 'description', 'preview_image',
        'is_active', 'is_system', 'created_by_type', 'created_by_id',
        'storefront_country',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_system' => 'boolean',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(ThemeVersion::class);
    }

    /** Uploaded images (logo, favicon, backgrounds). Owned by the theme, so versioning cannot lose them. */
    public function assets(): HasMany
    {
        return $this->hasMany(ThemeAsset::class);
    }

    public function publishedVersion(): HasOne
    {
        return $this->hasOne(ThemeVersion::class)->where('status', ThemeVersion::STATUS_PUBLISHED);
    }
}
