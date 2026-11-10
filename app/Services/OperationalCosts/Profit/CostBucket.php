<?php

namespace App\Services\OperationalCosts\Profit;

use App\Services\OperationalCosts\Support\Money;

/** What one kind of cost came to for a sale, as two separate figures: the estimate-inclusive one and the settled one. */
final class CostBucket
{
    public Money $estimated;

    public Money $actual;

    public function __construct(
        public readonly string $component,
        public readonly string $costClass,
        public int $missing = 0,
        public int $pendingActual = 0,
        public int $disputed = 0,
        /** Estimates kept on the record but not counted, because the settled actual of the same service stands in their place. */
        public int $supersededEstimates = 0,
    ) {
        $this->estimated = Money::zero();
        $this->actual = Money::zero();
    }
}
