<?php

namespace App\Services\OperationalCosts\Reports;

use App\Services\OperationalCosts\Profit\ConversionGaps;
use App\Services\OperationalCosts\Profit\ProfitRow;
use App\Services\OperationalCosts\Support\Money;

/**
 * The grand total of a grouped profit report. Estimated and actual stay two figures, and the number of sales with a gap is carried beside each.
 *
 * A total inherits what its rows could not convert: `gaps` is the sum of theirs, so a total with a gap is incomplete exactly as the row it came from. When every revenue figure
 * of the report was left out the revenue is unknown (`revenueKnown()`), not zero, and there is no profit to show.
 */
final readonly class ProfitTotals
{
    public function __construct(
        public Money $revenue,
        public Money $estimatedCost,
        public Money $actualCost,
        public Money $disputedCost,
        public int $orders,
        public int $incompleteEstimated,
        public int $incompleteActual,
        public int $missingCosts,
        public ConversionGaps $gaps = new ConversionGaps(),
        public int $convertedRevenues = 0,
        public int $disputedCosts = 0,
    ) {
    }

    /** @param iterable<ProfitRow> $rows */
    public static function of(iterable $rows): self
    {
        $revenue = $estimatedCost = $actualCost = $disputedCost = Money::zero();
        $orders = $incompleteEstimated = $incompleteActual = $missingCosts = $convertedRevenues = $disputedCosts = 0;
        $gaps = new ConversionGaps();
        foreach ($rows as $row) {
            $revenue = $revenue->plus($row->revenue);
            $estimatedCost = $estimatedCost->plus($row->estimatedCost);
            $actualCost = $actualCost->plus($row->actualCost);
            $disputedCost = $disputedCost->plus($row->disputedCost);
            $orders += $row->orders;
            $incompleteEstimated += $row->incompleteEstimated;
            $incompleteActual += $row->incompleteActual;
            $missingCosts += $row->missingCosts;
            $convertedRevenues += $row->convertedRevenues;
            $disputedCosts += $row->disputedCosts;
            $gaps = $gaps->plus($row->gaps);
        }

        return new self($revenue, $estimatedCost, $actualCost, $disputedCost, $orders, $incompleteEstimated, $incompleteActual, $missingCosts, $gaps, $convertedRevenues, $disputedCosts);
    }

    public function revenueKnown(): bool
    {
        return $this->gaps->revenueCount() === 0 || $this->convertedRevenues > 0;
    }

    /** Null when the revenue is unknown. */
    public function estimatedProfit(): ?Money
    {
        return $this->revenueKnown() ? $this->revenue->minus($this->estimatedCost) : null;
    }

    public function actualProfit(): ?Money
    {
        return $this->revenueKnown() ? $this->revenue->minus($this->actualCost) : null;
    }

    public function estimatedMarginPercent(): ?Money
    {
        return !$this->revenueKnown() || $this->revenue->isZero() ? null : $this->estimatedProfit()->times(100)->dividedBy($this->revenue, 2);
    }

    public function actualMarginPercent(): ?Money
    {
        return !$this->revenueKnown() || $this->revenue->isZero() ? null : $this->actualProfit()->times(100)->dividedBy($this->revenue, 2);
    }
}
