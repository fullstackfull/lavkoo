<?php

namespace App\Services\SalesChannels\Contracts;

use App\Models\ChannelListingDraft;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Data\ListingSubmission;

/**
 * A channel that can be asked to create (or replace) a listing for a product of ours.
 *
 * Both calls set an absolute state rather than adding one, so repeating either after a timeout cannot make a second listing. What makes a listing OURS is the seller's own
 * identifier in the draft (the SKU we chose), so the channel itself refuses to make a second listing for it.
 */
interface CreatesListings
{
    /** @throws \App\Services\SalesChannels\Exceptions\ChannelUnavailableException when a switch, a capability or an approval stops the write before anything is sent */
    public function putListing(SalesChannelAccount $account, ChannelListingDraft $draft): ListingSubmission;

    /** What the channel has made of a submission since: still working, accepted, or refused with its reasons. */
    public function listingSubmission(SalesChannelAccount $account, ChannelListingDraft $draft): ListingSubmission;
}
