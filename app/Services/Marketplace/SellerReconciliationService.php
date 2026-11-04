<?php

namespace App\Services\Marketplace;

use App\Models\VendorLedgerEntry;
use App\Services\Platform\Policy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Does what the seller sold add up to what the seller was credited?
 *
 * The platform already reconciles itself — `ReconciliationService` checks the ledger's running
 * balance, the commission snapshots and the settlements, across every shop, for whoever runs the
 * marketplace. None of that reaches the seller, who has the same question about their own money and
 * no way to ask it: they can see orders, and they can see a balance, and nothing joins the two.
 *
 * So this walks the same chain one shop at a time and shows each hand-off:
 *
 *   delivered lines → an earning recorded for each → a credit in the ledger for each
 *
 * and names what fell out at every step. A line delivered with no earning recorded is money the
 * seller has earned and not been told about; an earning with no credit is money recorded and not
 * paid into the balance. Both are real states with real causes — settlement not yet run, a job that
 * failed — and both are things a seller is entitled to see rather than discover a month later.
 *
 * Nothing here is estimated. Every figure is a sum over rows that exist, and where a row does not
 * exist the answer is that it does not exist.
 */
class SellerReconciliationService
{
    /** A cent of slack absorbs rounding noise without hiding a real discrepancy. */
    private const EPSILON = 0.01;

    /** Enough to open, not so many that the answer becomes a report nobody reads. */
    private const SAMPLE_SIZE = 20;

