<?php

namespace App\Services\SellerIntelligence;

use App\Models\SellerInsight;
use App\Services\Platform\Policy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * What needs doing, arranged by when it needs doing.
 *
 * Different from the home dashboard, which reports how the business is going. This reports what is
 * wrong with it right now — and the arrangement is the feature. A single list sorted by severity
 * still makes a seller read all of it to find the two things that must happen this morning; sections
 * answer "what now, what today, what is merely true" without any reading.
 *
 * Every section is a slice of the one issue store. Nothing here counts anything for itself, because
 * a Control Tower that computed its own numbers would be the second opinion this whole layer exists
 * to remove.
 *
 * Empty is the goal. A section with nothing in it is not a gap to fill with something reassuring —
 * it is the platform saying there is nothing there.
 */
class ControlTowerService
{
    /** Anything due inside this is today's problem, whatever its severity. */
    private const TODAY_HOURS = 24;

    /** How many rows a section carries. Beyond this the count is the answer, not the list. */
    private const SECTION_LIMIT = 20;

    public function __construct(private readonly SellerInsightEngine $insights)
    {
    }

    /**
     * The whole tower for one seller.
     *
     * @return array<string, mixed>
     */
    public function forSeller(int|string $sellerId): array
    {
        $live = $this->liveIssues($sellerId);

        return [
            'counts' => $this->insights->counts($sellerId),
            'sections' => [
                // Order matters: this is read top to bottom by somebody deciding what to do next.
                'critical_now' => $this->section($live->filter(
                    fn (SellerInsight $issue) => $issue->severity === SellerInsight::SEVERITY_CRITICAL,
                )),
                'needs_action_today' => $this->section($live->filter(
                    fn (SellerInsight $issue) => $issue->severity !== SellerInsight::SEVERITY_CRITICAL
                        && $issue->due_at !== null
                        && $issue->due_at->lte(now()->addHours(self::TODAY_HOURS)),
                )),
                'sla_risk' => $this->byCategory($live, SellerInsight::CATEGORY_ORDERS),
                'fulfillment_exceptions' => $this->byCategory($live, SellerInsight::CATEGORY_SHIPPING),
                'returns_requiring_action' => $this->byCategory($live, SellerInsight::CATEGORY_RETURNS),
                'financial_exceptions' => $this->byCategory($live, SellerInsight::CATEGORY_FINANCE),
                'inventory_risk' => $this->byCategory($live, SellerInsight::CATEGORY_INVENTORY),
                'catalog_and_pricing' => $this->section($live->filter(
                    fn (SellerInsight $issue) => in_array(
                        $issue->category,
                        [SellerInsight::CATEGORY_CATALOG, SellerInsight::CATEGORY_PRICING],
                        true,
                    ),
                )),
                // Not a problem list. This is where the platform shows its working on anything it
                // closed by itself, because "auto-resolved" is a claim that has to be checkable.
                'recently_auto_resolved' => $this->section($this->recentlyResolved($sellerId)),
            ],
            'health' => $this->health($sellerId, $live),
        ];
    }

    /**
     * How each domain is doing, from the issues standing in it.
     *
     * `healthy` here means "nothing detected", which is a narrower claim than "fine" and is worded
     * that way deliberately: a detector that has never run would also report nothing, and the
     * difference matters.
     *
     * @param  Collection<int, SellerInsight>  $live
     * @return array<string, mixed>
     */
    public function health(int|string $sellerId, ?Collection $live = null): array
    {
        $live ??= $this->liveIssues($sellerId);
        $health = [];

        foreach (SellerInsight::CATEGORIES as $category) {
            $inCategory = $live->where('category', $category);
            $worst = $inCategory
                ->sortBy(fn (SellerInsight $issue) => SellerInsight::SEVERITY_ORDER[$issue->severity] ?? 99)
                ->first();

            $health[$category] = [
                'state' => match ($worst?->severity) {
                    SellerInsight::SEVERITY_CRITICAL => 'critical',
                    SellerInsight::SEVERITY_HIGH => 'degraded',
                    SellerInsight::SEVERITY_MEDIUM, SellerInsight::SEVERITY_LOW => 'watch',
                    default => 'healthy',
                },
                'open' => $inCategory->count(),
                // What the issues in this domain are about, so a state has a reason attached.
                'affected' => (int) $inCategory->sum('affected_count'),
            ];
        }

        return $health;
    }

