<?php

namespace App\Services\Marketplace;

use App\Models\Seller;

use App\Models\StockMovement;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock adjustments and the movement log (Phase 3, Stage C).
 *
 * `adjust()` is the reasoned counterpart to editing `current_stock` on the product form: it changes
 * the stock **inside a transaction with the row locked**, refuses to drive it negative, and writes a
 * movement row that records the signed change, the resulting balance, a reason and who did it — so
 * the number always has a history behind it.
 *
 * `record()` is the append-only primitive other paths call to log a movement they have already
 * applied (the procurement service calls it on receipt). It never throws into its caller: a missing
 * log line must not fail the stock change it is describing.
 */
class InventoryService
{
    public function __construct(private readonly ?AuditLogger $audit = null)
    {
    }

    /**
     * Apply a reasoned manual adjustment to a product's stock.
     *
     * @return array{ok: bool, reason?: string, balance_after?: int}
     */
    public function adjust(int|string $productId, int $delta, string $reason, ?string $note = null, int|string|null $by = null, string $byType = 'admin'): array
    {
        if (!Schema::hasTable('products')) {
            return ['ok' => false, 'reason' => 'products_unavailable'];
        }
        if ($delta === 0) {
            return ['ok' => false, 'reason' => 'adjustment_cannot_be_zero'];
        }

        return DB::transaction(function () use ($productId, $delta, $reason, $note, $by, $byType) {
            $product = DB::table('products')->where('id', $productId)->lockForUpdate()->first();
            if (!$product) {
                return ['ok' => false, 'reason' => 'product_not_found'];
            }

            $current = (int) $product->current_stock;
            $new = $current + $delta;
            if ($new < 0) {
                // A negative correction cannot remove more than is on hand.
                return ['ok' => false, 'reason' => 'adjustment_would_make_stock_negative'];
            }

            DB::table('products')->where('id', $productId)->update(['current_stock' => $new, 'updated_at' => now()]);

            $this->record(
                productId: $productId,
                type: StockMovement::TYPE_ADJUSTMENT,
                qtyChange: $delta,
                balanceAfter: $new,
                reason: $reason,
                note: $note,
                sellerId: $product->user_id ?? null,
                createdBy: $by,
                createdByType: $byType,
            );

            $this->audit?->record(
                action: 'inventory.stock_adjusted',
                subject: ['type' => 'product', 'id' => $productId],
                before: ['current_stock' => $current],
                after: ['current_stock' => $new, 'reason' => $reason],
            );

            return ['ok' => true, 'balance_after' => $new];
        });
    }

    /**
     * Append a movement for a stock change that has already been applied elsewhere. Non-throwing —
     * used by the procurement receipt path, where a failed log write must never roll back a receipt.
     */
    public function record(
        int|string $productId,
        string $type,
        int $qtyChange,
        ?int $balanceAfter = null,
        ?string $reason = null,
        ?string $referenceType = null,
        int|string|null $referenceId = null,
        ?string $note = null,
        int|string|null $sellerId = null,
        int|string|null $createdBy = null,
        ?string $createdByType = null,
    ): ?StockMovement {
        if (!Schema::hasTable('stock_movements')) {
            return null;
        }

        try {
            return StockMovement::create([
                'product_id' => $productId,
                'seller_id' => $sellerId,
                'type' => $type,
                'qty_change' => $qtyChange,
                'balance_after' => $balanceAfter,
                'reason' => $reason,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId ? (int) $referenceId : null,
                'note' => $note,
                'created_by' => $createdBy,
                'created_by_type' => $createdByType,
            ]);
        } catch (\Throwable) {
            return null;    // a missing history line must not break the stock change it describes
        }
    }

    /**
     * Recent movements, optionally narrowed to one product, one type, or one seller.
     *
     * `sellerId` is not optional decoration: without it this is an admin view of every seller's
     * stock history, and the seller-facing endpoint that reads it would hand one shop the movement
     * log of another.
     */
    /**
     * How low is low, for this seller.
     *
     * Their own threshold when they have set one, the platform's otherwise. Read from here by both
     * the low-stock detector and the webhook that fires when a product crosses it, so a seller
     * cannot be told two different things about the same shelf.
     */
    public function stockLimitFor(int|string $sellerId): int
    {
        $limit = (int) Seller::where('id', $sellerId)->value('stock_limit');

        return $limit > 0 ? $limit : (int) getWebConfig(name: 'stock_limit');
    }

    public function recent(?int $productId = null, ?string $type = null, int $perPage = 25, int|string|null $sellerId = null)
    {
        $query = StockMovement::query()->orderByDesc('id');
        if ($productId) {
            $query->where('product_id', $productId);
        }
        if ($type) {
            $query->where('type', $type);
        }
        if ($sellerId !== null) {
            $query->where('seller_id', $sellerId);
        }

        return $query->paginate($perPage)->withQueryString();
    }
}
