<?php

namespace App\Services\SalesChannels\Inventory\Allocation;

use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Inventory\PoolKey;

/** The listings the last division of each pool was shared between, as that division left them (each carries its part, its margin and what it was last shown). */
class PoolListings
{
    /**
     * @param list<PoolKey> $keys
     * @return array<string, list<SalesChannelProductMapping>> by pool key (as text), in listing order
     */
    public function of(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $byPool = [];
        $listings = SalesChannelProductMapping::with('account:id,name,channel')->whereIn('allocation->pool', array_map('strval', $keys))->orderBy('id')->get();
        foreach ($listings as $listing) {
            $byPool[(string) $listing->allocation['pool']][] = $listing;
        }

        return $byPool;
    }
}
