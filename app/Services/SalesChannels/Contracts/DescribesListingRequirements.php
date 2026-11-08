<?php

namespace App\Services\SalesChannels\Contracts;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Data\ListingRequirements;

/**
 * A channel that can say what it requires of a product before it will list it.
 *
 * The answer comes from the channel itself (Amazon's Product Type Definitions, Trendyol's category attributes), never from a list kept here: a requirement we invented would
 * refuse a product the channel would have taken, or send one it will refuse.
 */
interface DescribesListingRequirements
{
    /** @param string $productType the channel's own product type (Amazon's productType, a Trendyol category id) */
    public function listingRequirements(SalesChannelAccount $account, string $productType): ListingRequirements;
}
