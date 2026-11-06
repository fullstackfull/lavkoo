<?php

namespace App\Services\OperationalCosts\Shipments;

use App\Services\OperationalCosts\Support\Money;

/** One item of a shipment as far as its cost is concerned. */
final readonly class ShipmentItemFact
{
    public function __construct(
        public string $targetType,
        public int $targetId,
        public ?int $productId,
        public string $variantKey,
        public ?string $sku,
        public int $quantity,
        public ?Money $weightKg,
        public Money $value,
    ) {
    }
}
