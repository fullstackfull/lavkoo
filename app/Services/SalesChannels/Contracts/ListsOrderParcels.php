<?php

namespace App\Services\SalesChannels\Contracts;

use App\Models\ExternalOrderMapping;

/**
 * Says how many parcels an order really left in, and what went into each.
 *
 * Why it is an interface and not a query: the parcels are FULFILLMENT's knowledge — its `provider_fulfillments` and their items — and the sales-channel module does not read
 * another module's tables. Fulfillment answers; the channel module decides what to tell a marketplace. The same arrangement as `WritesStorefrontStock`, for the same reason.
 *
 * Where the fulfilment module is absent, the no-op answer is "one parcel, the one mirrored onto the order itself", which is what this application did before parcels were
 * counted at all.
 */
interface ListsOrderParcels
{
    /**
     * The parcels of ONE channel order, each with its own tracking number and its own units, keyed by the CHANNEL's line ids.
     *
     * Only parcels a provider has accepted are returned: a shipment still pending is not something to tell a marketplace about, and one that was cancelled never left.
     *
     * @return list<\App\Services\SalesChannels\Data\OrderParcel>
     */
    public function parcelsFor(ExternalOrderMapping $mapping): array;
}
