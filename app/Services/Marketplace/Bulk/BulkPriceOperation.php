<?php

namespace App\Services\Marketplace\Bulk;

use App\Models\Product;
use App\Services\Marketplace\PricingPolicyService;
use App\Services\Marketplace\SellerPrincipal;

/**
 * Change many prices at once.
 *
 * The mode matters more than it looks. A seller running a seasonal adjustment thinks in percent; a
 * seller correcting a supplier change thinks in absolute money. Offering only one of the two makes
 * the other a spreadsheet exercise, which is exactly what a bulk tool exists to remove.
 *
 * Two refusals are deliberate rather than clamped. A change that would take a price to zero or below
 * is refused instead of floored at zero, because a free product is a catastrophic thing to publish by
 * arithmetic accident. And a percentage discount above the price is refused for the same reason —
 * silently capping it would tell the seller a number they did not choose.
 */
class BulkPriceOperation implements BulkOperation
{
    public const MODE_SET = 'set';
    public const MODE_INCREASE_PERCENT = 'increase_percent';
    public const MODE_DECREASE_PERCENT = 'decrease_percent';
    public const MODE_INCREASE_AMOUNT = 'increase_amount';
    public const MODE_DECREASE_AMOUNT = 'decrease_amount';

    public const MODES = [
        self::MODE_SET,
        self::MODE_INCREASE_PERCENT,
        self::MODE_DECREASE_PERCENT,
        self::MODE_INCREASE_AMOUNT,
        self::MODE_DECREASE_AMOUNT,
    ];

    public function type(): string
    {
        return 'price_update';
    }

    public function permission(): string
    {
        return 'products.manage';
    }

    public function rules(): array
    {
        return [
            'mode' => 'required|string|in:' . implode(',', self::MODES),
            'value' => 'required|numeric|min:0',
            // Optional: leave both out and the discount is untouched.
            'discount' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|string|in:percent,flat',
        ];
    }

    public function apply(Product $product, array $settings, SellerPrincipal $principal): array
    {
        $currentPrice = (float) $product->unit_price;
        $newPrice = $this->priceAfter($currentPrice, $settings['mode'], (float) $settings['value']);

        if ($newPrice <= 0) {
            return ['ok' => false, 'reason' => 'bulk_reason_price_would_be_zero_or_less'];
        }

        $newPrice = round($newPrice, 2);
        $discount = array_key_exists('discount', $settings) && $settings['discount'] !== null
            ? round((float) $settings['discount'], 2)
            : (float) $product->discount;
        $discountType = $settings['discount_type'] ?? $product->discount_type ?? 'flat';

        if ($discountType === 'percent' && $discount > 100) {
            return ['ok' => false, 'reason' => 'bulk_reason_discount_above_one_hundred_percent'];
        }
        if ($discountType === 'flat' && $discount >= $newPrice) {
            return ['ok' => false, 'reason' => 'bulk_reason_discount_not_below_price'];
        }

        // The seller's own floor, checked before the write rather than reported after it. Refused
        // with the numbers on the row, so a seller reading the failure list can see which floor and
        // by how much rather than being told "no".
        $floorCheck = app(PricingPolicyService::class)->check($product, $newPrice, $discount, $discountType);

        if (!$floorCheck['ok']) {
            return $floorCheck;
        }

        $before = ['unit_price' => $currentPrice, 'discount' => (float) $product->discount, 'discount_type' => $product->discount_type];

        if ($newPrice === $currentPrice && $discount === $before['discount'] && $discountType === $before['discount_type']) {
            // Counted as done, not as changed: the seller asked for a state and the product is
            // already in it. Reporting this as a failure would be a lie in the other direction.
            return ['ok' => true, 'before' => $before, 'after' => $before];
        }

        $product->forceFill([
            'unit_price' => $newPrice,
            'discount' => $discount,
            'discount_type' => $discountType,
        ])->save();

        return [
            'ok' => true,
            'before' => $before,
            'after' => ['unit_price' => $newPrice, 'discount' => $discount, 'discount_type' => $discountType],
        ];
    }

    private function priceAfter(float $current, string $mode, float $value): float
    {
        return match ($mode) {
            self::MODE_SET => $value,
            self::MODE_INCREASE_PERCENT => $current + ($current * $value / 100),
            self::MODE_DECREASE_PERCENT => $current - ($current * $value / 100),
            self::MODE_INCREASE_AMOUNT => $current + $value,
            self::MODE_DECREASE_AMOUNT => $current - $value,
        };
    }
}
