<?php

namespace App\Services\SellerIntelligence;

use App\Models\SellerInsight;
use App\Services\SellerIntelligence\Severity\ImpactSignals;
use App\Services\SellerIntelligence\Severity\SellerBaselineProvider;
use App\Services\SellerIntelligence\Severity\SeverityEngine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Runs the producers and keeps the insight store honest.
 *
 * The contract with a producer is deliberately simple: tell me everything this seller should be
 * warned about right now. Whatever it does not mention, this engine marks resolved. That means a
 * producer never has to remember what it said last time, and — more importantly — a warning
 * disappears by itself the moment the seller fixes what caused it. An alert that has to be dismissed
 * after it stops being true is how alert lists become wallpaper.
 */
class SellerInsightEngine
{
    /** @var array<int, InsightProducer> */
    private array $producers;

    /**
     * @param  iterable<InsightProducer>  $producers
     */
    public function __construct(
        iterable $producers = [],
        private readonly ?SeverityEngine $severity = null,
        private readonly ?SellerBaselineProvider $baselines = null,
    ) {
        $this->producers = $producers instanceof \Traversable ? iterator_to_array($producers) : $producers;
    }

    /** @return array<int, InsightProducer> */
    public function producers(): array
    {
        return $this->producers;
    }

    /**
     * Recompute one seller's insights.
     *
     * @param  array<int, string>|null  $only  limit to these types, for a targeted refresh after an event
     * @return array{written: int, resolved: int}
     */
    public function refresh(int|string $sellerId, ?array $only = null): array
    {
        if (!Schema::hasTable('seller_insights')) {
            return ['written' => 0, 'resolved' => 0];
        }

        $written = 0;
        $resolved = 0;

        // Measured once for the whole sweep. Severity is relative to this seller's own business, so
        // every detector needs the same denominators and none of them should re-query for them.
        $this->baselines?->forget($sellerId);

        foreach ($this->producers as $producer) {
            if ($only !== null && !in_array($producer->type(), $only, true)) {
                continue;
            }

            $seen = [];

            foreach ($producer->produce($sellerId) as $draft) {
                if (!$this->isValid($draft, $producer)) {
                    continue;
                }

                $seen[] = $draft->fingerprint();
                $this->store($draft);
                $written++;
            }

            $resolved += $this->resolveMissing($sellerId, $producer->type(), $seen);
        }

        return ['written' => $written, 'resolved' => $resolved];
    }

    /**
     * The seller's live insights, worst first.
     *
     * Ordered by the severity map rather than by the stored word, because alphabetically "critical"
     * sorts after "high" and the most urgent thing would land second.
     *
     * @param  array<int, string>|null  $types
     * @return Collection<int, SellerInsight>
     */
    public function open(int|string $sellerId, ?array $types = null, ?string $severity = null, int $limit = 50): Collection
    {
        if (!Schema::hasTable('seller_insights')) {
            return collect();
        }

        return SellerInsight::query()
            ->forSeller($sellerId)
            ->open()
            ->when($types, fn ($query, $only) => $query->whereIn('type', $only))
            ->when($severity, fn ($query, $level) => $query->where('severity', $level))
            ->get()
            // Sorted with an explicit comparator. `sortBy` given a list of bare callables does not
            // multi-sort — it treats them as one key — which put a medium insight above a high one.
            ->sort(function (SellerInsight $a, SellerInsight $b) {
                $rank = (SellerInsight::SEVERITY_ORDER[$a->severity] ?? 99)
                    <=> (SellerInsight::SEVERITY_ORDER[$b->severity] ?? 99);

                // Same severity: newest first, so a fresh warning is not buried under old ones.
                return $rank !== 0 ? $rank : $b->id <=> $a->id;
            })
            ->take($limit)
            ->values();
    }

    /** How many open insights this seller has, by severity — what a home badge needs. */
    public function counts(int|string $sellerId): array
    {
        $counts = array_fill_keys(array_keys(SellerInsight::SEVERITY_ORDER), 0);

        if (!Schema::hasTable('seller_insights')) {
            return $counts + ['total' => 0];
        }

        foreach (SellerInsight::forSeller($sellerId)->open()->get(['severity']) as $insight) {
            if (array_key_exists($insight->severity, $counts)) {
                $counts[$insight->severity]++;
            }
        }

        return $counts + ['total' => array_sum($counts)];
    }

    /**
     * The seller says they do not want to see this one.
     *
     * Critical insights cannot be dismissed: a seller may choose not to act on a suggestion, but not
     * to hide that their account is at risk. Returns false when the insight is not theirs, which is
     * the same answer as "no such insight" — an id is not a way to discover other sellers' rows.
     */
    public function dismiss(int|string $sellerId, int|string $insightId): bool
    {
        $insight = SellerInsight::forSeller($sellerId)->whereKey($insightId)->first();

        if (!$insight || $insight->severity === SellerInsight::SEVERITY_CRITICAL) {
            return false;
        }

        $insight->forceFill([
            'dismissed_at' => now(),
            'status' => SellerInsight::STATUS_DISMISSED,
        ])->save();

        return true;
    }

