<?php

namespace App\Services\SellerIntelligence\Severity;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Measures how big a seller's business is, once per sweep.
 *
 * Cached for the length of the request or command, because a sweep asks for the same seller's
 * baseline once per detector and the answer cannot change in between. Without that it would be
 * three queries times eight detectors times every seller.
 *
 * Only delivered orders count towards revenue. A basket that was placed and cancelled is not
 * turnover, and counting it would inflate the denominator that every severity score divides by —
 * making every problem look smaller for a seller with a cancellation problem, which is precisely
 * backwards.
 */
class SellerBaselineProvider
{
    /** The window every relative measure is taken over. */
    public const LOOKBACK_DAYS = 30;

    /** @var array<string, SellerBaseline> */
    private array $cache = [];

    public function for(int|string $sellerId): SellerBaseline
    {
        $key = (string) $sellerId;

        return $this->cache[$key] ??= new SellerBaseline(
            recentRevenue: $this->recentRevenue($sellerId),
            productCount: $this->productCount($sellerId),
            recentOrderCount: $this->recentOrderCount($sellerId),
        );
    }

    /** Between sweeps in a long-running worker, the shop may have changed. */
    public function forget(int|string|null $sellerId = null): void
    {
        if ($sellerId === null) {
            $this->cache = [];

            return;
        }

        unset($this->cache[(string) $sellerId]);
    }

    private function recentRevenue(int|string $sellerId): float
    {
        if (!Schema::hasTable('order_details')) {
            return 0.0;
        }

        $total = DB::table('order_details')
            ->where('seller_id', $sellerId)
            ->where('delivery_status', 'delivered')
            ->where('created_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->selectRaw('SUM(price * qty) as revenue')
            ->value('revenue');

        return round((float) ($total ?? 0), 2);
    }

    private function productCount(int|string $sellerId): int
    {
        if (!Schema::hasTable('products')) {
            return 0;
        }

        return (int) DB::table('products')
            ->where('added_by', 'seller')
            ->where('user_id', $sellerId)
            ->count();
    }

    private function recentOrderCount(int|string $sellerId): int
    {
        if (!Schema::hasTable('orders')) {
            return 0;
        }

        return (int) DB::table('orders')
            ->where('seller_is', 'seller')
            ->where('seller_id', $sellerId)
            ->where('created_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->count();
    }
}
