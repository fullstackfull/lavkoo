<?php

namespace App\Services\OperationalCosts\Shipments;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\PaymentMode;
use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\InventoryLocation;
use App\Models\Order;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;
use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/** Gathers the facts a shipment's cost depends on from the two places a shipment can live. */
class ShipmentFactsFactory
{
    /** A shipment the application sent to a provider (Torod Point, M5AZN). */
    public function forFulfillment(ProviderFulfillment $fulfillment): ShipmentFacts
    {
        $fulfillment->loadMissing('items');
        $order = Order::find($fulfillment->order_id);
        $address = $this->address($order?->shipping_address_data);
        $mapping = Schema::hasTable('external_order_mappings') ? ExternalOrderMapping::where('order_id', $fulfillment->order_id)->first() : null;
        $accepted = $fulfillment->submitted_at !== null;

        return new ShipmentFacts(
            subjectType: 'shipment',
            subjectId: (int) $fulfillment->id,
            providerCode: $fulfillment->provider,
            sellerId: 0,
            salesChannel: $mapping?->sales_channel ?? 'storefront',
            accountId: $mapping?->account_id,
            orderId: (int) $fulfillment->order_id,
            mappingId: $mapping?->id,
            providerFulfillmentId: (int) $fulfillment->id,
            inventoryLocationId: $this->locationOf($fulfillment->provider),
            serviceDate: CarbonImmutable::parse($fulfillment->submitted_at ?? $fulfillment->created_at ?? now()),
            currency: (string) $fulfillment->currency,
            items: $fulfillment->items->map(static fn (ProviderFulfillmentItem $item): ShipmentItemFact => new ShipmentItemFact(
                targetType: 'shipment_item',
                targetId: (int) $item->id,
                productId: (int) $item->product_id,
                variantKey: (string) $item->variant_key,
                sku: $item->sku,
                quantity: (int) $item->quantity,
                weightKg: $item->weight_kg === null ? null : Money::of($item->weight_kg),
                value: Money::of($item->line_total),
            ))->all(),
            cashOnDelivery: $fulfillment->payment_mode === PaymentMode::CashOnDelivery,
            codAmount: Money::of($fulfillment->cod_amount ?? 0),
            city: $address['city'] ?? null,
            country: $address['country'] ?? null,
            paymentMethod: $fulfillment->payment_mode?->value,
            cancelledAfterAcceptance: $fulfillment->status === FulfillmentStatus::Cancelled && $accepted,
            returned: $fulfillment->status === FulfillmentStatus::Returned,
            reference: $fulfillment->reference,
        );
    }

    /** A shipment someone else created at a provider that the application follows without sending anything. */
    public function forExternalShipment(ExternalOrderMapping $mapping): ShipmentFacts
    {
        $mapping->loadMissing('lines');
        $destination = (array) ($mapping->snapshot['destination'] ?? []);
        $status = $mapping->orderStatus();

        return new ShipmentFacts(
            subjectType: 'external_shipment',
            subjectId: (int) $mapping->id,
            providerCode: (string) $mapping->provider,
            sellerId: (int) $mapping->seller_id,
            salesChannel: (string) $mapping->sales_channel,
            accountId: (int) $mapping->account_id,
            orderId: $mapping->order_id,
            mappingId: (int) $mapping->id,
            providerFulfillmentId: null,
            inventoryLocationId: $this->locationOf($mapping->provider),
            serviceDate: CarbonImmutable::parse($mapping->shipped_at ?? $mapping->purchased_at ?? $mapping->created_at ?? now()),
            currency: (string) ($mapping->currency ?? ''),
            items: $mapping->lines->map(static fn (ExternalOrderLine $line): ShipmentItemFact => new ShipmentItemFact(
                targetType: 'channel_order_line',
                targetId: (int) $line->id,
                productId: $line->product_id,
                variantKey: (string) $line->variant_key,
                sku: $line->sku,
                quantity: $line->activeQuantity(),
                weightKg: $line->weight_kg === null ? null : Money::of($line->weight_kg),
                value: Money::of($line->line_total ?? 0),
            ))->all(),
            cashOnDelivery: false,
            codAmount: null,
            city: $destination['city'] ?? null,
            country: $destination['country'] ?? null,
            paymentMethod: null,
            cancelledAfterAcceptance: $status->value === 'cancelled' && $mapping->shipment_reference !== null,
            returned: $status->value === 'returned',
            reference: $mapping->shipment_reference,
        );
    }

    /** @return array<string, mixed> */
    private function address(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_object($raw) ? (array) $raw : (is_array($raw) ? $raw : []);
    }

    private function locationOf(?string $provider): ?int
    {
        if ($provider === null || !Schema::hasTable('inventory_locations')) {
            return null;
        }

        return InventoryLocation::where('provider', $provider)->where('is_active', true)->orderBy('id')->value('id');
    }
}
