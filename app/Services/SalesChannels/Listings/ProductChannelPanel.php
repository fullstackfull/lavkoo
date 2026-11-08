<?php

namespace App\Services\SalesChannels\Listings;

use App\Models\ChannelListingDraft;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Contracts\CreatesListings;
use App\Services\SalesChannels\ChannelConnectorRegistry;
use App\Services\SalesChannels\Support\SalesChannelSchema;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Where one product stands on every channel, for the product form: is it listed, is a draft waiting, what is the channel missing, is its stock or price being published, and when
 * was it last seen.
 *
 * Read only, and it asks nobody anything: no channel is called and no requirement is read here (the screen that shows this must not depend on a channel answering). Before the
 * module's migrations have run it answers with nothing at all, so the product form is exactly as it was.
 */
class ProductChannelPanel
{
    public function __construct(
        private readonly ChannelConnectorRegistry $connectors,
        private readonly ProductListingReadiness $readiness,
    ) {
    }

    /**
     * What the STORE still has to finish about this product before any channel may be asked (a description, a category, a thumbnail, images, a variant with a price). It is the
     * same answer for every channel, so it is asked once; and it calls nobody.
     *
     * @return list<string>
     */
    public function productProblems(?int $productId, string $variantKey = ''): array
    {
        return $productId === null ? [] : $this->readiness->problemsFor($productId, $variantKey);
    }

    /**
     * @return list<array{account: SalesChannelAccount, listing: ?SalesChannelProductMapping, draft: ?ChannelListingDraft, state: string, problems: list<string>, canCreate: bool}>
     */
    public function for(?int $productId): array
    {
        if ($productId === null || !SalesChannelSchema::ready()) {
            return [];
        }
        $drafts = Schema::hasTable('channel_listing_drafts')
            ? ChannelListingDraft::where('product_id', $productId)->orderBy('id')->get()->keyBy('account_id')
            : collect();
        $listings = SalesChannelProductMapping::where('product_id', $productId)->orderBy('id')->get()->keyBy('account_id');

        $rows = [];
        foreach (SalesChannelAccount::orderBy('id')->get() as $account) {
            $draft = $drafts->get($account->id);
            $listing = $listings->get($account->id);
            $rows[] = [
                'account' => $account,
                'listing' => $listing,
                'draft' => $draft,
                'state' => $this->state($listing, $draft),
                'problems' => array_values(array_map('strval', [...(array) ($draft->problems ?? []), ...(array) ($draft->channel_problems ?? [])])),
                'canCreate' => $this->canCreate($account),
            ];
        }

        return $rows;
    }

    /**
     * One word for the row: `listed` (the channel holds it), the draft's own state when there is no listing yet, or `not_offered`.
     *
     * A listing that exists while its draft was refused is still listed: the refusal was of a change, not of the listing.
     */
    private function state(?SalesChannelProductMapping $listing, ?ChannelListingDraft $draft): string
    {
        return match (true) {
            $listing !== null => 'listed',
            $draft !== null => (string) $draft->state,
            default => 'not_offered',
        };
    }

    private function canCreate(SalesChannelAccount $account): bool
    {
        try {
            return $this->connectors->for($account) instanceof CreatesListings;
        } catch (Throwable) {
            return false;
        }
    }
}
