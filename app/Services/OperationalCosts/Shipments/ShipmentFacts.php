<?php

namespace App\Services\OperationalCosts\Shipments;

use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonInterface;

/**
 * Everything the cost of one shipment depends on, gathered from wherever the shipment lives (a provider fulfillment the application
 * sent, or a shipment someone else created that we follow). Cost code reads only this, so it does not care which of the two it is.
 */
final readonly class ShipmentFacts
{
    /** @param list<ShipmentItemFact> $items */
    public function __construct(
        public string $subjectType,
        public int $subjectId,
        public string $providerCode,
        public int $sellerId,
        public string $salesChannel,
        public ?int $accountId,
        public ?int $orderId,
        public ?int $mappingId,
        public ?int $providerFulfillmentId,
        public ?int $inventoryLocationId,
        public CarbonInterface $serviceDate,
        public string $currency,
        public array $items,
        public bool $cashOnDelivery,
        public ?Money $codAmount,
        public ?string $city,
        public ?string $country,
        public ?string $paymentMethod,
        public bool $cancelledAfterAcceptance,
        public bool $returned,
        public ?string $reference = null,
    ) {
    }

    public function units(): int
    {
        return array_sum(array_map(static fn (ShipmentItemFact $item): int => $item->quantity, $this->items));
    }

    /** The parcel's weight, or null as soon as one item's weight is unknown: a guess here would price the shipment wrongly. */
    public function totalWeightKg(): ?Money
    {
        $total = Money::zero();
        foreach ($this->items as $item) {
            if ($item->weightKg === null) {
                return null;
            }
            $total = $total->plus($item->weightKg->times($item->quantity));
        }

        return $this->items === [] ? null : $total;
    }

    public function itemsValue(): Money
    {
        return Money::sum(array_map(static fn (ShipmentItemFact $item): Money => $item->value, $this->items));
    }
}
