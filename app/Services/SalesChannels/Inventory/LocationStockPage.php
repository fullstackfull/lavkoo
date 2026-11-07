<?php

namespace App\Services\SalesChannels\Inventory;

use App\Enums\Fulfillment\MappingStatus;
use App\Models\InventoryLocation;
use App\Models\InventoryLocationBalance;
use App\Models\InventoryPool;
use App\Models\ProviderProductMapping;
use App\Services\SalesChannels\Inventory\Allocation\PoolListings;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * One page of what a location holds, for the stock screen. Each location keeps to its own source (a provider's mappings, or the balances a channel reported or a person
 * typed), nothing is added across locations, and every figure is judged by the reader the engine and the publisher use, so the screen cannot say "confirmed" about a figure they
 * would not offer. Only the page that is shown is read.
 */
class LocationStockPage
{
    public function __construct(
        private readonly LocationStockReader $reader,
        private readonly PoolNet $pools,
        private readonly ReservationService $reservations,
        private readonly PoolListings $listings,
    ) {
    }

    /**
     * @return array{rows: LengthAwarePaginator, skus: int}
     *     each row: {sku: string|null, product_id: int|null, variant_key: string, availability: StockAvailability, pool: PoolFigures, holds: array<string, int> (open orders' units by channel),
     *     members: list<SalesChannelProductMapping> (the listings the pool was last shared between), extras: array{reserved: int|null, unfulfillable: int|null, inbound: int|null, total: int|null}|null}
     */
    public function for(InventoryLocation $location, ?string $sku, int $perPage): array
    {
        $page = match (true) {
            $location->provider !== null => $this->providerRows($location, $sku, $perPage),
            $this->reader->readsPlatform($location) => $this->platformRows($location, $sku, $perPage),
            default => $this->balanceRows($location, $sku, $perPage),
        };

        return ['rows' => $this->withPools($location, $page), 'skus' => $page->total()];
    }

    /** Each row with the pool it belongs to: the pool's own buffer (so that the net is the one the publisher works from), who promised its units, and the listings that share it. */
    private function withPools(InventoryLocation $location, LengthAwarePaginator $page): LengthAwarePaginator
    {
        $keys = $page->getCollection()->filter(static fn (array $row): bool => $row['product_id'] !== null)
            ->map(static fn (array $row): PoolKey => new PoolKey((int) $location->id, (int) $row['product_id'], $row['variant_key']))->values();
        $buffers = InventoryPool::where('location_id', $location->id)->whereIn('product_id', $keys->map(static fn (PoolKey $key): int => $key->productId)->unique()->all())->get()
            ->mapWithKeys(static fn (InventoryPool $pool): array => [(string) new PoolKey($location->id, $pool->product_id, $pool->variant_key) => (int) $pool->safety_buffer]);
        $members = $this->listings->of($keys->all());

        return $page->setCollection($page->getCollection()->map(function (array $row) use ($location, $buffers, $members): array {
            $key = new PoolKey((int) $location->id, (int) $row['product_id'], $row['variant_key']);

            return $row + [
                'pool' => $this->pools->of($location, $key->productId, $key->variantKey, $row['availability'], buffer: $buffers[(string) $key] ?? 0),
                'holds' => $row['availability']->inFlight > 0 ? $this->reservations->inFlightByChannel($key->locationId, $key->productId, $key->variantKey, $row['availability']->syncedAt) : [],
                'members' => $members[(string) $key] ?? [],
            ];
        }));
    }

    private function providerRows(InventoryLocation $location, ?string $sku, int $perPage): LengthAwarePaginator
    {
        return ProviderProductMapping::query()
            ->where('provider', $location->provider)
            ->where('status', MappingStatus::Approved->value)
            ->when($sku, static fn ($query, string $term) => $query->where('external_sku', 'like', '%' . $term . '%'))
            ->orderBy('product_id')->orderBy('variant_key')
            ->paginate($perPage)->withQueryString()
            ->through(fn (ProviderProductMapping $mapping): array => $this->row(
                sku: $mapping->external_sku,
                productId: (int) $mapping->product_id,
                variantKey: (string) $mapping->variant_key,
                availability: $this->reader->ofMapping($location, $mapping),
                extras: null,
            ));
    }

    /** The store's own products, a page of them at a time and read where they are kept: a product sold per variant is one row for each variant. */
    private function platformRows(InventoryLocation $location, ?string $sku, int $perPage): LengthAwarePaginator
    {
        $page = $this->reader->storeOwnedProducts()
            ->when($sku, static fn ($query, string $term) => $query->where(static fn ($inner) => $inner->where('code', 'like', '%' . $term . '%')->orWhere('name', 'like', '%' . $term . '%')))
            ->orderBy('id')->select(['id', 'code', 'added_by', 'product_type', 'current_stock', 'variation'])
            ->paginate($perPage)->withQueryString();

        return $page->setCollection($page->getCollection()->flatMap(function (object $product) use ($location): array {
            $variationTypes = collect(json_decode((string) $product->variation, true) ?: [])->filter(static fn (mixed $variation): bool => is_array($variation))->map(static fn (array $variation): string => (string) ($variation['type'] ?? ''))->values();

            return ($variationTypes->isEmpty() ? collect(['']) : $variationTypes)->map(fn (string $variantKey): array => $this->row(
                sku: $product->code,
                productId: (int) $product->id,
                variantKey: $variantKey,
                availability: $this->reader->ofProduct($location, $product, $variantKey),
                extras: null,
            ))->all();
        }));
    }

    private function balanceRows(InventoryLocation $location, ?string $sku, int $perPage): LengthAwarePaginator
    {
        return InventoryLocationBalance::query()
            ->where('location_id', $location->id)
            ->when($sku, static fn ($query, string $term) => $query->where('sku', 'like', '%' . $term . '%'))
            ->orderBy('sku')->orderBy('variant_key')
            ->paginate($perPage)->withQueryString()
            ->through(fn (InventoryLocationBalance $balance): array => $this->row(
                sku: $balance->sku,
                productId: $balance->product_id,
                variantKey: (string) $balance->variant_key,
                availability: $this->reader->ofBalance($location, $balance),
                extras: ['reserved' => $balance->reserved, 'unfulfillable' => $balance->unfulfillable, 'inbound' => $balance->inbound, 'total' => $balance->total],
            ));
    }

    /** @param array{reserved: int|null, unfulfillable: int|null, inbound: int|null, total: int|null}|null $extras */
    private function row(?string $sku, ?int $productId, string $variantKey, StockAvailability $availability, ?array $extras): array
    {
        return ['sku' => $sku, 'product_id' => $productId, 'variant_key' => $variantKey, 'availability' => $availability, 'extras' => $extras];
    }
}
