<?php

namespace App\Services\SalesChannels\Data;

use Carbon\CarbonInterface;

/**
 * ONE parcel of an order: the tracking number that really covers it, and the units that are really inside it.
 *
 * It exists because an order is not always one parcel. The router groups a customer's items BY PROVIDER, so an order whose items sit partly with Torod Point and partly with
 * M5AZN leaves as TWO parcels with two tracking numbers — and a channel told "all of it shipped, tracking T1" has been told something false about every unit that went in the
 * other box. The channel then shows the buyer a tracking number that will never move for half their order.
 *
 * So the unit of confirmation is this, not the order: `lineQuantities` holds only what this box contains.
 */
final readonly class OrderParcel
{
    /**
     * @param array<string, int> $lineQuantities the CHANNEL's own line id => units of it in THIS parcel
     */
    public function __construct(
        /** Our own reference for the parcel ("{order}-{TP|MZ}{generation}"): stable, and what the record of a confirmation is keyed by. */
        public string $reference,
        public string $provider,
        public ?string $trackingNumber,
        public ?string $trackingUrl,
        public ?CarbonInterface $shippedAt,
        public array $lineQuantities = [],
        /** What the provider says of it. A parcel the provider has not accepted yet is no parcel to confirm. */
        public ?string $status = null,
    ) {
    }

    /** A parcel worth telling a channel about: it has units in it and a tracking number to give the buyer. */
    public function isConfirmable(): bool
    {
        return $this->trackingNumber !== null && $this->trackingNumber !== '' && array_sum($this->lineQuantities) > 0;
    }
}
