<?php

namespace App\Services\SellerIntelligence;

use App\Models\SellerInsight;
use App\Services\Marketplace\SlaService;
use App\Services\Marketplace\VendorLedger;
use App\Services\SellerCenter\Revenue;
use App\Services\SellerIntelligence\Producers\InventoryRiskProducer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The seller's day, in numbers that were counted rather than estimated.
 *
 * Every figure here comes from a query over real rows. There is no forecasting, no smoothing and no
 * projection — a briefing that guessed would be read as fact the next morning and acted on.
 *
 * The comparison is the part that carries the meaning. "124 orders" says nothing without yesterday's
 * number beside it, and a percentage against a day that had no orders is arithmetic that produces
 * infinity, so it is reported as null and the client says "no comparison" rather than "+∞%".
 */
class DailyBriefingService
{
    public function __construct(
        private readonly ControlTowerService $tower,
        private readonly SlaService $sla,
        private readonly VendorLedger $ledger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function forSeller(int|string $sellerId, ?Carbon $day = null): array
    {
        $day ??= now();
        $today = $this->dayFigures($sellerId, $day->copy()->startOfDay(), $day->copy()->endOfDay());
        $yesterday = $this->dayFigures(
            $sellerId,
            $day->copy()->subDay()->startOfDay(),
            $day->copy()->subDay()->endOfDay(),
        );

        return [
            'date' => $day->toDateString(),
            'today' => $today,
            'previous_day' => $yesterday,
            'change' => $this->change($today, $yesterday),
            'waiting' => $this->waiting($sellerId),
            'issues' => $this->tower->summary($sellerId),
            'balance' => $this->balance($sellerId),
        ];
    }

    /** How many of the waiting orders have their deadline worked out one by one. */
    private const SLA_SCAN_LIMIT = 500;

    /**
     * What happened in a window.
     *
     * Revenue counts delivered lines only, matching every other revenue figure in the platform. A
     * briefing that counted placed orders as revenue would disagree with the statement, the reports
     * and the payout — four numbers for one question.
     *
     * @return array<string, mixed>
     */
    private function dayFigures(int|string $sellerId, Carbon $from, Carbon $to): array
    {
        if (!Schema::hasTable('orders')) {
            return ['orders' => 0, 'revenue' => 0.0, 'cancelled' => 0, 'returned' => 0];
        }

        $orders = DB::table('orders')
            ->where(['seller_is' => 'seller', 'seller_id' => $sellerId])
            ->whereBetween('created_at', [$from, $to]);

        // One definition of revenue for the whole platform: delivered lines, net of the line's own
        // discount. The briefing, the home KPIs, reconciliation and the payout all read it here.
        $revenue = Revenue::total($sellerId, $from, $to);

        return [
            'orders' => (clone $orders)->count(),
            'revenue' => round($revenue, 2),
            'cancelled' => (clone $orders)->where('order_status', 'canceled')->count(),
            'returned' => (clone $orders)->where('order_status', 'returned')->count(),
        ];
    }

    /**
     * What is waiting for the seller right now, counted rather than derived from issues.
     *
     * These are the operational queues — orders to ship, returns to answer — and they are counted
     * directly because a seller acts on the queue, not on the issue about the queue. An issue may
     * have been dismissed; the order still needs shipping.
     *
     * @return array<string, mixed>
     */
    private function waiting(int|string $sellerId): array
    {
        $awaiting = 0;
        $atRisk = 0;

        if (Schema::hasTable('orders')) {
            $queue = DB::table('orders')
                ->where(['seller_is' => 'seller', 'seller_id' => $sellerId])
                ->whereIn('order_status', SlaService::AWAITING_SELLER_STATUSES);

            // Counted in the database rather than from the rows read below: a seller with a
            // thousand orders to ship must not be told they have five hundred.
            $awaiting = (clone $queue)->count();

            // The deadline is worked out per row, so that part is bounded. The oldest first, which
            // is where the ones already past their deadline are.
            $orders = $queue->orderBy('created_at')->limit(self::SLA_SCAN_LIMIT)->get(['id', 'created_at']);

            foreach ($orders as $order) {
                $hoursLeft = $this->sla->hoursUntilDeadline(Carbon::parse($order->created_at));

                // Inside six hours or already past: the same window the severity engine treats as
                // urgent, so the briefing and the issue list cannot disagree about what is at risk.
                if ($hoursLeft !== null && $hoursLeft <= 6) {
                    $atRisk++;
                }
            }
        }

        return [
            'awaiting_shipment' => $awaiting,
            'sla_at_risk' => $atRisk,
            'returns_to_answer' => $this->pendingRefunds($sellerId),
            'low_stock_products' => $this->lowStockCount($sellerId),
        ];
    }

    private function pendingRefunds(int|string $sellerId): int
    {
        if (!Schema::hasTable('refund_requests') || !Schema::hasTable('orders')) {
            return 0;
        }

        return (int) DB::table('refund_requests')
            ->join('orders', 'orders.id', '=', 'refund_requests.order_id')
            ->where('orders.seller_is', 'seller')
            ->where('orders.seller_id', $sellerId)
            ->where('refund_requests.status', 'pending')
            ->count();
    }

    private function lowStockCount(int|string $sellerId): int
    {
        if (!Schema::hasTable('seller_insights')) {
            return 0;
        }

        // From the issue store rather than a second stock query: the threshold that decides "low"
        // lives in the inventory detector, and asking here would mean a second definition of it.
        //
        // The producer, not the category: stale and overstocked lines are inventory issues too, and
        // counting them here told a seller with fifty slow movers that fifty things were running out.
        return (int) SellerInsight::forSeller($sellerId)
            ->open()
            ->where('type', InventoryRiskProducer::TYPE)
            ->sum('affected_count');
    }

    /** @return array<string, mixed> */
    private function balance(int|string $sellerId): array
    {
        $balances = $this->ledger->balances($sellerId);

        return [
            'currency' => $this->ledger->baseCurrency(),
            'pending' => round($balances['pending'], 2),
            'withdrawable' => round($this->ledger->withdrawable($sellerId), 2),
        ];
    }

    /**
     * Day over day, as percentages where a percentage means anything.
     *
     * A day that had no orders has no percentage to compare against — dividing by it produces
     * infinity, and reporting "+∞%" or silently substituting 100 would both be lies. Null says there
     * is no comparison, and the client says so.
     *
     * @param  array<string, mixed>  $today
     * @param  array<string, mixed>  $yesterday
     * @return array<string, float|null>
     */
    private function change(array $today, array $yesterday): array
    {
        $change = [];

        foreach (['orders', 'revenue', 'cancelled', 'returned'] as $key) {
            $before = (float) ($yesterday[$key] ?? 0);
            $now = (float) ($today[$key] ?? 0);

            $change[$key] = $before > 0 ? round((($now - $before) / $before) * 100, 1) : null;
        }

        return $change;
    }
}
