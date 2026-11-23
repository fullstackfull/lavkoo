<?php

namespace App\Services\Marketplace;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\SaleNotRecordedException;
use App\Models\Product;
use App\Models\Seller;

use App\Models\StockMovement;
use App\Services\AuditLogger;
use App\Services\Fulfillment\ProviderStockOwnership;
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
    /** The two stock policies, with the same names and the same meaning `OrderManager::generateOrder` gives them. */
    public const POLICY_REJECT = 'reject';

    public const POLICY_ALLOW_OVERSELL = 'allow_oversell';

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
            if (app(ProviderStockOwnership::class)->controllingProvider($productId) !== null) {
                // A fulfillment warehouse sets this product's stock: a typed correction would only stand in for its figure until the next sync.
                return ['ok' => false, 'reason' => 'stock_is_set_by_the_warehouse_sync'];
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
                sellerId: ($product->added_by ?? null) === 'seller' ? ($product->user_id ?? null) : null,
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
     * Lock the products of one sale, in ascending id order, for the rest of the caller's transaction.
     *
     * The order matters and is not cosmetic: two sales holding the same two products in opposite orders would each hold the row
     * the other needs, which is a deadlock. `OrderManager::generateOrder` locks in this same order for this same reason, so a
     * till sale and a web checkout racing over the same two products cannot deadlock against each other either.
     *
     * @param list<int|string> $productIds
     */
    public function lockProductsForSale(array $productIds): void
    {
        $ids = collect($productIds)->filter()->unique()->sort()->values()->all();
        if ($ids === []) {
            return;
        }

        Product::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * Take one sold line off the shelf, or refuse the sale.
     *
     * This is the rule the web checkout already follows, in one place both tills can call rather than each keeping its own copy:
     *
     *  - the decrement is CONDITIONAL (`where current_stock >= qty`), so the database itself refuses to take stock that is not
     *    there and an affected-row count of zero is the refusal;
     *  - the SPECIFIC variant is guarded as well as the product total, because one variant's stock can mask another's shortfall
     *    past a total-only check;
     *  - a line of fewer than one unit is refused: `current_stock >= -4` is true of every row, so a negative quantity would
     *    walk straight through the conditional decrement, ADD stock, and log the addition as a sale;
     *  - a product that has variants must name one of them, and the one it names must exist on the product: a line naming
     *    none, or naming a variant the product no longer has, would otherwise be taken off the total with no variant check at
     *    all, leaving the variant quantities no longer summing to `current_stock`;
     *  - a refusal throws, so the caller's transaction rolls back and the sale leaves the stock exactly as it was;
     *  - the movement row records the balance the decrement actually produced, never a floored stand-in, and a movement that
     *    cannot be written refuses the sale: `record()` is deliberately non-throwing for the procurement path, but a sale whose
     *    history is missing is exactly what this method exists to prevent.
     *
     * `$stockPolicy` carries the same two values and the same meaning as generateOrder's: 'reject' (every unpaid path) or
     * 'allow_oversell' (the post-payment path only, where the money is already taken). Nothing new is introduced here.
     *
     * @throws InsufficientStockException when the shelf cannot cover the line and the policy is to reject
     */
    public function takeForSale(
        int|string $productId,
        int $quantity,
        ?string $variant = null,
        ?string $referenceType = null,
        int|string|null $referenceId = null,
        string $stockPolicy = self::POLICY_REJECT,
    ): void {
        $product = Product::where('id', $productId)->first();
        if (!$product) {
            return;
        }

        $rejects = $stockPolicy !== self::POLICY_ALLOW_OVERSELL;

        if ($quantity < 1) {
            // Not a shortfall the shelf can answer: a line of nothing, or of fewer than nothing, is not a sale at all.
            throw new InsufficientStockException(
                productId: $productId,
                productName: $product->name,
                requested: $quantity,
            );
        }

        $this->takeFromVariant(product: $product, variant: (string) $variant, quantity: $quantity, rejects: $rejects);

        if ($rejects) {
            $taken = Product::where('id', $productId)
                ->where('current_stock', '>=', $quantity)
                ->decrement('current_stock', $quantity);

            if ($taken === 0) {
                throw new InsufficientStockException(
                    productId: $productId,
                    productName: $product->name,
                    requested: $quantity,
                );
            }
        } else {
            Product::where('id', $productId)->decrement('current_stock', $quantity);
        }

        $movement = $this->record(
            productId: $productId,
            type: StockMovement::TYPE_SALE,
            qtyChange: -$quantity,
            balanceAfter: (int) (Product::where('id', $productId)->value('current_stock') ?? 0),
            referenceType: $referenceType,
            referenceId: $referenceId,
            sellerId: $this->sellerOfSale($product),
        );

        if ($movement === null) {
            // record() swallows its own failures by design. For a sale that is the one thing we cannot allow: the stock has
            // just moved and nothing says so. Throwing rolls the whole sale back, which is recoverable; an unlogged sale is not.
            throw new SaleNotRecordedException(productId: $productId, productName: $product->name, quantity: $quantity);
        }
    }

    /**
     * Whose stock history a sale belongs in.
     *
     * The same expression `OrderManager::generateOrder` writes on its own sale movements, so one product's history does not
     * change hands depending on which till sold it. It is NOT `products.seller_id`: that column is written nowhere in this
     * repository and holds the shop id in the rows that have one, while `InventoryService::recent($sellerId)` — the seller's
     * own movement log, and the v3 seller endpoint behind it — filters on the seller's id. Keyed on the shop, a seller's till
     * sales never appear in the seller's own log.
     */
    private function sellerOfSale(Product $product): int|string|null
    {
        return ($product->added_by ?? 'admin') === 'admin' ? null : ($product->user_id ?? null);
    }

    /**
     * Subtract a sold line from its own variant, or refuse the sale.
     *
     * The subtraction is the real one, not one floored at zero: a floor would leave the variant quantities no longer summing to
     * `current_stock`, which is the same kind of untruth as a movement row whose balance does not follow from its change.
     *
     * A product with no variants has nothing here to take and returns. A product that HAS variants must name one that exists
     * on it: an unmatched line (a variant renamed off the product while it sat in a cashier's cart, or no variant named at
     * all) is refused rather than taken off the total, because the total is the sum of the variants and taking from one
     * without the other is the shortfall this method exists to catch. The type is compared as a string, as the two writers
     * this rule was taken from compare it, so a variant whose `type` arrived from a data import as a number still matches.
     *
     * @throws InsufficientStockException
     */
    private function takeFromVariant(Product $product, string $variant, int $quantity, bool $rejects): void
    {
        $variations = json_decode((string) $product->variation, true);
        if (!is_array($variations) || $variations === []) {
            return;
        }

        $matched = false;
        $updated = [];
        foreach ($variations as $entry) {
            if (is_array($entry) && (string) ($entry['type'] ?? null) === $variant) {
                $matched = true;
                $entry['qty'] = (int) ($entry['qty'] ?? 0) - $quantity;
                if ($entry['qty'] < 0 && $rejects) {
                    throw new InsufficientStockException(
                        productId: $product->id,
                        productName: $product->name,
                        requested: $quantity,
                    );
                }
            }
            $updated[] = $entry;
        }

        if (!$matched && $rejects) {
            throw new InsufficientStockException(
                productId: $product->id,
                productName: $product->name,
                requested: $quantity,
            );
        }

        Product::where('id', $product->id)->update(['variation' => json_encode($updated)]);
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
            $query->ofSeller($sellerId);
        }

        return $query->paginate($perPage)->withQueryString();
    }
}
