<?php

namespace App\Services\SellerIntelligence\Severity;

use App\Models\SellerInsight;

/**
 * How much a problem matters to this particular seller.
 *
 * The brief is explicit that severity must not follow from the technical kind of error, and gives
 * the example that decides the design: a stockout on a product selling once every sixty days is not
 * the same event as a stockout on the seller's best seller. Every measure here is therefore
 * **relative to the seller's own business**, never absolute. Ten units is a catastrophe for one shop
 * and a rounding error for another, and an engine that scored them the same would be sorting by
 * nothing.
 *
 * ## The rules, in full
 *
 * The score is out of 100 and is the sum of five components. Each is capped, each is computed only
 * from a signal the detector actually supplied, and none of them is a constant somebody picked to
 * make a number look right.
 *
 * | Component | Max | How it is computed |
 * |---|---|---|
 * | Revenue | 40 | `revenue_at_risk ÷ seller_recent_revenue`, as a share of their own turnover. A quarter of it or more scores the full 40; the curve below that is linear. |
 * | Volume | 25 | `affected_count ÷ seller_total_count`, the share of their catalogue or order book involved. A tenth or more scores the full 25. |
 * | Urgency | 20 | Full once the deadline has passed. Inside it, linear from the point where a quarter of a day is left. |
 * | Duration | 10 | Full at seven days open. Linear below. |
 * | Recurrence | 5 | Full at ten detections. Linear below. |
 *
 * ## Two rules about honesty
 *
 * **A missing signal scores zero — it does not average away.** If a detector cannot measure revenue,
 * the revenue component is 0 rather than "the mean of what we do know". Scaling the score up to
 * compensate would be inventing the missing measurement, and the result would sort a
 * partially-measured issue above a fully-measured one. `confidence()` reports what fraction of the
 * components had data, so a caller can tell a well-measured 60 from a thinly-measured one.
 *
 * **A floor is absolute.** A detector that declares a finding critical gets critical, whatever the
 * arithmetic. Some things are not a matter of degree.
 *
 * ## Bands
 *
 * `critical` at 75, `high` at 40, `medium` at 20, `low` below. The numbers are chosen against the
 * weights above rather than picked, and two properties fall out of them:
 *
 * **The loudest single signal reaches `high` and stops.** Revenue saturates at 40, which is the
 * `high` band exactly. A detector reporting that a quarter of a seller's turnover is at stake, and
 * able to measure nothing else, produces a strong finding — not an emergency. Volume alone (25) and
 * urgency alone (20) land at `medium`, which is right for "a tenth of your catalogue is involved"
 * and "this deadline has passed" said in isolation.
 *
 * **`critical` needs at least three components.** The cheapest route to 75 is revenue, urgency,
 * duration and recurrence together; the usual one is revenue, volume and urgency. Nothing gets there
 * on one measurement, which is what stops a single noisy detector from filling a seller's screen
 * with emergencies.
 *
 * The first version of this had `high` at 50, above what revenue alone could reach, so a detector
 * that found a quarter of a shop's turnover at risk and nothing else reported `medium`. The bands
 * are load-bearing and a test pins them.
 */
class SeverityEngine
{
    /** Component ceilings. They total 100 by construction, not by coincidence. */
    public const WEIGHT_REVENUE = 40;
    public const WEIGHT_VOLUME = 25;
    public const WEIGHT_URGENCY = 20;
    public const WEIGHT_DURATION = 10;
    public const WEIGHT_RECURRENCE = 5;

    /** A quarter of a seller's recent revenue at stake is as bad as this component measures. */
    private const REVENUE_SATURATION = 0.25;

    /** A tenth of their catalogue or order book involved is as broad as this component measures. */
    private const VOLUME_SATURATION = 0.10;

    /** Inside six hours the urgency component starts climbing; past the deadline it is full. */
    private const URGENCY_WINDOW_HOURS = 6.0;

    /** A week open is as long as this component measures. */
    private const DURATION_SATURATION_HOURS = 168.0;

    /** Ten sightings is as recurrent as this component measures. */
    private const RECURRENCE_SATURATION = 10;

    public const BAND_CRITICAL = 75;
    public const BAND_HIGH = 40;
    public const BAND_MEDIUM = 20;

    /**
     * The score, 0–100.
     */
    public function score(ImpactSignals $signals): int
    {
        $total = $this->revenuePoints($signals)
            + $this->volumePoints($signals)
            + $this->urgencyPoints($signals)
            + $this->durationPoints($signals)
            + $this->recurrencePoints($signals);

        return (int) max(0, min(100, round($total)));
    }

    /**
     * The severity band, never below the detector's floor.
     */
    public function severity(ImpactSignals $signals, ?int $score = null): string
    {
        $score ??= $this->score($signals);

        $derived = match (true) {
            $score >= self::BAND_CRITICAL => SellerInsight::SEVERITY_CRITICAL,
            $score >= self::BAND_HIGH => SellerInsight::SEVERITY_HIGH,
            $score >= self::BAND_MEDIUM => SellerInsight::SEVERITY_MEDIUM,
            default => SellerInsight::SEVERITY_LOW,
        };

        return $this->atLeast($derived, $signals->severityFloor);
    }

