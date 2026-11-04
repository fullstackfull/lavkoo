<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductMetric;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rebuild product_metrics — the summary row collections rank against (§23).
 *
 * Runs on the scheduler so a Home request never aggregates order_details or analytics_daily
 * itself. Every number is a reading of data the platform already records:
 *
 *   sales_30d      order_details rows in 30 days — the same signal the existing best-selling
 *                  rail counts, windowed
 *   views_30d /
 *   carted_30d     analytics_daily product rollups (pageviews / cart_adds)
 *   rating         reviews average (active reviews)
 *   wishlist_count wish list rows
 *
 * Missing tables contribute zero rather than failing the run: an installation without analytics
 * still gets sales-ranked collections.
 */
class CommerceMetricsRefresh extends Command
{
    protected $signature = 'commerce:metrics-refresh';

    protected $description = 'Recompute the per-product engagement metrics dynamic collections rank by';

    private const WINDOW_DAYS = 30;
    private const CHUNK = 500;

    public function handle(): int
    {
        if (!Schema::hasTable('product_metrics')) {
            $this->warn('product_metrics is not migrated; nothing to refresh.');

            return self::SUCCESS;
        }

        $since = now()->subDays(self::WINDOW_DAYS);
        $sales = $this->grouped('order_details', 'product_id', 'created_at', $since);
        $ratings = $this->ratings();
        $wishes = $this->grouped('wishlists', 'product_id');
        [$views, $carts] = $this->analytics($since);

        $written = 0;
        $stamp = now();

        Product::query()->select('id')->orderBy('id')->chunkById(self::CHUNK, function ($products) use (
            $sales, $ratings, $wishes, $views, $carts, $stamp, &$written
        ) {
            $rows = $products->map(fn (Product $product) => [
                'product_id'     => $product->id,
                'sales_30d'      => (int) ($sales[$product->id] ?? 0),
                'views_30d'      => (int) ($views[$product->id] ?? 0),
                'carted_30d'     => (int) ($carts[$product->id] ?? 0),
                'rating'         => round((float) ($ratings[$product->id] ?? 0), 2),
                'wishlist_count' => (int) ($wishes[$product->id] ?? 0),
                'computed_at'    => $stamp,
            ])->all();

            ProductMetric::query()->upsert(
                $rows,
                ['product_id'],
                ['sales_30d', 'views_30d', 'carted_30d', 'rating', 'wishlist_count', 'computed_at'],
            );
            $written += count($rows);
        });

        // A deleted product's row is stale ranking data forever; sweep it with the same run.
        ProductMetric::query()
            ->whereNotIn('product_id', Product::query()->select('id'))
            ->delete();

        $this->info("Refreshed metrics for {$written} product(s).");

        return self::SUCCESS;
    }

    /** @return array<int, int> product_id => count */
    private function grouped(string $table, string $key, ?string $timeColumn = null, mixed $since = null): array
    {
        if (!Schema::hasTable($table)) {
            return [];
        }

        try {
            return DB::table($table)
                ->select($key, DB::raw('COUNT(*) as total'))
                ->when($timeColumn !== null, fn ($query) => $query->where($timeColumn, '>=', $since))
                ->groupBy($key)
                ->pluck('total', $key)
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int, float> product_id => average rating */
    private function ratings(): array
    {
        if (!Schema::hasTable('reviews')) {
            return [];
        }

        try {
            return DB::table('reviews')
                ->select('product_id', DB::raw('AVG(rating) as average'))
                ->where('status', 1)
                ->whereNull('delivery_man_id')
                ->groupBy('product_id')
                ->pluck('average', 'product_id')
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Views and cart-adds from the analytics product rollups.
     *
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private function analytics(mixed $since): array
    {
        if (!Schema::hasTable('analytics_daily')) {
            return [[], []];
        }

        try {
            $rows = DB::table('analytics_daily')
                ->select('dimension_key', DB::raw('SUM(pageviews) as views'), DB::raw('SUM(cart_adds) as carts'))
                ->where('dimension', 'product')
                ->where('date', '>=', $since->toDateString())
                ->groupBy('dimension_key')
                ->get();
        } catch (\Throwable) {
            return [[], []];
        }

        $views = [];
        $carts = [];

        foreach ($rows as $row) {
            $id = (int) $row->dimension_key;
            if ($id > 0) {
                $views[$id] = (int) $row->views;
                $carts[$id] = (int) $row->carts;
            }
        }

        return [$views, $carts];
    }
}
