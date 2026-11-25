<?php

namespace App\Models;

use App\Models\Scopes\StaffCountryProductRowScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Per-language SEO override for any entity (product, category, brand, vendor, page, …).
 */
class SeoMetaTranslation extends Model
{
    protected $table = 'seo_meta_translations';

    protected $fillable = [
        'seoable_type', 'seoable_id', 'language_code',
        'title', 'description', 'keywords', 'canonical_url',
        'og_title', 'og_description', 'og_image',
        'is_indexable', 'is_followable',
    ];

    protected $casts = [
        'is_indexable'  => 'boolean',
        'is_followable' => 'boolean',
    ];

    /**
     * The twenty most recent overrides are listed on the SEO translations screen. The ones that hang off a PRODUCT are narrowed to the
     * countries of a restricted employee (GCC-14c, slice 4), so the screen does not name another market's products; the overrides of a
     * category, a brand, a shop or a page have no market and are left exactly as they were. Inert for every request that binds no country.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::addGlobalScope(new StaffCountryProductRowScope(column: 'seoable_id', onlyWhen: ['seoable_type' => Product::class]));
    }

    public function seoable(): MorphTo
    {
        return $this->morphTo();
    }
}
