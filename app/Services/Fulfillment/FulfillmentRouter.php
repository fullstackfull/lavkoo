<?php

namespace App\Services\Fulfillment;

use App\Services\Fulfillment\ProviderRegistry;
use App\Services\Fulfillment\Contracts\ProviderIdentity;
use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\HoldReason;
use App\Models\Order;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;
use App\Services\Fulfillment\Support\WhatACourierIsPaidIn;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Splits one customer order into one provider fulfillment per provider.
 *
 * Reads ONLY the immutable item snapshots, never a product's current provider, and groups strictly by
 * provider — an M5AZN line can only ever land in an M5AZN fulfillment and a Torod Point line in a Torod
 * Point one. The customer still has one order, one payment and one parent reference; each fulfillment
 * gets its own reference, status, tracking and API history.
 *
 * Idempotent and safe to run concurrently: the order row is locked, the fulfillment key is unique in the
 * database, and routing an order that is already routed creates nothing.
 */
class FulfillmentRouter
{
    public function __construct(
        private readonly ProviderConnectionManager $connections,
        private readonly OrderEligibility $eligibility,
        private readonly FulfillmentMoney $money,
        private readonly FulfillmentQueue $queue,
        private readonly ProviderRegistry $registry,
    ) {
    }

    /** @return list<int> ids of the fulfillments created by this call */
    public function route(int $orderId): array
    {
        $created = [];
        $dispatchable = [];

        DB::transaction(function () use ($orderId, &$created, &$dispatchable): void {
            $order = Order::whereKey($orderId)->lockForUpdate()->first();
            if ($order === null) {
                return;
            }

            $unrouted = ProviderFulfillmentItem::where('order_id', $orderId)
                ->where('status', ProviderFulfillmentItem::STATUS_UNROUTED)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($unrouted->isEmpty()) {
                return;
            }

            $allItems = $this->money->allItems($orderId);

            foreach ($unrouted->groupBy('provider') as $providerValue => $items) {
                $provider = $this->registry->identity((string) $providerValue);
                if ($provider === null) {
                    continue;
                }
                if (!$this->eligibility->isEligible($order, $this->connections->config($provider))) {
                    continue;
                }
                if ($this->hasLiveFulfillment($orderId, $provider)) {
                    continue;
                }

                $fulfillment = $this->create($order, $provider, $items, $allItems);
                if ($fulfillment !== null) {
                    $created[] = $fulfillment->id;
                    if ($fulfillment->status === FulfillmentStatus::Pending) {
                        $dispatchable[] = $fulfillment->id;
                    }
                }
            }
        });

        // Only what can actually be sent is queued; a held fulfillment waits for its cause to be fixed.
        foreach ($dispatchable as $fulfillmentId) {
            $this->queue->dispatchFulfillment($fulfillmentId);
        }

        return $created;
    }

    /**
     * Held for a money reason that has since been resolved (a rate now exists, mixed COD was enabled,
     * the customer paid): recompute the plan and release it if it is now safe.
     */
    public function reevaluateHold(int $fulfillmentId): bool
    {
        $released = false;

        DB::transaction(function () use ($fulfillmentId, &$released): void {
            $fulfillment = ProviderFulfillment::whereKey($fulfillmentId)->lockForUpdate()->first();
            if ($fulfillment === null || $fulfillment->status !== FulfillmentStatus::Held
                || !in_array($fulfillment->hold_reason, [HoldReason::MIXED_COD_BLOCKED, HoldReason::CURRENCY_UNCONVERTIBLE], true)) {
                return;
            }

            $order = Order::whereKey($fulfillment->order_id)->lockForUpdate()->first();
            $provider = $fulfillment->providerIdentity();
            if ($order === null || $provider === null) {
                return;
            }

            $money = $this->money->assess($order, $provider, $fulfillment->items()->get(), $this->money->allItems($order->id));
            if ($money->blockedReason !== null) {
                return;
            }

            $fulfillment->forceFill([
                'status' => FulfillmentStatus::Pending,
                'hold_reason' => null,
                'last_error_code' => null,
                'last_error_message' => null,
            ] + $money->columns())->save();

            $released = true;
        });

        if ($released) {
            $this->queue->dispatchFulfillment($fulfillmentId);
        }

        return $released;
    }

    /**
     * @param Collection<int, ProviderFulfillmentItem> $items
     * @param Collection<int, ProviderFulfillmentItem> $allItems
     */
    private function create(Order $order, ProviderIdentity $provider, Collection $items, Collection $allItems): ?ProviderFulfillment
    {
        $generation = (int) ProviderFulfillment::where('order_id', $order->id)->where('provider', $provider->code())->max('generation') + 1;
        $itemIds = $items->pluck('id')->sort()->values()->all();
        $money = $this->money->assess($order, $provider, $items, $allItems);

        try {
            $fulfillment = DB::transaction(static function () use ($order, $provider, $generation, $itemIds, $money): ProviderFulfillment {
                return ProviderFulfillment::create([
                    'reference' => $order->id . '-' . $provider->referenceCode() . $generation,
                    'order_id' => $order->id,
                    'provider' => $provider->code(),
                    'generation' => $generation,
                    'idempotency_key' => hash('sha256', implode('|', ['v1', $order->id, $provider->code(), $generation, implode(',', $itemIds)])),
                    'status' => $money->blockedReason === null ? FulfillmentStatus::Pending : FulfillmentStatus::Held,
                    'hold_reason' => $money->blockedReason,
                    'currency' => WhatACourierIsPaidIn::forOrder($order, $provider->code()),
                    'created_by_type' => 'system',
                ] + $money->columns());
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent router created this exact fulfillment first. Nothing to do.
            return null;
        }

        ProviderFulfillmentItem::whereIn('id', $itemIds)->update([
            'provider_fulfillment_id' => $fulfillment->id,
            'status' => ProviderFulfillmentItem::STATUS_ROUTED,
        ]);

        return $fulfillment;
    }

    /** A fulfillment for this order+provider that is not finished: new unrouted items must not start a second one. */
    private function hasLiveFulfillment(int $orderId, ProviderIdentity $provider): bool
    {
        return ProviderFulfillment::where('order_id', $orderId)
            ->where('provider', $provider->code())
            ->whereIn('status', FulfillmentStatus::activeValues())
            ->exists();
    }
}
