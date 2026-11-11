<?php

namespace App\Services\SalesChannels\Noon;

use App\Models\ChannelListingDraft;
use App\Models\SalesChannelAccount;

/**
 * Which `sku_parent` of noon's belongs to one of our own `partner_sku` values.
 *
 * This class exists because of a gap in noon's API, not a preference of ours. Its catalogue is addressed two different ways and only one of them is ours:
 *
 *  - WE write with `partner_sku` — our own code, which the product upsert, the stock call, the price call and the barcode map all take;
 *  - noon READS back with `sku_parent` — its own code, which `POST /content/v1/product/content/get` takes, and that call takes nothing else.
 *
 * And noon publishes NO operation that turns one into the other. (`catplat/v1/sku/batch-map` ties a `partner_sku` to an `nsku_child`, which is a write, and whether an
 * `nsku_child` is a `sku_parent` is not stated anywhere.) The one moment the pair is ever visible is the 200 of an upsert, which answers `sku_parent` alongside a
 * `variants[]` entry carrying our `partner_sku`.
 *
 * So the pair is REMEMBERED, out of our own rows, and never derived: a `sku_parent` is not a transformation of a `partner_sku` and must not be built from one by any pattern.
 * Where it was never remembered this answers null, and the caller's only honest move is to say nothing is known — never that the product is absent, which is how a seller
 * ends up with the same item twice in noon's catalogue.
 */
class NoonParentSku
{
    /**
     * The `sku_parent` noon gave us for this `partner_sku`, or null when we were never told one.
     *
     * The CONFIRMED key wins: `listing_key` on the listing row is written only once noon reports the product as its own, whereas a draft's `submission_id` is merely what an
     * upsert answered and nothing has confirmed yet. Either is noon's own code; neither is a guess.
     */
    public function forPartnerSku(SalesChannelAccount $account, string $partnerSku): ?string
    {
        $sku = trim($partnerSku);
        if ($sku === '') {
            return null;
        }

        $draft = ChannelListingDraft::query()
            ->with('listing')
            ->where('account_id', $account->id)
            ->where('payload->sku', $sku)
            ->orderByDesc('id')
            ->first();
        if ($draft === null) {
            return null;
        }

        foreach ([$draft->listing?->listing_key, $draft->submission_id] as $candidate) {
            if (trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }

        return null;
    }
}
