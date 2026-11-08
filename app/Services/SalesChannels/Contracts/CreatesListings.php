<?php

namespace App\Services\SalesChannels\Contracts;

use App\Models\ChannelListingDraft;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Data\ListingSubmission;

/**
 * A channel that can be asked to create a listing for a product of ours, change one, and say what it holds for one of our SKUs.
 *
 * Creating and updating are DIFFERENT calls on purpose. A create sets the whole listing (Amazon's `putListingsItem`), which is only ever right when the channel holds nothing for
 * that SKU: used on an existing listing it would replace it, and every attribute the draft does not name would be gone. An update sends only the fields that changed
 * (`patchListingsItem`), so what the channel already holds and we were never told about stays as it is.
 *
 * Neither call makes a listing sellable: the publisher withholds every attribute named by `offerAttributes()`, because a price and a quantity may only go out through the
 * separately switched and separately approved price and stock publishing.
 */
interface CreatesListings
{
    /**
     * The channel's own attribute names that would give the listing a price, an offer or a quantity.
     *
     * @return list<string>
     */
    public function offerAttributes(): array;

    /**
     * Creates the listing of our own SKU. Only called when the channel holds nothing for that SKU.
     *
     * @param array<string, mixed> $attributes the channel's fields, already without any offer attribute
     * @throws \App\Services\SalesChannels\Exceptions\ChannelUnavailableException when a switch, a capability or an approval stops the write before anything is sent
     */
    public function createListing(SalesChannelAccount $account, ChannelListingDraft $draft, array $attributes): ListingSubmission;

    /**
     * Changes the fields given and nothing else.
     *
     * @param array<string, mixed> $changedAttributes only the fields whose value is different; never a removal
     */
    public function updateListing(SalesChannelAccount $account, ChannelListingDraft $draft, array $changedAttributes): ListingSubmission;

    /**
     * What the channel holds for one of our SKUs, asked afresh: LIVE with the channel's own statuses, REJECTED with its reasons, or WORKING when there is nothing (yet) or it
     * could not be asked. It reads and changes nothing, and it is what tells a submission from a listing.
     */
    public function listingFor(SalesChannelAccount $account, string $sku): ListingSubmission;
}