    /**
     * A draft has to be addressed, typed by its own producer and carry a severity we recognise.
     *
     * A producer that gets this wrong is a bug, and a bug here would put an unsortable or
     * unattributable row in front of a seller.
     */
    private function isValid(InsightDraft $draft, InsightProducer $producer): bool
    {
        return $draft->type === $producer->type()
            && $draft->title !== ''
            && in_array($draft->severity, InsightDraft::severities(), true);
    }

    /**
     * Write the draft, keeping whatever history the standing issue already has.
     *
     * An issue re-detected is the same issue with a longer story, not a new one. `first_detected_at`
     * and `detection_count` survive the upsert because "how long has this been true" and "how many
     * times have we seen it" are what escalation and the recurrence component run on — and both
     * would reset to zero on every sweep if the upsert simply overwrote the row.
     *
     * A seller's own status survives too. Someone who marked an issue in-progress an hour ago should
     * not find it back at `open` because a sweep ran; only a resolution changes that, and a
     * resolution is the detector's to declare by ceasing to report.
     */
    private function store(InsightDraft $draft): void
    {
        $existing = SellerInsight::where('fingerprint', $draft->fingerprint())->first();

        $history = [
            'first_detected_at' => $existing?->first_detected_at ?? now(),
            'last_detected_at' => now(),
            'detection_count' => ($existing?->detection_count ?? 0) + 1,
        ];

        $scored = $this->scored($draft, $existing, $history['detection_count']);

        // array_merge, not `+`. The union operator keeps the LEFT operand for a duplicate key, so
        // writing `$draft->attributes() + $scored` silently discarded the engine's computed severity
        // and its score breakdown in favour of the detector's own declared severity — which is the
        // whole thing the severity engine exists to replace. It went unnoticed because the two
        // happened to agree on the first cases tried.
        $attributes = array_merge(
            $draft->attributes(),
            $history,
            $scored,
            [
                // Reopened: the problem is back, whatever it was closed as.
                'resolved_at' => null,
                'resolution_type' => null,
                'resolution_message' => null,
                'status' => $this->statusFor($existing),
            ],
        );

        SellerInsight::updateOrCreate(['fingerprint' => $draft->fingerprint()], $attributes);
    }

    /**
     * Severity and impact, measured where the detector gave the engine something to measure.
     *
     * A detector that supplies no signals keeps the severity it declared. That is not a fallback so
     * much as the correct answer for a finding that is not a matter of degree — and it is what lets
     * the producers written before this engine existed keep working unchanged.
     *
     * @return array<string, mixed>
     */
    private function scored(InsightDraft $draft, ?SellerInsight $existing, int $detectionCount): array
    {
        if ($draft->signals === null || $this->severity === null) {
            return ['severity' => $draft->severity];
        }

        $baseline = $this->baselines?->for($draft->sellerId);

        // The detector measured its own half; the engine fills in the seller's, because a detector
        // should not have to know how big the shop is to report a problem in it.
        $signals = new ImpactSignals(
            revenueAtRisk: $draft->signals->revenueAtRisk,
            sellerRecentRevenue: $draft->signals->sellerRecentRevenue ?? $baseline?->recentRevenue,
            affectedCount: $draft->signals->affectedCount ?? $draft->affectedCount,
            sellerTotalCount: $draft->signals->sellerTotalCount ?? $baseline?->totalFor($draft->category),
            hoursUntilDue: $draft->signals->hoursUntilDue,
            openForHours: $draft->signals->openForHours ?? $existing?->openForHours(),
            detectionCount: $detectionCount,
            // Only what the detector explicitly declared as a floor. Treating its `severity` field
            // as one would let the engine raise a finding but never lower it — and lowering is the
            // whole point: a stockout on something that sells twice a year must be able to come out
            // below a stockout on the best seller, whatever the detector guessed.
            severityFloor: $draft->signals->severityFloor,
        );

        $score = $this->severity->score($signals);

        return [
            'severity' => $this->severity->severity($signals, $score),
            'impact_score' => $score,
            // The arithmetic that produced it, so "why is this critical" has an answer that is not
            // "because the system said so".
            'metadata' => array_merge($draft->metadata ?? [], ['score_breakdown' => $this->severity->breakdown($signals)]),
        ];
    }

    /**
     * The status a re-detected issue should carry.
     *
     * A seller working on something keeps working on it. A closed issue that has come back is open
     * again — the problem is real regardless of what anybody decided about it last time.
     */
    private function statusFor(?SellerInsight $existing): string
    {
        if ($existing && $existing->isLive()) {
            return $existing->status;
        }

        return SellerInsight::STATUS_OPEN;
    }

    /**
     * Anything this producer used to say and no longer does has stopped being true.
     *
     * @param  array<int, string>  $seen
     */
    private function resolveMissing(int|string $sellerId, string $type, array $seen): int
    {
        return SellerInsight::forSeller($sellerId)
            ->where('type', $type)
            ->whereNull('resolved_at')
            ->when($seen !== [], fn ($query) => $query->whereNotIn('fingerprint', $seen))
            ->update([
                'resolved_at' => now(),
                // The platform fixed nothing here — the condition stopped being true, which is a
                // different claim from self-healing and is recorded as one.
                'status' => SellerInsight::STATUS_RESOLVED,
                'resolution_type' => SellerInsight::RESOLUTION_AUTO,
            ]);
    }
}
