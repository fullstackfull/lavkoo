<?php

namespace App\Services\SalesChannels\Publishing;

use App\Models\InventoryLocation;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Inventory\Allocation\PoolAllocation;
use App\Services\SalesChannels\Inventory\Allocation\OrderFreshness;
use App\Services\SalesChannels\Inventory\Allocation\PoolAllocationService;
use App\Services\SalesChannels\Inventory\ChannelOrderReservations;
use App\Services\SalesChannels\Inventory\PoolKey;
use App\Services\SalesChannels\Inventory\PoolNet;

/**
 * Works out what one listing should be shown of its stock, or why nothing should be sent.
 *
 * The quantity is the listing's part of the pool's division (see PoolAllocationService), which is worked out once per pool in a run. Then three things keep a quantity from being
 * sent when sending it would be wrong:
 *
 *  - NEVER ABOVE THE CHANNEL'S OWN LOWERING. A channel lowers its quantity when it sells. When the channel was read AFTER our last write and shows less than we wrote, it sold the
 *    difference: writing our (older) figure again would put those units back on sale. The quantity stays at what the channel shows unless the pool has more than it had when we wrote.
 *  - A RAISE NEEDS CURRENT ORDERS. A quantity is only raised above what the channel shows while every account offering the pool has proof that its orders are all imported
 *    (OrderFreshness), and while the pool has not just been promised more than it holds (an order we have not imported is stock sold that the quantity does not show; a deficit
 *    means the figures are not to be trusted yet).
 *  - A RAISE WAITS FOR THE OTHERS TO COME DOWN. When a share moves from one listing to another (a listing joins, a setting changes) the listing that gives units up must be lowered
 *    before the one that gets them is raised, or the channels together show more than the pool has for a while.
 *
 * With the division switched off (`stock.allocation.enabled`) every listing is offered the pool's whole net, as before the division existed, and none of this applies.
 */
class StockPlanner
{
    /** @var array<string, PoolAllocation> the divisions worked out in this run, by pool */
    private array $run = [];

    public function __construct(
        private readonly StockListingGate $gate,
        private readonly PoolNet $pools,
        private readonly PoolAllocationService $allocations,
        private readonly OrderFreshness $freshness,
        private readonly ChannelOrderReservations $reservations,
    ) {
    }

    public function beginRun(): void
    {
        $this->run = [];
    }

    /** @return PlannedStock|string the quantity to offer, or the reason nothing is */
    public function plan(SalesChannelAccount $account, SalesChannelProductMapping $listing, PublishPolicy $policy): PlannedStock|string
    {
        $location = $policy->stockLocationId === null ? null : InventoryLocation::find($policy->stockLocationId);
        if (($refusal = $this->gate->refusal($account, $listing, $location)) !== null) {
            return $refusal;
        }
        if (!config('sales_channels.stock.allocation.enabled')) {
            return $this->wholeNet($listing, $location, $policy);
        }

        $key = PoolKey::ofListing($listing, (int) $location->id);
        $allocation = $this->run[(string) $key] ??= $this->allocations->allocate($key);
        if ($allocation->reason !== null) {
            return $allocation->reason;
        }
        $share = $allocation->shares[$listing->id] ?? null;
        if ($share === null) {
            return 'not_in_the_pool';
        }

        return $this->afterTheGuards($listing, $allocation, $share->publish());
    }

    /**
     * Works the divisions the given plans were made on out again, from the figures as they are now: a store order placed, a channel order imported or a figure read since the plan was
     * made changes the net, and nothing else tells a run that is about to send. Each pool is worked out once however many of the plans belong to it.
     *
     * @param array<int, PlannedStock> $plans
     */
    public function workOutAgain(array $plans): void
    {
        $keys = [];
        foreach ($plans as $plan) {
            if ($plan->allocation !== null) {
                $keys[(string) $plan->allocation->key] = $plan->allocation->key;
            }
        }
        foreach ($keys as $key) {
            $this->run[(string) $key] = $this->allocations->allocate($key);
        }
    }

    /** Is what was planned still the division the pool has, as last worked out in this run? A plan made with the division switched off has nothing to go stale. */
    public function isCurrent(PlannedStock $plan): bool
    {
        return $plan->allocation === null || ($this->run[(string) $plan->allocation->key]?->version ?? null) === $plan->allocation->version;
    }

