<?php

namespace App\Services\Storefront;

use App\Models\OrderDetail;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * "Frequently bought together" — the companion products shown on a product page.
 *
 * Two sources, in this order:
 *
 *  1. The merchant's own picks, saved on the product (Catalog -> product form). A hand-picked
 *     list is a merchandising decision and always wins, in the order it was picked.
 *  2. What customers ACTUALLY bought together: the products that appear most often in the same
 *     orders as this one. Real co-purchase data, so the panel keeps working on products nobody
 *     curated — and it is cached, because it reads the order history.
 *
 * Nothing is invented: with no picks and no shared orders the panel renders nothing rather than
 * padding itself with unrelated products.
 */
class BoughtTogetherService
{
    private const CACHE_TTL = 21600; // six hours — co-purchase patterns move slowly.

    public function isEnabled(): bool
    {
        return (bool) getWebConfig(name: 'bought_together_status');
    }

    public function limit(): int
    {
        return max(2, min(12, (int) getWebConfig(name: 'bought_together_limit') ?: 6));
    }

    /** The companions to show beside $product, already ordered and de-duplicated. */
    public function for(Product $product, ?int $limit = null): Collection
    {
        if (!$this->isEnabled()) {
            return collect();
        }

        $limit = $limit ?: $this->limit();
        $picked = $this->pickedIds($product);
        $ids = $picked;

        if (count($ids) < $limit && getWebConfig(name: 'bought_together_auto_fill')) {
            $ids = array_merge($ids, $this->coPurchasedIds($product, $limit * 2));
        }

        $ids = array_values(array_unique(array_filter($ids, fn ($id) => (int) $id !== (int) $product->id)));
        if ($ids === []) {
            return collect();
        }

        try {
            return Product::active()
                ->with('brand:id,name,slug')
                ->whereIn('id', array_slice($ids, 0, $limit))
                ->get()
                ->sortBy(fn ($row) => array_search($row->id, $ids, true))
                ->values();
        } catch (\Throwable $exception) {
            report($exception);
            return collect();
        }
    }

    /** @return array<int, int> the ids the merchant picked, in their order */
    public function pickedIds(Product $product): array
    {
        $raw = $product->bought_together_ids ?? '';
        $ids = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_filter(array_map('intval', $ids), fn ($id) => $id > 0));
    }

    /**
     * Products most often ordered in the same order as this one.
     *
     * @return array<int, int>
     */
    private function coPurchasedIds(Product $product, int $limit): array
    {
        try {
            return Cache::remember(
                'bought_together_' . $product->id . '_' . $limit,
                self::CACHE_TTL,
                function () use ($product, $limit) {
                    $orderIds = OrderDetail::where('product_id', $product->id)
                        ->latest('id')
                        // A product with a long history does not need the whole history to know
                        // what goes with it; the recent orders are also the more relevant ones.
                        ->take(500)
                        ->pluck('order_id');

                    if ($orderIds->isEmpty()) {
                        return [];
                    }

                    return OrderDetail::whereIn('order_id', $orderIds)
                        ->where('product_id', '!=', $product->id)
                        ->selectRaw('product_id, COUNT(*) as together_count')
                        ->groupBy('product_id')
                        ->orderByDesc('together_count')
                        ->take($limit)
                        ->pluck('product_id')
                        ->map(fn ($id) => (int) $id)
                        ->all();
                },
            );
        } catch (\Throwable $exception) {
            report($exception);
            return [];
        }
    }
}
