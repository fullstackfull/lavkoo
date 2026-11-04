<?php

namespace App\Services\Marketplace;

use App\Models\Seller;
use App\Models\SellerSlaBreach;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Schema;

/**
 * Seller SLA policy and breach ledger (Phase 3, Stage E).
 *
 * Turns the point-in-time scorecard into an enforceable policy: configurable thresholds, evaluated
 * against a seller's metrics, producing a breach ledger that opens when a line is crossed and clears
 * when the seller comes back inside it. It reuses SellerScorecardService for the numbers — one source
 * of truth, so the SLA and the scorecard can never disagree about a seller's cancellation rate.
 *
 * The comparison itself (`breachesFor`) is a pure function, so the policy is testable without a
 * database and any caller evaluating a hypothetical gets the same answer the ledger would record.
 */
class SlaService
{
    /** Rate ceilings are breached when the actual value is ABOVE them; rating is a floor. */
    public const RATE_METRICS = ['cancellation_rate', 'return_rate', 'refund_rate'];
    public const RATING_METRIC = 'avg_rating';

    /** Rating only counts once there are enough reviews to be meaningful — same gate the scorecard uses. */
    private const MIN_REVIEWS_FOR_RATING = 5;

    public function __construct(
        private readonly ?SellerScorecardService $scorecards = null,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * The policy thresholds, from settings with sensible defaults. Rates are maxima (a ceiling on how
     * bad they may get); avg_rating is a minimum floor.
     */
    public function thresholds(): array
    {
        return [
            'cancellation_rate' => (float) ($this->setting('sla_max_cancellation_rate') ?? 0.10),
            'return_rate' => (float) ($this->setting('sla_max_return_rate') ?? 0.10),
            'refund_rate' => (float) ($this->setting('sla_max_refund_rate') ?? 0.15),
            'avg_rating' => (float) ($this->setting('sla_min_rating') ?? 3.5),
            // How long a seller has to get an order moving, in hours.
            //
            // 24 is the marketplace's declared policy, not a placeholder: a processing deadline is a
            // promise made to customers and held against sellers, so the number has to come from
            // whoever runs the market. It is editable on the SLA policy page like every other line
            // here, and the seller-facing countdown reads this and nothing else.
            'processing_hours' => max(1, (int) ($this->setting('sla_processing_hours') ?? 24)),
        ];
    }

    /**
     * Statuses that still need something from the seller before the order can ship.
     *
     * The processing clock runs only while one of these holds. Once the order is out for delivery
     * it is the courier's, and once it is delivered or cancelled there is nothing left to be late
     * for.
     */
    public const AWAITING_SELLER_STATUSES = ['pending', 'confirmed', 'processing'];

    public function awaitsSeller(?string $orderStatus): bool
    {
        return in_array($orderStatus, self::AWAITING_SELLER_STATUSES, true);
    }

    /**
     * How long a seller has to get an order moving, or null when the policy is switched off.
     */
    public function processingWindowHours(): ?int
    {
        $hours = $this->thresholds()['processing_hours'] ?? null;

        return $hours !== null && $hours > 0 ? (int) $hours : null;
    }

    /**
     * When an order placed at this moment is due.
     *
     * Lives here rather than in whichever screen needs it, because the seller's countdown and the
     * deadline the marketplace judges them by must be the same number. Two clocks is worse than
     * none: a countdown that is kinder than the policy teaches sellers to trust it and then
     * penalises them for doing so.
     */
    public function processingDeadline(?\DateTimeInterface $placedAt): ?\Illuminate\Support\Carbon
    {
        $windowHours = $this->processingWindowHours();

        if ($placedAt === null || $windowHours === null) {
            return null;
        }

        return \Illuminate\Support\Carbon::instance($placedAt)->copy()->addHours($windowHours);
    }

    /**
     * Hours left before the deadline — negative once it has passed, so one number says both how
     * long is left and how late it is.
     */
    public function hoursUntilDeadline(?\DateTimeInterface $placedAt): ?float
    {
        $deadline = $this->processingDeadline($placedAt);

        return $deadline === null ? null : round(now()->diffInMinutes($deadline, false) / 60, 2);
    }

    /**
     * Which policy lines a metrics array breaches — pure, no side effects.
     *
     * @return array<int, array{metric: string, threshold: float, actual: float}>
     */
    public function breachesFor(array $metrics, ?array $thresholds = null): array
    {
        $t = $thresholds ?? $this->thresholds();
        $breaches = [];

        foreach (self::RATE_METRICS as $metric) {
            $actual = (float) ($metrics[$metric] ?? 0);
            if ($actual > $t[$metric]) {
                $breaches[] = ['metric' => $metric, 'threshold' => $t[$metric], 'actual' => $actual];
            }
        }

        // Rating is a floor, and only judged with enough reviews behind it.
        $reviewCount = (int) ($metrics['review_count'] ?? 0);
        $rating = $metrics['avg_rating'] ?? null;
        if ($reviewCount >= self::MIN_REVIEWS_FOR_RATING && $rating !== null && (float) $rating < $t['avg_rating']) {
            $breaches[] = ['metric' => self::RATING_METRIC, 'threshold' => $t['avg_rating'], 'actual' => (float) $rating];
        }

        return $breaches;
    }

    /**
     * Evaluate one seller against the policy and reconcile the ledger: open a breach for each newly
     * crossed line, clear any open breach the seller is now back inside. Returns the open breaches.
     */
    public function evaluate(int|string $sellerId): array
    {
        if (!Schema::hasTable('seller_sla_breaches')) {
            return [];
        }

        $metrics = ($this->scorecards ?? new SellerScorecardService())->scorecard($sellerId);
        $current = collect($this->breachesFor($metrics))->keyBy('metric');

        $open = SellerSlaBreach::where('seller_id', $sellerId)->open()->get()->keyBy('metric');

        // Clear breaches the seller has recovered from.
        foreach ($open as $metric => $row) {
            if (!$current->has($metric)) {
                $row->update(['status' => SellerSlaBreach::STATUS_CLEARED, 'cleared_at' => now()]);
            }
        }

        // Open breaches for newly crossed lines (leave an already-open one as it is — one row per line).
        foreach ($current as $metric => $b) {
            if (!$open->has($metric)) {
                SellerSlaBreach::create([
                    'seller_id' => $sellerId,
                    'metric' => $metric,
                    'threshold' => $b['threshold'],
                    'actual_value' => $b['actual'],
                    'status' => SellerSlaBreach::STATUS_OPEN,
                ]);
                $this->audit?->record(
                    action: 'sla.breach_opened',
                    subject: ['type' => 'seller', 'id' => $sellerId],
                    after: ['metric' => $metric, 'threshold' => $b['threshold'], 'actual' => $b['actual']],
                );
            }
        }

        return SellerSlaBreach::where('seller_id', $sellerId)->open()->get()->all();
    }

    /**
     * Evaluate every approved seller. Bounded by the seller count; a very large marketplace would move
     * this to a queued job, which is a scale change, not a correctness one.
     *
     * @return int the number of sellers evaluated
     */
    public function evaluateAll(): int
    {
        if (!Schema::hasTable('sellers')) {
            return 0;
        }

        $count = 0;
        Seller::where('status', 'approved')->orderBy('id')->chunk(100, function ($sellers) use (&$count) {
            foreach ($sellers as $seller) {
                $this->evaluate($seller->id);
                $count++;
            }
        });

        return $count;
    }

    private function setting(string $name): mixed
    {
        try {
            return getWebConfig(name: $name);
        } catch (\Throwable) {
            return null;
        }
    }
}
