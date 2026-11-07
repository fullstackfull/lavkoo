<?php

namespace App\Services\SalesChannels\Inventory;

use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\StockSource;
use App\Models\InventoryLocation;
use App\Models\InventoryLocationBalance;
use App\Models\ProviderProductMapping;
use App\Services\Fulfillment\FulfillmentSchema;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reads the stock of a product at a location from that location's source of truth, without copying it.
 *
 *  - A PROVIDER location (Torod Point, M5AZN) is read from `provider_product_mappings`, where the provider integration already keeps its figure
 *    together with whether it was verified as available stock. A figure the integration does not vouch for is `unknown`, never `confirmed`.
 *  - A CHANNEL or MANUAL location (Amazon FBA, a shelf counted by hand) is read from `inventory_location_balances`.
 *  - A location whose source is the PLATFORM (the own warehouse, once `sales_channels.inventory.own_warehouse_source` is `platform` and the location was switched by the audited
 *    command) is read through from the storefront's own stock, one product at a time and never copied: a product without variants is its `current_stock` (a negative one reads as 0),
 *    a variant is its own quantity, and what the store does not count in units (a digital product, unlimited stock, a product a provider holds, a variant that is not there, the
 *    whole of a product sold per variant) is unknown. The figure is confirmed as of the moment it was read.
 *
 * Nothing here ever writes anything: not the provider's figures, not the storefront's stock, and reading a channel's quantity never changes ours.
 */
class LocationStockReader
{
    /** The storefront's figure for stock it does not count in units (digital products). */
    private const UNLIMITED_STOCK = 999999999;

    public function __construct(private readonly ReservationService $reservations)
    {
    }

    /** Is this location's figure the storefront's own stock, read through? Both the location's source and the switch say so: neither alone changes what is read. */
    public function readsPlatform(InventoryLocation $location): bool
    {
        return $location->isStorefrontOwned() && config('sales_channels.inventory.own_warehouse_source') === 'platform';
    }

    /** The products of the store that the own warehouse can hold: the platform's own physical products that no provider holds. */
    public function storeOwnedProducts(): Builder
    {
        return DB::table('products')->where('added_by', 'admin')->where('product_type', 'physical')->when(FulfillmentSchema::productColumnReady(), static fn ($query) => $query->whereNull('fulfillment_provider'));
    }

    /** @param string|null $exceptReservation a reservation not to count against the figure (the order that is asking whether the shelf can cover it) */
    public function availability(InventoryLocation $location, int $productId, string $variantKey = '', ?string $exceptReservation = null): StockAvailability
    {
        $found = match (true) {
            $location->provider !== null => $this->fromProviderMapping($location, $productId, $variantKey),
            $this->readsPlatform($location) => $this->fromPlatformStock($productId, $variantKey),
            default => $this->fromBalance($location, $productId, $variantKey),
        };

        return $this->settled($location, $productId, $variantKey, $found, $exceptReservation);
    }

    /** What the storefront holds of one product (or variant) that a page of them already loaded, judged exactly as `availability` judges it. */
    public function ofProduct(InventoryLocation $location, object $product, string $variantKey): StockAvailability
    {
        return $this->settled($location, (int) $product->id, $variantKey, $this->readProduct($product, $variantKey));
    }

    /** What a provider's own mapping says about one product, judged exactly as the engine and the publisher judge it (for a screen that lists a page of them). */
    public function ofMapping(InventoryLocation $location, ProviderProductMapping $mapping): StockAvailability
    {
        return $this->settled($location, (int) $mapping->product_id, (string) $mapping->variant_key, $this->readMapping($mapping));
    }

    /** What a stored balance says, judged exactly as the engine and the publisher judge it. */
    public function ofBalance(InventoryLocation $location, InventoryLocationBalance $balance): StockAvailability
    {
        return $this->settled($location, (int) $balance->product_id, (string) $balance->variant_key, $this->readBalance($balance));
    }

    private function settled(InventoryLocation $location, int $productId, string $variantKey, StockAvailability $found, ?string $exceptReservation = null): StockAvailability
    {
        if ($found->available === null) {
            return $found;
        }

        return new StockAvailability(
            available: $found->available,
            quality: $this->withFreshness($found->quality, $found->syncedAt),
            syncedAt: $found->syncedAt,
            source: $found->source,
            inFlight: $this->reservations->inFlight((int) $location->id, $productId, $variantKey, $found->syncedAt, $exceptReservation, untilApplied: $this->readsPlatform($location)),
            forTheWholeProduct: $found->forTheWholeProduct,
        );
    }

    /**
     * Every product the location knows a figure for.
     *
     * @return list<array{product_id: int|null, variant_key: string, sku: string|null, availability: StockAvailability}>
     */
    public function snapshot(InventoryLocation $location): array
    {
        $rows = [];
        if ($this->readsPlatform($location)) {
            $this->storeOwnedProducts()->orderBy('id')->chunkById(500, function ($products) use ($location, &$rows): void {
                foreach ($products as $product) {
                    foreach ($this->variantKeysOf($product) as $variantKey) {
                        $rows[] = ['product_id' => (int) $product->id, 'variant_key' => $variantKey, 'sku' => $product->code, 'availability' => $this->ofProduct($location, $product, $variantKey)];
                    }
                }
            });

            return $rows;
        }
        if ($location->provider !== null) {
            foreach (ProviderProductMapping::where('provider', $location->provider)->where('status', MappingStatus::Approved->value)->get() as $mapping) {
                $rows[] = ['product_id' => (int) $mapping->product_id, 'variant_key' => (string) $mapping->variant_key, 'sku' => $mapping->external_sku, 'availability' => $this->availability($location, (int) $mapping->product_id, (string) $mapping->variant_key)];
            }

            return $rows;
        }

        foreach (InventoryLocationBalance::where('location_id', $location->id)->get() as $balance) {
            $rows[] = [
                'product_id' => $balance->product_id,
                'variant_key' => (string) $balance->variant_key,
                'sku' => $balance->sku,
                'availability' => new StockAvailability($balance->available, $this->withFreshness((string) $balance->quality, $balance->source_synced_at), $balance->source_synced_at, 'balance'),
            ];
        }

        return $rows;
    }

