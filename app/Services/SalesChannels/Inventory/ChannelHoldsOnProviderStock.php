<?php

namespace App\Services\SalesChannels\Inventory;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\InventoryLocation;
use App\Services\Fulfillment\Contracts\ReportsChannelHolds;
use App\Services\SalesChannels\Support\SalesChannelSchema;
use Carbon\CarbonInterface;

/**
 * What the channels have promised of a provider's stock, for the storefront's side of the same shelf.
 *
 * A provider location (Torod Point's warehouse, M5AZN's supplier stock) is sold from by the storefront AND published to Amazon FBM and
 * Trendyol, and all three read the one figure the provider reports. The channels' side already takes these holds off before it publishes
 * (`PoolNet`); this is the same number for the storefront's side, so that a unit an open channel order holds is counted ONCE, against the
 * source figure, instead of being offered to a customer as well.
 *
 * Which holds count is `ReservationService::inFlight`'s rule and nothing new: units still on the shelf (RESERVED) always, and units a shipped
 * order took off (COMMITTED) only until the provider reports a figure taken after it took them, because from then on the provider's own count
 * has them off. A hold whose channel order has an internal order of its own is already released (`ChannelOrderReservations`), and the store's
 * provider items count it instead: that is the other half of counting a unit once.
 *
 * Every location of the provider is asked, active or not: a provider reports one figure per product, so a hold anywhere against it is a hold
 * against that figure. A whole sync run asks once whether the module's tables are there and once which locations a provider has, because it
 * comes through this for every product it writes. Read only — it never writes a reservation, a location or the storefront's stock.
 */
class ChannelHoldsOnProviderStock implements ReportsChannelHolds
{
    private ?bool $moduleIsReady = null;

    /** @var array<string, list<int>> */
    private array $locationsByProvider = [];

    public function __construct(private readonly ReservationService $reservations)
    {
    }

    public function unitsHeld(ProviderCode $provider, int $productId, string $variantKey, ?CarbonInterface $figureReadAt): int
    {
        if (!config('sales_channels.inventory.hold_channel_units_from_storefront', true) || !$this->ready()) {
            return 0;
        }

        $units = 0;
        foreach ($this->locationsOf($provider) as $locationId) {
            $units += $this->reservations->inFlight(
                locationId: $locationId,
                productId: $productId,
                variantKey: $variantKey,
                snapshotAt: $figureReadAt ?? now(),
            );
        }

        return $units;
    }

    private function ready(): bool
    {
        return $this->moduleIsReady ??= SalesChannelSchema::ready();
    }

    /** @return list<int> */
    private function locationsOf(ProviderCode $provider): array
    {
        return $this->locationsByProvider[$provider->value] ??= InventoryLocation::where('provider', $provider->value)
            ->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }
}
