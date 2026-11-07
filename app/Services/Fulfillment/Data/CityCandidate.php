<?php

namespace App\Services\Fulfillment\Data;

use App\Models\ProviderCity;

/**
 * A provider city whose name is exactly one of our city's names, and what its region name says about it.
 *
 * A candidate is only ever a proposal for a person to decide on. A provider city whose region names ANOTHER of our regions is not
 * a candidate at all; one whose region we cannot place stays a candidate but is never approved without a person.
 */
final readonly class CityCandidate
{
    public const REGION_CONFIRMED = 'confirmed';

    /** The provider city carries no region name, so there is nothing to confirm or to contradict. */
    public const REGION_UNKNOWN = 'unknown';

    /** It carries a region name that is none of our regions' names. */
    public const REGION_UNPLACEABLE = 'unplaceable';

    public function __construct(
        public ProviderCity $city,
        public string $region,
    ) {
    }

    /** May a unique candidate like this be approved without a person looking at it? */
    public function canBeApprovedAutomatically(): bool
    {
        return $this->region !== self::REGION_UNPLACEABLE;
    }
}
