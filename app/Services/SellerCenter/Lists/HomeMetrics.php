<?php

namespace App\Services\SellerCenter\Lists;

use App\Services\SellerCenter\Revenue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The period figures on Seller Home (handoff 07.1).
 *
 * Six KPIs, a trend line with its comparison, and the top products for the period. Every figure is
 * counted from real rows through `Revenue`, so the number here and the number on the statement are
 * the same number.
 *
 * The comparison is the part that carries the meaning — "218 orders" says nothing without last
 * week's figure beside it — which is also why a comparison against a period with no data renders
 * `—` rather than an invented percentage.
 */
class HomeMetrics
{
    private const DAYS = ['today' => 1, '7d' => 7, '30d' => 30, '90d' => 90];

    /** @return array{from: Carbon, to: Carbon, previousFrom: Carbon, previousTo: Carbon, days: int} */
    public function window(string $period): array
    {
        $days = self::DAYS[$period] ?? 7;
        $to = now()->endOfDay();
        $from = now()->subDays($days - 1)->startOfDay();

        return [
            'days' => $days,
            'from' => $from,
            'to' => $to,
            'previousFrom' => (clone $from)->subDays($days),
            'previousTo' => (clone $from)->subSecond(),
        ];
    }

    /**
     * The six KPIs, each with its comparison and whether the move is an improvement.
     *
     * Delta colour is by meaning, not by sign: a cancellation rate going up is worsening even
     * though the arrow points up (handoff 04 §28).
     *
     * @return array<int, array<string, mixed>>
     */
    public function kpis(int $sellerId, string $period): array
    {
        $window = $this->window($period);

        $sales = Revenue::total($sellerId, $window['from'], $window['to']);
        $previousSales = Revenue::total($sellerId, $window['previousFrom'], $window['previousTo']);
        $units = Revenue::units($sellerId, $window['from'], $window['to']);
        $previousUnits = Revenue::units($sellerId, $window['previousFrom'], $window['previousTo']);

        $orders = $this->orderCount($sellerId, $window['from'], $window['to']);
        $previousOrders = $this->orderCount($sellerId, $window['previousFrom'], $window['previousTo']);

        $cancelled = $this->orderCount($sellerId, $window['from'], $window['to'], ['canceled', 'failed']);
        $returned = $this->orderCount($sellerId, $window['from'], $window['to'], ['returned']);
        $previousCancelled = $this->orderCount($sellerId, $window['previousFrom'], $window['previousTo'], ['canceled', 'failed']);
        $previousReturned = $this->orderCount($sellerId, $window['previousFrom'], $window['previousTo'], ['returned']);

        $averageOrder = $orders > 0 ? $sales / $orders : 0.0;
        $previousAverage = $previousOrders > 0 ? $previousSales / $previousOrders : 0.0;

        $cancellationRate = $orders > 0 ? ($cancelled / $orders) * 100 : null;
        $previousCancellationRate = $previousOrders > 0 ? ($previousCancelled / $previousOrders) * 100 : null;
        $returnRate = $orders > 0 ? ($returned / $orders) * 100 : null;
        $previousReturnRate = $previousOrders > 0 ? ($previousReturned / $previousOrders) * 100 : null;

        return [
            $this->kpi('sales', $sales, $previousSales, moreIsBetter: true, money: true),
            $this->kpi('orders', $orders, $previousOrders, moreIsBetter: true),
            $this->kpi('units', $units, $previousUnits, moreIsBetter: true),
            $this->kpi('avg_order_value', $averageOrder, $previousAverage, moreIsBetter: true, money: true),
            $this->rateKpi('cancellation', $cancellationRate, $previousCancellationRate, moreIsBetter: false),
            $this->rateKpi('return_rate', $returnRate, $previousReturnRate, moreIsBetter: false),
        ];
    }

