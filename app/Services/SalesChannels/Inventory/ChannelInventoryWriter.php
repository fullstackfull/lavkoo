<?php

namespace App\Services\SalesChannels\Inventory;

use App\Models\InventoryLocationBalance;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Data\RemoteInventoryItem;
use Carbon\CarbonInterface;

/**
 * Records the stock a channel holds for us (Amazon FBA) as the balance of that channel's own location.
 *
 * It never touches the Torod Point, M5AZN or own-warehouse locations: FBA stock is Amazon's, is shown and costed on its own, and its location can be shown to no
 * storefront and no other channel. A SKU nobody has linked to a product is still stored (so the stock is visible), without a product.
 */
class ChannelInventoryWriter
{
    public function __construct(private readonly InventoryLocationService $locations)
    {
    }

    /**
     * @param iterable<RemoteInventoryItem> $items
     * @return array{stored: int, unlinked: int}
     */
    public function store(SalesChannelAccount $account, iterable $items): array
    {
        $stored = 0;
        $unlinked = 0;
        foreach ($items as $item) {
            // One location per account, and one per marketplace only for an account that sells in several (the same SKU may then hold different stock in each).
            $location = $this->locations->ensureFbaLocation($account, count((array) $account->marketplace_ids) > 1 ? $item->marketplaceId : null);
            $mapping = SalesChannelProductMapping::where('account_id', $account->id)->where('listing_key', $item->sku)->mapped()->first();
            if ($mapping === null) {
                $unlinked++;
            }

            InventoryLocationBalance::updateOrCreate(
                ['location_id' => $location->id, 'sku' => $item->sku, 'variant_key' => (string) ($mapping->variant_key ?? '')],
                [
                    'product_id' => $mapping?->product_id,
                    'available' => $item->available,
                    'reserved' => $item->reserved,
                    'unfulfillable' => $item->unfulfillable,
                    'inbound' => $item->inbound,
                    'total' => $item->total,
                    'quality' => InventoryLocationBalance::CONFIRMED,
                    'source_synced_at' => now(),
                    'meta' => array_filter(['asin' => $item->asin, 'fn_sku' => $item->fnSku, 'condition' => $item->condition, 'channel_updated_at' => $item->lastUpdated?->toIso8601String()], static fn ($value): bool => $value !== null),
                ],
            );
            $stored++;
        }

        return ['stored' => $stored, 'unlinked' => $unlinked];
    }

    /** After a COMPLETE read: a SKU the channel no longer reports keeps its last figure, marked stale, so it is never offered as current stock. */
    public function markUnseenStale(SalesChannelAccount $account, CarbonInterface $runStartedAt): int
    {
        $locationIds = $this->locations->fbaLocationIds($account);
        if ($locationIds === []) {
            return 0;
        }

        return InventoryLocationBalance::whereIn('location_id', $locationIds)
            ->where('source_synced_at', '<', $runStartedAt)
            ->where('quality', '!=', InventoryLocationBalance::STALE)
            ->update(['quality' => InventoryLocationBalance::STALE]);
    }
}
