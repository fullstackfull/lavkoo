<?php

namespace App\Services\SalesChannels\Shipments;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\ExternalOrderMapping;
use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\Exceptions\ProviderUnavailableException;
use App\Services\Fulfillment\FieldMapStore;
use App\Services\Fulfillment\Providers\TorodPoint\TorodFieldMaps;
use App\Services\Fulfillment\Providers\TorodPoint\TorodFulfillmentClient;
use App\Services\Fulfillment\Support\FieldMap;
use Carbon\CarbonImmutable;

/**
 * Finds, READ-ONLY, the Torod Point order that was created straight from a channel order, by ONE exact fact: the channel's order number (or package id, or tracking number: the account's
 * `torod_reference_field` says which) is the text Torod holds in the invoice number of that order. Torod's order list has no search, so the first pages of the list are read once and kept in memory for a short while; an order further back is not seen
 * and counts as not found, never as a guess. Nothing about the buyer (name, phone, address, city) is ever compared: it would "prove" nothing.
 *
 * Which of Torod's fields carries the channel's identifier is NOT KNOWN (no live sample of a shipment created from Trendyol exists), so this lookup is off until the owner has seen one and set
 * `sales_channels.shipment_discovery`; the orders field map must also be verified. When anything about the read is unreadable the lookup says it is unavailable instead of finding nothing.
 */
final class TorodShipmentLookup implements ExternalShipmentLookup
{
    private const PAGE_SIZE = 50;

    /** @var array{at: CarbonImmutable, orders: list<array{invoice: string, reference: string, status: string}>}|null */
    private ?array $index = null;

    public function __construct(
        private readonly TorodFulfillmentClient $client,
        private readonly FieldMapStore $fieldMaps,
    ) {
    }

    public function available(): bool
    {
        return (bool) config('sales_channels.shipment_discovery.enabled', false) && $this->orders() !== null;
    }

    public function candidates(ExternalOrderMapping $mapping): array
    {
        $identifier = $this->identifierOf($mapping);
        $orders = $this->orders();
        if ($identifier === '' || $orders === null) {
            return [];
        }

        $ours = ProviderFulfillment::where('provider', ProviderCode::TorodPoint->value)->whereIn('provider_reference', array_column($orders, 'reference'))->pluck('provider_reference')->all();

        return array_values(array_map(
            fn (array $order): ShipmentCandidate => new ShipmentCandidate(provider: ProviderCode::TorodPoint->value, reference: $order['reference'], status: $order['status'] ?: null, matchedOn: 'invoice_number = ' . $this->fieldOf($mapping)),
            array_filter($orders, static fn (array $order): bool => $order['invoice'] === $identifier && !in_array($order['reference'], $ours, true)),
        ));
    }

    /** The provider's status word of the order with this reference, when the scan sees it. */
    public function statusOf(string $reference): ?string
    {
        foreach ($this->orders() ?? [] as $order) {
            if ($order['reference'] === $reference) {
                return $order['status'];
            }
        }

        return null;
    }

    /** Which of the channel's values Torod holds as the reference: the account's own setting (`torod_reference_field`), the channel order number unless it says otherwise. */
    private function fieldOf(ExternalOrderMapping $mapping): string
    {
        $mapping->loadMissing('account');
        $field = (string) $mapping->account?->setting('torod_reference_field', 'order_number');

        return in_array($field, ['package_id', 'tracking_number'], true) ? $field : 'order_number';
    }

    private function identifierOf(ExternalOrderMapping $mapping): string
    {
        return trim((string) match ($this->fieldOf($mapping)) {
            'package_id' => $mapping->external_package_id,
            'tracking_number' => $mapping->tracking_number,
            default => $mapping->external_order_id,
        });
    }

    /** @return list<array{invoice: string, reference: string, status: string}>|null null when the list cannot be read or the map that reads it is not verified */
    private function orders(): ?array
    {
        $ttl = (int) config('sales_channels.shipment_discovery.torod.cache_seconds', 120);
        if ($this->index !== null && $this->index['at']->diffInSeconds(now(), absolute: true) < $ttl) {
            return $this->index['orders'];
        }

        $stored = $this->fieldMaps->get(ProviderCode::TorodPoint, TorodFieldMaps::ORDERS);
        $map = $stored->map();
        if (!$stored->verified || !$map->has('invoice_number') || !$map->has('reference')) {
            return null;
        }

        $orders = [];
        try {
            for ($page = 1; $page <= max(1, (int) config('sales_channels.shipment_discovery.torod.max_pages', 3)); $page++) {
                $result = $this->client->listOrders($page, self::PAGE_SIZE);
                $items = $result->ok() && $result->json !== null ? FieldMap::extractItems($result->json, $stored->itemsPath) : null;
                if ($items === null) {
                    return null;
                }
                foreach ($items as $item) {
                    $orders[] = ['invoice' => trim($map->string($item, 'invoice_number')), 'reference' => trim($map->string($item, 'reference')), 'status' => trim($map->string($item, 'status'))];
                }
                if (count($items) < self::PAGE_SIZE) {
                    break;
                }
            }
        } catch (ProviderUnavailableException) {
            return null;
        }

        $this->index = ['at' => CarbonImmutable::now(), 'orders' => array_values(array_filter($orders, static fn (array $order): bool => $order['invoice'] !== '' && $order['reference'] !== ''))];

        return $this->index['orders'];
    }
}
