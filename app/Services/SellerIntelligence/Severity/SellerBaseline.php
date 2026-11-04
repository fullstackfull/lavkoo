<?php

namespace App\Services\SellerIntelligence\Severity;

use App\Models\SellerInsight;

/**
 * The size of this seller's business, so a finding can be read as a share of it.
 *
 * Every severity measure is relative, which means every one of them needs a denominator. Computing
 * it inside each detector would mean the same three queries running once per finding; computing it
 * once per seller per sweep is the difference between a sweep that scales and one that does not.
 *
 * A new shop with no history has zeroes here, and that is handled by the engine scoring those
 * components at zero rather than dividing by nothing. A shop with no revenue yet is not in more
 * danger than an established one.
 */
final class SellerBaseline
{
    public function __construct(
        /** Delivered revenue over the lookback window, in base currency. */
        public readonly float $recentRevenue = 0.0,

        /** How many products the seller lists. */
        public readonly int $productCount = 0,

        /** How many orders they have taken in the lookback window. */
        public readonly int $recentOrderCount = 0,
    ) {
    }

    /**
     * The denominator that fits this category.
     *
     * A catalogue problem is a share of the catalogue; an order problem is a share of the order
     * book. Using one number for both would make a shop with ten thousand products and three orders
     * look calm during an order crisis.
     */
    public function totalFor(?string $category): ?int
    {
        return match ($category) {
            SellerInsight::CATEGORY_CATALOG,
            SellerInsight::CATEGORY_INVENTORY,
            SellerInsight::CATEGORY_PRICING => $this->productCount ?: null,

            SellerInsight::CATEGORY_ORDERS,
            SellerInsight::CATEGORY_SHIPPING,
            SellerInsight::CATEGORY_RETURNS,
            SellerInsight::CATEGORY_FINANCE => $this->recentOrderCount ?: null,

            default => null,
        };
    }
}
