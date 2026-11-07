<?php

namespace App\Services\OperationalCosts\Profit;

use App\Services\OperationalCosts\Support\Money;

/**
 * A line of a grouped profit report: sales that share a channel, a provider, a month, a product...
 *
 * `gaps` says what could not be converted into the reporting currency (revenue and costs, and in which currencies): a row with any is INCOMPLETE, whatever else it
 * counts, and its profit is a figure that could be computed, never a finished one. A row whose revenue was all left out has an unknown revenue (`revenueKnown()`), not a
 * revenue of zero, and no profit. `missingCosts` (a cost nobody determined) and `disputedCosts` (a charge being contested, in neither figure) are counted apart from these.
 */
final class ProfitRow
{
    public Money $revenue;

    public Money $estimatedCost;

    public Money $actualCost;

    public Money $disputedCost;

    public int $orders = 0;

    public int $incompleteEstimated = 0;

    public int $incompleteActual = 0;

    public int $missingCosts = 0;

    public int $units = 0;

    public int $disputedCosts = 0;

    /** How many revenue figures were converted: with none and a gap, the revenue is unknown. */
    public int $convertedRevenues = 0;

    public ConversionGaps $gaps;

    public function __construct(public readonly string $key, public readonly string $label)
    {
        $this->revenue = Money::zero();
        $this->estimatedCost = Money::zero();
        $this->actualCost = Money::zero();
        $this->disputedCost = Money::zero();
        $this->gaps = new ConversionGaps();
    }

    public function revenueKnown(): bool
    {
        return $this->gaps->revenueCount() === 0 || $this->convertedRevenues > 0;
    }

    /** Null when the revenue is unknown: a profit cannot be told from nothing. */
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
        return $this->revenueKnown() && !$this->revenue->isZero() ? $this->estimatedProfit()->times(100)->dividedBy($this->revenue, 2) : null;
    }

    public function actualMarginPercent(): ?Money
    {
        return $this->revenueKnown() && !$this->revenue->isZero() ? $this->actualProfit()->times(100)->dividedBy($this->revenue, 2) : null;
    }
}
