<?php

namespace App\Services\OperationalCosts\Data;

use App\Services\OperationalCosts\Support\Money;

/**
 * What a sale (or transfer) took from the cost layers. `shortfall` is the part of the quantity no layer could cover: those units have
 * no known cost yet, and the caller reports them as missing rather than pricing them at zero.
 */
final readonly class ConsumptionResult
{
    /** @param list<array{layer_id: int, quantity: int, unit_cost: string, landed_unit_cost: string, supplier_id?: int, supplier_name?: string|null}> $pieces the layers the units came from; a layer with a supplier hands it on */
    public function __construct(
        public int $requested,
        public int $covered,
        public Money $totalCost,
        public string $currency,
        public array $pieces,
    ) {
    }

    public function shortfall(): int
    {
        return max(0, $this->requested - $this->covered);
    }

    public function isComplete(): bool
    {
        return $this->shortfall() === 0;
    }
}
