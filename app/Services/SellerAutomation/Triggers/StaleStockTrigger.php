<?php

namespace App\Services\SellerAutomation\Triggers;

use App\Models\SellerAutomationAction;
use App\Services\SellerAutomation\AutomationTrigger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock that is live, in the shop, and not moving.
 *
 * "Not moving" is read from delivered order lines rather than from views or clicks: a product can be
 * looked at all day and still be capital sitting on a shelf. A listing that has never sold and was
 * added yesterday is not stale, so the age of the listing bounds the window too.
 */
class StaleStockTrigger implements AutomationTrigger
{
    use SelectsSimpleProducts;

    public const KEY = 'stale_stock';

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
        return ['days' => 'required|integer|min:7|max:365'];
    }

    public function match(int $sellerId, array $settings, int $limit, array $scope = []): Collection
    {
        if (!Schema::hasTable('products') || !Schema::hasTable('order_details')) {
            return collect();
        }

        $since = now()->subDays((int) ($settings['days'] ?? 60));

        $products = $this->sellerProducts($sellerId, $scope)
            ->where('status', 1)
            ->where('current_stock', '>', 0)
            // A listing younger than the window has not had the chance to be stale.
            ->where('created_at', '<=', $since)
            ->whereNotIn('id', $this->soldSince($sellerId, $since))
            ->orderByDesc('current_stock')
            ->limit($limit)
            ->get();

        return $this->withoutVariants($products);
    }

    /**
     * Products of this seller with at least one delivered line since the cutoff.
     *
     * @return array<int, int>
     */
    private function soldSince(int $sellerId, \DateTimeInterface $since): array
    {
        return DB::table('order_details')
            ->join('products', 'products.id', '=', 'order_details.product_id')
            ->where(['products.added_by' => 'seller', 'products.user_id' => $sellerId])
            ->where('order_details.delivery_status', 'delivered')
            ->where('order_details.created_at', '>=', $since)
            ->distinct()
            ->pluck('order_details.product_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
