<?php

namespace App\Models;

use App\Services\Theme\PublishValidator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reusable child element inside a theme section (slide, footer column, menu link, …).
 */
class ThemeBlock extends Model
{
    protected $fillable = [
        'theme_section_id', 'type', 'sort_order', 'is_visible', 'settings',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
        'sort_order' => 'integer',
        'settings'   => 'array',
    ];

    protected static function booted(): void
    {
        // A block carries the content its section is judged on — a hero with no slides, a tab with
        // no label — so editing one invalidates the same cached verdict a section edit does.
        //
        // The section lookup is remembered for the request because reordering saves every block in
        // turn: without the memo, dragging one card to the top of ten costs ten identical lookups
        // of the same section. A section never changes version, so the memo cannot go stale.
        $versionOf = [];

        $forget = static function (self $block) use (&$versionOf) {
            $sectionId = (int) $block->theme_section_id;

            $versionOf[$sectionId] ??= ThemeSection::whereKey($sectionId)->value('theme_version_id');

            PublishValidator::forget($versionOf[$sectionId]);
        };

        static::saved($forget);
        static::deleted($forget);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(ThemeSection::class, 'theme_section_id');
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_visible', true);
    }
}
