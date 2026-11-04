<?php

namespace App\Models;

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

    public function seoable(): MorphTo
    {
        return $this->morphTo();
    }
}
