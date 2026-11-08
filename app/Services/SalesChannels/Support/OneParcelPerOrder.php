<?php

namespace App\Services\SalesChannels\Support;

use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Services\SalesChannels\Contracts\ListsOrderParcels;
use App\Services\SalesChannels\Data\OrderParcel;
use Carbon\CarbonImmutable;

/**
 * The answer where nothing counts parcels: ONE parcel, the one already mirrored onto the channel order itself, holding every line of it.
 *
 * This is exactly what this application did before parcels were counted, so a host without the fulfilment module behaves as it always did. It is also the honest answer for a
 * channel order the shop never fulfilled itself: there is one shipment and the channel order carries its tracking number.
 */
final class OneParcelPerOrder implements ListsOrderParcels
{
    /** @return list<OrderParcel> */
    public function parcelsFor(ExternalOrderMapping $mapping): array
    {
        $quantities = $mapping->lines
            ->mapWithKeys(static fn (ExternalOrderLine $line): array => [(string) $line->external_line_id => $line->activeQuantity()])
            ->filter()
            ->all();

        if ($quantities === []) {
            return [];
        }

        return [new OrderParcel(
            // The package the channel itself named, so a confirmation of it is recorded under a reference that does not move.
            reference: $mapping->external_package_id !== '' ? $mapping->external_package_id : (string) $mapping->external_order_id,
            provider: (string) ($mapping->provider ?? ''),
            trackingNumber: $mapping->tracking_number,
            trackingUrl: $mapping->tracking_url,
            shippedAt: CarbonImmutable::parse($mapping->shipped_at ?? now()),
            lineQuantities: $quantities,
            status: $mapping->shipment_status,
        )];
    }
}
