<?php

namespace App\Services\SalesChannels\Inventory\Allocation;

use App\Models\InventoryReservation;
use App\Services\SalesChannels\Inventory\PoolKey;
use App\Services\SalesChannels\Sync\ChannelSyncService;
use App\Services\SalesChannels\Sync\SyncStateStore;

/**
 * How many units a channel is likely to sell before we hear of it (nothing at all while `lag_window_minutes` is 0, the default). A channel lowers its own quantity the moment it sells, and our next absolute write is worked out from orders
 * we have already imported: without a margin, that write puts back the units sold meanwhile. The margin is the account's sales of this pool over the last `lag_rate_minutes`,
 * scaled to the time that will pass before its orders are imported and the next quantity is sent (the order interval, the age of the last good order import and half the publish
 * interval, and never less than `lag_window_minutes`).
 */
class LagEstimator
{
    public function __construct(private readonly SyncStateStore $states)
    {
    }

    /**
     * @param list<int> $accountIds
     * @return array<int, int> units by account id
     */
    public function forAccounts(PoolKey $key, array $accountIds): array
    {
        if ((int) config('sales_channels.stock.allocation.lag_window_minutes') <= 0) {
            return array_fill_keys($accountIds, 0);
        }
        $rate = max(1, (int) config('sales_channels.stock.allocation.lag_rate_minutes'));
        $recent = $accountIds === [] ? collect() : InventoryReservation::where('location_id', $key->locationId)->where('product_id', $key->productId)->where('variant_key', $key->variantKey)
            ->whereIn('account_id', $accountIds)->where('state', '!=', InventoryReservation::RELEASED)->where('reserved_at', '>=', now()->subMinutes($rate))
            ->selectRaw('account_id, sum(quantity) as units')->groupBy('account_id')->pluck('units', 'account_id');

        $lag = [];
        foreach ($accountIds as $accountId) {
            $units = (int) ($recent[$accountId] ?? 0);
            $lag[$accountId] = $units === 0 ? 0 : (int) ceil($units * $this->windowMinutes($accountId) / $rate);
        }

        return $lag;
    }

    private function windowMinutes(int $accountId): int
    {
        $lastGood = $this->states->state($accountId, ChannelSyncService::ORDERS)?->last_success_at;
        $age = $lastGood === null ? 0 : max(0, (int) $lastGood->diffInMinutes(now()));
        $needed = (int) config('sales_channels.sync.intervals.orders', 5) + $age + (int) ceil((int) config('sales_channels.stock.allocation.publish_interval_minutes') / 2);

        return max((int) config('sales_channels.stock.allocation.lag_window_minutes'), $needed);
    }
}
