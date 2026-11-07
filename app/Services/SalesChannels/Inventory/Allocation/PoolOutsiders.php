<?php

namespace App\Services\SalesChannels\Inventory\Allocation;

use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Inventory\PoolKey;
use App\Services\SalesChannels\Publishing\PublishPolicyResolver;

/**
 * The listings that no longer take part in a pool and whose channel still shows what we last wrote to it: switching a listing's stock publishing off, or a rule that refuses it, stops
 * the writes and leaves the quantity on the channel. Those units are as much on offer as any member's, so the division counts them as taken (see PoolAllocationService) instead of
 * giving them to a sibling.
 *
 * Only a listing we have written a quantity to, linked to the pool's product and variant and taking its stock from the pool's location, counts (one with no location of its own
 * left counts at the pool its last division put it in); an Amazon FBA listing never does, Amazon holds that stock. A listing nobody ever published to is the seller's own business: what
 * it shows is not ours to subtract.
 */
class PoolOutsiders
{
    public function __construct(private readonly PublishPolicyResolver $policies)
    {
    }

    /**
     * @param list<PoolMember> $members
     * @return list<PoolOutsider> in listing order
     */
    public function of(PoolKey $key, array $members): array
    {
        $memberIds = array_map(static fn (PoolMember $member): int => (int) $member->listing->id, $members);
        $outsiders = [];

        $listings = SalesChannelProductMapping::mapped()->where('product_id', $key->productId)->where('variant_key', $key->variantKey)
            ->whereNotNull('last_published_quantity')->whereNotIn('id', $memberIds)->orderBy('id')->get();
        foreach ($listings as $listing) {
            $shown = $listing->shownQuantity() ?? 0;
            if ($shown > 0 && $listing->fulfillment_mode !== 'fba' && $this->takesItsStockFrom(listing: $listing, key: $key)) {
                $outsiders[] = new PoolOutsider($listing, $shown);
            }
        }

        return $outsiders;
    }

    private function takesItsStockFrom(SalesChannelProductMapping $listing, PoolKey $key): bool
    {
        $locationId = $this->policies->resolve($listing)->stockLocationId;

        return $locationId === null ? ($listing->allocation['pool'] ?? null) === (string) $key : $locationId === $key->locationId;
    }
}
