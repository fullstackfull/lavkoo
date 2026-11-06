<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderFulfillmentItem;
use App\Models\ProviderProductMapping;
use App\Services\Fulfillment\Support\ProviderCurrency;

/**
 * Freezes who fulfils an order line, at the moment the order is placed.
 *
 * Called from inside the checkout transaction, once per line, right after the line is written. A
 * product with no provider (the default, and every existing product) costs nothing and writes nothing:
 * the absence of a snapshot IS the in-house default. A provider-owned product MUST get a snapshot — so
 * this method does not swallow errors: if it cannot record ownership the order transaction rolls back,
 * rather than letting an M5AZN or Torod Point item silently fall through to in-house handling.
 *
 * The snapshot is what routing reads. Reassigning the product afterwards cannot move an order that is
 * already placed.
 */
class OrderItemOwnershipSnapshotter
{
    public function __construct(private readonly ProviderCurrency $currency)
    {
    }

    /** @param array<string, mixed> $product the product as stored in `order_details.product_details` */
    public function capture(
        int $orderId,
        int $orderDetailId,
        array $product,
        ?string $variant,
        int $quantity,
        float $unitPrice,
        float $netAmount,
        float $lineTax,
        string $taxModel,
    ): ?ProviderFulfillmentItem {
        $provider = ProviderCode::tryFrom((string) ($product['fulfillment_provider'] ?? ''));
        if ($provider === null) {
            return null;
        }

        $variantKey = (string) ($variant ?? '');
        $mapping = $this->mappingFor($provider, (int) $product['id'], $variantKey);
        $variation = $this->variationEntry($product, $variantKey);
        $lineTotalStored = $netAmount + ($taxModel === 'exclude' ? $lineTax : 0.0);
        $lineTotal = $this->currency->fromStored($lineTotalStored);

        return ProviderFulfillmentItem::create([
            'order_id' => $orderId,
            'order_detail_id' => $orderDetailId,
            'product_id' => (int) $product['id'],
            'variant_key' => $variantKey,
            'provider' => $provider->value,
            'mapping_id' => $mapping?->id,
            'external_product_id' => $mapping?->external_id,
            'external_sku' => $mapping?->external_sku,
            'external_barcode' => $mapping?->external_barcode,
            'sku' => $variation['sku'] ?? ($product['code'] ?? null),
            'name' => (string) ($product['name'] ?? ''),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_discount' => max(0.0, $unitPrice * $quantity - $netAmount),
            'line_tax' => $lineTax,
            'line_total' => $lineTotal ?? 0,
            'unit_cost' => $mapping?->supplier_cost,
            'weight_kg' => isset($product['weight']) && is_numeric($product['weight']) ? (float) $product['weight'] : null,
            'status' => ProviderFulfillmentItem::STATUS_UNROUTED,
            'snapshot' => [
                'captured_at' => now()->toIso8601String(),
                'product' => [
                    'id' => (int) $product['id'],
                    'code' => $product['code'] ?? null,
                    'barcode' => $product['barcode'] ?? null,
                    'purchase_price' => $product['purchase_price'] ?? null,
                    'unit_price' => $product['unit_price'] ?? null,
                ],
                'variation' => $variation,
                'mapping' => $mapping === null ? null : [
                    'id' => $mapping->id,
                    'status' => $mapping->status->value,
                    'external_id' => $mapping->external_id,
                    'external_variant_id' => $mapping->external_variant_id,
                    'supplier_cost' => $mapping->supplier_cost,
                    'supplier_cost_currency' => $mapping->supplier_cost_currency,
                ],
                'currency_converted' => $lineTotal !== null,
            ],
        ]);
    }

    /** The variant's own mapping first, then the whole-product mapping, never another provider's. */
    private function mappingFor(ProviderCode $provider, int $productId, string $variantKey): ?ProviderProductMapping
    {
        $candidates = ProviderProductMapping::where('provider', $provider->value)
            ->where('product_id', $productId)
            ->whereIn('variant_key', array_unique([$variantKey, '']))
            ->get();

        return $candidates->firstWhere('variant_key', $variantKey) ?? $candidates->firstWhere('variant_key', '');
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>|null
     */
    private function variationEntry(array $product, string $variantKey): ?array
    {
        if ($variantKey === '') {
            return null;
        }

        $variations = $product['variation'] ?? [];
        if (is_string($variations)) {
            $variations = json_decode($variations, true) ?: [];
        }

        foreach ($variations as $entry) {
            if (is_array($entry) && ($entry['type'] ?? null) === $variantKey) {
                return $entry;
            }
        }

        return null;
    }
}
