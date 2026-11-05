<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Enums\Fulfillment\StockSource;
use App\Models\ProviderProductMapping;
use App\Models\StockMovement;
use App\Services\Marketplace\InventoryService;
use Illuminate\Support\Facades\DB;

/**
 * Brings the storefront's stock in line with a provider's real stock — for products whose stock the
 * provider owns, and only from a source that has been verified.
 *
 * Available to sell = what the provider reports, minus what we have already sold to customers but not yet
 * handed to the provider (pending / held / in-flight dispatches). Without that subtraction a sync landing
 * between "order placed" and "order accepted" would put the stock back up and oversell it.
 *
 * Stock and price changes go through one locked write per product, log a stock movement, and are
 * idempotent: syncing the same numbers twice changes nothing the second time.
 */
class InventorySyncService
{
    private const NOT_YET_WITH_PROVIDER = [
        FulfillmentStatus::Pending, FulfillmentStatus::Held, FulfillmentStatus::Dispatching,
        FulfillmentStatus::ReconcilePending, FulfillmentStatus::RetryWait, FulfillmentStatus::DryRun,
    ];

    public function __construct(private readonly InventoryService $inventory)
    {
    }

    /**
     * @param array<string, int> $stockByKey provider quantity keyed "externalId" or "externalId|externalVariantId"
     * @return array{updated: int, unchanged: int, unmapped: int}
     */
    public function apply(ProviderCode $provider, array $stockByKey): array
    {
        $stats = ['updated' => 0, 'unchanged' => 0, 'unmapped' => 0];

        $mappings = ProviderProductMapping::where('provider', $provider->value)
            ->where('status', MappingStatus::Approved->value)
            ->get();
        $byKey = [];
        foreach ($mappings as $mapping) {
            $key = $mapping->external_variant_id !== '' ? $mapping->external_id . '|' . $mapping->external_variant_id : $mapping->external_id;
            $byKey[$key] = $mapping;
        }

        foreach ($stockByKey as $key => $quantity) {
            $mapping = $byKey[(string) $key] ?? null;
            if ($mapping === null) {
                $stats['unmapped']++;
                continue;
            }

            $changed = DB::transaction(function () use ($mapping, $quantity, $provider): bool {
                $mapping->forceFill([
                    'provider_stock' => max(0, (int) $quantity),
                    'provider_stock_synced_at' => now(),
                    'stock_source' => StockSource::ProviderSync,
                ])->save();

                return $this->writeStoreStock($mapping, $provider, max(0, (int) $quantity));
            });

            $stats[$changed ? 'updated' : 'unchanged']++;
        }

        return $stats;
    }

    /** Units sold to customers for this product/variant that the provider has not accepted yet. */
    public function reservedNotYetWithProvider(ProviderCode $provider, int $productId, string $variantKey): int
    {
        return (int) DB::table('provider_fulfillment_items as items')
            ->join('provider_fulfillments as pf', 'pf.id', '=', 'items.provider_fulfillment_id')
            ->where('items.provider', $provider->value)
            ->where('items.product_id', $productId)
            ->where('items.variant_key', $variantKey)
            ->whereIn('pf.status', array_map(static fn (FulfillmentStatus $status): string => $status->value, self::NOT_YET_WITH_PROVIDER))
            ->sum('items.quantity');
    }

    private function writeStoreStock(ProviderProductMapping $mapping, ProviderCode $provider, int $providerQuantity): bool
    {
        $product = DB::table('products')->where('id', $mapping->product_id)->lockForUpdate()->first();
        if ($product === null) {
            return false;
        }

        $available = max(0, $providerQuantity - $this->reservedNotYetWithProvider($provider, (int) $product->id, $mapping->variant_key));
        $before = (int) $product->current_stock;
        $values = [];

        if ($mapping->variant_key !== '') {
            $variations = json_decode((string) $product->variation, true) ?: [];
            $found = false;
            foreach ($variations as &$variation) {
                if (($variation['type'] ?? null) === $mapping->variant_key) {
                    $variation['qty'] = $available;
                    $found = true;
                }
            }
            unset($variation);
            if (!$found) {
                return false;
            }
            $values['variation'] = json_encode($variations);
            $after = (int) array_sum(array_map(static fn (array $variation): int => (int) ($variation['qty'] ?? 0), $variations));
        } else {
            $after = $available;
        }

        if ($after === $before && !isset($values['variation'])) {
            return false;
        }

        $values['current_stock'] = $after;
        $values['updated_at'] = now();
        DB::table('products')->where('id', $product->id)->update($values);

        if ($after !== $before) {
            $this->inventory->record(
                productId: $product->id,
                type: StockMovement::TYPE_ADJUSTMENT,
                qtyChange: $after - $before,
                balanceAfter: $after,
                reason: 'provider_sync',
                referenceType: 'provider_sync',
                note: $provider->value . ':' . $mapping->external_id,
            );
        }

        return $after !== $before;
    }
}
