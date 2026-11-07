<?php

namespace App\Services\OperationalCosts\PurchaseOrders;

/**
 * What the layers would do about one received purchase-order line: the verdict, the units its layers already account for, the new tranche (when there is one) and, when the
 * platform's received quantity has fallen below what was layered, by how many units. Nothing here has been written.
 */
final readonly class LinePlan
{
    public function __construct(
        public PurchaseLine $line,
        public LineVerdict $verdict,
        public int $accountedUnits,
        public ?Tranche $tranche,
        public int $reducedBy,
    ) {
    }
}
