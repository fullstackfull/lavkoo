<?php

namespace App\Services\Marketplace;

use App\Models\Order;
use App\Models\OrderDetail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What has happened to one order, in order.
 *
 * The order screen has always shown a status — one word, the current one — and nothing about how it
 * got there. That is the thing sellers ask about when something goes wrong: when was it confirmed,
 * who moved it, when was the courier assigned, when did the delivery date change and why.
 *
 * Every entry here comes from a record that already exists. Nothing is inferred from the current
 * status, because an inferred timeline is a story rather than a history: it would happily show
 * "confirmed" at a time nobody confirmed anything.
 */
class SellerOrderTimelineService
{
    public function __construct(private readonly SlaService $sla)
    {
    }

    /**
     * The order's history, oldest first, plus where its processing deadline falls.
     *
     * @return array<string, mixed>|null null when the order is not this seller's
     */
    public function timelineFor(int|string $orderId, int|string $sellerId): ?array
    {
        $details = Schema::hasTable('order_details')
            ? OrderDetail::withoutEagerLoads()
                ->where(['order_id' => $orderId, 'seller_id' => $sellerId])
                ->get(['id'])
            : collect();

        if ($details->isEmpty()) {
            return null;
        }

        $order = Order::find($orderId);

        $events = collect()
            ->merge($this->placed($order))
            ->merge($this->statusChanges($orderId))
            ->merge($this->deliveryManAssigned($order))
            ->merge($this->expectedDateChanges($orderId))
            ->merge($this->fulfillmentSteps($orderId, $sellerId))
            ->merge($this->edits($orderId))
            ->merge($this->refundRequests($details))
            ->filter(fn (array $event) => $event['at'] !== null)
            ->sortBy(fn (array $event) => $event['at'])
            ->values()
            ->map(fn (array $event) => [
                'key' => $event['key'],
                'at' => Carbon::parse($event['at'])->toIso8601String(),
                'actor' => $event['actor'] ?? null,
                'note' => $event['note'] ?? null,
            ])
            ->all();

        return [
            'events' => $events,
            'sla' => $this->slaFor($order),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function placed(?Order $order): array
    {
        return $order?->created_at ? [[
            'key' => 'order_placed',
            'at' => $order->created_at,
        ]] : [];
    }

    /** @return array<int, array<string, mixed>> */
    private function statusChanges(int|string $orderId): array
    {
        if (!Schema::hasTable('order_status_histories')) {
            return [];
        }

        return DB::table('order_status_histories')
            ->where('order_id', $orderId)
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => [
                'key' => 'status_' . $row->status,
                'at' => $row->created_at,
                // Who moved it. A seller chasing a cancellation needs to know whether it was them,
                // the customer, or the marketplace.
                'actor' => $row->user_type,
                'note' => $row->cause,
            ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function deliveryManAssigned(?Order $order): array
    {
        return $order?->deliveryman_assigned_at ? [[
            'key' => 'delivery_man_assigned',
            'at' => $order->deliveryman_assigned_at,
        ]] : [];
    }

    /** @return array<int, array<string, mixed>> */
    private function expectedDateChanges(int|string $orderId): array
    {
        if (!Schema::hasTable('order_expected_delivery_histories')) {
            return [];
        }

        return DB::table('order_expected_delivery_histories')
            ->where('order_id', $orderId)
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => [
                'key' => 'expected_delivery_date_changed',
                'at' => $row->created_at,
                'actor' => $row->user_type,
                'note' => $row->cause,
            ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function fulfillmentSteps(int|string $orderId, int|string $sellerId): array
    {
        if (!Schema::hasTable('order_fulfillments')) {
            return [];
        }

        $events = [];

        foreach (DB::table('order_fulfillments')->where(['order_id' => $orderId, 'seller_id' => $sellerId])->get() as $fulfillment) {
            foreach (['picked_at' => 'picked', 'packed_at' => 'packed', 'shipped_at' => 'shipped'] as $column => $key) {
                if ($fulfillment->{$column}) {
                    $events[] = [
                        'key' => 'fulfillment_' . $key,
                        'at' => $fulfillment->{$column},
                        'note' => $fulfillment->tracking_number ?: $fulfillment->carrier,
                    ];
                }
            }
        }

        return $events;
    }

    /** @return array<int, array<string, mixed>> */
    private function edits(int|string $orderId): array
    {
        if (!Schema::hasTable('order_edit_histories')) {
            return [];
        }

        return DB::table('order_edit_histories')
            ->where('order_id', $orderId)
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => [
                'key' => 'order_edited',
                'at' => $row->created_at,
            ])->all();
    }

    /**
     * @param  Collection<int, OrderDetail>  $details
     * @return array<int, array<string, mixed>>
     */
    private function refundRequests(Collection $details): array
    {
        if (!Schema::hasTable('refund_requests')) {
            return [];
        }

        return DB::table('refund_requests')
            ->whereIn('order_details_id', $details->pluck('id')->all())
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => [
                'key' => 'refund_requested',
                'at' => $row->created_at,
                'note' => $row->refund_reason ?? null,
            ])->all();
    }

    /**
     * The processing deadline, or null once the order no longer waits on the seller.
     *
     * Read from SlaService so the countdown here, the Action Center's countdown and the deadline
     * the marketplace judges the seller by are one number.
     *
     * @return array<string, mixed>|null
     */
    private function slaFor(?Order $order): ?array
    {
        if ($order === null || !$this->sla->awaitsSeller($order->order_status)) {
            return null;
        }

        $deadline = $this->sla->processingDeadline($order->created_at);
        if ($deadline === null) {
            return null;
        }

        $hoursLeft = $this->sla->hoursUntilDeadline($order->created_at);

        return [
            'deadline' => $deadline->toIso8601String(),
            'hours_left' => $hoursLeft,
            'is_late' => $hoursLeft !== null && $hoursLeft <= 0,
            'window_hours' => $this->sla->processingWindowHours(),
        ];
    }
}
