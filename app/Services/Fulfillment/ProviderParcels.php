<?php

namespace App\Services\Fulfillment;

use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;
use App\Services\SalesChannels\Contracts\ListsOrderParcels;
use App\Services\SalesChannels\Data\OrderParcel;
use App\Services\SalesChannels\Support\OneParcelPerOrder;
use Illuminate\Support\Facades\Schema;

/**
 * Fulfillment's answer to "how many parcels did this order really leave in, and what was in each?".
 *
 * `FulfillmentRouter` groups an order's items BY PROVIDER (`$unrouted->groupBy('provider')`), so an order with some items at Torod Point and some at M5AZN becomes two
 * `provider_fulfillments` with two references, two tracking numbers and two sets of items. One of them is mirrored onto the channel order's own `tracking_number`; the other
 * is only in this table. Telling a marketplace that the whole order shipped under the mirrored number is false for every unit in the other box.
 *
 * Matching to the channel's lines is by (product, variant), which is how every other part of this system pairs a channel line with ours — the channel's own line id is not on
 * a provider item, and a name never matches anything.
 *
 * A parcel the provider has not accepted yet is left out: there is nothing to tell a channel about a shipment that may never go. So is a cancelled or failed one.
 */
class ProviderParcels implements ListsOrderParcels
{
    public function __construct(private readonly OneParcelPerOrder $fallback)
    {
    }

    /** @return list<OrderParcel> */
    public function parcelsFor(ExternalOrderMapping $mapping): array
    {
        // The channel order's own internal order: the one the router made parcels out of. Without one there is nothing of ours to count, and the honest answer is the one
        // the channel order already carries.
        $orderId = $mapping->order_id;
        if ($orderId === null || !Schema::hasTable('provider_fulfillments')) {
            return $this->fallback->parcelsFor($mapping);
        }

        // Which of OUR (product, variant) pairs is which of the CHANNEL's lines. Built once; a pair the channel never named cannot be confirmed to it at all.
        $linesByPair = [];
        foreach ($mapping->lines as $line) {
            $pair = $this->pair((int) $line->product_id, (string) $line->variant_key);
            // A channel that sold the same product on two lines: the units are attributed to the lines in order, so the total can never exceed what was sold.
            $linesByPair[$pair][] = $line;
        }

        $parcels = [];
        $fulfillments = ProviderFulfillment::where('order_id', $orderId)->orderBy('id')->get();
        foreach ($fulfillments as $fulfillment) {
            if (!$fulfillment->status->isAcceptedByProvider()) {
                continue;
            }
            $quantities = $this->channelLineQuantities($fulfillment, $linesByPair);
            if ($quantities === []) {
                continue;
            }
            $parcels[] = new OrderParcel(
                reference: (string) $fulfillment->reference,
                provider: (string) $fulfillment->provider,
                trackingNumber: $fulfillment->tracking_number,
                trackingUrl: $fulfillment->tracking_url,
                shippedAt: $fulfillment->shipped_at ?? $fulfillment->submitted_at,
                lineQuantities: $quantities,
                status: $fulfillment->status->value,
            );
        }

        // An order the shop did not fulfil through a provider at all (or whose parcels are all still pending) is still one shipment to the channel, with the tracking number
        // the channel order carries. Counting zero parcels here would stop it being confirmed at all, which is worse than the single parcel this always used to be.
        return $parcels === [] ? $this->fallback->parcelsFor($mapping) : $parcels;
    }

    /**
     * The units of THIS parcel, as the channel's own line ids.
     *
     * @param array<string, list<ExternalOrderLine>> $linesByPair
     * @return array<string, int>
     */
    private function channelLineQuantities(ProviderFulfillment $fulfillment, array $linesByPair): array
    {
        $quantities = [];
        $items = ProviderFulfillmentItem::where('provider_fulfillment_id', $fulfillment->id)
            ->where('status', '!=', ProviderFulfillmentItem::STATUS_CANCELLED)
            ->get(['product_id', 'variant_key', 'quantity']);

        foreach ($items as $item) {
            $pair = $this->pair((int) $item->product_id, (string) $item->variant_key);
            $left = (int) $item->quantity;
            foreach ($linesByPair[$pair] ?? [] as $line) {
                if ($left <= 0) {
                    break;
                }
                $lineId = (string) $line->external_line_id;
                // Never more of a line than the channel sold on it, however the provider's items were split.
                $room = max(0, $line->activeQuantity() - ($quantities[$lineId] ?? 0));
                $take = min($room, $left);
                if ($take > 0) {
                    $quantities[$lineId] = ($quantities[$lineId] ?? 0) + $take;
                    $left -= $take;
                }
            }
        }

        return array_filter($quantities, static fn (int $units): bool => $units > 0);
    }

    private function pair(int $productId, string $variantKey): string
    {
        return $productId . '|' . $variantKey;
    }
}
