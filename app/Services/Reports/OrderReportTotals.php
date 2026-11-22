<?php

namespace App\Services\Reports;

use Illuminate\Support\Collection;

/**
 * The totals of the order report, one block per currency the orders of the period were sold in (D1-06).
 *
 * The rows of that report each state the money their own order was sold in, so one summary underneath them cannot: adding thirty dinars to two
 * hundred and fifty dirhams, multiplying the sum by the riyal rate and printing it under the riyal sign states a figure nobody was charged, over
 * a period in which no order was in riyals at all. The orders are therefore grouped by the currency their own record names and each group is
 * totalled on its own, carrying one of its orders along so that whatever prints a block prints it in that order's money.
 *
 * A period whose orders record no currency — every period of a shop that has not switched a storefront currency on — is one group of orders that
 * carry none, so the document is the single summary it has always been, figure for figure.
 */
final class OrderReportTotals
{
    /**
     * @param Collection<int, object> $orders the orders of the period, as the report's own query returns them
     *
     * @return list<array{order: object, totals: array<string, float|int>}> in the order the groups first appear
     */
    public function perCurrency(Collection $orders): array
    {
        return $orders
            ->groupBy(fn (object $order): string => (string) ($order->currency_code ?? ''))
            ->map(fn (Collection $group): array => ['order' => $group->first(), 'totals' => $this->totalsOf($group)])
            ->values()
            ->all();
    }

    /**
     * The same ten figures the report has always summed, over one group instead of over every order of the period.
     *
     * @param Collection<int, object> $orders
     *
     * @return array<string, float|int>
     */
    private function totalsOf(Collection $orders): array
    {
        return [
            'order_amount' => $orders->sum('order_amount') ?? 0,
            'product_discount' => $orders->sum('details_sum_discount') ?? 0,
            'coupon_discount' => $orders->sum('discount_amount') ?? 0,
            'referral_discount' => $orders->sum('refer_and_earn_discount') ?? 0,
            'tax' => $orders->sum('total_tax_amount') ?? ($orders->sum('details_sum_tax') ?? 0),
            'commission' => $orders->sum('admin_commission') ?? 0,
            'due_amount' => $orders->sum('edit_due_amount') ?? 0,
            'return_amount' => $orders->sum('edit_return_amount') ?? 0,
            'delivery_charge' => $orders->sum(fn (object $order): float|int => $order->shipping_cost - ($order->extra_discount_type == 'free_shipping_over_order_amount' ? $order->extra_discount : 0)),
            'deliveryman_incentive' => $orders->sum(fn (object $order): float|int => ($order->delivery_type == 'self_delivery' && $order->delivery_man_id) ? $order->deliveryman_charge : 0),
        ];
    }
}
