<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Enums\Fulfillment\StockSource;
use App\Models\ProviderFulfillmentItem;
use App\Models\ProviderProductMapping;
use App\Models\StockMovement;
use App\Services\Fulfillment\Contracts\NotesStockChanged;
use App\Services\Fulfillment\Contracts\ReportsChannelHolds;
use App\Services\Marketplace\InventoryService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Brings the storefront's stock in line with a provider's real stock — for products whose stock the
 * provider owns, and only from a source that has been verified.
 *
 * Available to sell = what the provider reports, minus what we have already sold to customers but the provider
 * has not taken on yet (unrouted, pending, held, in-flight, failed). Without that subtraction a sync landing
 * between "order placed" and "order accepted" would put the stock back up and oversell it.
 *
 * The same provider shelf may also be published to a sales channel (Amazon FBM, Trendyol), and the units its open orders hold come off
 * too (`ReportsChannelHolds`, 0 without the sales-channel module): one figure, one subtraction per promise, so a unit a channel has
 * already sold is not offered to a customer as well. A channel order that has an internal order of its own is counted by that order's
 * provider lines and holds nothing besides, so it is never subtracted twice.
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

    /** Accepted by the provider and not finished: the shipments whose units the provider holds but may not have counted yet. */
    private const ACCEPTED_AND_OPEN = [
        FulfillmentStatus::Submitted, FulfillmentStatus::Processing, FulfillmentStatus::InTransit, FulfillmentStatus::OutForDelivery,
        FulfillmentStatus::DeliveryPostponed, FulfillmentStatus::DeliveryFailed, FulfillmentStatus::PartiallyDelivered, FulfillmentStatus::NeedsAttention,
    ];

    /** What one write to the store's stock came to. */
    private const UPDATED = 'updated';
    private const UNCHANGED = 'unchanged';
    private const UNSUPPORTED = 'unsupported';
    private const NOT_ASSIGNED = 'not_assigned';

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly ReportsChannelHolds $channelHolds,
        private readonly NotesStockChanged $stockNotes,
    ) {
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
     * into zero: the product keeps the stock it has, and the count says how many figures were refused. So does a mapping whose stock
     * cannot be written: a whole-product mapping on a product that has variants, a variant that is no longer there.
     *
     * @param array<string, mixed> $stockByKey the provider's figure keyed "externalId" or "externalId|externalVariantId"; null where there was none
     * @param CarbonInterface|null $readStartedAt when the figures began to be read: an order the provider accepted after that moment may not be in them yet, so it is still held back
     * @return array{updated: int, unchanged: int, unmapped: int, not_assigned: int, invalid: int, unsupported: int, absent: int} `absent` counts assigned products the response said nothing about
     */
    public function applyToAssignedProducts(ProviderCode $provider, array $stockByKey, ?CarbonInterface $readStartedAt = null): array
    {
        $stats = ['updated' => 0, 'unchanged' => 0, 'unmapped' => 0, 'not_assigned' => 0, 'invalid' => 0, 'unsupported' => 0, 'absent' => 0];

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
                $stats[$this->storeAssigned($mapping, $provider, $quantity, $readStartedAt)]++;
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
     *
     * With `$acceptedSince`, the lines the provider accepted at or after that moment are held back too: a figure read from the provider
     * from then on may not have counted them yet, and a sync that put them back would sell units the provider already holds. A shipment
     * with no recorded acceptance time counts from its last event, and failing that from its last change.
     */
    public function reservedNotYetWithProvider(ProviderCode $provider, int $productId, string $variantKey, ?CarbonInterface $acceptedSince = null): int
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
                ->orWhereIn('pf.status', array_map(static fn (FulfillmentStatus $status): string => $status->value, self::NOT_YET_WITH_PROVIDER))
                ->when($acceptedSince !== null, static fn ($recent) => $recent->orWhere(static fn ($accepted) => $accepted
                    ->whereIn('pf.status', array_map(static fn (FulfillmentStatus $status): string => $status->value, self::ACCEPTED_AND_OPEN))
                    ->whereRaw('COALESCE(pf.submitted_at, pf.last_received_at, pf.updated_at) >= ?', [$acceptedSince]))))
            ->sum('items.quantity');
    }

    /**
     * Writes the storefront's stock again from the figures the provider has ALREADY given, without asking the provider and without making an old figure look new.
     *
     * It is for the moment a product changes hands: the number the storefront sells from has to become the new owner's, computed the way every other write computes it (the
     * provider's figure less the store orders it has not taken on and less what the channels hold), and each figure keeps the age it really has, so a stale one stays stale
     * and is not published as confirmed. Nothing is sent anywhere and no mapping is re-stamped.
     *
     * @return array{products: int, updated: int, unchanged: int, unsupported: int, not_assigned: int} `products` counts the mappings whose figure was read
     */
    public function rewriteStoreStockFromStoredFigures(ProviderCode $provider, int $productId): array
    {
        $stats = ['products' => 0, 'updated' => 0, 'unchanged' => 0, 'unsupported' => 0, 'not_assigned' => 0];
        $mappings = ProviderProductMapping::where('provider', $provider->value)
            ->where('product_id', $productId)
            ->where('status', MappingStatus::Approved->value)
            ->orderBy('id')
            ->get();

        foreach ($mappings as $mapping) {
            if ($mapping->provider_stock === null) {
                continue;
            }
            $stats['products']++;
            $outcome = DB::transaction(fn (): string => $this->writeStoreStock($mapping, $provider, (int) $mapping->provider_stock, $mapping->provider_stock_synced_at, assignedOnly: true));
            $stats[$outcome] = ($stats[$outcome] ?? 0) + 1;
        }

        return $stats;
    }

    /** One line in the platform's own stock ledger, for a change to a product's stock this module made outside a provider sync (a product leaving a provider). */
    public function recordStockMovement(int $productId, int $qtyChange, int $balanceAfter, string $reason, ?string $note = null): void
    {
        $this->inventory->record(
            productId: $productId,
            type: StockMovement::TYPE_ADJUSTMENT,
            qtyChange: $qtyChange,
            balanceAfter: $balanceAfter,
            reason: $reason,
            referenceType: $reason,
            note: $note,
        );
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
    private function wholeUnits(mixed $figure): ?int
    {
        $isCount = (is_int($figure) || (is_float($figure) && is_finite($figure) && floor($figure) === $figure))
            && $figure >= 0
            && $figure <= self::MAX_UNITS;

        return $isCount ? (int) $figure : null;
    }

    /** The provider's figure on the mapping, labelled as synced. */
    private function stamp(ProviderProductMapping $mapping, int $providerQuantity): void
    {
        $mapping->forceFill([
            'provider_stock' => $providerQuantity,
            'provider_stock_synced_at' => now(),
            'stock_source' => StockSource::ProviderSync,
        ])->save();
    }

    /** One locked write: the provider's figure on the mapping, and the store's stock for the product it belongs to. */
    private function store(ProviderProductMapping $mapping, ProviderCode $provider, int $providerQuantity): bool
    {
        return DB::transaction(function () use ($mapping, $provider, $providerQuantity): bool {
            $this->stamp($mapping, $providerQuantity);

            return $this->writeStoreStock($mapping, $provider, $providerQuantity) === self::UPDATED;
        });
    }

    /**
     * The same write for a product assigned to the provider: ownership is checked again under the product's lock, and the mapping is
     * labelled as synced only when the store's stock could really be written.
     *
     * @return string one of the stats keys: updated, unchanged, unsupported, not_assigned
     */
    private function storeAssigned(ProviderProductMapping $mapping, ProviderCode $provider, int $providerQuantity, ?CarbonInterface $acceptedSince): string
    {
        $before = (int) ($mapping->provider_stock ?? -1);
        $outcome = DB::transaction(function () use ($mapping, $provider, $providerQuantity, $acceptedSince): string {
            $written = $this->writeStoreStock($mapping, $provider, $providerQuantity, $acceptedSince, assignedOnly: true);
            if ($written === self::UPDATED || $written === self::UNCHANGED) {
                $this->stamp($mapping, $providerQuantity);
            }

            return $written;
        });

        // Whoever else sells this shelf (Amazon FBM, Trendyol) may now be shown a different quantity, and used to find out only at the next quarter-hour sweep. The note is
        // made once the figure is written and only when it really moved; it sends nothing to any channel and decides nothing about whether anything may be sent.
        if ($outcome === self::UPDATED || $outcome === self::UNCHANGED) {
            $this->stockNotes->providerFigureChanged(
                provider: $provider,
                productId: (int) $mapping->product_id,
                variantKey: (string) $mapping->variant_key,
                figureChanged: $before !== $providerQuantity,
            );
        }

        return $outcome;
    }

    private function writeStoreStock(ProviderProductMapping $mapping, ProviderCode $provider, int $providerQuantity, ?CarbonInterface $acceptedSince = null, bool $assignedOnly = false): string
    {
        $product = DB::table('products')->where('id', $mapping->product_id)->lockForUpdate()->first();
        if ($product === null) {
            return self::UNSUPPORTED;
        }
        if ($assignedOnly && ($product->fulfillment_provider ?? null) !== $provider->value) {
            return self::NOT_ASSIGNED;
        }

        $variations = json_decode((string) $product->variation, true) ?: [];
        if ($assignedOnly && $mapping->variant_key === '' && $variations !== []) {
            // One figure for a product the storefront sells per variant cannot be spread over the variants.
            return self::UNSUPPORTED;
        }

        $promised = $this->reservedNotYetWithProvider($provider, (int) $product->id, $mapping->variant_key, $acceptedSince)
            + $this->channelHolds->unitsHeld(provider: $provider, productId: (int) $product->id, variantKey: (string) $mapping->variant_key, figureReadAt: $acceptedSince);
        $available = max(0, $providerQuantity - $promised);
        $before = (int) $product->current_stock;
        $values = [];
        $variantChanged = false;

        if ($mapping->variant_key !== '') {
            $found = false;
            foreach ($variations as &$variation) {
                if (($variation['type'] ?? null) === $mapping->variant_key) {
                    // A variant without a price is not for sale; the provider having stock of it must not make it free.
                    $quantity = (float) ($variation['price'] ?? 0) > 0 ? $available : 0;
                    $variantChanged = $variantChanged || ($variation['qty'] ?? null) !== $quantity;
                    $variation['qty'] = $quantity;
                    $found = true;
                }
            }
            unset($variation);
            if (!$found) {
                return self::UNSUPPORTED;
            }
            $values['variation'] = json_encode($variations);
            $after = (int) array_sum(array_map(static fn (array $variation): int => (int) ($variation['qty'] ?? 0), $variations));
        } else {
            $after = $available;
        }

        // The legacy write (M5AZN) keeps rewriting a variation it did not change: its behaviour is not part of this change.
        $nothingToRewrite = !isset($values['variation']) || ($assignedOnly && !$variantChanged);
        if ($after === $before && $nothingToRewrite) {
            return self::UNCHANGED;
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

        return $after !== $before ? self::UPDATED : self::UNCHANGED;
    }
}
