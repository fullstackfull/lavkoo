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
    private const CHANNEL_KEYS = ['trendyol' => 'trendyol', 'amazon' => 'amazon_fbm'];

    /** @return string|null why nothing is published for this listing, or null when it may be */
    public function refusal(SalesChannelAccount $account, SalesChannelProductMapping $listing, ?InventoryLocation $location): ?string
    {
        if ($listing->fulfillment_mode === 'fba') {
            return 'fba_listing';
        }
        if ($account->channel === 'amazon' && $listing->fulfillment_mode === null) {
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
