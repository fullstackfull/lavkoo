<?php

namespace App\Services\Marketplace;

use App\Models\ProductBatch;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Schema;

/**
 * Product batches and expiry (Phase 3, Stage C).
 *
 * A tracking overlay over the catalogue: batches make expiry visible (`expiringSoon`, `expired`) and
 * actionable. The one action that changes sellable quantity — writing off an expired batch — is not
 * done here directly; it is delegated to InventoryService so the removal is a reasoned, logged
 * `adjustment` (reason `expiry`) exactly like any other stock change, rather than a second, silent
 * path that mutates `current_stock`.
 */
class BatchService
{
    public function __construct(
        private readonly ?InventoryService $inventory = null,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /** Record a batch. Tracking only — it does not change `current_stock`, which the receipt/adjust paths own. */
    public function addBatch(int|string $productId, ?string $batchNumber, ?string $expiryDate, int $qty, int|string|null $sellerId = null, int|string|null $by = null, ?string $note = null): ProductBatch
    {
        $qty = max(0, $qty);
        $batch = ProductBatch::create([
            'product_id' => $productId,
            'seller_id' => $sellerId,
            'batch_number' => $batchNumber,
            'expiry_date' => $expiryDate ?: null,
            'quantity' => $qty,
            'initial_quantity' => $qty,
            'status' => ProductBatch::STATUS_ACTIVE,
            'note' => $note,
            'created_by' => $by,
        ]);

        $this->audit?->record(
            action: 'inventory.batch_added',
            subject: ['type' => 'product', 'id' => $productId],
            after: ['batch_id' => $batch->id, 'batch_number' => $batchNumber, 'expiry_date' => $expiryDate, 'quantity' => $qty],
        );

        return $batch;
    }

    /** Active batches expiring within $days from now (including already expired), soonest first. */
    public function expiringSoon(?int $days = null, ?int $productId = null)
    {
        if (!Schema::hasTable('product_batches')) {
            return collect();
        }

        // The marketplace's horizon unless a caller asks for a different one. On a regulated
        // catalogue how far ahead expiring stock is surfaced is an operational decision, and it was
        // a default written twice — here and again in the admin list that calls this.
        $days ??= app(OperationsPolicy::class)->batchExpiryDays();

        $query = ProductBatch::active()
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays($days)->toDateString())
            ->orderBy('expiry_date');

        if ($productId) {
            $query->where('product_id', $productId);
        }

        return $query->get();
    }

    /** Active batches already past their expiry date. */
    public function expired(?int $productId = null)
    {
        if (!Schema::hasTable('product_batches')) {
            return collect();
        }

        $query = ProductBatch::active()
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', now()->toDateString())
            ->orderBy('expiry_date');

        if ($productId) {
            $query->where('product_id', $productId);
        }

        return $query->get();
    }

    /**
     * Write off a batch's remaining quantity: remove it from sellable stock through the inventory
     * adjustment path (reason `expiry`), then mark the batch depleted. If the adjustment is refused
     * (it would drive stock negative), the batch is left untouched and the reason is returned.
     *
     * @return array{ok: bool, reason?: string, balance_after?: int}
     */
    public function writeOff(ProductBatch $batch, int|string|null $by = null): array
    {
        if ($batch->status !== ProductBatch::STATUS_ACTIVE || $batch->quantity <= 0) {
            return ['ok' => false, 'reason' => 'batch_is_not_active'];
        }

        $result = ($this->inventory ?? app(InventoryService::class))->adjust(
            productId: $batch->product_id,
            delta: -$batch->quantity,
            reason: 'expiry',
            note: 'Batch write-off' . ($batch->batch_number ? ' — ' . $batch->batch_number : ''),
            by: $by,
            byType: 'admin',
        );

        if (!$result['ok']) {
            return $result;   // e.g. adjustment_would_make_stock_negative — leave the batch as it is
        }

        $batch->update(['quantity' => 0, 'status' => ProductBatch::STATUS_DEPLETED]);

        $this->audit?->record(
            action: 'inventory.batch_written_off',
            subject: ['type' => 'product', 'id' => $batch->product_id],
            after: ['batch_id' => $batch->id, 'balance_after' => $result['balance_after'] ?? null],
        );

        return $result;
    }
}
