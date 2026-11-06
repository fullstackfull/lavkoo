<?php

namespace App\Services\SalesChannels\Shipments;

/** A shipment found at a provider that might be the one for a channel order. */
final readonly class ShipmentCandidate
{
    public function __construct(
        public string $provider,
        public string $reference,
        public ?string $trackingNumber = null,
        public ?string $carrier = null,
        public ?string $status = null,
        public ?string $matchedOn = null,
    ) {
    }
}
