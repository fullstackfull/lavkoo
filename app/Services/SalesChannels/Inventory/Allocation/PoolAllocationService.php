<?php

namespace App\Services\SalesChannels\Inventory\Allocation;

use App\Models\InventoryLocation;
use App\Models\InventoryPool;
use App\Services\SalesChannels\Inventory\PoolKey;
use App\Services\SalesChannels\Inventory\PoolNet;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The single writer of the division of a pool. Everything that changes what the channels are shown of one physical stock goes through here, one pool at a time:
 *
 *  1. the pool's row is taken `FOR UPDATE` (it is made first when it does not exist yet: two processes making it at once is harmless). Nothing else is locked, and never `products`:
 *     the checkout locks product rows, and a lock taken here in the other order would deadlock with it;
 *  2. only then are the inputs read, so they are as fresh as the moment the lock was granted: the figure, the orders the provider has not counted, the reservations, the listings
 *     and their settings, and what the listings that no longer take part still show;
 *  3. the net is divided (StockAllocator), the result is checked (the reserve and the entitlements never add up to more than the net: a division that does is a defect and
 *     rolls back, it is never sent), and the pool's version moves if, and only if, the division is not what it was.
 *
 * A listing that takes no part in the pool any more (publishing switched off, refused by a rule) still shows its last quantity on its channel and nothing here can lower it: those
 * units are counted as taken before anything is shared, so that a sibling is never raised into them.
 *
 * The same inputs give the same division and leave the version where it is, so any number of processes may ask for it at once.
 */
class PoolAllocationService
{
    /** A priority no listing can be given (the lowest is 0): what a listing outside the pool still shows is shared out ahead of every other claim. */
    private const BEFORE_EVERY_LISTING = -1;

    public function __construct(
        private readonly PoolNet $net,
        private readonly PoolMembers $members,
        private readonly PoolOutsiders $outsiders,
        private readonly StockAllocator $allocator,
        private readonly LagEstimator $lag,
        private readonly OrderFreshness $freshness,
    ) {
    }

    public function allocate(PoolKey $key): PoolAllocation
    {
        $this->ensureRow($key);

        return DB::transaction(fn (): PoolAllocation => $this->divide($key, InventoryPool::where($key->columns())->lockForUpdate()->firstOrFail()), attempts: 3);
    }