    /** @return PlannedStock|string */
    private function wholeNet(SalesChannelProductMapping $listing, InventoryLocation $location, PublishPolicy $policy): PlannedStock|string
    {
        $pool = $this->pools->for($location, (int) $listing->product_id, (string) $listing->variant_key);
        if ($pool->refusal !== null) {
            return $pool->refusal;
        }
        if (!$pool->isPublishable()) {
            return 'stock_not_confirmed:' . $pool->quality;
        }
        $quantity = max(0, (int) $pool->net() - $policy->safetyBuffer);

        return new PlannedStock(quantity: $policy->maxQuantity === null ? $quantity : min($quantity, $policy->maxQuantity), shown: $listing->shownQuantity());
    }

    /** @return PlannedStock|string */
    private function afterTheGuards(SalesChannelProductMapping $listing, PoolAllocation $allocation, int $quantity): PlannedStock|string
    {
        $shown = $listing->shownQuantity();
        $quantity = $this->notAboveTheChannel($listing, $allocation, $quantity);
        $raising = $shown === null || $quantity > $shown;

        if (($blocked = $this->blockedFromRaising($allocation, $shown, $quantity)) !== null) {
            return $blocked;
        }
        if ($raising && $this->othersAreAboveTheirShare($listing, $allocation)) {
            return 'awaiting_siblings';
        }
        if ($listing->readBackIsNewer() && $listing->remote_quantity !== null && (int) $listing->remote_quantity === $quantity) {
            return 'unchanged';
        }

        return new PlannedStock(quantity: $quantity, shown: $shown, allocation: $allocation);
    }

    /**
     * Why this quantity may not go out: after a deficit nothing is raised, the first quantity of a listing included; while the orders of an account are not known to be all imported a
     * quantity is not raised above what the channel is believed to show (the first quantity has no earlier one to protect).
     */
    private function blockedFromRaising(PoolAllocation $allocation, ?int $shown, int $quantity): ?string
    {
        $aboveWhatIsShown = $shown !== null && $quantity > $shown;

        return match ($allocation->blockedBy()) {
            PoolAllocation::DEFICIT_BLOCK => $shown === null || $aboveWhatIsShown ? PoolAllocation::DEFICIT_BLOCK : null,
            PoolAllocation::ORDER_SYNC_STALE => $aboveWhatIsShown ? PoolAllocation::ORDER_SYNC_STALE : null,
            default => null,
        };
    }

    /**
     * A channel that was read after our last write and shows less than we wrote sold the difference, and writing our older figure again would put those units back on sale. So the
     * quantity stays at what the channel shows, unless the pool has more than when we wrote, or the orders explain the difference: a complete order sweep that began after the channel
     * was read has imported every sale it had made, and none of its units is left out of every pool (an order cancelled since gave its units back, and they are the pool's again).
     */
    private function notAboveTheChannel(SalesChannelProductMapping $listing, PoolAllocation $allocation, int $quantity): int
    {
        $remote = $listing->remote_quantity;
        $lowered = $listing->readBackIsNewer() && $remote !== null && $listing->last_published_quantity !== null && (int) $remote < (int) $listing->last_published_quantity;
        $netAtLastWrite = $listing->snapshot['publish']['stock_net'] ?? null;
        $poolHasMore = $netAtLastWrite !== null && (int) $allocation->net > (int) $netAtLastWrite;
        if (!$lowered || $quantity <= (int) $remote || $poolHasMore) {
            return $quantity;
        }

        return $this->everySaleIsKnown($listing) ? $quantity : (int) $remote;
    }

    private function everySaleIsKnown(SalesChannelProductMapping $listing): bool
    {
        return $this->freshness->importedSince(accountId: (int) $listing->account_id, moment: $listing->last_seen_at)
            && ($this->reservations->unattributedUnits(accountId: (int) $listing->account_id)[(int) $listing->account_id] ?? 0) === 0;
    }

    private function othersAreAboveTheirShare(SalesChannelProductMapping $listing, PoolAllocation $allocation): bool
    {
        $others = array_diff(array_keys($allocation->shares), [(int) $listing->id]);
        if ($others === []) {
            return false;
        }
        $written = SalesChannelProductMapping::whereIn('id', $others)->whereNotNull('last_published_quantity')->pluck('last_published_quantity', 'id');

        foreach ($written as $id => $quantity) {
            if ((int) $quantity > $allocation->shares[(int) $id]->publish()) {
                return true;
            }
        }

        return false;
    }
}
