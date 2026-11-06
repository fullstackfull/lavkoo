<?php

namespace App\Services\OperationalCosts\Data;

use App\Services\OperationalCosts\Support\Money;

/** One item a shared charge can be split across, with the measures a split can follow. A measure that is not known is null. */
final readonly class AllocationTarget
{
    public function __construct(
        public string $type,
        public int $id,
        public ?int $productId,
        public string $variantKey,
        public Money $quantity,
        public ?Money $weight,
        public Money $value,
    ) {
    }
}
