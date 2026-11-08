<?php

namespace App\Services\SalesChannels\Contracts;

use App\Models\SalesChannelAccount;

/**
 * A channel that keeps its own list of brands and will only accept one of THEM — by id, not by name.
 *
 * Amazon needs nothing of the sort: a brand there is a string on the listing. Trendyol will refuse a product whose `brandId` is not one of its own, and the id is not
 * something a person can know, so the name has to be turned into it.
 *
 * It RESOLVES and never chooses. Several brands can carry one name ("Acme", "ACME Pharma", "Acme Health"), and picking one of them for somebody would put our product under
 * another company's brand — the same reason `ReadsCatalog` refuses to pick an ASIN and `ProductMatcher` refuses to pick a product.
 */
interface ResolvesChannelBrands
{
    /**
     * The channel's brands whose name matches, most exact first.
     *
     * An empty list means the channel answered and has no such brand — a brand that must be registered with the channel before the product can be listed, which is a person's
     * errand and not something to work around. A channel that could not be asked THROWS, so that "we do not know" is never mistaken for "there is no such brand".
     *
     * @return list<array{id: int, name: string}>
     */
    public function brandsNamed(SalesChannelAccount $account, string $name): array;
}