    /**
     * The row is made when it is missing, and the check does not lock anything: `INSERT IGNORE` of a row that exists takes a shared lock on it, which would make every division
     * queue for a division that is being worked out before it has even begun. Two processes that both find it missing both try; the one that loses is ignored.
     */
    private function ensureRow(PoolKey $key): void
    {
        if (!InventoryPool::where($key->columns())->exists()) {
            DB::table('inventory_pools')->insertOrIgnore($key->columns() + ['created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Everything from here on happens with the pool's row locked: the inputs are read after the lock was granted. */
    private function divide(PoolKey $key, InventoryPool $pool): PoolAllocation
    {
        $startedAt = now();
        $location = InventoryLocation::find($key->locationId);
        $members = $this->members->of($key, $location);
        $outsiders = $this->outsiders->of(key: $key, members: $members);
        $figures = $location === null ? null : $this->net->for($location, $key->productId, $key->variantKey, buffer: (int) $pool->safety_buffer);
        $reason = $figures === null ? 'no_stock_source' : ($figures->refusal ?? ($figures->isPublishable() ? null : 'stock_not_confirmed:' . $figures->quality));
        $accountIds = array_values(array_unique(array_map(static fn (PoolMember $member): int => (int) $member->account->id, $members)));
        $net = $reason === null ? $figures->net() : null;
        $deficit = $reason === null ? $figures->deficit() : 0;

        $result = $reason === null ? $this->allocator->allocate($net, $this->reserveOf($pool), [...$this->takenClaims($outsiders), ...$this->claims($members, $this->lag->forAccounts($key, $accountIds))]) : null;
        if ($result !== null && $result->storefrontReserve + $result->entitled() > $net) {
            throw new LogicException("The division of pool {$key} gives away " . ($result->storefrontReserve + $result->entitled()) . " units of a net of {$net}.");
        }

        $holdBefore = (int) $pool->deficit_hold_cycles;
        $stale = $this->freshness->staleAccounts($accountIds);
        $fingerprint = $this->fingerprint(net: $net, deficit: $deficit, raisingBlockedBy: PoolAllocation::raisingBlockedBy($deficit, $holdBefore > 0, $stale), reason: $reason, result: $result, members: $members, outsiders: $outsiders);
        $allocation = new PoolAllocation(
            key: $key,
            version: (int) $pool->version + ($fingerprint !== $pool->fingerprint ? 1 : 0),
            net: $net,
            deficit: $deficit,
            deficitHold: $holdBefore > 0,
            reason: $reason,
            storefrontReserve: $result?->storefrontReserve ?? 0,
            shares: array_intersect_key($result?->shares ?? [], array_flip(array_map(static fn (PoolMember $member): int => (int) $member->listing->id, $members))),
            staleAccounts: $stale,
        );

        $this->store(members: $members, outsiders: $outsiders, allocation: $allocation, everyone: $allocation->version !== (int) $pool->version);
        $this->keepBooks($pool, $allocation, $fingerprint, $reason === null ? $this->holdAfter($holdBefore, $deficit) : $holdBefore, $startedAt);

        return $allocation;
    }

    /** After a deficit a quantity is held down for `deficit_block_cycles` computations that come back clean; a computation that cannot tell (the figure is not usable) changes nothing. */
    private function holdAfter(int $holdBefore, int $deficit): int
    {
        return $deficit > 0 ? max(1, (int) config('sales_channels.stock.allocation.deficit_block_cycles')) : max(0, $holdBefore - 1);
    }

    private function keepBooks(InventoryPool $pool, PoolAllocation $allocation, string $fingerprint, int $holdCycles, CarbonInterface $startedAt): void
    {
        $pool->forceFill([
            'version' => $allocation->version,
            'fingerprint' => $fingerprint,
            'last_net' => $allocation->net,
            'deficit' => $allocation->deficit,
            'deficit_hold_cycles' => $holdCycles,
            'last_computed_at' => $startedAt,
        ])->save();
        // An order that arrived while this was being worked out marked the pool in the same second or later: its mark stays.
        InventoryPool::whereKey($pool->id)->where('dirty_at', '<', $startedAt)->update(['dirty_at' => null]);
    }

    /**
     * What the division came to, without its version and its time: the version moves only when this does, so that any number of processes may ask for the same division
     * without invalidating each other's plans.
     *
     * @param list<PoolMember> $members
     * @param list<PoolOutsider> $outsiders
     */
    private function fingerprint(?int $net, int $deficit, ?string $raisingBlockedBy, ?string $reason, ?AllocationResult $result, array $members, array $outsiders): string
    {
        $shares = array_map(static function (PoolMember $member) use ($result): array {
            $share = $result?->shares[$member->listing->id] ?? null;

            return [$member->listing->id, $share?->rule, $share?->entitlement, $share?->buffer, $share?->lag];
        }, $members);
        $taken = array_map(static fn (PoolOutsider $outsider): array => [$outsider->listing->id, $outsider->shown], $outsiders);

        return md5((string) json_encode([$net, $deficit, $raisingBlockedBy, $reason, $result?->storefrontReserve, $shares, $taken]));
    }

    private function reserveOf(InventoryPool $pool): StorefrontReserve
    {
        [$mode, $value] = $pool->storefront_reserve_mode !== null
            ? [$pool->storefront_reserve_mode, $pool->storefront_reserve_value]
            : [config('sales_channels.stock.allocation.storefront_reserve_mode'), config('sales_channels.stock.allocation.storefront_reserve_value')];

        return new StorefrontReserve(in_array($mode, [StorefrontReserve::FIXED, StorefrontReserve::PERCENT], true) ? $mode : StorefrontReserve::PERCENT, max(0, (int) $value));
    }

    /**
     * What the listings outside the pool still show is shared out first and in full: nothing can lower it, so nothing may be given to anyone else.
     *
     * @param list<PoolOutsider> $outsiders
     * @return list<AllocationMember>
     */
    private function takenClaims(array $outsiders): array
    {
        return array_map(static fn (PoolOutsider $outsider): AllocationMember => new AllocationMember(
            listingId: (int) $outsider->listing->id,
            mode: StockShare::FIXED,
            value: $outsider->shown,
            priority: self::BEFORE_EVERY_LISTING,
        ), $outsiders);
    }

    /**
     * @param list<PoolMember> $members
     * @param array<int, int> $lagByAccount
     * @return list<AllocationMember>
     */
    private function claims(array $members, array $lagByAccount): array
    {
        return array_map(static fn (PoolMember $member): AllocationMember => new AllocationMember(
            listingId: (int) $member->listing->id,
            mode: $member->policy->shareMode,
            value: $member->policy->shareValue,
            priority: $member->policy->priority,
            cap: $member->policy->maxQuantity,
            buffer: $member->policy->safetyBuffer,
            lag: $lagByAccount[(int) $member->account->id] ?? 0,
        ), $members);
    }

    /**
     * What each listing is told about its part of the pool, in a column of its own: the snapshot is rewritten as a whole by the publisher and the listing sync. Everyone is told when
     * the division moved; otherwise only the listing that was never told (its record was cleared, or it joined without moving the division) is.
     *
     * @param list<PoolMember> $members
     * @param list<PoolOutsider> $outsiders
     */
    private function store(array $members, array $outsiders, PoolAllocation $allocation, bool $everyone): void
    {
        foreach ($members as $member) {
            if (!$everyone && ($member->listing->allocation['version'] ?? null) === $allocation->version) {
                continue;
            }
            $share = $allocation->shares[$member->listing->id] ?? null;
            $member->listing->forceFill(['allocation' => [
                'pool' => (string) $allocation->key,
                'version' => $allocation->version,
                'computed_at' => now()->toIso8601String(),
                'net' => $allocation->net,
                'deficit' => $allocation->deficit,
                'reserve' => $allocation->storefrontReserve,
                'rule' => $share?->rule,
                'entitlement' => $share?->entitlement,
                'buffer' => $share?->buffer ?? $member->policy->safetyBuffer,
                'lag' => $share?->lag,
                'publish' => $share?->publish(),
                'reason' => $allocation->reason,
                'raising_blocked_by' => $allocation->blockedBy(),
            ]])->save();
        }
        foreach ($outsiders as $outsider) {
            if (!$everyone && ($outsider->listing->allocation['version'] ?? null) === $allocation->version) {
                continue;
            }
            $outsider->listing->forceFill(['allocation' => [
                'pool' => (string) $allocation->key,
                'version' => $allocation->version,
                'computed_at' => now()->toIso8601String(),
                'net' => $allocation->net,
                'outside' => true,
                'shown' => $outsider->shown,
                'reason' => PoolAllocation::OUTSIDE_THE_POOL,
            ]])->save();
        }
    }
}
