<?php

namespace App\Services\OperationalCosts\PurchaseOrders;

use App\Enums\OperationalCosts\PurchaseLineRefusal;
use App\Services\OperationalCosts\Support\Money;

/** Whether a received purchase-order line may be made into a cost layer: either where, at what cost and in what currency, or the one reason it may not. */
final readonly class LineVerdict
{
    private function __construct(
        public ?PurchaseLineRefusal $refusal,
        public ?int $locationId = null,
        public ?Money $unitCost = null,
        public ?string $currency = null,
    ) {
    }

    /** @param int|null $locationId where the layer goes; null in a dry run for a default place that the real run makes first */
    public static function eligible(?int $locationId, Money $unitCost, string $currency): self
    {
        return new self(refusal: null, locationId: $locationId, unitCost: $unitCost, currency: $currency);
    }

    public static function refused(PurchaseLineRefusal $reason): self
    {
        return new self(refusal: $reason);
    }

    public function isEligible(): bool
    {
        return $this->refusal === null;
    }
}
