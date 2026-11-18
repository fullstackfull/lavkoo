<?php

namespace Modules\TaxModule\app\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D1-07: what the rows of `order_taxes` add up to against the tax the order carries (`orders.total_tax_amount`), for every order in a period. A report that adds the rows
 * up shows the first, the order and its customer's invoice carry the second, and until the rows of a cart of several lines were corrected the first was a share of the
 * second. It only reads: it never writes, restates or repairs a row, because what a finished order recorded is the owner's and the finance reviewer's to decide on, not a
 * command's. An order is classed as reconciled, short (its rows add up to less than its tax), over (to more), or without rows (it carries a tax and has none recorded).
 */
final class OrderTaxRowsReconciler
{
    public const RECONCILED = 'reconciled';

    public const SHORT = 'short';

    public const OVER = 'over';

    public const WITHOUT_ROWS = 'without_rows';

    private const CLASSES = [self::SHORT, self::OVER, self::WITHOUT_ROWS];

    private const ORDERS_PER_READ = 1000;

    /** Whether the database has what the comparison needs: the orders and the rows of their tax. */
    public function canRun(): bool
    {
        return Schema::hasTable('orders') && Schema::hasTable('order_taxes') && Schema::hasColumn('orders', 'total_tax_amount');
    }

    /**
     * @param float $tolerance the largest difference, in the currency of the order, that is rounding and not a difference
     * @param int $listLimit how many orders of each class are named (the counts and the sums always cover all of them)
     * @return array{
     *     orders: int,
     *     reconciled: int,
     *     short: array{count: int, carried: float, recorded: float, orders: list<array{id: int, carried: float, recorded: float}>},
     *     over: array{count: int, carried: float, recorded: float, orders: list<array{id: int, carried: float, recorded: float}>},
     *     without_rows: array{count: int, carried: float, recorded: float, orders: list<array{id: int, carried: float, recorded: float}>}
     * }
     */
    public function reconcile(?CarbonInterface $from = null, ?CarbonInterface $to = null, float $tolerance = 0.005, int $listLimit = 20): array
    {
        $report = ['orders' => 0, self::RECONCILED => 0] + array_fill_keys(self::CLASSES, ['count' => 0, 'carried' => 0.0, 'recorded' => 0.0, 'orders' => []]);

        DB::table('orders')
            ->select(['id', 'total_tax_amount'])
            ->when($from !== null, static fn ($query) => $query->where('created_at', '>=', $from))
            ->when($to !== null, static fn ($query) => $query->where('created_at', '<=', $to))
            ->chunkById(self::ORDERS_PER_READ, function (Collection $orders) use (&$report, $tolerance, $listLimit): void {
                $recorded = $this->recordedTaxOf(orderIds: $orders->pluck('id')->all());

                foreach ($orders as $order) {
                    $carried = (float) $order->total_tax_amount;
                    $rowsAddUpTo = $recorded[$order->id] ?? null;
                    $class = $this->classOf(carried: $carried, recorded: $rowsAddUpTo, tolerance: $tolerance);
                    $report['orders']++;

                    if ($class === self::RECONCILED) {
                        $report[self::RECONCILED]++;

                        continue;
                    }

                    $report[$class]['count']++;
                    $report[$class]['carried'] += $carried;
                    $report[$class]['recorded'] += $rowsAddUpTo ?? 0.0;

                    if (count($report[$class]['orders']) < $listLimit) {
                        $report[$class]['orders'][] = ['id' => (int) $order->id, 'carried' => round($carried, 4), 'recorded' => round($rowsAddUpTo ?? 0.0, 4)];
                    }
                }
            });

        foreach (self::CLASSES as $class) {
            $report[$class]['carried'] = round($report[$class]['carried'], 4);
            $report[$class]['recorded'] = round($report[$class]['recorded'], 4);
        }

        return $report;
    }

    /** @param float|null $recorded what the rows add up to, or null when the order has none */
    private function classOf(float $carried, ?float $recorded, float $tolerance): string
    {
        if ($recorded === null) {
            return $carried > $tolerance ? self::WITHOUT_ROWS : self::RECONCILED;
        }

        return match (true) {
            $recorded < $carried - $tolerance => self::SHORT,
            $recorded > $carried + $tolerance => self::OVER,
            default => self::RECONCILED,
        };
    }

    /**
     * @param list<int|string> $orderIds
     * @return array<int|string, float> the sum of the rows of each order that has any
     */
    private function recordedTaxOf(array $orderIds): array
    {
        return DB::table('order_taxes')
            ->whereIn('order_id', $orderIds)
            ->where('order_type', 'order')
            ->when(Schema::hasColumn('order_taxes', 'tax_for'), static fn ($query) => $query->where('tax_for', 'tax_for_orders'))
            ->groupBy('order_id')
            ->selectRaw('order_id, SUM(tax_amount) as recorded')
            ->pluck('recorded', 'order_id')
            ->map(static fn ($recorded): float => (float) $recorded)
            ->all();
    }
}
