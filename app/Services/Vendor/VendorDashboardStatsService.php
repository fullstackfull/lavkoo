<?php

namespace App\Services\Vendor;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Real vendor dashboard KPIs (Phase 1.4).
 *
 * The brief is explicit: never display a fake percentage change when no comparison period exists.
 * `compare()` therefore returns null for the change whenever the baseline is absent, and callers
 * render "no comparison data" instead of a fabricated arrow. Growth from a zero baseline is
 * reported as "new" rather than an infinite/100% jump, which is the honest description.
 *
 * The comparison maths is pure and unit-testable; the queries are thin and defensive (missing
 * tables degrade to zeroes rather than fatals, since this runs on a dashboard).
 */
class VendorDashboardStatsService
{
    public const DIR_UP   = 'up';
    public const DIR_DOWN = 'down';
    public const DIR_FLAT = 'flat';

    /**
     * Honest period-over-period comparison.
     *
     * @return array{current:float, previous:?float, change:?float, direction:?string, state:string}
     *         state: comparable | no_baseline | new
     */
    public function compare(float $current, ?float $previous): array
    {
        // No prior period at all -> we must not invent a trend.
        if ($previous === null) {
            return ['current' => $current, 'previous' => null, 'change' => null, 'direction' => null, 'state' => 'no_baseline'];
        }

        // Baseline of zero: any positive value is "new", not an infinite percentage.
        if ($previous == 0.0) {
            return [
                'current'   => $current,
                'previous'  => 0.0,
                'change'    => null,
                'direction' => $current > 0 ? self::DIR_UP : self::DIR_FLAT,
                'state'     => $current > 0 ? 'new' : 'comparable',
            ];
        }

        $change = (($current - $previous) / abs($previous)) * 100;

        return [
            'current'   => $current,
            'previous'  => $previous,
            'change'    => round($change, 1),
            'direction' => $this->direction($change),
            'state'     => 'comparable',
        ];
    }

    private function direction(float $change): string
    {
        if (abs($change) < 0.05) {
            return self::DIR_FLAT;
        }
        return $change > 0 ? self::DIR_UP : self::DIR_DOWN;
    }

    /**
     * Inventory alerts a seller actually acts on.
     * @return array{low_stock:int, out_of_stock:int, threshold:int}
     */
    public function inventoryAlerts(int $sellerId, int $lowStockThreshold = 5): array
    {
        $empty = ['low_stock' => 0, 'out_of_stock' => 0, 'threshold' => $lowStockThreshold];

        if (!Schema::hasTable('products')) {
            return $empty;
        }

        try {
            $base = DB::table('products')->where('user_id', $sellerId)->where('added_by', 'seller');

            return [
                'low_stock'    => (clone $base)->where('current_stock', '>', 0)
                                    ->where('current_stock', '<=', $lowStockThreshold)->count(),
                'out_of_stock' => (clone $base)->where('current_stock', '<=', 0)->count(),
                'threshold'    => $lowStockThreshold,
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    /**
     * Revenue and order count for a window.
     * @return array{revenue:float, orders:int}
     */
    public function salesSummary(int $sellerId, string $from, string $to): array
    {
        $empty = ['revenue' => 0.0, 'orders' => 0];

        if (!Schema::hasTable('orders')) {
            return $empty;
        }

        try {
            $rows = DB::table('orders')
                ->where('seller_id', $sellerId)
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(order_amount), 0) as revenue')
                ->first();

            return [
                'revenue' => (float) ($rows->revenue ?? 0),
                'orders'  => (int) ($rows->order_count ?? 0),
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    /**
     * Sales for a window compared against the immediately preceding window of equal length.
     * When the shop has no history before the current window, the comparison is reported as
     * `no_baseline` rather than a fabricated percentage.
     */
    public function salesWithComparison(int $sellerId, string $from, string $to): array
    {
        $current = $this->salesSummary($sellerId, $from, $to);

        $fromTs = strtotime($from);
        $toTs   = strtotime($to);
        $span   = max(1, $toTs - $fromTs);

        $prevFrom = date('Y-m-d H:i:s', $fromTs - $span);
        $prevTo   = date('Y-m-d H:i:s', $fromTs - 1);

        $hasHistory = $this->hasOrdersBefore($sellerId, $from);
        $previous = $hasHistory ? $this->salesSummary($sellerId, $prevFrom, $prevTo) : null;

        return [
            'revenue' => $this->compare((float) $current['revenue'], $previous ? (float) $previous['revenue'] : null),
            'orders'  => $this->compare((float) $current['orders'], $previous ? (float) $previous['orders'] : null),
            'period'  => ['from' => $from, 'to' => $to],
        ];
    }

    private function hasOrdersBefore(int $sellerId, string $before): bool
    {
        if (!Schema::hasTable('orders')) {
            return false;
        }
        try {
            return DB::table('orders')
                ->where('seller_id', $sellerId)
                ->where('created_at', '<', $before)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
