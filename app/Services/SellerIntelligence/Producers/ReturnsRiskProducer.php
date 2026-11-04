<?php

namespace App\Services\SellerIntelligence\Producers;

use App\Services\Marketplace\OperationsPolicy;
use App\Models\ReturnShipment;
use App\Models\SellerInsight;
use App\Services\SellerCenter\Copy;
use App\Services\SellerIntelligence\InsightDraft;
use App\Services\SellerIntelligence\InsightProducer;
use App\Services\SellerIntelligence\Severity\ImpactSignals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Returns waiting on the seller.
 *
 * A refund request with nobody answering it is the worst kind of open item: the customer is waiting,
 * the marketplace is counting the delay against the seller's standing, and the seller may not know
 * the request exists. It is also the one place where doing nothing is actively worse than deciding
 * either way.
 *
 * The second finding is goods that arrived and stopped there. A return received but never processed
 * is stock the seller has back and has not put anywhere, and money the customer has not seen — both
 * sides of one unfinished job.
 */
class ReturnsRiskProducer implements InsightProducer
{
    public const TYPE = 'RETURNS_RISK';

    private const LIMIT = 100;

    public function type(): string
    {
        return self::TYPE;
    }

    public function produce(int|string $sellerId): iterable
    {
        yield from $this->unansweredRefunds($sellerId);
        yield from $this->receivedButUnfinished($sellerId);
    }

    /** @return iterable<InsightDraft> */
    private function unansweredRefunds(int|string $sellerId): iterable
    {
        $responseHours = app(OperationsPolicy::class)->returnsResponseHours();

        if (!Schema::hasTable('refund_requests') || !Schema::hasTable('orders')) {
            return;
        }

        $waiting = DB::table('refund_requests')
            ->join('orders', 'orders.id', '=', 'refund_requests.order_id')
            ->where('orders.seller_is', 'seller')
            ->where('orders.seller_id', $sellerId)
            ->where('refund_requests.status', 'pending')
            ->where('refund_requests.created_at', '<=', now()->subHours($responseHours))
            ->orderBy('refund_requests.created_at')
            ->limit(self::LIMIT)
            ->get([
                'refund_requests.id',
                'refund_requests.order_id',
                'refund_requests.amount',
                'refund_requests.created_at',
            ]);

        foreach ($waiting as $request) {
            $waitingHours = round(\Illuminate\Support\Carbon::parse($request->created_at)->diffInMinutes(now()) / 60, 1);

            yield new InsightDraft(
                sellerId: $sellerId,
                type: self::TYPE,
                severity: SellerInsight::SEVERITY_HIGH,
                title: 'insight_refund_response_overdue',
                body: Copy::line('insight_body_refund_overdue', [
                    'order' => '#' . $request->order_id,
                    'elapsed' => Copy::duration((int) round($waitingHours * 60)),
                ]),
                entityType: 'refund_request',
                entityId: $request->id,
                metric: $waitingHours,
                impact: (float) $request->amount,
                actionKey: 'open_refund',
                actionParams: ['refund_request_id' => $request->id, 'order_id' => $request->order_id],
                category: SellerInsight::CATEGORY_RETURNS,
                dueAt: \Illuminate\Support\Carbon::parse($request->created_at)->addHours($responseHours),
                signals: new ImpactSignals(
                    revenueAtRisk: (float) $request->amount,
                    affectedCount: 1,
                    // Already past the window, so urgency is at its ceiling and counting up.
                    hoursUntilDue: -($waitingHours - $responseHours),
                    openForHours: $waitingHours,
                    // Doing nothing is worse than either decision, so this never falls to advisory.
                    severityFloor: SellerInsight::SEVERITY_HIGH,
                ),
                metadata: ['waiting_hours' => $waitingHours, 'window_hours' => $responseHours],
            );
        }
    }

    /** @return iterable<InsightDraft> */
    private function receivedButUnfinished(int|string $sellerId): iterable
    {
        $processingHours = app(OperationsPolicy::class)->returnsProcessingHours();

        if (!Schema::hasTable('return_shipments')) {
            return;
        }

        $stuck = ReturnShipment::where('seller_id', $sellerId)
            ->whereIn('status', [ReturnShipment::STATUS_AUTHORIZED, ReturnShipment::STATUS_IN_TRANSIT])
            ->where('created_at', '<=', now()->subHours($processingHours))
            ->orderBy('created_at')
            ->limit(self::LIMIT)
            ->get();

        if ($stuck->isEmpty()) {
            return;
        }

        yield new InsightDraft(
            sellerId: $sellerId,
            type: self::TYPE,
            severity: SellerInsight::SEVERITY_MEDIUM,
            title: 'insight_returns_awaiting_processing',
            body: Copy::choice('insight_body_returns_waiting_one', 'insight_body_returns_waiting', $stuck->count(), [
                'hours' => $processingHours,
            ]),
            entityType: 'return_group',
            entityId: 'awaiting_processing',
            metric: $stuck->count(),
            actionKey: 'open_returns',
            actionParams: ['return_ids' => $stuck->pluck('id')->take(50)->all()],
            category: SellerInsight::CATEGORY_RETURNS,
            affectedCount: $stuck->count(),
            signals: new ImpactSignals(affectedCount: $stuck->count()),
            metadata: ['count' => $stuck->count(), 'oldest_hours' => round(\Illuminate\Support\Carbon::parse($stuck->first()->created_at)->diffInMinutes(now()) / 60, 1)],
        );
    }
}
