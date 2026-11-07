<?php

namespace App\Services\SalesChannels\Data;

use Carbon\CarbonInterface;

/** Tell the channel an order has shipped, with the carrier and tracking number, for an order WE fulfil. */
final readonly class ShipmentConfirmation
{
    /** @param array<string, int> $lineQuantities external line id => quantity shipped */
    public function __construct(
        public string $orderId,
        public string $packageId,
        public string $carrierCode,
        public ?string $carrierName,
        public string $trackingNumber,
        public CarbonInterface $shippedAt,
        public array $lineQuantities,
    ) {
    }
}
