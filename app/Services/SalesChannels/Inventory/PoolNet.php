<?php

namespace App\Services\SalesChannels\Inventory;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\InventoryLocation;
use App\Services\Fulfillment\InventorySyncService;

/**
 * The one answer to "how much of this pool may still be offered?", used by the publisher, by the routing stock check and by the stock screen, so that they can never disagree.
 *
 * A POOL is one product (or one variant of it) at one location. Its figure P is read raw from the location (`LocationStockReader::availability` stays raw: the cost module reads
 * it too), and then every promise that the figure does not show yet is taken off once:
 *
 *  - Hc, the units on open channel orders (the reservations of this location, product and variant);
 *  - Hs, the units on STORE orders the provider has not counted yet (a provider pool only): the storefront sells provider stock minus exactly these (`InventorySyncService`),
 *    and a figure taken before they were accepted does not show them. A figure a person typed counts from the time it was typed, and a figure nobody reads again keeps every
 *    order accepted since, delivered ones included (`DeliveredStoreOrders`);
 *  - B, the pool's safety buffer.
 *
 * Never subtracted: units on store orders at a pool the store itself owns (they are off `current_stock` already) and units on an internal order made for a channel order (the
 * hold of such an order is released, see ChannelOrderReservations, because Hs counts it).
 */
class PoolNet
{
    public function __construct(
        private readonly LocationStockReader $stock,
        private readonly InventorySyncService $storeOrders,
        private readonly DeliveredStoreOrders $deliveredStoreOrders,
        private readonly ProductVariations $variations,
    ) {
    }

    /** @param string|null $exceptReservation a reservation not to count (the order that is asking whether the shelf can cover it) */
    public function for(InventoryLocation $location, int $productId, string $variantKey = '', ?string $exceptReservation = null, int $buffer = 0): PoolFigures
    {
        return $this->of($location, $productId, $variantKey, $this->stock->availability($location, $productId, $variantKey, $exceptReservation), $buffer);
    }

    /** The same for a figure that has been read already (a screen lists a page of them). */
    public function of(InventoryLocation $location, int $productId, string $variantKey, StockAvailability $availability, int $buffer = 0): PoolFigures
    {
        return new PoolFigures(
            available: $availability->available,
            quality: $availability->quality,
            syncedAt: $availability->syncedAt,
            source: $availability->source,
            storeHolds: $this->storeHolds($location, $productId, $variantKey, $availability),
            channelHolds: $availability->inFlight,
            buffer: $buffer,
            refusal: $this->refusal($location, $productId, $variantKey),
        );
    }

    private function storeHolds(InventoryLocation $location, int $productId, string $variantKey, StockAvailability $availability): int
    {
        $provider = $location->provider === null ? null : ProviderCode::tryFrom($location->provider);
        if ($provider === null || $availability->available === null) {
            return 0;
        }

        $notDeliveredYet = $this->storeOrders->reservedNotYetWithProvider($provider, $productId, $variantKey, $availability->syncedAt);
        if ($availability->quality !== StockAvailability::MANUAL || $availability->syncedAt === null) {
            return $notDeliveredYet;
        }

        return $notDeliveredYet + $this->deliveredStoreOrders->unitsSince($provider, $productId, $variantKey, $availability->syncedAt);
    }

    private function refusal(InventoryLocation $location, int $productId, string $variantKey): ?string
    {
        if ($location->isStorefrontOwned()) {
            return PoolFigures::STOREFRONT_OWNED;
        }

        return $variantKey === '' && $this->variations->exist($productId) ? PoolFigures::VARIANT_KEY_REQUIRED : null;
    }
}
