<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A scheduled overlay on a composed page (Phase 3.3).
 *
 * A campaign never rewrites the page it dresses: its overrides are applied at serve time on top
 * of whatever is published, and the moment its window closes — or it is paused, cancelled or
 * deleted — the base page is simply what it always was. That is the §33/§34 guarantee, held by
 * construction rather than by a restore step that could fail.
 */
class ExperienceCampaign extends Model
{
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_PAUSED    = 'paused';
    public const STATUS_ENDED     = 'ended';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_SCHEDULED, self::STATUS_ACTIVE,
        self::STATUS_PAUSED, self::STATUS_ENDED, self::STATUS_CANCELLED,
    ];

    /** The statuses a serve may consider at all; the window still has the final word. */
    public const SERVABLE_STATUSES = [self::STATUS_SCHEDULED, self::STATUS_ACTIVE];

    protected $fillable = ['name', 'status', 'page', 'priority', 'starts_at', 'ends_at', 'overrides'];

    protected $casts = [
        'priority'  => 'integer',
        'starts_at' => 'datetime',
        'ends_at'   => 'datetime',
        'overrides' => 'array',
    ];

    /**
     * Whether this campaign dresses the page RIGHT NOW.
     *
     * Checked at serve time even for rows the scheduler has already transitioned, so a campaign
     * ends on time whether or not the cron ran — the scheduler tidies statuses, the window
     * decides reality.
     */
    public function isLive(?Carbon $now = null): bool
    {
        $now ??= now();

        // SCHEDULED means "live once its window opens" — one without a start time has no window
        // to open, and serving it immediately would be an activation nobody performed. ACTIVE is
        // an explicit go-live, so a missing start simply means "since activation".
        if ($this->status === self::STATUS_SCHEDULED && $this->starts_at === null) {
            return false;
        }

        return in_array($this->status, self::SERVABLE_STATUSES, true)
            && ($this->starts_at === null || $now->greaterThanOrEqualTo($this->starts_at))
            && ($this->ends_at === null || $now->lessThan($this->ends_at));
    }

    /** @return array<int, array<string, mixed>> */
    public function overrideRows(): array
    {
        return array_values(array_filter(
            is_array($this->overrides) ? $this->overrides : [],
            'is_array',
        ));
    }
}
