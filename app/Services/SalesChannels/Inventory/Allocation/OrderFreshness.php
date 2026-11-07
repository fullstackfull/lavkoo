<?php

namespace App\Services\SalesChannels\Inventory\Allocation;

use App\Models\SalesChannelSyncState;
use App\Services\SalesChannels\Sync\ChannelSyncService;

/**
 * Whether the orders of an account are up to date. A quantity is only RAISED while they are: an order the channel has taken and we have not imported yet is stock sold that the
 * quantity does not show, and raising the quantity then offers it a second time. An account whose orders were never read (no order import to wait for) has nothing to be late.
 */
class OrderFreshness
{
    /**
     * @param list<int> $accountIds
     * @return list<int> the accounts whose last successful order import is older than `order_fresh_minutes`, or that never succeeded
     */
    public function staleAccounts(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }
        $limit = now()->subMinutes((int) config('sales_channels.stock.allocation.order_fresh_minutes'));

        return SalesChannelSyncState::whereIn('account_id', $accountIds)->where('resource', ChannelSyncService::ORDERS)
            ->where(static fn ($query) => $query->whereNull('last_success_at')->orWhere('last_success_at', '<', $limit))
            ->pluck('account_id')->map(static fn ($id): int => (int) $id)->all();
    }
}
