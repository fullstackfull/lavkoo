<?php

namespace App\Services\SellerAutomation\Triggers;

use App\Models\SellerAutomationAction;
use App\Services\SellerAutomation\AutomationTrigger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Listings still selling, but not for much longer.
 *
 * Deliberately excludes zero: a product that has already run out is the `out_of_stock` trigger's
 * business, and a seller who wired both would otherwise be told twice about the same shelf.
 */
class LowStockTrigger implements AutomationTrigger
{
    use SelectsSimpleProducts;

    public const KEY = 'low_stock';

    public function key(): string
    {
        return self::KEY;
    }

    public function subjectType(): string
    {
        return SellerAutomationAction::SUBJECT_PRODUCT;
    }

    public function rules(): array
    {
        return ['threshold' => 'required|integer|min:1|max:1000'];
    }

    public function match(int $sellerId, array $settings, int $limit, array $scope = []): Collection
    {
        if (!Schema::hasTable('products')) {
            return collect();
        }

        $products = $this->sellerProducts($sellerId, $scope)
            ->where('status', 1)
            ->where('current_stock', '>', 0)
            ->where('current_stock', '<=', (int) ($settings['threshold'] ?? 5))
            ->orderBy('current_stock')
            ->limit($limit)
            ->get();

        return $this->withoutVariants($products);
    }
}
