<?php

namespace App\Services\SalesChannels\Publishing;

use App\Models\InventoryLocation;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;

/**
 * Whether a listing that is opted in can take stock from its location at all. A listing that cannot takes no part of a pool either: its share would go to nobody.
 *
 * Amazon FBA listings and FBA stock are never touched (Amazon holds that stock); a quantity goes to the merchant-fulfilled offer (`DEFAULT`) of an Amazon listing, and on a listing
 * whose offer Amazon did not describe that would start one, so only a listing known to have one takes it; a location is active and may only feed the channels it lists.
 */
class StockListingGate
{
    /**
     * The channel key a location must list before it may feed that channel.
     *
     * **`noon` is here deliberately rather than by falling through.** The owner approved noon joining the shared pool on
     * 2026-11-10 (docs/sales-channels/decisions.md §8, decision one) with the admission kept **per location**, and the
     * admission is this list meeting `inventory_locations.publishable_channels`. No location ships admitting noon --
     * `InventoryLocationService::ensureDefaults()` grants `storefront`, `trendyol` and `amazon_fbm` only -- so noon takes no
     * stock until a person admits a location, and no existing location is changed by anything here. Before this entry the
     * key arrived by the `??` below, which gave the same answer for the wrong reason: nobody had decided it.
     */
    private const CHANNEL_KEYS = ['trendyol' => 'trendyol', 'amazon' => 'amazon_fbm', 'noon' => 'noon'];

    /**
     * Fulfilment modes where the CHANNEL holds the stock and ships it: Amazon's FBA and noon's FBN. Those units are the
     * channel's own, counted in its own balance, and they never join the pool or take a share of ours. Each has its own
     * refusal because each is a different fact to put in front of a person.
     */
    private const CHANNEL_HELD_MODES = ['fba' => 'fba_listing', 'fbn' => 'fbn_listing'];

    /**
     * Channels that can hold stock themselves, so silence about who fulfils is not an answer we may assume.
     *
     * Trendyol is absent on purpose: its Gulf documentation describes no stock it fulfils, so every parcel is the seller's
     * and a missing mode means merchant-fulfilled rather than unknown.
     */
    private const FULFILMENT_MUST_BE_STATED = ['amazon', 'noon'];

    /** Whether these units belong to the channel rather than to us. Read by the pool arithmetic too, so the two agree. */
    public static function channelHoldsTheStock(SalesChannelProductMapping $listing): bool
    {
        return array_key_exists((string) $listing->fulfillment_mode, self::CHANNEL_HELD_MODES);
    }

    /** @return string|null why nothing is published for this listing, or null when it may be */
    public function refusal(SalesChannelAccount $account, SalesChannelProductMapping $listing, ?InventoryLocation $location): ?string
    {
        if (self::channelHoldsTheStock($listing)) {
            return self::CHANNEL_HELD_MODES[(string) $listing->fulfillment_mode];
        }
        if (in_array($account->channel, self::FULFILMENT_MUST_BE_STATED, true) && $listing->fulfillment_mode === null) {
            return 'fulfillment_mode_unknown';
        }
        if ($location === null || !$location->is_active) {
            return 'no_stock_source';
        }
        if (!in_array(self::CHANNEL_KEYS[$account->channel] ?? $account->channel, (array) ($location->publishable_channels ?? []), true)) {
            return 'location_not_for_this_channel';
        }

        return null;
    }
}
