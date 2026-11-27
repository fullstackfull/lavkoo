<?php

namespace App\Services\SalesChannels\Data;

use Carbon\CarbonInterface;

/** Tell the channel an order has shipped, with the carrier and tracking number, for an order WE fulfil. */
final readonly class ShipmentConfirmation
{
    /**
     * @param array<string, int> $lineQuantities external line id => quantity shipped
     * @param string|null $marketplaceId the channel's own marketplace this ORDER was bought in, where the order recorded one (Amazon does;
     *                                   H2-07). It is read from the order and never stored a second time, and a channel that has no such
     *                                   notion leaves it null.
     */
    public function __construct(
        public string $orderId,
        public string $packageId,
        public string $carrierCode,
        public ?string $carrierName,
        public string $trackingNumber,
        public CarbonInterface $shippedAt,
        public array $lineQuantities,
        public ?string $marketplaceId = null,
    ) {
    }
}
