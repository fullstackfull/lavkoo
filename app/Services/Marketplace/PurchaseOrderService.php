<?php

namespace App\Services\Marketplace;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase orders and receiving (Phase 3, Stage C).
 *
 * The one behaviour that makes this the *supply* side rather than a filing cabinet: receiving a line
 * **increments product stock** — the same `products.current_stock` the storefront sells and the
 * transactional order path decrements. Receiving runs inside a transaction with a row lock, so two
 * concurrent receipts of the same product cannot lose an update, mirroring the discipline the sell
 * side already uses.
 *
 * The header status is *derived* from how much of the order has been received, so it can never
 * disagree with the lines, and a line can never be received beyond what was ordered.
 */
class PurchaseOrderService
{
    public function __construct(private readonly ?AuditLogger $audit = null)
    {
    }

    /**
     * Create a draft purchase order with its lines.
     *
     * @param array<int, array{product_id?: int|null, description?: string|null, sku?: string|null, qty_ordered: int, unit_cost?: float}> $items
     */
    public function create(int|string $supplierId, int|string|null $sellerId, array $attributes, array $items, int|string|null $createdBy = null): PurchaseOrder
    {
        return DB::transaction(function () use ($supplierId, $sellerId, $attributes, $items, $createdBy) {
            $po = PurchaseOrder::create([
                'reference' => $this->nextReference(),
                'supplier_id' => $supplierId,
                'seller_id' => $sellerId,
                'status' => PurchaseOrder::STATUS_DRAFT,
                'currency' => $attributes['currency'] ?? null,
                'expected_at' => $attributes['expected_at'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $createdBy,
            ]);

            foreach ($items as $item) {
                $this->addItem($po, $item);
            }

            $this->recalculateTotals($po);

            $this->audit?->record(
                action: 'procurement.po_created',
                subject: ['type' => 'purchase_order', 'id' => $po->id],
                after: ['reference' => $po->reference, 'supplier_id' => $supplierId],
            );

            return $po->fresh('items');
        });
    }

    public function addItem(PurchaseOrder $po, array $item): PurchaseOrderItem
    {
        $qty = max(0, (int) ($item['qty_ordered'] ?? 0));
        $unitCost = round((float) ($item['unit_cost'] ?? 0), 2);

        return $po->items()->create([
            'product_id' => $item['product_id'] ?? null,
            'description' => $item['description'] ?? null,
            'sku' => $item['sku'] ?? null,
            'qty_ordered' => $qty,
            'qty_received' => 0,
            'unit_cost' => $unitCost,
            'line_total' => round($qty * $unitCost, 2),
        ]);
    }

    /** Place a draft order — it stops being editable and becomes receivable. */
    public function place(PurchaseOrder $po): array
    {
        if ($po->status !== PurchaseOrder::STATUS_DRAFT) {
            return ['ok' => false, 'reason' => 'only_a_draft_can_be_placed'];
        }
        if ($po->items()->count() === 0) {
            return ['ok' => false, 'reason' => 'cannot_place_an_empty_order'];
        }

        $po->status = PurchaseOrder::STATUS_ORDERED;
        $po->ordered_at = now();
        $po->save();

        $this->audit?->record(
            action: 'procurement.po_placed',
            subject: ['type' => 'purchase_order', 'id' => $po->id],
            after: ['reference' => $po->reference],
        );

        return ['ok' => true, 'purchase_order' => $po];
    }

    /**
     * Receive a quantity against one line: bump qty_received, increment the linked product's stock,
     * and re-derive the header status — all in one transaction with the product row locked.
     */
    public function receiveItem(PurchaseOrderItem $item, int $qty, int|string|null $receivedBy = null): array
    {
        $po = $item->purchaseOrder;
        if (!$po || !$po->canReceive()) {
            return ['ok' => false, 'reason' => 'order_is_not_in_a_receivable_state'];
        }
        $qty = (int) $qty;
        if ($qty <= 0) {
            return ['ok' => false, 'reason' => 'quantity_must_be_positive'];
        }
        if ($qty > $item->outstanding()) {
            return ['ok' => false, 'reason' => 'cannot_receive_more_than_was_ordered'];
        }

        return DB::transaction(function () use ($item, $qty, $receivedBy, $po) {
            // Re-read and LOCK the line inside the transaction, then re-check the outstanding cap. The
            // check above is a fast pre-filter on a stale model; without this locked re-check two
            // concurrent receiveItem calls could both pass it and each increment stock, over-receiving
            // (e.g. ordered 10, two receipts of 10 -> +20 stock while the line records only 10).
            $item = \App\Models\PurchaseOrderItem::where('id', $item->id)->lockForUpdate()->first();
            if (!$item) {
                return ['ok' => false, 'reason' => 'item_not_found'];
            }
            if ($qty > $item->outstanding()) {
                return ['ok' => false, 'reason' => 'cannot_receive_more_than_was_ordered'];
            }

            // Increment the catalogue stock this line replenishes. Lock the row first so a concurrent
            // receipt (or an order decrementing it) serialises rather than losing the update.
            if ($item->product_id && Schema::hasTable('products')) {
                $locked = DB::table('products')->where('id', $item->product_id)->lockForUpdate()->first();
                if ($locked) {
                    $newStock = (int) $locked->current_stock + $qty;
                    DB::table('products')->where('id', $item->product_id)
                        ->update(['current_stock' => $newStock, 'updated_at' => now()]);

                    // Log the receipt into the stock-movement history so the increase has a reason and
                    // a reference back to this PO. Non-throwing: a missing log line must not roll back
                    // the receipt.
                    app(InventoryService::class)->record(
                        productId: $item->product_id,
                        type: \App\Models\StockMovement::TYPE_RECEIPT,
                        qtyChange: $qty,
                        balanceAfter: $newStock,
                        referenceType: 'purchase_order',
                        referenceId: $po->id,
                        sellerId: $po->seller_id,
                        createdBy: $receivedBy,
                        createdByType: 'admin',
                    );
                }
            }

            $item->qty_received = (int) $item->qty_received + $qty;
            $item->save();

            $this->recomputeStatus($po);

            $this->audit?->record(
                action: 'procurement.po_received',
                subject: ['type' => 'purchase_order', 'id' => $po->id],
                after: ['item_id' => $item->id, 'product_id' => $item->product_id, 'qty' => $qty],
            );

            return ['ok' => true, 'item' => $item->fresh(), 'purchase_order' => $po->fresh()];
        });
    }

    /** Receive every outstanding unit on the order in one call. */
    public function receiveAll(PurchaseOrder $po, int|string|null $receivedBy = null): array
    {
        if (!$po->canReceive()) {
            return ['ok' => false, 'reason' => 'order_is_not_in_a_receivable_state'];
        }

        foreach ($po->items as $item) {
            $outstanding = $item->outstanding();
            if ($outstanding > 0) {
                $this->receiveItem($item, $outstanding, $receivedBy);
            }
        }

        return ['ok' => true, 'purchase_order' => $po->fresh()];
    }

    public function cancel(PurchaseOrder $po): array
    {
        if ($po->status === PurchaseOrder::STATUS_RECEIVED) {
            return ['ok' => false, 'reason' => 'a_fully_received_order_cannot_be_canceled'];
        }

        $po->status = PurchaseOrder::STATUS_CANCELED;
        $po->save();

        $this->audit?->record(
            action: 'procurement.po_canceled',
            subject: ['type' => 'purchase_order', 'id' => $po->id],
        );

        return ['ok' => true, 'purchase_order' => $po];
    }

    public function recalculateTotals(PurchaseOrder $po): void
    {
        $subtotal = (float) $po->items()->sum('line_total');
        $po->subtotal = round($subtotal, 2);
        $po->total = round($subtotal, 2);   // no tax/shipping on the PO yet — total mirrors subtotal
        $po->save();
    }

    /**
     * Derive the header status from the lines: nothing received → still 'ordered'; everything
     * received → 'received'; anything in between → 'partially_received'. Never disagrees with the
     * lines because it is computed from them.
     */
    private function recomputeStatus(PurchaseOrder $po): void
    {
        $items = $po->items()->get();
        $ordered = (int) $items->sum('qty_ordered');
        $received = (int) $items->sum('qty_received');

        if ($received <= 0) {
            $status = PurchaseOrder::STATUS_ORDERED;
        } elseif ($received >= $ordered) {
            $status = PurchaseOrder::STATUS_RECEIVED;
            $po->received_at = now();
        } else {
            $status = PurchaseOrder::STATUS_PARTIALLY_RECEIVED;
        }

        $po->status = $status;
        $po->save();
    }

    /**
     * A unique, human-readable reference. Derives the running number in PHP (no MariaDB-only SQL) so
     * it stays portable to the SQLite test database.
     */
    private function nextReference(): string
    {
        $prefix = 'PO-' . date('Ym') . '-';
        $last = PurchaseOrder::where('reference', 'like', $prefix . '%')
            ->orderByDesc('id')->value('reference');

        $seq = 1;
        if ($last && preg_match('/(\d+)$/', $last, $m)) {
            $seq = ((int) $m[1]) + 1;
        }

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
