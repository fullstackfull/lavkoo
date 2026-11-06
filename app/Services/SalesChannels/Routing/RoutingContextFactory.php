<?php

namespace App\Services\SalesChannels\Routing;

use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\InventoryLocation;
use App\Services\SalesChannels\Inventory\LocationStockReader;

/** Reads an order and its lines into the facts a routing decision is allowed to use. */
class RoutingContextFactory
{
    public function __construct(private readonly LocationStockReader $stock)
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
            lines: $mapping->lines->map(fn (ExternalOrderLine $line): RoutingLine => $this->line($line, $locations->get($line->inventory_location_id)))->all(),
        );
    }

    private function line(ExternalOrderLine $line, ?InventoryLocation $location): RoutingLine
    {
        $confirmed = $location !== null && $line->product_id !== null
            && ($availability = $this->stock->availability($location, (int) $line->product_id, (string) $line->variant_key))->isPublishable()
            && (int) $availability->sellable() >= $line->activeQuantity();

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
