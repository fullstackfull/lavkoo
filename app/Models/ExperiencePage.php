<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One page of an experience: home, header, footer, or anything a merchant adds later.
 *
 * @property int $id
 * @property int $theme_id
 * @property string $channel
 * @property string $slug
 * @property string|null $title
 * @property string $kind
 * @property bool $is_enabled
 * @property int $sort_order
 */
class ExperiencePage extends Model
{
    /** Ships with the engine, renders a screen the clients already have, cannot be deleted. */
    public const KIND_SYSTEM = 'system';

    /** The merchant's own — a campaign landing page, a seasonal edit. */
    public const KIND_CUSTOM = 'custom';

    /** Served to every channel. A page built for one surface carries that channel instead. */
    public const CHANNEL_SHARED = 'shared';

    /** The pages the engine guarantees exist for every theme. */
    public const SYSTEM_SLUGS = ['home', 'header', 'footer'];

    protected $fillable = [
        'theme_id', 'channel', 'slug', 'title', 'kind', 'is_enabled', 'sort_order',
    ];

    protected $casts = [
        'theme_id'   => 'integer',
        'is_enabled' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }

    /**
     * Every section placed on this page, across every version of the theme.
     *
     * A page outlives the versions that render it — that is the point of it having a row.
     */
    public function sections(): HasMany
    {
        return $this->hasMany(ThemeSection::class, 'experience_page_id');
    }

    public function isSystem(): bool
    {
        return $this->kind === self::KIND_SYSTEM;
    }

    /** What the builder shows when the merchant never named the page. */
    public function displayTitle(): string
    {
        return $this->title ?: ucfirst(str_replace(['-', '_'], ' ', $this->slug));
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }
}
