<?php

namespace App\Services\SalesChannels\Inventory\Allocation;

use App\Models\InventoryLocation;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Inventory\PoolKey;
use App\Services\SalesChannels\Publishing\PublishPolicyResolver;
use App\Services\SalesChannels\Publishing\StockListingGate;

/**
 * The listings that share a pool: every linked listing of every account that is opted in to stock publishing, takes its stock from this pool's location, sells in the pool's own
 * market and can be given stock at all (not an FBA listing, not an Amazon listing of unknown fulfilment). Opting in is the listing's own decision, so a policy never makes a
 * listing a member.
 *
 * The MARKET is the pool's, not the listing's: who may take units of a pool of another country is {@see PoolMarket}'s question, and it is asked here because membership is where
 * units are handed out. It is a different question from the one the gate answers for a listing, and the reasoning for the difference is recorded on that class.
 */
class PoolMembers
{
    public function __construct(
        private readonly PublishPolicyResolver $policies,
        private readonly StockListingGate $gate,
        private readonly PoolMarket $market,
    ) {
    }

    /** @return list<PoolMember> in listing order */
    public function of(PoolKey $key, ?InventoryLocation $location): array
    {
        $members = [];
        $listings = SalesChannelProductMapping::with('account')->mapped()->where('product_id', $key->productId)->where('variant_key', $key->variantKey)
            ->where('inventory_publish', true)->orderBy('id')->get();

        foreach ($listings as $listing) {
            $policy = $this->policies->resolve($listing);
            if ($listing->account === null || $policy->stockLocationId !== $key->locationId || !$this->market->admits($listing->account, $key->market)) {
                continue;
            }
            if ($this->gate->refusal($listing->account, $listing, $location) !== null) {
                continue;
            }
            $members[] = new PoolMember($listing, $listing->account, $policy);
        }

        return $members;
    }
}
