<?php

namespace App\Services\Marketplace;

use App\Models\WarehouseStock;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Warehouse stock allocation and transfers (Phase 3, Stage C).
 *
 * This service moves quantity between warehouse rows; it never touches `products.current_stock`. That
 * is the whole design: `current_stock` stays the sellable source of truth, and this partitions it into
 * locations. So `place()` allocates part of the *unallocated* remainder into a warehouse, `remove()`
 * hands it back, and `transfer()` moves it between warehouses — the sellable total is preserved by
 * every operation here, which is why none of this can affect the order path.
 */
class WarehouseService
{
    public function __construct(private readonly ?AuditLogger $audit = null)
    {
    }

    /** Total of a product already allocated to warehouses. */
    public function allocated(int|string $productId): int
    {
        if (!Schema::hasTable('warehouse_stock')) {
            return 0;
        }

        return (int) WarehouseStock::where('product_id', $productId)->sum('quantity');
    }

    /** Sellable stock not yet placed in any warehouse: current_stock − allocated (never negative). */
    public function unallocated(int|string $productId): int
    {
        $current = 0;
        if (Schema::hasTable('products')) {
            $current = (int) (DB::table('products')->where('id', $productId)->value('current_stock') ?? 0);
        }

        return max(0, $current - $this->allocated($productId));
    }

    /**
     * Allocate units of a product from the unallocated remainder into a warehouse.
     *
     * @return array{ok: bool, reason?: string, quantity?: int}
     */
    public function place(int|string $warehouseId, int|string $productId, int $qty, int|string|null $by = null): array
    {
        if ($qty <= 0) {
            return ['ok' => false, 'reason' => 'quantity_must_be_positive'];
        }

        return DB::transaction(function () use ($warehouseId, $productId, $qty, $by) {
            // Serialise all allocation for this product on its catalogue row before measuring the
            // unallocated remainder. unallocated() sums warehouse_stock without a lock, so two
            // concurrent place() calls — even to different warehouses — could otherwise both read the
            // same remainder and each commit, allocating more than the sellable total holds.
            if (Schema::hasTable('products')) {
                DB::table('products')->where('id', $productId)->lockForUpdate()->first();
            }

            if ($qty > $this->unallocated($productId)) {
                return ['ok' => false, 'reason' => 'not_enough_unallocated_stock'];
            }

            $row = $this->lockRow($warehouseId, $productId);
            $newQty = $row->quantity + $qty;
            $row->quantity = $newQty;
            $row->save();

            $this->audit?->record(
                action: 'warehouse.stock_placed',
                subject: ['type' => 'product', 'id' => $productId],
                after: ['warehouse_id' => $warehouseId, 'placed' => $qty, 'quantity' => $newQty],
            );

            return ['ok' => true, 'quantity' => $newQty];
        });
    }

    /** Return units from a warehouse to the unallocated pool. */
    public function remove(int|string $warehouseId, int|string $productId, int $qty, int|string|null $by = null): array
    {
        if ($qty <= 0) {
            return ['ok' => false, 'reason' => 'quantity_must_be_positive'];
        }

        return DB::transaction(function () use ($warehouseId, $productId, $qty) {
            $row = $this->lockRow($warehouseId, $productId);
            if ($qty > $row->quantity) {
                return ['ok' => false, 'reason' => 'warehouse_does_not_hold_that_many'];
            }
            $row->quantity -= $qty;
            $row->save();

            return ['ok' => true, 'quantity' => $row->quantity];
        });
    }

    /**
     * Move units of a product from one warehouse to another. Sellable total is unchanged — this only
     * relocates. Both rows are locked so concurrent transfers cannot lose an update.
     */
    public function transfer(int|string $fromWarehouseId, int|string $toWarehouseId, int|string $productId, int $qty, int|string|null $by = null): array
    {
        if ($qty <= 0) {
            return ['ok' => false, 'reason' => 'quantity_must_be_positive'];
        }
        if ((int) $fromWarehouseId === (int) $toWarehouseId) {
            return ['ok' => false, 'reason' => 'source_and_destination_must_differ'];
        }

        return DB::transaction(function () use ($fromWarehouseId, $toWarehouseId, $productId, $qty, $by) {
            // Lock in a stable order (by warehouse id) to avoid a deadlock between two opposite transfers.
            [$firstId, $secondId] = $fromWarehouseId < $toWarehouseId
                ? [$fromWarehouseId, $toWarehouseId] : [$toWarehouseId, $fromWarehouseId];
            $this->lockRow($firstId, $productId);
            $this->lockRow($secondId, $productId);

            $from = $this->lockRow($fromWarehouseId, $productId);
            if ($qty > $from->quantity) {
                return ['ok' => false, 'reason' => 'source_warehouse_does_not_hold_that_many'];
            }
            $to = $this->lockRow($toWarehouseId, $productId);

            $from->quantity -= $qty;
            $from->save();
            $to->quantity += $qty;
            $to->save();

            $this->audit?->record(
                action: 'warehouse.transfer',
                subject: ['type' => 'product', 'id' => $productId],
                after: ['from' => $fromWarehouseId, 'to' => $toWarehouseId, 'qty' => $qty],
            );

            return ['ok' => true, 'from_quantity' => $from->quantity, 'to_quantity' => $to->quantity];
        });
    }

    /**
     * A product's distribution: quantity per warehouse plus the unallocated remainder.
     *
     * @return array{allocated: int, unallocated: int, by_warehouse: array<int, int>}
     */
    public function breakdown(int|string $productId): array
    {
        $byWarehouse = [];
        if (Schema::hasTable('warehouse_stock')) {
            $byWarehouse = WarehouseStock::where('product_id', $productId)
                ->pluck('quantity', 'warehouse_id')->map(fn ($q) => (int) $q)->toArray();
        }

        return [
            'allocated' => $this->allocated($productId),
            'unallocated' => $this->unallocated($productId),
            'by_warehouse' => $byWarehouse,
        ];
    }

    /** Get (locking) the row for a (warehouse, product), creating it at zero if absent. */
    private function lockRow(int|string $warehouseId, int|string $productId): WarehouseStock
    {
        $row = WarehouseStock::where(['warehouse_id' => $warehouseId, 'product_id' => $productId])->lockForUpdate()->first();
        if (!$row) {
            $row = WarehouseStock::create(['warehouse_id' => $warehouseId, 'product_id' => $productId, 'quantity' => 0]);
            $row = WarehouseStock::where('id', $row->id)->lockForUpdate()->first();
        }

        return $row;
    }
}
