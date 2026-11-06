<?php

namespace App\Services\SalesChannels\Shipments;

use App\Models\ExternalOrderMapping;

/** The honest default: no provider search exists, so there are never candidates and nothing is linked on a guess. */
final class NullShipmentLookup implements ExternalShipmentLookup
{
    public function available(): bool
    {
        return false;
    }

    public function candidates(ExternalOrderMapping $mapping): array
    {
        return [];
    }
}
