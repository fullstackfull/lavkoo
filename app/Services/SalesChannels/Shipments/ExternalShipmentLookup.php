<?php

namespace App\Services\SalesChannels\Shipments;

use App\Models\ExternalOrderMapping;

/**
 * Finds, READ-ONLY, the shipment a provider already holds for a channel order whose shipping someone else arranged (a Trendyol account connected straight to Torod Point).
 *
 * It must never create or change anything at the provider. The bound implementation (see SalesChannelsServiceProvider) says whether it can search at all: the Torod Point and
 * M5AZN adapters expose no search-by-reference call today, so the default implementation cannot, and the order is linked by a person instead.
 */
interface ExternalShipmentLookup
{
    /** Whether this lookup can search at all; when it cannot, nothing is guessed. */
    public function available(): bool;

    /** @return list<ShipmentCandidate> */
    public function candidates(ExternalOrderMapping $mapping): array;
}
