<?php

namespace App\Services\OperationalCosts\Reports;

use App\Services\OperationalCosts\Profit\ProfitRow;
use App\Services\OperationalCosts\Support\Money;

/** The grand total of a grouped profit report. Estimated and actual stay two figures, and the number of sales with a gap is carried beside each. */
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
    ) {
    }

    /** @param iterable<ProfitRow> $rows */
    public static function of(iterable $rows): self
    {
        $revenue = $estimatedCost = $actualCost = $disputedCost = Money::zero();
        $orders = $incompleteEstimated = $incompleteActual = $missingCosts = 0;
        foreach ($rows as $row) {
            $revenue = $revenue->plus($row->revenue);
            $estimatedCost = $estimatedCost->plus($row->estimatedCost);
            $actualCost = $actualCost->plus($row->actualCost);
            $disputedCost = $disputedCost->plus($row->disputedCost);
            $orders += $row->orders;
            $incompleteEstimated += $row->incompleteEstimated;
            $incompleteActual += $row->incompleteActual;
            $missingCosts += $row->missingCosts;
        }

        return new self($revenue, $estimatedCost, $actualCost, $disputedCost, $orders, $incompleteEstimated, $incompleteActual, $missingCosts);
    }

    public function estimatedProfit(): Money
    {
        return $this->revenue->minus($this->estimatedCost);
    }

    public function actualProfit(): Money
    {
        return $this->revenue->minus($this->actualCost);
    }

    public function estimatedMarginPercent(): ?Money
    {
        return $this->revenue->isZero() ? null : $this->estimatedProfit()->times(100)->dividedBy($this->revenue, 2);
    }

    public function actualMarginPercent(): ?Money
    {
        return $this->revenue->isZero() ? null : $this->actualProfit()->times(100)->dividedBy($this->revenue, 2);
    }
}
