<?php

namespace App\Services\Fulfillment;

use App\Models\ProviderFulfillmentItem;
use App\Models\ProviderProductMapping;
use App\Services\Fulfillment\Contracts\ProviderIdentity;
use App\Services\Fulfillment\Exceptions\UnknownFulfillmentSourceException;
use App\Services\Fulfillment\Support\ProviderCurrency;
use App\Services\Fulfillment\Support\WhatAMarketsOwnGoodsAreFulfilledBy;
use App\Services\Fulfillment\Support\WhatAnUnknownSourceDoes;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
 *
 * **Which codes count as a provider is {@see ProviderRegistry}'s answer, not the enum's.** This asked `ProviderCode::tryFrom()` until
 * H1-11's configuration gate reached it, so a product assigned to a carrier declared in `config/fulfillment.php` without an enum case --
 * which is every carrier added for a second country -- got NO snapshot and fell through to in-house packing, silently, inside the
 * checkout transaction. The registry answers for the two shipped carriers and the declared ones alike.
 *
 * A code the registry cannot resolve either is a different thing and has its own rule: {@see WhatAnUnknownSourceDoes}.
 *
 * The snapshot also records WHERE it was placed: the market of the product and the id of the carrier account that market's shipments run
 * against (C-04). Both are nullable and both are written only from the day the migration runs; a row that carries neither is an order
 * placed before markets existed, and the rule for reading one is {@see OwnershipHistory}.
 */
class OrderItemOwnershipSnapshotter
{
    public function __construct(
        private readonly ProviderCurrency $currency,
        private readonly ProviderRegistry $registry,
        private readonly ProviderConnectionManager $connections,
    ) {
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
        $stored = trim((string) ($product['fulfillment_provider'] ?? ''));

        if ($stored === '') {
            $stored = (string) $this->sourceOfTheMarketsOwnGoods($product);
        }

        if ($stored === '') {
            return null;
        }

        $provider = $this->registry->identity($stored);

        if ($provider === null && !$this->refuseOrHold($stored, (int) $product['id'])) {
            return null;
        }

        $variantKey = (string) ($variant ?? '');
        $mapping = $provider === null ? null : $this->mappingFor($provider, (int) $product['id'], $variantKey);
        $variation = $this->variationEntry($product, $variantKey);
        $lineTotalStored = $netAmount + ($taxModel === 'exclude' ? $lineTax : 0.0);
        $lineTotal = $this->currency->fromStored($lineTotalStored);

        $market = $this->marketOf($product);

        return ProviderFulfillmentItem::create($this->whereItWasPlaced($market, $provider) + [
            'order_id' => $orderId,
            'order_detail_id' => $orderDetailId,
            'product_id' => (int) $product['id'],
            'variant_key' => $variantKey,
            'provider' => $provider?->code() ?? $stored,
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

    /**
     * Whether a source the registry cannot resolve is snapshotted all the same, by the owner's rule (C-03).
     *
     * `false` is today's platform: no snapshot, the line is packed in-house. `true` freezes the line with the code it actually names,
     * which nothing can route, so a person sees it before anything ships. REFUSE never returns: it throws, and the checkout
     * transaction around this call rolls the order back.
     */
    private function refuseOrHold(string $stored, int $productId): bool
    {
        if (WhatAnUnknownSourceDoes::refuses()) {
            throw new UnknownFulfillmentSourceException(storedSource: $stored, productId: $productId);
        }

        return WhatAnUnknownSourceDoes::holds();
    }

    /**
     * The source a market's OWN goods are fulfilled by, for a line whose product names none (C-03).
     *
     * `anyMarketNamesOne()` is asked FIRST, and that order is the point: while no market names a source -- which is every platform as
     * it ships -- an in-house line makes not one query it did not make before, which is the promise {@see marketOf()} documents.
     *
     * @param array<string, mixed> $product
     */
    private function sourceOfTheMarketsOwnGoods(array $product): ?string
    {
        if (!WhatAMarketsOwnGoodsAreFulfilledBy::anyMarketNamesOne()) {
            return null;
        }

        return WhatAMarketsOwnGoodsAreFulfilledBy::forMarket($this->marketOf($product));
    }

    /**
     * The market this line was sold in: what the order line's own copy of the product says, and only where that says nothing, the
     * product row.
     *
     * The copy is preferred because it is the product as it was that day, which is what every other value in this snapshot is; the row
     * is read only for a copy written before the column existed, and only for a provider line, so an in-house checkout makes no query
     * it did not make before.
     *
     * @param array<string, mixed> $product
     */
    private function marketOf(array $product): ?string
    {
        $named = trim((string) ($product[StorefrontCatalog::COLUMN] ?? ''));

        if ($named !== '') {
            return $named;
        }

        if (!Schema::hasColumn('products', StorefrontCatalog::COLUMN)) {
            return null;
        }

        $stored = DB::table('products')->where('id', (int) $product['id'])->value(StorefrontCatalog::COLUMN);

        return is_string($stored) && trim($stored) !== '' ? trim($stored) : null;
    }

    /**
     * The market and the carrier account the line was placed under, as far as the table can hold them.
     *
     * Dormant until `2027_01_02_100000` runs: without the columns this adds nothing and the snapshot is exactly the row it was before.
     *
     * @return array<string, mixed>
     */
    private function whereItWasPlaced(?string $market, ?ProviderIdentity $provider): array
    {
        $row = [];

        if (Schema::hasColumn('provider_fulfillment_items', OwnershipHistory::MARKET)) {
            $row[OwnershipHistory::MARKET] = $market;
        }

        if (Schema::hasColumn('provider_fulfillment_items', OwnershipHistory::ACCOUNT)) {
            $row[OwnershipHistory::ACCOUNT] = $provider === null
                ? null
                : $this->connections->accountIdFor(provider: $provider, country: $market);
        }

        return $row;
    }

    /** The variant's own mapping first, then the whole-product mapping, never another provider's. */
    private function mappingFor(ProviderIdentity $provider, int $productId, string $variantKey): ?ProviderProductMapping
    {
        $candidates = ProviderProductMapping::where('provider', $provider->code())
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
