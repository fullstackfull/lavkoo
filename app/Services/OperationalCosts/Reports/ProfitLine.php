<?php

namespace App\Services\OperationalCosts\Reports;

use App\Services\OperationalCosts\Profit\ProfitResult;
use App\Services\OperationalCosts\Profit\ProfitRow;
use App\Services\OperationalCosts\Support\Money;

/**
 * One line of a profit report, whatever it groups by: a product, a channel, a provider or a single sale.
 *
 * The figures are the services' own and are not computed again here: this puts the two shapes they come in (a grouped ProfitRow, one sale's ProfitResult) behind
 * one, so the page and the export read the same thing. Estimated and actual stay two sets of figures. A line says how many sales have a gap
 * (`incompleteEstimated`, `incompleteActual`) and how many costs are missing; a single sale also says how many of its services still wait for the real charge.
 */
final readonly class ProfitLine
{
    public function __construct(
        public ProfitRow|ProfitResult $source,
        public string $key,
        public string $label,
        public ?int $orders,
        public ?int $units,
        public int $missing,
        public ?int $pending,
        public int $incompleteEstimated,
        public int $incompleteActual,
    ) {
    }

    /** A group of sales (a channel, a provider) or of product lines. `$groupsSales` is false for products: a product row counts units, not orders. */
    public static function ofRow(ProfitRow $row, string $label, bool $groupsSales): self
    {
        return new self(
            source: $row,
            key: $row->key,
            label: $label,
            orders: $groupsSales ? $row->orders : null,
            units: $groupsSales ? null : $row->units,
            missing: $row->missingCosts,
            pending: null,
            incompleteEstimated: $row->incompleteEstimated,
            incompleteActual: $row->incompleteActual,
        );
    }

    public static function ofResult(ProfitResult $result): self
    {
        return new self(
            source: $result,
            key: $result->subjectType . ':' . $result->subjectId,
            label: (string) $result->reference,
            orders: 1,
            units: null,
            missing: count($result->missingComponents),
            pending: count($result->pendingActualComponents),
            incompleteEstimated: $result->estimatedComplete() ? 0 : 1,
            incompleteActual: $result->actualComplete() ? 0 : 1,
        );
    }

    public function revenue(): Money
    {
        return $this->source->revenue;
    }

    public function estimatedCost(): Money
    {
        return $this->source->estimatedCost;
    }

    public function actualCost(): Money
    {
        return $this->source->actualCost;
    }

    public function disputedCost(): Money
    {
        return $this->source->disputedCost;
    }

    public function estimatedProfit(): Money
    {
        return $this->source->estimatedProfit();
    }

    public function actualProfit(): Money
    {
        return $this->source->actualProfit();
    }

    public function estimatedMarginPercent(): ?Money
    {
        return $this->source->estimatedMarginPercent();
    }

    public function actualMarginPercent(): ?Money
    {
        return $this->source->actualMarginPercent();
    }

    public function subjectType(): ?string
    {
        return $this->source instanceof ProfitResult ? $this->source->subjectType : null;
    }

    public function subjectId(): ?int
    {
        return $this->source instanceof ProfitResult ? $this->source->subjectId : null;
    }
}
