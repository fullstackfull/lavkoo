<?php

namespace App\Services\SellerIntelligence\Producers;

use App\Models\SellerInsight;
use App\Services\Platform\Policy;
use App\Services\SellerCenter\Copy;
use App\Services\DeliverySyria\DeliverySyriaStatus;
use App\Services\SellerIntelligence\InsightDraft;
use App\Services\SellerIntelligence\InsightProducer;
use App\Services\SellerIntelligence\Severity\ImpactSignals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shipments that have stopped moving.
 *
 * A parcel whose courier status has not changed in days is the failure a seller cannot see: the
 * order screen says "out for delivery" and has said so all week. The signal is real —
 * `delivery_syria_parcels.status_updated_at` is written by the courier's own webhook — so silence in
 * that column is the courier not saying anything, which is precisely what is worth knowing.
 *
 * Deliberately not a per-parcel alarm. One issue with a count, because a courier having a bad week
 * is one problem however many parcels it touches, and because forty separate alerts about forty
 * parcels is how a seller learns to ignore all of them.
 */
class ShippingExceptionProducer implements InsightProducer
{
    public const TYPE = 'SHIPPING_EXCEPTION';

    private const LIMIT = 200;

    public function type(): string
    {
        return self::TYPE;
    }

    public function produce(int|string $sellerId): iterable
    {
        if (!Schema::hasTable('delivery_syria_parcels') || !Schema::hasTable('orders')) {
            return [];
        }

        // Courier silence tolerance is a per-market judgement — a two-day gap is normal in one
        // country and an incident in another — so the marketplace sets it rather than the code.
        $policy = app(Policy::class);
        $silentHours = $policy->int('shipping_silent_hours');
        $stopAfterDays = $policy->int('shipping_stop_after_days');

        $silent = DB::table('delivery_syria_parcels')
            ->join('orders', 'orders.id', '=', 'delivery_syria_parcels.order_id')
            ->where('orders.seller_is', 'seller')
            ->where('orders.seller_id', $sellerId)
            ->whereNotIn('orders.order_status', ['delivered', 'canceled', 'returned', 'failed'])
            ->where('delivery_syria_parcels.created_at', '>=', now()->subDays($stopAfterDays))
            ->where(function ($query) use ($silentHours) {
                // Either the courier has gone quiet, or it never said anything in the first place.
                $query->where('delivery_syria_parcels.status_updated_at', '<=', now()->subHours($silentHours))
                    ->orWhere(function ($inner) use ($silentHours) {
                        $inner->whereNull('delivery_syria_parcels.status_updated_at')
                            ->where('delivery_syria_parcels.created_at', '<=', now()->subHours($silentHours));
                    });
            })
            ->orderBy('delivery_syria_parcels.created_at')
            ->limit(self::LIMIT)
            ->get([
                'delivery_syria_parcels.id',
                'delivery_syria_parcels.order_id',
                'delivery_syria_parcels.tracking_number',
                'delivery_syria_parcels.courier_status',
                'delivery_syria_parcels.status_updated_at',
                'delivery_syria_parcels.created_at',
                'orders.order_amount',
            ]);

        if ($silent->isEmpty()) {
            return [];
        }

        $value = round((float) $silent->sum('order_amount'), 2);
        $oldest = $silent->first();
        $silentSince = $oldest->status_updated_at ?? $oldest->created_at;
        $longestSilenceHours = round(\Illuminate\Support\Carbon::parse($silentSince)->diffInMinutes(now()) / 60, 1);

        yield new InsightDraft(
            sellerId: $sellerId,
            type: self::TYPE,
            severity: SellerInsight::SEVERITY_HIGH,
            title: 'insight_shipments_not_moving',
            body: Copy::choice('insight_body_shipments_silent_one', 'insight_body_shipments_silent', $silent->count(), [
                'elapsed' => Copy::duration((int) round($longestSilenceHours * 60)),
                'value' => $value,
            ]),
            entityType: 'shipment_group',
            entityId: 'not_moving',
            metric: $silent->count(),
            impact: $value,
            actionKey: 'open_orders',
            actionParams: ['order_ids' => $silent->pluck('order_id')->take(50)->all()],
            category: SellerInsight::CATEGORY_SHIPPING,
            affectedCount: $silent->count(),
            signals: new ImpactSignals(
                revenueAtRisk: $value,
                affectedCount: $silent->count(),
                openForHours: $longestSilenceHours,
            ),
            metadata: [
                'count' => $silent->count(),
                'longest_silence_hours' => $longestSilenceHours,
                'window_hours' => $silentHours,
                'last_known_status' => $oldest->courier_status,
            ],
        );
    }
}
