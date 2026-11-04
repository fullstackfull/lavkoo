<?php

namespace App\Services\Monitoring\Support;

/**
 * Latency percentiles that a live store can actually afford.
 *
 * An average response time hides exactly the requests worth knowing about: a p99 of nine seconds
 * disappears into a 180ms mean. Computing true percentiles means keeping every sample, which for a
 * shop doing millions of requests is not an option — so requests are counted into fixed buckets
 * (the Prometheus approach) and percentiles are interpolated from the cumulative counts.
 *
 * The cost is fixed: one small integer array per route per minute, no matter the traffic. The
 * error is bounded by bucket width, which is why the buckets are dense where it matters (5-500ms)
 * and coarse where the exact number stops mattering (past 10s, "very slow" is the whole story).
 */
class Histogram
{
    /** @var array<int, float> ascending upper bounds */
    private array $bounds;

    /** @var array<int, int> counts per bucket; one extra slot for "over the last bound" */
    private array $counts;

    private int $total = 0;

    private float $sum = 0.0;

    private ?float $min = null;

    private ?float $max = null;

    /** @param array<int, float|int>|null $bounds */
    public function __construct(?array $bounds = null)
    {
        $bounds = $bounds ?? (array) config('monitoring.latency_buckets_ms', [5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000, 30000]);
        $this->bounds = array_values(array_map('floatval', $bounds));
        sort($this->bounds);
        $this->counts = array_fill(0, count($this->bounds) + 1, 0);
    }

    public function observe(float $value): void
    {
        $this->total++;
        $this->sum += $value;
        $this->min = $this->min === null ? $value : min($this->min, $value);
        $this->max = $this->max === null ? $value : max($this->max, $value);

        foreach ($this->bounds as $index => $bound) {
            if ($value <= $bound) {
                $this->counts[$index]++;

                return;
            }
        }

        // Past the last bound: the overflow slot.
        $this->counts[count($this->bounds)]++;
    }

    /**
     * Rebuild from stored state (a row read back out of the database).
     *
     * @param  array<int, int>  $counts
     */
    public static function fromState(array $counts, int $total, float $sum, ?float $min, ?float $max, ?array $bounds = null): self
    {
        $histogram = new self($bounds);
        $expected = count($histogram->bounds) + 1;
        $histogram->counts = array_slice(array_pad(array_map('intval', array_values($counts)), $expected, 0), 0, $expected);
        $histogram->total = $total;
        $histogram->sum = $sum;
        $histogram->min = $min;
        $histogram->max = $max;

        return $histogram;
    }

    /** Fold another histogram of the same shape into this one — how minutes become hours. */
    public function merge(self $other): void
    {
        if ($other->bounds !== $this->bounds) {
            // Different bucket layout (config changed since the row was written): the counts are
            // not comparable, so merging them would invent a distribution. Totals still are.
            $this->total += $other->total;
            $this->sum += $other->sum;
            $this->min = $this->pickMin($other->min);
            $this->max = $this->pickMax($other->max);

            return;
        }

        foreach ($other->counts as $index => $count) {
            $this->counts[$index] += $count;
        }
        $this->total += $other->total;
        $this->sum += $other->sum;
        $this->min = $this->pickMin($other->min);
        $this->max = $this->pickMax($other->max);
    }

    /**
     * The value below which `$quantile` of observations fall.
     *
     * Interpolates linearly inside the bucket the quantile lands in, so p95 of a route whose
     * requests all sit between 100 and 250ms reads as a number in that range rather than snapping
     * to the bucket edge.
     */
    public function quantile(float $quantile): ?float
    {
        if ($this->total === 0) {
            return null;
        }

        $target = $quantile * $this->total;
        $cumulative = 0;
        $lowerBound = 0.0;

        foreach ($this->bounds as $index => $bound) {
            $inBucket = $this->counts[$index];
            if ($cumulative + $inBucket >= $target) {
                if ($inBucket === 0) {
                    return $this->clamp($bound);
                }
                $withinBucket = ($target - $cumulative) / $inBucket;

                return $this->clamp($lowerBound + ($bound - $lowerBound) * $withinBucket);
            }
            $cumulative += $inBucket;
            $lowerBound = $bound;
        }

        // The quantile lands in the overflow bucket: the largest honest answer is the observed max,
        // and failing that the last bound — never a made-up larger number.
        return $this->max ?? ($this->bounds[count($this->bounds) - 1] ?? null);
    }

    /**
     * Hold an interpolated percentile inside the values that were actually observed.
     *
     * Interpolation assumes observations are spread evenly across their bucket, and they are not:
     * fifteen requests that all took ~700ms land in the (500, 1000] bucket, and interpolating the
     * median puts it at 750 — above the slowest request that actually happened. A p50 larger than
     * the max is visibly wrong and, worse, quietly inflates every latency chart, so the answer is
     * clamped to the real min and max the bucket recorded.
     */
    private function clamp(float $value): float
    {
        if ($this->min !== null) {
            $value = max($value, $this->min);
        }
        if ($this->max !== null) {
            $value = min($value, $this->max);
        }

        return $value;
    }

    public function percentiles(float ...$quantiles): array
    {
        $result = [];
        foreach ($quantiles as $quantile) {
            $result['p' . rtrim(rtrim(number_format($quantile * 100, 2, '.', ''), '0'), '.')] = $this->round($this->quantile($quantile));
        }

        return $result;
    }

    /** The five the dashboard shows, in one call. */
    public function standardPercentiles(): array
    {
        return [
            'p50' => $this->round($this->quantile(0.50)),
            'p75' => $this->round($this->quantile(0.75)),
            'p90' => $this->round($this->quantile(0.90)),
            'p95' => $this->round($this->quantile(0.95)),
            'p99' => $this->round($this->quantile(0.99)),
            'max' => $this->round($this->max),
            'avg' => $this->round($this->average()),
            'count' => $this->total,
        ];
    }

    public function average(): ?float
    {
        return $this->total > 0 ? $this->sum / $this->total : null;
    }

    public function count(): int
    {
        return $this->total;
    }

    public function sum(): float
    {
        return $this->sum;
    }

    public function min(): ?float
    {
        return $this->min;
    }

    public function max(): ?float
    {
        return $this->max;
    }

    /** How many observations exceeded a threshold — the timeout rate, without storing samples. */
    public function countAbove(float $threshold): int
    {
        $above = 0;
        foreach ($this->bounds as $index => $bound) {
            if ($bound > $threshold) {
                $above += $this->counts[$index];
            }
        }

        return $above + $this->counts[count($this->bounds)];
    }

    /** @return array<int, int> */
    public function counts(): array
    {
        return $this->counts;
    }

    /** @return array<int, float> */
    public function bounds(): array
    {
        return $this->bounds;
    }

    /** The compact form that goes into a database column. */
    public function toState(): array
    {
        return [
            'counts' => $this->counts,
            'total' => $this->total,
            'sum' => round($this->sum, 3),
            'min' => $this->min,
            'max' => $this->max,
        ];
    }

    private function pickMin(?float $other): ?float
    {
        if ($other === null) {
            return $this->min;
        }

        return $this->min === null ? $other : min($this->min, $other);
    }

    private function pickMax(?float $other): ?float
    {
        if ($other === null) {
            return $this->max;
        }

        return $this->max === null ? $other : max($this->max, $other);
    }

    private function round(?float $value): ?float
    {
        return $value === null ? null : round($value, 1);
    }
}
