<?php

namespace App\Services\SellerAutomation\Triggers;

use App\Models\SellerAutomationAction;
use App\Services\SellerAutomation\AutomationTrigger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Listings that are live with nothing left to sell.
 *
 * The most expensive kind of listing there is: it takes the click, takes the order, and then the
 * seller has to cancel it — which costs them the sale, the customer, and their cancellation rate.
 */
class OutOfStockTrigger implements AutomationTrigger
{
    use SelectsSimpleProducts;

    public const KEY = 'out_of_stock';

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
        return ['threshold' => 'nullable|integer|min:0|max:1000'];
    }

    public function match(int $sellerId, array $settings, int $limit, array $scope = []): Collection
    {
        if (!Schema::hasTable('products')) {
            return collect();
        }

        $products = $this->sellerProducts($sellerId, $scope)
            ->where('status', 1)
            ->where('current_stock', '<=', (int) ($settings['threshold'] ?? 0))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $this->withoutVariants($products);
    }
}
