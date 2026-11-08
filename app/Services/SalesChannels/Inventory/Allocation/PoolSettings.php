<?php

namespace App\Services\SalesChannels\Inventory\Allocation;

use App\Models\ChannelStockEvent;
use App\Models\InventoryPool;
use App\Services\AuditLogger;
use App\Services\SalesChannels\Inventory\Outbox\StockEventNudge;
use App\Services\SalesChannels\Inventory\Outbox\StockEventOutbox;
use App\Services\SalesChannels\Inventory\PoolKey;
use Illuminate\Support\Facades\DB;

/**
 * A pool's own settings: the reserve kept for the storefront and a safety buffer. They are checked (StockShareValidator), taken under the pool's lock so that a division being
 * worked out never reads half of them, audited, and the pool is marked so that its division is worked out again. A pool with no row, or a field left empty, uses the default.
 */
class PoolSettings
{
    public function __construct(
        private readonly StockShareValidator $validator,
        private readonly AuditLogger $audit,
        private readonly DirtyPools $dirtyPools,
        private readonly StockEventOutbox $outbox,
        private readonly StockEventNudge $nudge,
    ) {
    }

    /**
     * @param array<string, mixed> $input storefront_reserve_mode, storefront_reserve_value, safety_buffer
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function save(PoolKey $key, array $input, ?int $adminId): InventoryPool
    {
        $values = $this->validator->pool($input);
        DB::table('inventory_pools')->insertOrIgnore($key->columns() + ['created_at' => now(), 'updated_at' => now()]);

        $pool = DB::transaction(function () use ($key, $values, $adminId): InventoryPool {
            $pool = InventoryPool::where($key->columns())->lockForUpdate()->firstOrFail();
            $before = $pool->only(array_keys($values));
            $pool->forceFill($values)->save();
            $this->audit->record(action: 'sales_channel.pool_settings_changed', subject: $pool, before: $before, after: $pool->only(array_keys($values)), context: ['by' => $adminId, 'pool' => (string) $key]);
            $this->dirtyPools->mark([$key]);
            // In the same transaction as the settings: a reserve or a share that was saved and never acted on would leave the channels divided by the old rule.
            $this->outbox->record([$key], ChannelStockEvent::POOL_SETTINGS, subjectType: 'inventory_pool', subjectId: (int) $pool->id);

            return $pool;
        });
        $this->nudge->soon();

        return $pool;
    }
}
