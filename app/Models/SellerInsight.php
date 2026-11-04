<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One operational issue: something wrong, at risk or unusual in a seller's business.
 *
 * The table is still called `seller_insights` because renaming a production table would break every
 * reader for a cosmetic gain. The row is the issue.
 *
 * `status` is the lifecycle a seller actually works in — acknowledged is not the same as started,
 * and started is not the same as waiting on somebody else. It also separates a problem the platform
 * fixed by itself from one a person fixed, which is the difference between claiming self-healing and
 * demonstrating it.
 *
 * @property int $id
 * @property int $seller_id
 * @property string $type
 * @property string $severity
 * @property string $title
 */
class SellerInsight extends Model
{
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_LOW = 'low';

    /** Worst first. Stored as words for readability; ordered by this map, never alphabetically. */
    public const SEVERITY_ORDER = [
        self::SEVERITY_CRITICAL => 0,
        self::SEVERITY_HIGH => 1,
        self::SEVERITY_MEDIUM => 2,
        self::SEVERITY_LOW => 3,
    ];

    public const STATUS_DETECTED = 'detected';
    public const STATUS_OPEN = 'open';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_WAITING = 'waiting';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_AUTO_RESOLVED = 'auto_resolved';
    public const STATUS_DISMISSED = 'dismissed';

    /** Still somebody's problem. Everything the Control Tower counts is one of these. */
    public const LIVE_STATUSES = [
        self::STATUS_DETECTED, self::STATUS_OPEN, self::STATUS_ACKNOWLEDGED,
        self::STATUS_IN_PROGRESS, self::STATUS_WAITING,
    ];

    /** Nothing more happens to these. */
    public const CLOSED_STATUSES = [
        self::STATUS_RESOLVED, self::STATUS_AUTO_RESOLVED, self::STATUS_DISMISSED,
    ];

    /** Statuses a seller may move an issue to themselves. Resolution is the detector's to declare. */
    public const SELLER_SETTABLE_STATUSES = [
        self::STATUS_ACKNOWLEDGED, self::STATUS_IN_PROGRESS, self::STATUS_WAITING, self::STATUS_OPEN,
    ];

    public const CATEGORY_ORDERS = 'orders';
    public const CATEGORY_INVENTORY = 'inventory';
    public const CATEGORY_CATALOG = 'catalog';
    public const CATEGORY_PRICING = 'pricing';
    public const CATEGORY_RETURNS = 'returns';
    public const CATEGORY_SHIPPING = 'shipping';
    public const CATEGORY_FINANCE = 'finance';
    public const CATEGORY_INTEGRATIONS = 'integrations';

    public const CATEGORIES = [
        self::CATEGORY_ORDERS, self::CATEGORY_INVENTORY, self::CATEGORY_CATALOG,
        self::CATEGORY_PRICING, self::CATEGORY_RETURNS, self::CATEGORY_SHIPPING,
        self::CATEGORY_FINANCE, self::CATEGORY_INTEGRATIONS,
    ];

    public const RESOLUTION_AUTO = 'auto';
    public const RESOLUTION_SELLER = 'seller';
    public const RESOLUTION_EXPIRED = 'expired';
    public const RESOLUTION_SUPERSEDED = 'superseded';

    protected $fillable = [
        'seller_id', 'type', 'category', 'severity', 'status', 'title', 'body',
        'entity_type', 'entity_id', 'metric', 'impact', 'impact_score', 'affected_count',
        'action_key', 'action_params', 'metadata', 'fingerprint',
        'expires_at', 'due_at', 'first_detected_at', 'last_detected_at', 'detection_count',
        'escalation_level', 'assigned_staff_id',
        'dismissed_at', 'resolved_at', 'resolution_type', 'resolution_message',
    ];

    protected $casts = [
        'seller_id' => 'integer',
        'metric' => 'float',
        'impact' => 'float',
        'impact_score' => 'integer',
        'affected_count' => 'integer',
        'detection_count' => 'integer',
        'escalation_level' => 'integer',
        'assigned_staff_id' => 'integer',
        'action_params' => 'array',
        'metadata' => 'array',
        'expires_at' => 'datetime',
        'due_at' => 'datetime',
        'first_detected_at' => 'datetime',
        'last_detected_at' => 'datetime',
        'dismissed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /**
     * Live: still somebody's problem, and not past its own expiry.
     *
     * The dismissed and resolved timestamp checks stay alongside the status check rather than being
     * replaced by it. They are the same fact written twice — the backfill made sure of that — and a
     * row written by an older deployment mid-rollout would otherwise read as open.
     */
    /**
     * Worst first, everywhere (handoff 06 §1).
     *
     * A CASE rather than MySQL's `FIELD()`: the ordering is domain logic, and a list that only
     * sorts correctly on one database engine is a list that sorts wrongly in every test.
     */
    public function scopeOrderBySeverity(Builder $query, string $direction = 'asc'): Builder
    {
        $case = 'CASE severity';
        $bindings = [];
        foreach (self::SEVERITY_ORDER as $severity => $rank) {
            $case .= ' WHEN ? THEN ' . (int) $rank;
            $bindings[] = $severity;
        }
        $case .= ' ELSE 99 END ' . ($direction === 'desc' ? 'DESC' : 'ASC');

        return $query->orderByRaw($case, $bindings);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::LIVE_STATUSES)
            ->whereNull('dismissed_at')
            ->whereNull('resolved_at')
            ->where(function (Builder $inner) {
                $inner->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function scopeForSeller(Builder $query, int|string $sellerId): Builder
    {
        return $query->where('seller_id', $sellerId);
    }

    /** Worst first, then by how much it matters, then newest. The Control Tower's order. */
    public function scopeWorstFirst(Builder $query): Builder
    {
        return $query
            ->orderByRaw($this->severityOrderExpression())
            ->orderByDesc('impact_score')
            ->orderByDesc('id');
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE_STATUSES, true);
    }

    /** Critical standing cannot be hidden: a seller may decline to act, not to be told. */
    public function isDismissible(): bool
    {
        return $this->severity !== self::SEVERITY_CRITICAL;
    }

    /** Past its deadline, where it had one. */
    public function isOverdue(): bool
    {
        return $this->due_at !== null && $this->due_at->isPast() && $this->isLive();
    }

    /** How long this has been true, in hours — what escalation runs on. */
    public function openForHours(): float
    {
        $since = $this->first_detected_at ?? $this->created_at;

        return $since ? round($since->diffInMinutes(now()) / 60, 2) : 0.0;
    }

    /**
     * Severity ordering as SQL, so a page of issues comes back sorted rather than being sorted after
     * it arrives — which only ever sorts the page, not the list.
     */
    private function severityOrderExpression(): string
    {
        $cases = '';
        foreach (self::SEVERITY_ORDER as $severity => $rank) {
            $cases .= sprintf(" WHEN '%s' THEN %d", $severity, $rank);
        }

        return 'CASE severity' . $cases . ' ELSE 99 END';
    }
}
