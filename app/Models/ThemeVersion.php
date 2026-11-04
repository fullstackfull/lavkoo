<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A versioned snapshot of a theme's configuration. Lifecycle: draft -> published -> archived.
 */
class ThemeVersion extends Model
{
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED  = 'archived';

    protected $fillable = [
        'theme_id', 'label', 'change_note', 'status', 'settings', 'based_on_version_id', 'published_at',
        'publish_at', 'revision', 'checksum',
    ];

    protected $casts = [
        'settings'     => 'array',
        'published_at' => 'datetime',
        'publish_at'   => 'datetime',
        'revision'     => 'integer',
    ];

    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ThemeSection::class)->orderBy('sort_order');
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    /**
     * Drafts whose scheduled moment has arrived.
     *
     * Scoped to drafts on purpose: publishing archives the previous version, so a version that has
     * already gone live must never be picked up again by a schedule nobody cleared.
     */
    public function scopeDueToPublish(Builder $query): Builder
    {
        return $query->draft()
            ->whereNotNull('publish_at')
            ->where('publish_at', '<=', now());
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /** Whether this version is waiting for a moment that has not come yet. */
    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_DRAFT && $this->publish_at !== null;
    }
}
