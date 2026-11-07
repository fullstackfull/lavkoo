<?php

namespace App\Services\OperationalCosts\Profit;

use App\Services\OperationalCosts\Support\Money;

/**
 * The profit of one sale, shown as two figures that are never mixed:
 *
 *  - ESTIMATED: revenue less every cost we have a figure for, estimates included. An estimate that was later replaced by the real charge is
 *    not counted twice (the ledger keeps one effective entry per service).
 *  - ACTUAL: revenue less the costs that are settled (the real charge, or one an invoice confirmed). A cost still only estimated is not in it.
 *
 * Each says whether it is COMPLETE. A cost nobody could determine is missing, not zero, so a figure with a gap is provisional: `missing` and
 * `pendingActual` name the gaps. An amount in a currency with no recorded exchange rate is left out and recorded in `gaps` (a sale whose revenue is in such a currency
 * has an unknown revenue, `revenueKnown()`, not a revenue of zero, and no profit).
 */
final readonly class ProfitResult
{
    /**
     * @param array<string, CostBucket> $buckets
     * @param list<string> $missingComponents
     * @param list<string> $pendingActualComponents
     * @param list<string> $notes
     */
    public function __construct(
        public string $subjectType,
        public int $subjectId,
        public string $salesChannel,
        public ?int $accountId,
        public ?string $provider,
        public ?string $fulfillmentMode,
        public string $state,
        public string $currency,
        public string $occurredOn,
        public ?string $reference,
        public Money $revenue,
        public array $buckets,
        public Money $estimatedCost,
        public Money $actualCost,
        public Money $disputedCost,
        public array $missingComponents,
        public array $pendingActualComponents,
        public bool $revenueTrusted,
        public array $notes,
        public ConversionGaps $gaps = new ConversionGaps(),
    ) {
    }

    public function revenueKnown(): bool
    {
        return $this->gaps->revenueCount() === 0;
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

    public function estimatedComplete(): bool
    {
        return $this->missingComponents === [] && $this->revenueTrusted;
    }

    public function actualComplete(): bool
    {
        return $this->missingComponents === [] && $this->pendingActualComponents === [] && $this->disputedCost->isZero() && $this->revenueTrusted;
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
