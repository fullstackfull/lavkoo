<?php

namespace App\Services\SalesChannels\Inventory\Allocation;

use App\Models\InventoryPool;
use App\Services\SalesChannels\Inventory\PoolKey;
use Illuminate\Support\Facades\DB;

/**
 * The pools whose promises changed since their division was last worked out. An order that is imported, changed or cancelled marks its pools; the quick run after an order
 * import works them out and sends what lowers a quantity. Marking never waits for a division being worked out (it only sets a time), and a mark made while a division is being
 * worked out survives it. With the division switched off nothing is marked.
 */
class DirtyPools
{
    /** @param iterable<PoolKey> $keys */
    public function mark(iterable $keys): void
    {
        if (!config('sales_channels.stock.allocation.enabled')) {
            return;
        }
        foreach ($keys as $key) {
            DB::table('inventory_pools')->upsert(
                [$key->columns() + ['dirty_at' => now(), 'created_at' => now(), 'updated_at' => now()]],
                ['location_id', 'product_id', 'variant_key'],
                ['dirty_at', 'updated_at'],
            );
        }
    }

    /** @return list<PoolKey> the oldest mark first */
    public function pending(int $limit = 500): array
    {
        return InventoryPool::whereNotNull('dirty_at')->orderBy('dirty_at')->orderBy('id')->limit($limit)->get()
            ->map(static fn (InventoryPool $pool): PoolKey => new PoolKey((int) $pool->location_id, (int) $pool->product_id, (string) $pool->variant_key))->all();
    }
}
