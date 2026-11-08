<?php

namespace App\Services\SalesChannels\Inventory\Outbox;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\ChannelStockEvent;
use App\Models\InventoryLocation;
use App\Services\Fulfillment\Contracts\NotesStockChanged;
use App\Services\SalesChannels\Inventory\PoolKey;

/**
 * The sales-channel module's answer to "a provider's figure changed": record it, so the channels hear about it in seconds instead of at the next quarter-hour sweep.
 *
 * Only the locations that stand for THAT provider's shelf are named. A location whose stock the channel itself owns — Amazon FBA, and Noon FBN when it is built — is not one
 * of them: that stock is not a provider figure, it is the marketplace's own, and it must never be mixed into the shared pool. Nor is a manual or platform location: no
 * provider figure arrived for those.
 *
 * It records and nudges; it sends nothing. Whether a quantity may reach a channel stays entirely with the publisher and the transport.
 */
class ProviderFigureNotes implements NotesStockChanged
{
    public function __construct(
        private readonly StockEventOutbox $outbox,
        private readonly StockEventNudge $nudge,
    ) {
    }

    public function providerFigureChanged(ProviderCode $provider, int $productId, string $variantKey, bool $figureChanged = true): void
    {
        if (!$figureChanged || !$this->outbox->on()) {
            return;
        }

        $pools = array_map(
            static fn (int $locationId): PoolKey => new PoolKey($locationId, $productId, $variantKey),
            $this->locationsOf($provider),
        );
        if ($pools === []) {
            return;
        }

        // Our own shop's number was just written by the sync that called this, with this very figure: writing it again would be the same arithmetic on the same numbers.
        $written = $this->outbox->record($pools, ChannelStockEvent::PROVIDER_FIGURE, subjectType: 'product', subjectId: $productId, storefrontWritten: true);
        if ($written > 0) {
            $this->nudge->soon();
        }
    }

    /**
     * The locations that stand for this provider's own shelf.
     *
     * @return list<int>
     */
    private function locationsOf(ProviderCode $provider): array
    {
        return InventoryLocation::active()
            ->where('provider', $provider->value)
            // `provider` is the source of truth here, not a channel's own shelf (Amazon FBA, and Noon FBN when it is built) and not a figure a person typed.
            ->where('source_of_truth', InventoryLocation::TRUTH_PROVIDER)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
