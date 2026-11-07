<?php

namespace App\Services\SalesChannels\Routing;

use App\Enums\SalesChannels\ExecutionPolicy;
use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\InventoryLocation;
use App\Services\SalesChannels\Inventory\ChannelOrderReservations;
use App\Services\SalesChannels\Inventory\PoolNet;

/** Reads an order and its lines into the facts a routing decision is allowed to use. */
class RoutingContextFactory
{
    public function __construct(private readonly PoolNet $pools)
    {
    }

    public function for(ExternalOrderMapping $mapping): RoutingContext
    {
        $mapping->loadMissing('lines', 'account');
        $snapshot = (array) ($mapping->snapshot ?? []);
        $locations = InventoryLocation::whereIn('id', $mapping->lines->pluck('inventory_location_id')->filter()->unique())->get()->keyBy('id');

        return new RoutingContext(
            mappingId: (int) $mapping->id,
            channel: (string) $mapping->sales_channel,
            accountId: (int) $mapping->account_id,
            mode: $mapping->fulfillmentMode(),
            status: $mapping->orderStatus(),
            channelPaysCarrier: match ($mapping->fulfillment_channel_raw) {
                'trendyol_pays' => 'channel',
                'seller_pays' => 'seller',
                default => null,
            },
            isCod: (bool) ($snapshot['is_cod'] ?? false),
            allowCod: (bool) $mapping->account?->setting('route_cod_orders', false),
            country: isset($snapshot['destination']['country']) ? strtoupper((string) $snapshot['destination']['country']) : null,
            city: isset($snapshot['destination']['city']) ? (string) $snapshot['destination']['city'] : null,
            orderTotal: $mapping->order_total === null ? null : (string) $mapping->order_total,
            currentExecutionPolicy: $mapping->execution_policy,
            alreadyDispatched: $mapping->order_id !== null || $mapping->shipment_link_state === ExternalOrderMapping::LINK_LINKED,
            lines: $mapping->lines->map(fn (ExternalOrderLine $line): RoutingLine => $this->line($mapping, $line, $locations->get($line->inventory_location_id)))->all(),
            accountPolicy: ExecutionPolicy::tryFrom((string) $mapping->account?->execution_policy) ?? ExecutionPolicy::ManualReview,
        );
    }

    private function line(ExternalOrderMapping $mapping, ExternalOrderLine $line, ?InventoryLocation $location): RoutingLine
    {
        // The units this very line holds are not counted against it: the order is asking whether the shelf covers it, and it was reserved a moment ago when it was read.
        $confirmed = $location !== null && $line->product_id !== null
            && ($pool = $this->pools->for($location, (int) $line->product_id, (string) $line->variant_key, ChannelOrderReservations::keyOf($mapping, $line)))->isPublishable()
            && (int) $pool->net() >= $line->activeQuantity();

        return new RoutingLine(
            lineId: (int) $line->id,
            externalLineId: (string) $line->external_line_id,
            productId: $line->product_id === null ? null : (int) $line->product_id,
            sku: $line->sku,
            quantity: $line->activeQuantity(),
            locationId: $location?->id,
            provider: $location?->provider,
            stockConfirmed: $confirmed,
            cancelled: $line->activeQuantity() === 0,
        );
    }
}
