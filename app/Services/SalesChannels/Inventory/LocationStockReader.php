<?php

namespace App\Services\SalesChannels\Inventory;

use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\StockSource;
use App\Models\InventoryLocation;
use App\Models\InventoryLocationBalance;
use App\Models\ProviderProductMapping;
use Carbon\CarbonInterface;

/**
 * Reads the stock of a product at a location from that location's source of truth, without copying it.
 *
 *  - A PROVIDER location (Torod Point, M5AZN) is read from `provider_product_mappings`, where the provider integration already keeps its figure
 *    together with whether it was verified as available stock. A figure the integration does not vouch for is `unknown`, never `confirmed`.
 *  - A CHANNEL or MANUAL location (Amazon FBA, a shelf counted by hand) is read from `inventory_location_balances`.
 *
 * Nothing here ever writes the provider's figures, and reading a channel's quantity never changes ours.
 */
class LocationStockReader
{
    public function __construct(private readonly ReservationService $reservations)
    {
    }

    public function availability(InventoryLocation $location, int $productId, string $variantKey = ''): StockAvailability
    {
        $found = $location->provider !== null
            ? $this->fromProviderMapping($location, $productId, $variantKey)
            : $this->fromBalance($location, $productId, $variantKey);

        return $this->settled($location, $productId, $variantKey, $found);
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

    private function settled(InventoryLocation $location, int $productId, string $variantKey, StockAvailability $found): StockAvailability
    {
        if ($found->available === null) {
            return $found;
        }

        return new StockAvailability(
            available: $found->available,
            quality: $this->withFreshness($found->quality, $found->syncedAt),
            syncedAt: $found->syncedAt,
            source: $found->source,
            inFlight: $this->reservations->inFlight((int) $location->id, $productId, $variantKey, $found->syncedAt),
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

        return $mapping === null ? StockAvailability::unknown('provider_mapping') : $this->readMapping($mapping);
    }

    private function readMapping(ProviderProductMapping $mapping): StockAvailability
    {
        if ($mapping->provider_stock === null) {
            return StockAvailability::unknown('provider_mapping');
        }

        $quality = match ($mapping->stock_source) {
            StockSource::ProviderSync => StockAvailability::CONFIRMED,
            StockSource::Manual => StockAvailability::MANUAL,
            default => StockAvailability::UNKNOWN,
        };

        return new StockAvailability((int) $mapping->provider_stock, $quality, $mapping->provider_stock_synced_at, 'provider_mapping');
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
