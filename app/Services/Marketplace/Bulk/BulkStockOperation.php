<?php

namespace App\Services\Marketplace\Bulk;

use App\Models\Product;
use App\Services\Marketplace\InventoryService;
use App\Services\Marketplace\SellerPrincipal;
use App\Traits\ProductTrait;

/**
 * Change many stock levels at once.
 *
 * Stock is not a column here — it is a balance with a history. So this does not write
 * `current_stock`; it goes through `InventoryService::adjust()`, which locks the row, refuses to
 * drive the balance negative, and writes the movement line that explains where the number came
 * from. A bulk tool that bypassed that would leave hundreds of unexplained jumps in the ledger, and
 * the ledger is the only thing that makes a stock number defensible later.
 *
 * A variant product is refused rather than guessed at. Its stock lives per variant, and there is no
 * honest way to spread one number across variants the seller did not name — distributing it evenly
 * would be inventing data, and writing the total while leaving the variants alone would break the
 * storefront's per-variant availability.
 *
 * The restock waiting list is cleared through the same shared method the web panel and the single
 * product API already use, so a customer waiting on a product restocked in bulk is told about it
 * exactly as they would be otherwise.
 */
class BulkStockOperation implements BulkOperation
{
    use ProductTrait;

    public const MODE_SET = 'set';
    public const MODE_INCREASE = 'increase';
    public const MODE_DECREASE = 'decrease';

    public const MODES = [self::MODE_SET, self::MODE_INCREASE, self::MODE_DECREASE];

    public function type(): string
    {
        return 'stock_update';
    }

    public function permission(): string
    {
        return 'inventory.manage';
    }

    public function rules(): array
    {
        return [
            'mode' => 'required|string|in:' . implode(',', self::MODES),
            'value' => 'required|integer|min:0',
            'note' => 'nullable|string|max:255',
        ];
    }

    public function apply(Product $product, array $settings, SellerPrincipal $principal): array
    {
        if ($this->hasVariations($product)) {
            return ['ok' => false, 'reason' => 'bulk_reason_variant_stock_must_be_set_per_variant'];
        }

        $current = (int) $product->current_stock;
        $delta = match ($settings['mode']) {
            self::MODE_SET => (int) $settings['value'] - $current,
            self::MODE_INCREASE => (int) $settings['value'],
            self::MODE_DECREASE => -(int) $settings['value'],
        };

        $before = ['current_stock' => $current];

        if ($delta === 0) {
            // Already where the seller asked for it. Done, not refused.
            return ['ok' => true, 'before' => $before, 'after' => $before];
        }

        $result = app(InventoryService::class)->adjust(
            productId: $product->id,
            delta: $delta,
            reason: 'bulk_' . $settings['mode'],
            note: $settings['note'] ?? null,
            by: $principal->actorId(),
            byType: $principal->actorType(),
        );

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'reason' => 'bulk_reason_' . ($result['reason'] ?? 'stock_adjustment_refused')];
        }

        $updated = $product->newQuery()->withoutGlobalScope('translate')->find($product->id);
        $this->updateRestockRequestListAndNotify(product: $product, updatedProduct: $updated);

        return ['ok' => true, 'before' => $before, 'after' => ['current_stock' => (int) $result['balance_after']]];
    }

    private function hasVariations(Product $product): bool
    {
        $variation = $product->variation;
        $decoded = is_array($variation) ? $variation : json_decode((string) $variation, true);

        return is_array($decoded) && count($decoded) > 0;
    }
}
