<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;
use App\Services\Fulfillment\Data\FulfillmentSummary;
use Illuminate\Support\Facades\DB;

/**
 * The parent-order status policy.
 *
 * The customer has one order; the providers have one fulfillment each. This derives the single picture
 * of the order from them, and it only ever READS: it never writes `orders.order_status`. That column is
 * financially loaded (delivered matures earnings, canceled restocks) and is moved only by the existing
 * order flow — the same boundary the Delivery Syria webhook keeps.
 *
 * The rules that matter:
 *  - The order is "delivered" only when EVERY required fulfillment is delivered. One delivered parcel
 *    out of two is "partially delivered", never delivered.
 *  - Warehouse picking/packing is progress, not delivery: it never moves a fulfillment past "processing".
 *  - A cancelled fulfillment is no longer required; if all are cancelled the order is cancelled.
 *  - A fulfillment that needs a human (failed dispatch, failed delivery attempt, unknown outcome,
 *    provider-reported issue) puts the order in "attention required" unless it is already fully finished.
 */
class FulfillmentAggregator
{
    public const NONE = 'none';
    public const NOT_STARTED = 'not_started';
    public const PROCESSING = 'processing';
    public const PARTIALLY_SHIPPED = 'partially_shipped';
    public const SHIPPED = 'shipped';
    public const PARTIALLY_DELIVERED = 'partially_delivered';
    public const DELIVERED = 'delivered';
    public const ATTENTION_REQUIRED = 'attention_required';
    public const CANCELLED = 'cancelled';
    public const RETURNED = 'returned';
    public const PARTIALLY_RETURNED = 'partially_returned';

    private const NEEDS_HUMAN = [
        FulfillmentStatus::Failed, FulfillmentStatus::DeliveryFailed, FulfillmentStatus::DeliveryPostponed,
        FulfillmentStatus::NeedsAttention, FulfillmentStatus::ReconcilePending, FulfillmentStatus::PartiallyDelivered,
    ];

    private const ON_THE_ROAD = [FulfillmentStatus::InTransit, FulfillmentStatus::OutForDelivery];

    public function summarize(int $orderId): FulfillmentSummary
    {
        $fulfillments = ProviderFulfillment::with('items')->where('order_id', $orderId)->orderBy('id')->get();
        $statuses = $fulfillments->pluck('status')->all();

        $providers = $fulfillments->pluck('provider')->unique()->count();
        $providerLines = ProviderFulfillmentItem::where('order_id', $orderId)->where('status', '!=', ProviderFulfillmentItem::STATUS_CANCELLED)->count();
        $inHouse = $fulfillments->isNotEmpty()
            && DB::table('order_details')->where('order_id', $orderId)->count() > $providerLines;

        $required = array_values(array_filter($statuses, static fn (FulfillmentStatus $status): bool => $status !== FulfillmentStatus::Cancelled));
        $unfinished = array_values(array_filter($required, static fn (FulfillmentStatus $status): bool => !$status->isTerminal()));
        $notHandedOver = ProviderFulfillmentItem::where('order_id', $orderId)->where('status', ProviderFulfillmentItem::STATUS_UNROUTED)->exists();

        return new FulfillmentSummary(
            status: self::statusFor($statuses),
            fulfillments: $fulfillments,
            mixed: $providers > 1 || ($providers >= 1 && $inHouse),
            allRequiredFinished: $fulfillments->isNotEmpty() && $unfinished === [] && !$notHandedOver,
            deliveryBlockedReason: $unfinished === [] && !$notHandedOver ? null : 'provider_shipments_not_delivered',
        );
    }

    /**
     * The pure policy: a list of fulfillment statuses in, one aggregate status out.
     *
     * @param list<FulfillmentStatus> $statuses every fulfillment of the order, cancelled ones included
     */
    public static function statusFor(array $statuses): string
    {
        if ($statuses === []) {
            return self::NONE;
        }

        $required = array_values(array_filter($statuses, static fn (FulfillmentStatus $status): bool => $status !== FulfillmentStatus::Cancelled));
        if ($required === []) {
            return self::CANCELLED;
        }

        $count = count($required);
        $delivered = self::count($required, [FulfillmentStatus::Delivered]);
        $returned = self::count($required, [FulfillmentStatus::Returned]);

        if ($delivered === $count) {
            return self::DELIVERED;
        }
        if ($returned === $count) {
            return self::RETURNED;
        }
        if ($delivered + $returned === $count) {
            return self::PARTIALLY_RETURNED;
        }
        if (self::count($required, self::NEEDS_HUMAN) > 0) {
            return self::ATTENTION_REQUIRED;
        }
        if ($delivered > 0) {
            return self::PARTIALLY_DELIVERED;
        }

        $onTheRoad = self::count($required, self::ON_THE_ROAD);
        if ($onTheRoad === $count) {
            return self::SHIPPED;
        }
        if ($onTheRoad > 0) {
            return self::PARTIALLY_SHIPPED;
        }

        return self::count($required, [FulfillmentStatus::Submitted, FulfillmentStatus::Processing]) > 0
            ? self::PROCESSING
            : self::NOT_STARTED;
    }

    /**
     * @param list<FulfillmentStatus> $statuses
     * @param list<FulfillmentStatus> $needles
     */
    private static function count(array $statuses, array $needles): int
    {
        return count(array_filter($statuses, static fn (FulfillmentStatus $status): bool => in_array($status, $needles, true)));
    }
}