    /**
     * @return array{
     *     from: string, to: string,
     *     delivered: array{lines: int, orders: int, gross: float},
     *     recorded: array{lines: int, commission: float, net: float, source: string},
     *     credited: array{entries: int, amount: float},
     *     gaps: array{lines_without_earning: array, earnings_without_credit: array},
     *     reconciles: bool
     * }
     */
    public function forSeller(int|string $sellerId, ?string $from = null, ?string $to = null): array
    {
        [$start, $end] = $this->window($from, $to);

        $delivered = $this->delivered($sellerId, $start, $end);
        $recorded = $this->recorded($sellerId, $start, $end);
        $credited = $this->credited($sellerId, $start, $end);

        $linesWithoutEarning = $this->linesWithoutEarning($sellerId, $start, $end);
        $earningsWithoutCredit = $this->earningsWithoutCredit($sellerId, $start, $end);

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'delivered' => $delivered,
            'recorded' => $recorded,
            'credited' => $credited,
            'gaps' => [
                'lines_without_earning' => $linesWithoutEarning,
                'earnings_without_credit' => $earningsWithoutCredit,
            ],
            // Only true when nothing fell out of either hand-off. Deliberately not "the totals are
            // close enough": a shop can have a matching total and still be missing one line's
            // earning and carrying an extra credit that cancels it out.
            'reconciles' => $linesWithoutEarning['count'] === 0
                && $earningsWithoutCredit['count'] === 0
                && abs($recorded['net'] - $credited['amount']) < self::EPSILON,
        ];
    }

    /** @return array{lines: int, orders: int, gross: float} */
    private function delivered(int|string $sellerId, Carbon $start, Carbon $end): array
    {
        if (!Schema::hasTable('order_details')) {
            return ['lines' => 0, 'orders' => 0, 'gross' => 0.0];
        }

        $row = DB::table('order_details')
            ->join('orders', 'orders.id', '=', 'order_details.order_id')
            ->where(['orders.seller_is' => 'seller', 'orders.seller_id' => $sellerId])
            ->where('order_details.delivery_status', 'delivered')
            ->whereBetween('order_details.created_at', [$start, $end])
            ->selectRaw('COUNT(*) as line_count, COUNT(DISTINCT order_details.order_id) as orders, '
                . 'COALESCE(SUM(order_details.price * order_details.qty - order_details.discount), 0) as gross')
            ->first();

        return [
            'lines' => (int) ($row->line_count ?? 0),
            'orders' => (int) ($row->orders ?? 0),
            'gross' => round((float) ($row->gross ?? 0), 2),
        ];
    }

    /**
     * What was actually recorded as earned, from whichever record exists.
     *
     * The per-line commission snapshot is preferred because it names the rule that was applied. The
     * older per-order transaction is the fallback, and the answer says which was used — a figure
     * whose provenance is unstated is a figure nobody can check.
     *
     * @return array{lines: int, commission: float, net: float, source: string}
     */
    private function recorded(int|string $sellerId, Carbon $start, Carbon $end): array
    {
        if (Schema::hasTable('order_item_commissions')) {
            $row = DB::table('order_item_commissions')
                ->where(['seller_is' => 'seller', 'seller_id' => $sellerId])
                ->whereBetween('created_at', [$start, $end])
                ->selectRaw('COUNT(*) as line_count, COALESCE(SUM(commission_amount), 0) as commission, '
                    . 'COALESCE(SUM(seller_net_amount - reversed_amount), 0) as net')
                ->first();

            if ((int) ($row->line_count ?? 0) > 0) {
                return [
                    'lines' => (int) $row->line_count,
                    'commission' => round((float) $row->commission, 2),
                    // Reversals subtracted, because a refunded line was earned and then un-earned,
                    // and reporting the gross would have the seller looking for money they gave back.
                    'net' => round((float) $row->net, 2),
                    'source' => SellerOrderBreakdownService::SOURCE_COMMISSION_LEDGER,
                ];
            }
        }

        if (Schema::hasTable('order_transactions')) {
            $row = DB::table('order_transactions')
                ->where(['seller_is' => 'seller', 'seller_id' => $sellerId])
                ->whereBetween('created_at', [$start, $end])
                ->selectRaw('COUNT(*) as line_count, COALESCE(SUM(admin_commission), 0) as commission, '
                    . 'COALESCE(SUM(seller_amount), 0) as net')
                ->first();

            if ((int) ($row->line_count ?? 0) > 0) {
                return [
                    'lines' => (int) $row->line_count,
                    'commission' => round((float) $row->commission, 2),
                    'net' => round((float) $row->net, 2),
                    'source' => SellerOrderBreakdownService::SOURCE_ORDER_TRANSACTION,
                ];
            }
        }

        return ['lines' => 0, 'commission' => 0.0, 'net' => 0.0, 'source' => SellerOrderBreakdownService::SOURCE_NOT_RECORDED];
    }

    /** @return array{entries: int, amount: float} */
    private function credited(int|string $sellerId, Carbon $start, Carbon $end): array
    {
        if (!Schema::hasTable('vendor_ledger_entries')) {
            return ['entries' => 0, 'amount' => 0.0];
        }

        // Two entries make one earning: the credit is the commissionable amount and the commission
        // is a separate debit booked against the same line. Summing only the credit compares gross
        // against `recorded['net']`, which differs by the whole commission on every order — so no
        // shop could ever reconcile, and the screen showed two totals for the same sales.
        $row = DB::table('vendor_ledger_entries')
            ->where(['seller_is' => 'seller', 'seller_id' => $sellerId])
            ->whereIn('entry_type', [VendorLedgerEntry::TYPE_ORDER_EARNING, VendorLedgerEntry::TYPE_COMMISSION_CHARGE])
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw(
                'COALESCE(SUM(credit - debit), 0) as amount, '
                . "COALESCE(SUM(CASE WHEN entry_type = ? THEN 1 ELSE 0 END), 0) as entries",
                [VendorLedgerEntry::TYPE_ORDER_EARNING],
            )
            ->first();

        return [
            // Earnings, not entries: the commission debits are the other half of the same fact.
            'entries' => (int) ($row->entries ?? 0),
            'amount' => round((float) ($row->amount ?? 0), 2),
        ];
    }

    /**
     * Delivered lines the marketplace has not recorded an earning for.
     *
     * @return array{count: int, amount: float, sample: array<int, array>}
     */
    private function linesWithoutEarning(int|string $sellerId, Carbon $start, Carbon $end): array
    {
        if (!Schema::hasTable('order_details') || !Schema::hasTable('order_item_commissions')) {
            return ['count' => 0, 'amount' => 0.0, 'sample' => []];
        }

        $query = DB::table('order_details')
            ->join('orders', 'orders.id', '=', 'order_details.order_id')
            ->leftJoin('order_item_commissions', 'order_item_commissions.order_details_id', '=', 'order_details.id')
            ->where(['orders.seller_is' => 'seller', 'orders.seller_id' => $sellerId])
            ->where('order_details.delivery_status', 'delivered')
            ->whereBetween('order_details.created_at', [$start, $end])
            ->whereNull('order_item_commissions.id');

        $totals = (clone $query)
            ->selectRaw('COUNT(*) as line_count, COALESCE(SUM(order_details.price * order_details.qty - order_details.discount), 0) as amount')
            ->first();

        return [
            'count' => (int) ($totals->line_count ?? 0),
            'amount' => round((float) ($totals->amount ?? 0), 2),
            'sample' => (clone $query)
                ->orderByDesc('order_details.id')
                ->limit(self::SAMPLE_SIZE)
                ->get([
                    'order_details.id as order_detail_id',
                    'order_details.order_id',
                    'order_details.qty',
                    'order_details.price',
                    'order_details.created_at',
                ])
                // Cast rather than passed through: the driver hands back decimals as strings, and a
                // client that has to guess which numbers arrived as text will eventually guess wrong.
                ->map(fn ($row) => [
                    'order_detail_id' => (int) $row->order_detail_id,
                    'order_id' => (int) $row->order_id,
                    'qty' => (int) $row->qty,
                    'price' => round((float) $row->price, 2),
                    'created_at' => $row->created_at,
                ])
                ->all(),
        ];
    }

    /**
     * Earnings recorded for which no credit reached the balance.
     *
     * Matched on the ledger's own reference to the order, which is how the ledger records where an
     * earning came from. An order with a commission snapshot and no ledger entry pointing at it is
     * money the seller has been told they earned and not been given.
     *
     * @return array{count: int, amount: float, sample: array<int, array>}
     */
    private function earningsWithoutCredit(int|string $sellerId, Carbon $start, Carbon $end): array
    {
        if (!Schema::hasTable('order_item_commissions') || !Schema::hasTable('vendor_ledger_entries')) {
            return ['count' => 0, 'amount' => 0.0, 'sample' => []];
        }

        // The ledger records one earning per order *line*, keyed on `order_details.id` — see
        // OrderManager, which passes `referenceType: 'order_details'`. Looking for `'order'` and an
        // order id found nothing, so the exclusion below never applied and every correctly credited
        // order was reported to the seller as money they had not been paid. The id spaces differ as
        // well as the type string, so the reference ids are mapped back through `order_details`.
        $creditedLineIds = DB::table('vendor_ledger_entries')
            ->where(['seller_is' => 'seller', 'seller_id' => $sellerId])
            ->where('entry_type', VendorLedgerEntry::TYPE_ORDER_EARNING)
            ->where('reference_type', 'order_details')
            ->pluck('reference_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $creditedOrders = collect();

        if ($creditedLineIds->isNotEmpty() && Schema::hasTable('order_details')) {
            $creditedOrders = DB::table('order_details')
                ->whereIn('id', $creditedLineIds->all())
                ->distinct()
                ->pluck('order_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->values();
        }

        $query = DB::table('order_item_commissions')
            ->where(['seller_is' => 'seller', 'seller_id' => $sellerId])
            ->whereBetween('created_at', [$start, $end])
            ->when($creditedOrders->isNotEmpty(), fn ($q) => $q->whereNotIn('order_id', $creditedOrders->all()))
            ->groupBy('order_id');

        $rows = (clone $query)
            ->selectRaw('order_id, COALESCE(SUM(seller_net_amount - reversed_amount), 0) as amount, MAX(created_at) as recorded_at')
            ->orderByDesc('order_id')
            ->get();

        return [
            'count' => $rows->count(),
            'amount' => round((float) $rows->sum('amount'), 2),
            'sample' => $rows->take(self::SAMPLE_SIZE)->map(fn ($row) => [
                'order_id' => (int) $row->order_id,
                'amount' => round((float) $row->amount, 2),
                'recorded_at' => $row->recorded_at,
            ])->all(),
        ];
    }

    /**
     * The window, defaulting to the last thirty days.
     *
     * A malformed date widens the window rather than matching nothing: an unreadable filter that
     * silently returns an empty reconciliation would read as "everything balances".
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(?string $from, ?string $to): array
    {
        $start = $this->parse($from) ?? now()->subDays(app(Policy::class)->int('reconciliation_lookback_days'));
        $end = $this->parse($to) ?? now();

        return $start->lessThanOrEqualTo($end)
            ? [$start->startOfDay(), $end->endOfDay()]
            : [$end->startOfDay(), $start->endOfDay()];
    }

    private function parse(?string $value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