    /**
     * The trend series: one point per day for the period, plus the same for the period before it.
     *
     * @return array{labels: array<int, string>, current: array<int, float>, previous: array<int, float>}
     */
    public function trend(int $sellerId, string $period): array
    {
        $window = $this->window($period);
        $days = min($window['days'], 90);

        $current = [];
        $previous = [];
        $labels = [];

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $day = now()->subDays($offset);
            $current[] = Revenue::total($sellerId, $day->copy()->startOfDay(), $day->copy()->endOfDay());

            $comparison = $day->copy()->subDays($days);
            $previous[] = Revenue::total($sellerId, $comparison->copy()->startOfDay(), $comparison->copy()->endOfDay());

            // Only the ends and the middle are labelled; a 90-day axis of dates is unreadable.
            $labels[] = $day->format('j M');
        }

        return [
            'labels' => $this->thinLabels($labels),
            'current' => $current,
            'previous' => $previous,
        ];
    }

    /**
     * Top products for the period, by revenue.
     *
     * @return array<int, array<string, mixed>>
     */
    public function topProducts(int $sellerId, string $period, int $limit = 6): array
    {
        $window = $this->window($period);
        $lines = Revenue::lines($sellerId, $window['from'], $window['to']);

        if ($lines === null || !Schema::hasTable('products')) {
            return [];
        }

        return $lines
            ->join('products', 'products.id', '=', 'order_details.product_id')
            ->groupBy('order_details.product_id', 'products.name', 'products.code', 'products.current_stock')
            ->orderByDesc(DB::raw('SUM(' . Revenue::NET_LINE . ')'))
            ->limit($limit)
            ->get([
                'order_details.product_id',
                'products.name',
                'products.code',
                'products.current_stock',
                DB::raw('SUM(order_details.qty) as units'),
                DB::raw('SUM(' . Revenue::NET_LINE . ') as revenue'),
            ])
            ->map(fn ($row) => [
                'id' => (int) $row->product_id,
                'name' => $row->name,
                'sku' => $row->code,
                'units' => (int) $row->units,
                'revenue' => (float) $row->revenue,
                'stock' => (int) $row->current_stock,
            ])
            ->all();
    }

    private function orderCount(int $sellerId, Carbon $from, Carbon $to, ?array $statuses = null): int
    {
        if (!Schema::hasTable('orders')) {
            return 0;
        }

        $query = DB::table('orders')
            ->where(['seller_is' => 'seller', 'seller_id' => $sellerId])
            ->whereBetween('created_at', [$from, $to]);

        if ($statuses !== null) {
            $query->whereIn('order_status', $statuses);
        }

        return (int) $query->count();
    }

    private function kpi(string $key, float $value, float $previous, bool $moreIsBetter, bool $money = false): array
    {
        $change = Revenue::change($value, $previous);

        return [
            'key' => $key,
            'value' => $value,
            'money' => $money,
            'delta' => $change === null ? null : ($change > 0 ? '+' : '') . $change . '%',
            'improving' => $change === null ? null : ($change >= 0) === $moreIsBetter,
        ];
    }

    /**
     * A rate KPI moves in points, not in percent — "1.8% up 12%" is a sentence nobody can act on.
     */
    private function rateKpi(string $key, ?float $value, ?float $previous, bool $moreIsBetter): array
    {
        $change = ($value === null || $previous === null) ? null : round($value - $previous, 1);

        return [
            'key' => $key,
            'value' => $value,
            'rate' => true,
            'delta' => $change === null ? null : ($change > 0 ? '+' : '') . $change . 'pt',
            'improving' => $change === null ? null : ($change >= 0) === $moreIsBetter,
        ];
    }

    /** @param array<int, string> $labels */
    private function thinLabels(array $labels): array
    {
        $count = count($labels);
        if ($count <= 8) {
            return $labels;
        }

        $keep = [0, (int) floor($count / 2), $count - 1];

        return array_values(array_filter(
            $labels,
            static fn ($label, $index) => in_array($index, $keep, true),
            ARRAY_FILTER_USE_BOTH,
        ));
    }
}
