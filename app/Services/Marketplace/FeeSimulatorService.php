<?php

namespace App\Services\Marketplace;

use App\Models\Product;
use Illuminate\Support\Facades\Schema;

/**
 * What the marketplace would charge on a sale that has not happened yet.
 *
 * A seller deciding what to charge for something needs the same arithmetic the platform will
 * actually run, not an approximation of it. So this calls `CommissionEngine` — the very code that
 * writes `order_item_commissions` when an order is placed — rather than reimplementing the rate
 * tables. If the two ever disagreed, the seller would price against a number the marketplace does
 * not use, and find out at settlement.
 *
 * It is deliberately narrow. Commission is what the marketplace charges the seller and is knowable
 * before a sale from rules that exist right now. Tax depends on the customer, shipping depends on
 * where they are and who bears it, and payment fees depend on how they choose to pay — none of
 * which exist yet for an order nobody has placed. Those are named as excluded rather than estimated:
 * a plausible number a seller prices against is worse than an honest gap.
 */
class FeeSimulatorService
{
    /** Named so the client can say what the figure does not cover, rather than implying it covers everything. */
    public const EXCLUDED = ['tax', 'shipping', 'payment_processing'];

    public function __construct(private readonly CommissionEngine $commissions)
    {
    }

    /**
     * @param  array{product_id?: int|string|null, unit_price?: float|string|null, quantity?: int|null, discount?: float|string|null, category_id?: int|string|null}  $input
     * @return array{
     *     quantity: int, unit_price: float, discount_per_unit: float, gross: float,
     *     commissionable_amount: float, commission_amount: float, seller_receives: float,
     *     effective_rate_percent: float|null, rule: array, product: array|null, excludes: array<int, string>
     * }
     */
    public function simulate(int|string $sellerId, array $input): array
    {
        $product = $this->productFor($sellerId, $input['product_id'] ?? null);

        $quantity = max(1, (int) ($input['quantity'] ?? 1));
        $unitPrice = $this->price($input['unit_price'] ?? null, $product?->unit_price);
        $discount = $this->discountPerUnit($input, $product);

        // Never below zero: a discount larger than the price is a configuration error, and pricing
        // against a negative line would make the whole answer nonsense.
        $netUnitPrice = max(0, round($unitPrice - $discount, 4));
        $gross = round($unitPrice * $quantity, 4);
        $commissionable = round($netUnitPrice * $quantity, 4);

        $line = [
            'seller_is' => 'seller',
            'seller_id' => $sellerId,
            'product_id' => $product?->id,
            'category_id' => $input['category_id'] ?? $product?->category_id,
        ];

        $result = $this->commissions->calculate($line, $commissionable);

        return [
            'quantity' => $quantity,
            'unit_price' => round($unitPrice, 2),
            'discount_per_unit' => round($discount, 2),
            'gross' => round($gross, 2),
            'commissionable_amount' => round($result['commissionable_amount'], 2),
            'commission_amount' => round($result['commission_amount'], 2),
            'seller_receives' => round($result['seller_net_amount'], 2),
            // What the seller is really asking: what share of this sale does the marketplace take.
            // Null rather than zero when there is nothing to take a share of — a percentage of
            // nothing is not nought per cent, it is undefined.
            'effective_rate_percent' => $result['commissionable_amount'] > 0
                ? round($result['commission_amount'] / $result['commissionable_amount'] * 100, 2)
                : null,
            'rule' => [
                'id' => $result['rule_id'],
                'scope' => $result['rule_scope_type'],
                'label' => $result['rule_label'],
                'rate_type' => $result['rate_type'],
                'percentage' => $result['percentage'],
                'fixed_amount' => $result['fixed_amount'],
            ],
            'product' => $product === null ? null : [
                'id' => $product->id,
                'name' => $product->getRawOriginal('name'),
                'unit_price' => (float) $product->unit_price,
                'discount' => (float) $product->discount,
                'discount_type' => $product->discount_type,
            ],
            'excludes' => self::EXCLUDED,
        ];
    }

    /**
     * The product, only if it belongs to this shop.
     *
     * A simulator that accepted any id would answer questions about another seller's catalogue and
     * their commission rules — a vendor-scoped rule is competitive information.
     */
    private function productFor(int|string $sellerId, int|string|null $productId): ?Product
    {
        if (!$productId || !Schema::hasTable('products')) {
            return null;
        }

        return Product::withoutGlobalScope('translate')
            ->where('id', $productId)
            ->where(['added_by' => 'seller', 'user_id' => $sellerId])
            ->first();
    }

    private function price(float|string|null $given, float|string|null $fromProduct): float
    {
        if ($given !== null && $given !== '') {
            return max(0, (float) $given);
        }

        return max(0, (float) ($fromProduct ?? 0));
    }

    /**
     * The seller's own discount on one unit, in money.
     *
     * A percentage discount is turned into money against the price being simulated rather than the
     * product's stored price, so asking "what if I charged 120 instead of 100" carries the 10% with
     * it instead of holding a discount of 10 that was calculated against a price nobody is charging.
     */
    private function discountPerUnit(array $input, ?Product $product): float
    {
        $unitPrice = $this->price($input['unit_price'] ?? null, $product?->unit_price);

        if (array_key_exists('discount', $input) && $input['discount'] !== null && $input['discount'] !== '') {
            $type = $input['discount_type'] ?? 'flat';

            return $type === 'percent'
                ? $unitPrice * (float) $input['discount'] / 100
                : (float) $input['discount'];
        }

        if (!$product) {
            return 0;
        }

        return $product->discount_type === 'percent'
            ? $unitPrice * (float) $product->discount / 100
            : (float) $product->discount;
    }
}
