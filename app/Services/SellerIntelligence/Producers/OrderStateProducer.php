<?php

namespace App\Services\SellerIntelligence\Producers;

use App\Models\SellerInsight;
use App\Services\SellerCenter\Copy;
use App\Services\SellerIntelligence\InsightDraft;
use App\Services\SellerIntelligence\InsightProducer;
use App\Services\SellerIntelligence\Severity\ImpactSignals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orders that contradict themselves.
 *
 * Not "something is late" but "something cannot be true": an order delivered and still unpaid on a
 * method that is paid up front, or cancelled and still holding the customer's money. These are the
 * findings a seller has no way to look for, because nothing on any screen shows two fields at once.
 *
 * Each is a state pair that the platform's own flow should never produce, so one appearing means
 * something failed silently — a gateway callback that never arrived, a cancellation that did not
 * reverse. Raised as one issue per kind with a count, not one per order: forty orders in the same
 * broken state are one failure with forty victims.
 */
class OrderStateProducer implements InsightProducer
{
    public const TYPE = 'ORDER_STATE';

    /** Cash on delivery is unpaid until it is delivered; nothing else should be. */
    private const PAY_ON_DELIVERY_METHODS = ['cash_on_delivery', 'offline_payment'];

    private const LOOKBACK_DAYS = 60;

    public function type(): string
    {
        return self::TYPE;
    }

    public function produce(int|string $sellerId): iterable
    {
        if (!Schema::hasTable('orders')) {
            return [];
        }

        foreach ($this->contradictions($sellerId) as $kind => $orders) {
            if ($orders->isEmpty()) {
                continue;
            }

            $value = round((float) $orders->sum('order_amount'), 2);

            yield new InsightDraft(
                sellerId: $sellerId,
                type: self::TYPE,
                severity: SellerInsight::SEVERITY_HIGH,
                title: 'insight_order_state_' . $kind,
                body: Copy::choice('insight_body_order_state_one', 'insight_body_order_state', $orders->count(), [
                    'state' => translate('order_state_' . $kind),
                    'value' => $value,
                ]),
                // The kind, not an order: forty orders in one broken state are one problem.
                entityType: 'order_state',
                entityId: $kind,
                metric: $orders->count(),
                impact: $value,
                actionKey: 'open_orders',
                actionParams: ['order_ids' => $orders->pluck('id')->take(50)->all(), 'state' => $kind],
                category: SellerInsight::CATEGORY_ORDERS,
                affectedCount: $orders->count(),
                signals: new ImpactSignals(
                    revenueAtRisk: $value,
                    affectedCount: $orders->count(),
                    // Money in the wrong state is not a matter of degree, however few orders it is.
                    severityFloor: SellerInsight::SEVERITY_HIGH,
                ),
                metadata: ['kind' => $kind, 'count' => $orders->count(), 'value' => $value],
            );
        }
    }

    /**
     * @return array<string, \Illuminate\Support\Collection>
     */
    private function contradictions(int|string $sellerId): array
    {
        $base = fn () => DB::table('orders')
            ->where(['seller_is' => 'seller', 'seller_id' => $sellerId])
            ->where('created_at', '>=', now()->subDays(self::LOOKBACK_DAYS));

        return [
            // Handed to the customer and never paid for, on a method that is not paid on delivery.
            'delivered_unpaid' => $base()
                ->where('order_status', 'delivered')
                ->where('payment_status', '!=', 'paid')
                ->whereNotIn('payment_method', self::PAY_ON_DELIVERY_METHODS)
                ->limit(200)
                ->get(['id', 'order_amount']),

            // Cancelled while still holding the customer's money. Somebody is owed a refund.
            'canceled_paid' => $base()
                ->where('order_status', 'canceled')
                ->where('payment_status', 'paid')
                ->limit(200)
                ->get(['id', 'order_amount']),
        ];
    }
}
