<?php

namespace App\Services\SalesChannels\Inventory;

use App\Enums\Fulfillment\MappingStatus;
use App\Models\InventoryLocation;
use App\Models\InventoryLocationBalance;
use App\Models\ProviderProductMapping;
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
    ) {
    }

    /**
     * @return array{rows: LengthAwarePaginator, skus: int}
     *     each row: {sku: string|null, product_id: int|null, variant_key: string, availability: StockAvailability, pool: PoolFigures, extras: array{reserved: int|null, unfulfillable: int|null, inbound: int|null, total: int|null}|null}
     */
    public function for(InventoryLocation $location, ?string $sku, int $perPage): array
    {
        $page = $location->provider !== null ? $this->providerRows($location, $sku, $perPage) : $this->balanceRows($location, $sku, $perPage);

        return ['rows' => $page, 'skus' => $page->total()];
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
                location: $location,
                sku: $mapping->external_sku,
                productId: (int) $mapping->product_id,
                variantKey: (string) $mapping->variant_key,
                availability: $this->reader->ofMapping($location, $mapping),
                extras: null,
            ));
    }

    private function balanceRows(InventoryLocation $location, ?string $sku, int $perPage): LengthAwarePaginator
    {
        return InventoryLocationBalance::query()
            ->where('location_id', $location->id)
            ->when($sku, static fn ($query, string $term) => $query->where('sku', 'like', '%' . $term . '%'))
            ->orderBy('sku')->orderBy('variant_key')
            ->paginate($perPage)->withQueryString()
            ->through(fn (InventoryLocationBalance $balance): array => $this->row(
                location: $location,
                sku: $balance->sku,
                productId: $balance->product_id,
                variantKey: (string) $balance->variant_key,
                availability: $this->reader->ofBalance($location, $balance),
                extras: ['reserved' => $balance->reserved, 'unfulfillable' => $balance->unfulfillable, 'inbound' => $balance->inbound, 'total' => $balance->total],
            ));
    }

    /** @param array{reserved: int|null, unfulfillable: int|null, inbound: int|null, total: int|null}|null $extras */
    private function row(InventoryLocation $location, ?string $sku, ?int $productId, string $variantKey, StockAvailability $availability, ?array $extras): array
    {
        return [
            'sku' => $sku,
            'product_id' => $productId,
            'variant_key' => $variantKey,
            'availability' => $availability,
            'pool' => $this->pools->of($location, (int) $productId, $variantKey, $availability),
            'extras' => $extras,
        ];
    }
}
