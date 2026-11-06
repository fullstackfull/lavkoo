<?php

namespace App\Services\Fulfillment\Providers\TorodPoint;

use App\Enums\Fulfillment\PaymentMode;
use App\Models\Order;
use App\Models\ProviderCity;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;
use App\Services\Fulfillment\Support\ProviderConfig;
use App\Services\Fulfillment\Support\SaudiPhone;

/**
 * Builds the body of POST /public/fulfillment/order from the Fulfillment API v5 document's sample:
 *
 *   receiverName, receiverPhone, receiverPhone2, receiverAddress {cityId, regionId, addressLine1},
 *   notes, weight, receiverBusinessName, shipmentType (REGULAR = paid, COD = cash on delivery),
 *   codCollectionMethod, cod, [cost], items [{productId, sku, price, quantity}], invoiceNumber
 *
 * `invoiceNumber` is OUR fulfillment reference: it is what links the provider's later last-mile webhooks
 * back to this fulfillment, and what a reconciliation looks for. Only this fulfillment's own items go in.
 *
 * Three fields the document leaves unexplained are handled conservatively: `villageId` is omitted (our
 * checkout has no district), `cost` is omitted unless an operator chose what it should carry, and for
 * cash on delivery `codCollectionMethod` is whatever LogesTechs told the operator to send — never guessed.
 */
class TorodOrderPayloadBuilder
{
    /** @return array<string, mixed> */
    public function build(ProviderFulfillment $fulfillment, Order $order, ProviderConfig $config, ProviderCity $city): array
    {
        $address = $this->address($order);
        $isCod = $fulfillment->payment_mode === PaymentMode::CashOnDelivery;
        $local = (string) SaudiPhone::normalize((string) ($address['phone'] ?? ''));

        $payload = [
            'receiverName' => trim((string) ($address['contact_person_name'] ?? '')),
            'receiverPhone' => $this->formatPhone($local, $config),
            'receiverPhone2' => '',
            'receiverAddress' => array_filter([
                'cityId' => (string) $city->external_id,
                'regionId' => $city->region_external_id,
                'addressLine1' => trim((string) ($address['address'] ?? '')),
            ], static fn ($value): bool => $value !== null && $value !== ''),
            'notes' => $this->notes($order),
            'weight' => $this->weight($fulfillment, $config),
            'receiverBusinessName' => '',
            'shipmentType' => $isCod ? 'COD' : 'REGULAR',
            'codCollectionMethod' => $isCod ? $config->string('cod_collection_method') : 'PREPAID',
            'cod' => $this->money($isCod ? (float) $fulfillment->cod_amount : 0.0),
        ];

        $cost = $this->cost($fulfillment, $config);
        if ($cost !== null) {
            $payload['cost'] = $cost;
        }

        $payload['items'] = $fulfillment->items->map(fn (ProviderFulfillmentItem $item): array => array_filter([
            'productId' => ctype_digit((string) $item->external_product_id) ? (int) $item->external_product_id : $item->external_product_id,
            'sku' => $item->external_sku,
            'price' => round((float) $item->line_total / max(1, $item->quantity), 2),
            'quantity' => (int) $item->quantity,
        ], static fn ($value): bool => $value !== null && $value !== ''))->values()->all();
        $payload['invoiceNumber'] = $fulfillment->reference;

        return $payload;
    }

    /** @return array<string, mixed> */
    public function address(Order $order): array
    {
        $raw = $order->shipping_address_data;

        return is_object($raw) ? (array) $raw : (is_array($raw) ? $raw : []);
    }

    private function formatPhone(string $local, ProviderConfig $config): string
    {
        return $config->string('phone_format', 'international_00') === 'local' || $local === ''
            ? $local
            : '00966' . substr($local, 1);
    }

    private function weight(ProviderFulfillment $fulfillment, ProviderConfig $config): int
    {
        $total = (float) $fulfillment->items->sum(static fn (ProviderFulfillmentItem $item): float => (float) $item->weight_kg * $item->quantity);
        if ($total <= 0) {
            $total = (float) ($config->get('default_weight_kg') ?: 1);
        }

        return max(1, (int) ceil($total));
    }

    private function cost(ProviderFulfillment $fulfillment, ProviderConfig $config): ?float
    {
        return match ($config->string('cost_source', 'omit')) {
            'shipping_fee' => max(0.0, round((float) $fulfillment->shipping_allocation, 2)),
            'items_total' => round((float) $fulfillment->items_total, 2),
            default => null,
        };
    }

    private function notes(Order $order): string
    {
        return mb_substr(trim((string) $order->order_note), 0, 250);
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
