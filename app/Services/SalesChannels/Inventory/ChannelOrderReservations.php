<?php

namespace App\Services\SalesChannels\Inventory;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Publishing\PublishPolicyResolver;

/**
 * Keeps the units that open channel orders have promised out of the quantity offered to the channels, so that stock is neither sold twice nor pushed back over a channel's own
 * deduction. One reservation per order line, at the location the line's listing takes its stock from:
 *
 *  - an order that is open (new, pending, awaiting review, processing) and is ours to fulfil holds its active units (RESERVED), and follows the order when units are cancelled;
 *  - once the shipment is linked or the order has shipped or been delivered the units have left the shelf: the reservation is COMMITTED and counts only until the location
 *    reports a figure taken after that moment, so the same units are never taken off twice;
 *  - an order that is cancelled, failed or returned gives its units back (RELEASED);
 *  - an order the channel ships itself (FBA, channel-managed) or whose mode is not known draws no stock of ours and holds nothing.
 *
 * Safe to run as often as you like: it only brings the reservations in line with the order as it is now.
 */
class ChannelOrderReservations
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly PublishPolicyResolver $policies,
    ) {
    }

    public function sync(ExternalOrderMapping $mapping): void
    {
        $mapping->loadMissing('lines');
        $holds = !$mapping->fulfillmentMode()->isFulfilledByChannel() && $mapping->fulfillmentMode()->value !== 'unknown';

        foreach ($mapping->lines as $line) {
            $key = self::keyOf($mapping, $line);
            $units = $line->activeQuantity();
            $location = $this->locationOf($line);

            if (!$holds || $units < 1 || in_array($mapping->orderStatus(), [ChannelOrderStatus::Cancelled, ChannelOrderStatus::Failed, ChannelOrderStatus::Returned], true)) {
                $this->reservations->release($key, 'order_' . $mapping->status);
                continue;
            }
            if ($location === null || $line->product_id === null) {
                continue;
            }

            if ($this->hasLeftTheShelf($mapping)) {
                // An order first seen when it has already shipped holds nothing: the location's count already shows it.
                $this->reservations->commit($key);
                continue;
            }
            $this->reservations->reserve($key, $location, (int) $line->product_id, (string) $line->variant_key, $units, $line->sku, (int) $mapping->id);
        }
    }

    public static function keyOf(ExternalOrderMapping $mapping, ExternalOrderLine $line): string
    {
        return 'channel-order:' . $mapping->id . ':' . $line->id;
    }

    private function hasLeftTheShelf(ExternalOrderMapping $mapping): bool
    {
        return in_array($mapping->orderStatus(), [ChannelOrderStatus::Shipped, ChannelOrderStatus::Delivered], true)
            || $mapping->shipment_link_state === ExternalOrderMapping::LINK_LINKED;
    }

    /** The location the line's listing takes its stock from; the one routing chose for the line when the listing names none. */
    private function locationOf(ExternalOrderLine $line): ?int
    {
        $listing = $line->product_mapping_id === null ? null : SalesChannelProductMapping::find($line->product_mapping_id);
        $location = $listing === null ? null : $this->policies->resolve($listing)->stockLocationId;

        return $location ?? $line->inventory_location_id;
    }
}
