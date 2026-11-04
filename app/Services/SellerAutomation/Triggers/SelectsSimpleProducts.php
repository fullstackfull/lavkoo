<?php

namespace App\Services\SellerAutomation\Triggers;

use App\Models\Product;
use App\Services\SellerAutomation\RuleScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The shop's own physical products whose header stock number means what it says.
 *
 * A variant product's stock lives on its variations; the column on the header row is a total that
 * no single change can honestly move. Bulk stock updates already refuse those for the same reason.
 * Excluded in SQL where it is cheap and checked again in PHP, because the column has been written by
 * several generations of code and holds `null`, `''` and `'[]'` for the same idea.
 *
 * Triggers select whole rows rather than named columns. A trigger cannot know which fields the
 * action paired with it will read — `publish_listing` reads the marketplace's approval flag, which
 * no stock trigger would think to select — and a missing column reads as null, which made a listing
 * look unapproved rather than raising anything. The rows are capped by the rule's own limit, so the
 * cost of reading them whole is bounded and the class of bug is gone.
 */
trait SelectsSimpleProducts
{
    /**
     * @param  array<string, array<int, int>>  $scope  the part of the catalogue the rule may touch
     */
    private function sellerProducts(int $sellerId, array $scope = []): Builder
    {
        $query = Product::withoutGlobalScope('translate')
            ->where(['added_by' => 'seller', 'user_id' => $sellerId, 'product_type' => 'physical'])
            ->where(function (Builder $query) {
                $query->whereNull('variation')->orWhereIn('variation', ['', '[]']);
            });

        return RuleScope::apply($query, $scope);
    }

    /** @param Collection<int, Product> $products */
    private function withoutVariants(Collection $products): Collection
    {
        return $products->reject(function (Product $product): bool {
            $decoded = is_array($product->variation)
                ? $product->variation
                : json_decode((string) $product->variation, true);

            return is_array($decoded) && count($decoded) > 0;
        })->values();
    }
}
