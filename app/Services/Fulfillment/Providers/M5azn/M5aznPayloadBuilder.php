<?php

namespace App\Services\Fulfillment\Providers\M5azn;

use App\Enums\Fulfillment\PaymentMode;
use App\Models\Order;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;
use App\Services\Fulfillment\Support\ProviderConfig;
use App\Services\Fulfillment\Support\SaudiPhone;

/**
 * Builds the body of POST /api/v1/orders exactly as M5AZN documents it:
 *
 *   id, total, payment_method ("cod" | "card"), cod_amount, order_created_at, platform_name,
 *   shipping_info {address, country, country_code, city},
 *   customer_info {first_name, last_name, email, phone},
 *   items [{id, product_id, sku, name, qty_ordered}]
 *
 * It contains ONLY the fulfillment's own items and its own allocated amounts: never the parent order's
 * other lines, and — for cash on delivery — only the share this provider collects.
 */
class M5aznPayloadBuilder
{
    /** @return array<string, mixed> */
    public function build(ProviderFulfillment $fulfillment, Order $order, ProviderConfig $config): array
    {
        $address = $this->address($order);
        $isCod = $fulfillment->payment_mode === PaymentMode::CashOnDelivery;
        [$first, $last] = $this->splitName((string) ($address['contact_person_name'] ?? ''));

        return [
            'id' => (int) $order->id,
            'total' => (float) $fulfillment->total_amount,
            'payment_method' => $isCod ? 'cod' : 'card',
            'cod_amount' => $isCod ? (float) $fulfillment->cod_amount : 0.0,
            'order_created_at' => $order->created_at?->format('Y-m-d H:i:s'),
            'platform_name' => $this->platformName($config),
            'shipping_info' => [
                'address' => trim((string) ($address['address'] ?? '')),
                'country' => $config->string('country_name', 'Saudi Arabia'),
                'country_code' => $config->string('country_code', 'SA'),
                'city' => trim((string) ($address['city'] ?? '')),
            ],
            'customer_info' => [
                'first_name' => $first,
                'last_name' => $last,
                'email' => trim((string) ($address['email'] ?? '')),
                'phone' => SaudiPhone::normalize((string) ($address['phone'] ?? '')) ?? '',
            ],
            'items' => $fulfillment->items->map(fn (ProviderFulfillmentItem $item): array => [
                'id' => (int) $item->order_detail_id,
                'product_id' => ctype_digit((string) $item->external_product_id) ? (int) $item->external_product_id : $item->external_product_id,
                'sku' => (string) $item->external_sku,
                'name' => $item->name,
                'qty_ordered' => (int) $item->quantity,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> the order's shipping address as a plain array (it is stored as a JSON object) */
    public function address(Order $order): array
    {
        $raw = $order->shipping_address_data;

        return is_object($raw) ? (array) $raw : (is_array($raw) ? $raw : []);
    }

    /** @return array{0: string, 1: string} */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name), 2) ?: [];

        return [$parts[0] ?? '', $parts[1] ?? '-'];
    }

    private function platformName(ProviderConfig $config): string
    {
        $configured = $config->string('platform_name');

        return $configured !== '' ? $configured : (string) (getWebConfig(name: 'company_name') ?? config('app.name'));
    }
}
