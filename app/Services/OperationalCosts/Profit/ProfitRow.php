<?php

namespace App\Services\OperationalCosts\Profit;

use App\Services\OperationalCosts\Support\Money;

/** A line of a grouped profit report: sales that share a channel, a provider, a month, a product... */
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

    public function __construct(public readonly string $key, public readonly string $label)
    {
        $this->revenue = Money::zero();
        $this->estimatedCost = Money::zero();
        $this->actualCost = Money::zero();
        $this->disputedCost = Money::zero();
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