    /**
     * How much of the picture the score was built from, 0–1.
     *
     * A 60 built from every component is a different claim from a 60 built from urgency alone, and
     * the difference has to be visible or the number is being oversold.
     */
    public function confidence(ImpactSignals $signals): float
    {
        $measured = 0;
        $measured += $this->hasRevenue($signals) ? self::WEIGHT_REVENUE : 0;
        $measured += $this->hasVolume($signals) ? self::WEIGHT_VOLUME : 0;
        $measured += $signals->hoursUntilDue !== null ? self::WEIGHT_URGENCY : 0;
        $measured += $signals->openForHours !== null ? self::WEIGHT_DURATION : 0;
        $measured += self::WEIGHT_RECURRENCE;

        return round($measured / 100, 2);
    }

    /**
     * The components, for an issue that has to explain itself.
     *
     * "Why is this critical" is a question the Issue Center must answer, and the only honest answer
     * is the arithmetic that produced it.
     *
     * @return array<string, float>
     */
    public function breakdown(ImpactSignals $signals): array
    {
        return [
            'revenue' => round($this->revenuePoints($signals), 1),
            'volume' => round($this->volumePoints($signals), 1),
            'urgency' => round($this->urgencyPoints($signals), 1),
            'duration' => round($this->durationPoints($signals), 1),
            'recurrence' => round($this->recurrencePoints($signals), 1),
            'confidence' => $this->confidence($signals),
        ];
    }

    /**
     * A share of the seller's own turnover, not an absolute sum.
     *
     * A seller with no recent revenue scores zero here rather than infinity: a new shop with one
     * problem is not in more danger than an established one, and dividing by nothing would say so.
     */
    private function revenuePoints(ImpactSignals $signals): float
    {
        if (!$this->hasRevenue($signals)) {
            return 0.0;
        }

        $share = $signals->revenueAtRisk / $signals->sellerRecentRevenue;

        return min(1.0, $share / self::REVENUE_SATURATION) * self::WEIGHT_REVENUE;
    }

    private function volumePoints(ImpactSignals $signals): float
    {
        if (!$this->hasVolume($signals)) {
            return 0.0;
        }

        $share = $signals->affectedCount / $signals->sellerTotalCount;

        return min(1.0, $share / self::VOLUME_SATURATION) * self::WEIGHT_VOLUME;
    }

    /**
     * Full marks once the deadline has passed, climbing inside the last few hours before it.
     *
     * Deliberately flat until then: an order with twenty hours left is not urgent, and a curve that
     * started scoring it would make everything look mildly urgent, which is the same as nothing
     * being urgent.
     */
    private function urgencyPoints(ImpactSignals $signals): float
    {
        if ($signals->hoursUntilDue === null) {
            return 0.0;
        }

        if ($signals->hoursUntilDue <= 0) {
            return self::WEIGHT_URGENCY;
        }

        if ($signals->hoursUntilDue >= self::URGENCY_WINDOW_HOURS) {
            return 0.0;
        }

        $closeness = 1 - ($signals->hoursUntilDue / self::URGENCY_WINDOW_HOURS);

        return $closeness * self::WEIGHT_URGENCY;
    }

    private function durationPoints(ImpactSignals $signals): float
    {
        if ($signals->openForHours === null || $signals->openForHours <= 0) {
            return 0.0;
        }

        return min(1.0, $signals->openForHours / self::DURATION_SATURATION_HOURS) * self::WEIGHT_DURATION;
    }

    private function recurrencePoints(ImpactSignals $signals): float
    {
        $sightings = max(1, $signals->detectionCount) - 1;

        return min(1.0, $sightings / (self::RECURRENCE_SATURATION - 1)) * self::WEIGHT_RECURRENCE;
    }

    private function hasRevenue(ImpactSignals $signals): bool
    {
        return $signals->revenueAtRisk !== null
            && $signals->revenueAtRisk > 0
            && $signals->sellerRecentRevenue !== null
            && $signals->sellerRecentRevenue > 0;
    }

    private function hasVolume(ImpactSignals $signals): bool
    {
        return $signals->affectedCount !== null
            && $signals->affectedCount > 0
            && $signals->sellerTotalCount !== null
            && $signals->sellerTotalCount > 0;
    }

    /** Returns whichever of the two is worse. */
    private function atLeast(string $derived, ?string $floor): string
    {
        if ($floor === null || !isset(SellerInsight::SEVERITY_ORDER[$floor])) {
            return $derived;
        }

        return SellerInsight::SEVERITY_ORDER[$floor] < SellerInsight::SEVERITY_ORDER[$derived]
            ? $floor
            : $derived;
    }
}
