<?php

namespace App\Services\SalesChannels\Publishing;

use App\Models\InventoryLocation;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Inventory\Allocation\DirtyPools;
use App\Services\SalesChannels\Inventory\Allocation\PoolAllocationService;
use App\Services\SalesChannels\Inventory\Allocation\PoolMembers;

/**
 * The quick run after orders were imported: every pool whose promises changed is divided again, and what LOWERS a quantity at a channel is sent at once, so that stock another
 * channel sold is not offered for the next quarter of an hour. It never raises a quantity: that is for the regular run, which also checks that the orders are up to date.
 *
 * A pool nobody offers is only worked out again (so that it is clean and its figures are current). An account whose stock run is busy (the regular run holds the lease) leaves its
 * pools marked for the next run; anything else that keeps a quantity from being delivered is caught by the regular run, which compares every listing with what it last wrote.
 */
class DirtyPoolRun
{
    public function __construct(
        private readonly DirtyPools $dirty,
        private readonly PoolMembers $members,
        private readonly PoolAllocationService $allocations,
        private readonly StockPricePublisher $publisher,
    ) {
    }

    /**
     * @param SalesChannelAccount|null $only the one account to send for (the pools it does not offer are left alone)
     * @return array{pools: int, accounts: array<int, array{result: string, detail: string|null, stats: array<string, mixed>}>}
     */
    public function run(?SalesChannelAccount $only = null): array
    {
        $poolsByAccount = [];
        $pools = $this->dirty->pending();
        foreach ($pools as $key) {
            $accountIds = array_unique(array_map(static fn ($member): int => (int) $member->account->id, $this->members->of($key, InventoryLocation::find($key->locationId))));
            if ($accountIds === [] && $only === null) {
                $this->allocations->allocate($key);
            }
            foreach ($accountIds as $accountId) {
                $poolsByAccount[$accountId][] = (string) $key;
            }
        }

        $results = [];
        foreach (SalesChannelAccount::whereIn('id', array_keys($poolsByAccount))->when($only !== null, static fn ($query) => $query->whereKey($only->id))->orderBy('id')->get() as $account) {
            $results[(int) $account->id] = $this->publisher->publishReductions($account, $poolsByAccount[$account->id]);
        }

        return ['pools' => count($pools), 'accounts' => $results];
    }
}
