<?php

namespace App\Services\SalesChannels\Contracts;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Data\CatalogMatch;

/**
 * A channel whose own catalogue can be asked "do you already hold a product with THIS barcode?" — Amazon's Catalog Items, and whatever Noon turns out to offer.
 *
 * It answers about the CHANNEL'S catalogue, not about our listings. Those are two different questions and only one of them can tell us whether to create a listing:
 *
 *  - *Does the channel's catalogue hold this product?* — this contract. It gives the ASIN (or the channel's equivalent) and, the part that matters, the channel's own
 *    `productType`, without which `DescribesListingRequirements` cannot be asked anything.
 *  - *Do WE have a listing for our SKU?* — `CreatesListings::listingFor()`. That one, and only that one, decides whether a write creates or updates.
 *
 * So a `NONE` here never licenses a create: it says the catalogue has no such product, not that our SKU is unlisted. And an answer this contract could not get is
 * `CatalogMatch::UNKNOWN`, which a caller must never read as "nothing is there".
 */
interface ReadsCatalog
{
    /**
     * Asks the channel's catalogue about our identifiers, most trustworthy first.
     *
     * @param array<string, string> $identifiers kind => value, e.g. `['EAN' => '590123412345']`. Kinds the channel does not know are left out rather than guessed at.
     */
    public function catalogMatch(SalesChannelAccount $account, array $identifiers): CatalogMatch;
}
