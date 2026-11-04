<?php

namespace App\Services\SellerIntelligence\Producers;

use App\Services\Marketplace\OperationsPolicy;
use App\Models\Order;
use App\Services\SellerIntelligence\InsightDraft;
use App\Models\SellerInsight;
use App\Services\SellerCenter\Copy;
use App\Services\SellerIntelligence\InsightProducer;
use App\Services\SellerIntelligence\Severity\ImpactSignals;
use App\Services\Marketplace\SlaService;
use Illuminate\Support\Facades\Schema;

/**
 * Orders the seller still owes an action on, and how long they have left.
 *
 * The marketplace already measures SLA breaches after the fact — SlaService writes them to the
 * breach ledger and the scorecard reads them. That is accountability, not help: by the time a breach
 * exists the order is already late. This producer looks at orders still inside their window and
 * tells the seller which ones are about to fall out of it.
 *
 * The deadline is derived from the order's own age against the configured window, so it is the same
 * clock the marketplace judges them by — not a second, kinder one.
 */
class OrderSlaProducer implements InsightProducer
{
    public const TYPE = 'ORDER_SLA';

    public function __construct(private readonly SlaService $sla)
    {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function produce(int|string $sellerId): iterable
    {
        if (!Schema::hasTable('orders')) {
            return [];
        }

        $windowHours = $this->sla->processingWindowHours();
        if ($windowHours === null) {
            return [];
        }

        $orders = Order::query()
            ->where(['seller_is' => 'seller', 'seller_id' => $sellerId])
            ->whereIn('order_status', SlaService::AWAITING_SELLER_STATUSES)
            ->orderBy('created_at')
            ->limit(200)
            ->get(['id', 'order_status', 'order_amount', 'created_at']);

        foreach ($orders as $order) {
            // The deadline comes from the SLA policy, not from a copy of the arithmetic kept here.
            // The order screen shows the same countdown by asking the same question.
            $deadline = $this->sla->processingDeadline($order->created_at);
            if ($deadline === null) {
                continue;
            }

            $hoursLeft = $this->sla->hoursUntilDeadline($order->created_at);
            $isLate = $hoursLeft <= 0;

            // Still comfortably inside the window: nothing to say yet.
            if (!$isLate && $hoursLeft > $windowHours * app(OperationsPolicy::class)->slaUrgentFraction()) {
                continue;
            }

            yield new InsightDraft(
                sellerId: $sellerId,
                type: self::TYPE,
                severity: $isLate ? 'critical' : 'high',
                title: $isLate ? 'insight_order_late' : 'insight_order_due_soon',
                body: Copy::line(
                    $isLate ? 'insight_body_order_late' : 'insight_body_order_due_soon',
                    ['order' => '#' . $order->id, 'elapsed' => Copy::duration((int) round(abs($hoursLeft) * 60))],
                ),
                entityType: 'order',
                entityId: $order->id,
                metric: round($hoursLeft, 2),
                impact: (float) $order->order_amount,
                actionKey: 'open_order',
                actionParams: [
                    'order_id' => $order->id,
                    'deadline' => $deadline->toIso8601String(),
                    'hours_left' => round($hoursLeft, 2),
                ],
                // A late order stops being news once it is very late; the breach ledger owns it then.
                expiresAt: $deadline->copy()->addDays(7),
                category: SellerInsight::CATEGORY_ORDERS,
                dueAt: $deadline,
                signals: new ImpactSignals(
                    // What the order is worth. An order approaching its deadline is money the seller
                    // still has, and a marketplace standing they still have.
                    revenueAtRisk: (float) $order->order_amount,
                    affectedCount: 1,
                    hoursUntilDue: round($hoursLeft, 2),
                    severityFloor: $isLate ? SellerInsight::SEVERITY_HIGH : null,
                ),
                metadata: ['deadline' => $deadline->toIso8601String(), 'window_hours' => $windowHours],
            );
        }
    }
}
