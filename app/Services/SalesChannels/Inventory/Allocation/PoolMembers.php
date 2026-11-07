<?php

namespace App\Services\SalesChannels\Inventory\Allocation;

use App\Models\InventoryLocation;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Inventory\PoolKey;
use App\Services\SalesChannels\Publishing\PublishPolicyResolver;
use App\Services\SalesChannels\Publishing\StockListingGate;

/**
 * The listings that share a pool: every linked listing of every account that is opted in to stock publishing, takes its stock from this pool's location and can be given stock
 * at all (not an FBA listing, not an Amazon listing of unknown fulfilment). Opting in is the listing's own decision, so a policy never makes a listing a member.
 */
class PoolMembers
{
    public function __construct(
        private readonly PublishPolicyResolver $policies,
        private readonly StockListingGate $gate,
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
            if ($listing->account === null || $policy->stockLocationId !== $key->locationId || $this->gate->refusal($listing->account, $listing, $location) !== null) {
                continue;
            }
            $members[] = new PoolMember($listing, $listing->account, $policy);
        }

        return $members;
    }
}