    /**
     * The one-line summary a badge needs.
     *
     * @return array<string, mixed>
     */
    public function summary(int|string $sellerId): array
    {
        $live = $this->liveIssues($sellerId);

        return [
            'critical' => $live->where('severity', SellerInsight::SEVERITY_CRITICAL)->count(),
            'due_today' => $live->filter(
                fn (SellerInsight $issue) => $issue->due_at !== null
                    && $issue->due_at->lte(now()->addHours(self::TODAY_HOURS)),
            )->count(),
            'total' => $live->count(),
            // What the standing issues are about, which is the number that makes "37 products need
            // action" out of "4 issues".
            'affected' => (int) $live->sum('affected_count'),
        ];
    }

    /** @return Collection<int, SellerInsight> */
    private function liveIssues(int|string $sellerId): Collection
    {
        if (!Schema::hasTable('seller_insights')) {
            return collect();
        }

        // Read once and sliced in memory. Nine sections against the same rows is nine queries
        // otherwise, on a page a seller opens all day.
        return SellerInsight::forSeller($sellerId)->open()->worstFirst()->limit(app(Policy::class)->int('limit_control_tower_rows'))->get();
    }

    /** @return Collection<int, SellerInsight> */
    private function recentlyResolved(int|string $sellerId): Collection
    {
        if (!Schema::hasTable('seller_insights')) {
            return collect();
        }

        return SellerInsight::forSeller($sellerId)
            ->whereIn('status', [SellerInsight::STATUS_RESOLVED, SellerInsight::STATUS_AUTO_RESOLVED])
            ->where('resolved_at', '>=', now()->subDays(7))
            ->orderByDesc('resolved_at')
            ->limit(self::SECTION_LIMIT)
            ->get();
    }

    /** @param Collection<int, SellerInsight> $live */
    private function byCategory(Collection $live, string $category): array
    {
        return $this->section($live->where('category', $category));
    }

    /**
     * A section is a count and the first few, never the whole list.
     *
     * The count is the part a seller acts on — "21 orders require action" — and the rows are there
     * so the number can be opened rather than believed.
     *
     * @param  Collection<int, SellerInsight>  $issues
     * @return array<string, mixed>
     */
    private function section(Collection $issues): array
    {
        return [
            'count' => $issues->count(),
            'affected' => (int) $issues->sum('affected_count'),
            'issues' => $issues->take(self::SECTION_LIMIT)->map(fn (SellerInsight $issue) => [
                'id' => $issue->id,
                'type' => $issue->type,
                'category' => $issue->category,
                'severity' => $issue->severity,
                'status' => $issue->status,
                'title' => $issue->title,
                'body' => $issue->body,
                'entity_type' => $issue->entity_type,
                'entity_id' => $issue->entity_id,
                'affected_count' => $issue->affected_count,
                'impact' => $issue->impact,
                'impact_score' => $issue->impact_score,
                'due_at' => $issue->due_at,
                'is_overdue' => $issue->isOverdue(),
                'escalation_level' => $issue->escalation_level,
                'action_key' => $issue->action_key,
                'action_params' => $issue->action_params,
                'dismissible' => $issue->isDismissible(),
                'first_detected_at' => $issue->first_detected_at,
                'resolved_at' => $issue->resolved_at,
                'resolution_type' => $issue->resolution_type,
            ])->values(),
        ];
    }
}