    private function fromProviderMapping(InventoryLocation $location, int $productId, string $variantKey): StockAvailability
    {
        $candidates = ProviderProductMapping::where('provider', $location->provider)->where('product_id', $productId)->where('status', MappingStatus::Approved->value)
            ->whereIn('variant_key', array_unique([$variantKey, '']))->get();
        $mapping = $candidates->firstWhere('variant_key', $variantKey) ?? $candidates->firstWhere('variant_key', '');

        return $mapping === null ? StockAvailability::unknown('provider_mapping') : $this->readMapping($mapping, forTheWholeProduct: $variantKey !== '' && (string) $mapping->variant_key === '');
    }

    private function readMapping(ProviderProductMapping $mapping, bool $forTheWholeProduct = false): StockAvailability
    {
        if ($mapping->provider_stock === null) {
            return StockAvailability::unknown('provider_mapping');
        }

        $quality = match ($mapping->stock_source) {
            StockSource::ProviderSync => StockAvailability::CONFIRMED,
            StockSource::Manual => StockAvailability::MANUAL,
            default => StockAvailability::UNKNOWN,
        };

        return new StockAvailability((int) $mapping->provider_stock, $quality, $mapping->provider_stock_synced_at, 'provider_mapping', forTheWholeProduct: $forTheWholeProduct);
    }

    private function fromPlatformStock(int $productId, string $variantKey): StockAvailability
    {
        $product = DB::table('products')->where('id', $productId)->first($this->productColumns());

        return $product === null ? StockAvailability::unknown('platform_stock') : $this->readProduct($product, $variantKey);
    }

    /** @return list<string> */
    private function productColumns(): array
    {
        return array_values(array_filter(['id', 'code', 'added_by', 'product_type', 'current_stock', 'variation', FulfillmentSchema::productColumnReady() ? 'fulfillment_provider' : null]));
    }

    private function readProduct(object $product, string $variantKey): StockAvailability
    {
        $variations = $this->variationsOf($product);
        $units = match (true) {
            ($product->added_by ?? 'admin') !== 'admin', ($product->product_type ?? 'physical') !== 'physical', (int) $product->current_stock >= self::UNLIMITED_STOCK, ($product->fulfillment_provider ?? null) !== null => null,
            $variations === [] => $variantKey === '' ? max(0, (int) $product->current_stock) : null,
            default => $variantKey === '' ? null : $this->variantUnits($variations, $variantKey),
        };

        return $units === null ? StockAvailability::unknown('platform_stock') : new StockAvailability($units, StockAvailability::CONFIRMED, now(), 'platform_stock');
    }

    /** @return list<array<string, mixed>> */
    private function variationsOf(object $product): array
    {
        $decoded = is_string($product->variation ?? null) ? json_decode($product->variation, true) : ($product->variation ?? null);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /** @return list<string> a product sold per variant is one pool for each variant, any other is one pool */
    private function variantKeysOf(object $product): array
    {
        $variations = $this->variationsOf($product);

        return $variations === [] ? [''] : array_map(static fn (array $variation): string => (string) ($variation['type'] ?? ''), $variations);
    }

    /** @param list<array<string, mixed>> $variations */
    private function variantUnits(array $variations, string $variantKey): ?int
    {
        foreach ($variations as $variation) {
            if ((string) ($variation['type'] ?? '') === $variantKey) {
                return max(0, (int) ($variation['qty'] ?? 0));
            }
        }

        return null;
    }

    private function fromBalance(InventoryLocation $location, int $productId, string $variantKey): StockAvailability
    {
        $balance = InventoryLocationBalance::where('location_id', $location->id)->where('product_id', $productId)->where('variant_key', $variantKey)->first();

        return $balance === null ? StockAvailability::unknown('balance') : $this->readBalance($balance);
    }

    private function readBalance(InventoryLocationBalance $balance): StockAvailability
    {
        if ($balance->available === null) {
            return StockAvailability::unknown('balance');
        }

        return new StockAvailability((int) $balance->available, (string) $balance->quality, $balance->source_synced_at, 'balance');
    }

    /** A confirmed figure that has not been refreshed for too long is stale: the source may have changed and we have not heard. */
    private function withFreshness(string $quality, ?CarbonInterface $syncedAt): string
    {
        if ($quality !== StockAvailability::CONFIRMED) {
            return $quality;
        }
        $limit = (int) config('sales_channels.stock.stale_after_minutes', 180);

        return $syncedAt === null || $syncedAt->lt(now()->subMinutes($limit)) ? StockAvailability::STALE : StockAvailability::CONFIRMED;
    }
}
