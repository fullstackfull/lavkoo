<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Enums\Fulfillment\StockSource;
use App\Models\ProviderFulfillmentItem;
use App\Models\ProviderProductMapping;
use App\Models\StockMovement;
use App\Services\Marketplace\InventoryService;
use Illuminate\Support\Facades\DB;

/**
 * Brings the storefront's stock in line with a provider's real stock — for products whose stock the
 * provider owns, and only from a source that has been verified.
 *
 * Available to sell = what the provider reports, minus what we have already sold to customers but the provider
 * has not taken on yet (unrouted, pending, held, in-flight, failed). Without that subtraction a sync landing
 * between "order placed" and "order accepted" would put the stock back up and oversell it.
 *
 * Stock and price changes go through one locked write per product, log a stock movement, and are
 * idempotent: syncing the same numbers twice changes nothing the second time.
 */
class InventorySyncService
{
    /** The largest figure taken as a stock level: a bigger one is a wrong field, not a warehouse. */
    public const MAX_UNITS = 100_000_000;

    private const NOT_YET_WITH_PROVIDER = [
        FulfillmentStatus::Pending, FulfillmentStatus::Held, FulfillmentStatus::Dispatching,
        FulfillmentStatus::ReconcilePending, FulfillmentStatus::RetryWait, FulfillmentStatus::DryRun, FulfillmentStatus::Failed,
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
        $byKey = $this->keyed($mappings);

        foreach ($stockByKey as $key => $quantity) {
            $mapping = $byKey[(string) $key] ?? null;
            if ($mapping === null) {
                $stats['unmapped']++;
                continue;
            }

            $stats[$this->store($mapping, $provider, max(0, (int) $quantity)) ? 'updated' : 'unchanged']++;
        }

        return $stats;
    }

    /**
     * A warehouse's figures for the products ASSIGNED to it, from a source whose stock field has been verified and confirmed.
     *
     * Only an approved mapping of a product that is assigned to this provider is written. A figure that is not a whole number of
     * units (missing, negative, fractional, not a number, or given twice with different values) is never written and never turned
     * into zero: the product keeps the stock it has, and the count says how many figures were refused.
     *
     * @param array<string, int|float|null> $stockByKey the provider's figure keyed "externalId" or "externalId|externalVariantId"; null where there was none
     * @return array{updated: int, unchanged: int, unmapped: int, not_assigned: int, invalid: int, absent: int} `absent` counts assigned products the response said nothing about
     */
    public function applyToAssignedProducts(ProviderCode $provider, array $stockByKey): array
    {
        $stats = ['updated' => 0, 'unchanged' => 0, 'unmapped' => 0, 'not_assigned' => 0, 'invalid' => 0, 'absent' => 0];

        $mappings = ProviderProductMapping::where('provider_product_mappings.provider', $provider->value)
            ->where('provider_product_mappings.status', MappingStatus::Approved->value)
            ->join('products', 'products.id', '=', 'provider_product_mappings.product_id')
            ->get(['provider_product_mappings.*', 'products.fulfillment_provider as assigned_to']);
        $byKey = $this->keyed($mappings);

        foreach ($stockByKey as $key => $figure) {
            $mapping = $byKey[(string) $key] ?? null;
            if ($mapping === null) {
                $stats['unmapped']++;
            } elseif ($mapping->assigned_to !== $provider->value) {
                $stats['not_assigned']++;
            } elseif (($quantity = $this->wholeUnits($figure)) === null) {
                $stats['invalid']++;
            } else {
                $stats[$this->store($mapping, $provider, $quantity) ? 'updated' : 'unchanged']++;
            }
        }

        $assigned = array_filter($byKey, static fn (ProviderProductMapping $mapping): bool => $mapping->assigned_to === $provider->value);
        $stats['absent'] = count(array_diff_key($assigned, $stockByKey));

        return $stats;
    }

    /**
     * Units sold to customers, on orders still open, that the provider has not taken on yet.
     *
     * That is every non-cancelled provider line whose shipment has not been accepted: lines of an order that
     * has not been routed at all (a cash-on-delivery order waiting to be confirmed is the normal case), and lines
     * in a pending / held / in-flight / dry-run / failed shipment. A line the provider already holds is the
     * provider's to count in its own stock. Leaving the unrouted ones out would let a sync put stock back up
     * that a customer has already bought, oversell it, and then have a cancellation restore it a second time.
     */
    public function reservedNotYetWithProvider(ProviderCode $provider, int $productId, string $variantKey): int
    {
        return (int) DB::table('provider_fulfillment_items as items')
            ->join('orders', 'orders.id', '=', 'items.order_id')
            ->leftJoin('provider_fulfillments as pf', 'pf.id', '=', 'items.provider_fulfillment_id')
            ->where('items.provider', $provider->value)
            ->where('items.product_id', $productId)
            ->where('items.variant_key', $variantKey)
            ->where('items.status', '!=', ProviderFulfillmentItem::STATUS_CANCELLED)
            ->whereNotIn('orders.order_status', OrderEligibility::FINISHED_STATUSES)
            ->where(static fn ($query) => $query
                ->whereNull('items.provider_fulfillment_id')
                ->orWhereIn('pf.status', array_map(static fn (FulfillmentStatus $status): string => $status->value, self::NOT_YET_WITH_PROVIDER)))
            ->sum('items.quantity');
    }

    /**
     * @param iterable<ProviderProductMapping> $mappings
     * @return array<string, ProviderProductMapping>
     */
    private function keyed(iterable $mappings): array
    {
        $byKey = [];
        foreach ($mappings as $mapping) {
            $key = $mapping->external_variant_id !== '' ? $mapping->external_id . '|' . $mapping->external_variant_id : $mapping->external_id;
            $byKey[$key] = $mapping;
        }

        return $byKey;
    }

    /** A figure the provider gave for a quantity of units, or null when it cannot be one. A whole number from 0 up to a plausible ceiling is the only kind. */
    private function wholeUnits(int|float|null $figure): ?int
    {
        $units = match (true) {
            is_int($figure) => $figure,
            is_float($figure) && is_finite($figure) && floor($figure) === $figure => (int) $figure,
            default => null,
        };

        return $units !== null && $units >= 0 && $units <= self::MAX_UNITS ? $units : null;
    }

    /** One locked write: the provider's figure on the mapping, and the store's stock for the product it belongs to. */
    private function store(ProviderProductMapping $mapping, ProviderCode $provider, int $providerQuantity): bool
    {
        return DB::transaction(function () use ($mapping, $provider, $providerQuantity): bool {
            $mapping->forceFill([
                'provider_stock' => $providerQuantity,
                'provider_stock_synced_at' => now(),
                'stock_source' => StockSource::ProviderSync,
            ])->save();

            return $this->writeStoreStock($mapping, $provider, $providerQuantity);
        });
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
                    // A variant without a price is not for sale; the provider having stock of it must not make it free.
                    $variation['qty'] = (float) ($variation['price'] ?? 0) > 0 ? $available : 0;
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
