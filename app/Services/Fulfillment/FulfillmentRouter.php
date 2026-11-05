<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\PaymentMode;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\Order;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;
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
        private readonly CodPolicy $codPolicy,
        private readonly ProviderOrderShare $shares,
        private readonly FulfillmentQueue $queue,
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

            $allItems = ProviderFulfillmentItem::where('order_id', $orderId)
                ->where('status', '!=', ProviderFulfillmentItem::STATUS_CANCELLED)
                ->get();
            $plan = $this->codPolicy->plan($order, $allItems);

            foreach ($unrouted->groupBy('provider') as $providerValue => $items) {
                $provider = ProviderCode::tryFrom((string) $providerValue);
                if ($provider === null) {
                    continue;
                }
                if (!$this->eligibility->isEligible($order, $this->connections->config($provider))) {
                    continue;
                }
                if ($this->hasLiveFulfillment($orderId, $provider)) {
                    continue;
                }

                $fulfillment = $this->create($order, $provider, $items, $plan, $allItems);
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
            $items = ProviderFulfillmentItem::where('order_id', $fulfillment->order_id)
                ->where('status', '!=', ProviderFulfillmentItem::STATUS_CANCELLED)
                ->get();
            if ($order === null) {
                return;
            }

            $plan = $this->codPolicy->plan($order, $items);
            if ($plan->blockedReason !== null) {
                return;
            }

            $cod = $plan->codByProvider[$fulfillment->provider] ?? 0.0;
            $fulfillment->forceFill([
                'status' => FulfillmentStatus::Pending,
                'hold_reason' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'payment_mode' => $plan->isCod && $cod > 0 ? PaymentMode::CashOnDelivery : PaymentMode::Prepaid,
                'cod_amount' => $plan->isCod ? $cod : 0,
                'total_amount' => $this->shares->totalFor($plan->orderTotal, (int) $fulfillment->order_id, $fulfillment->items()->get(), $items),
            ])->save();

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
    private function create(
        Order $order,
        ProviderCode $provider,
        Collection $items,
        \App\Services\Fulfillment\Data\CollectionPlan $plan,
        Collection $allItems,
    ): ?ProviderFulfillment {
        $generation = (int) ProviderFulfillment::where('order_id', $order->id)->where('provider', $provider->value)->max('generation') + 1;
        $itemIds = $items->pluck('id')->sort()->values()->all();
        $blocked = $plan->blockedReason;
        $cod = $plan->codByProvider[$provider->value] ?? 0.0;
        $itemsTotal = round((float) $items->sum('line_total'), 2);
        $total = $this->shares->totalFor($plan->orderTotal, $order->id, $items, $allItems);

        try {
            $fulfillment = DB::transaction(static function () use ($order, $provider, $generation, $itemIds, $blocked, $plan, $cod, $itemsTotal, $total): ProviderFulfillment {
                return ProviderFulfillment::create([
                    'reference' => $order->id . '-' . $provider->referenceCode() . $generation,
                    'order_id' => $order->id,
                    'provider' => $provider->value,
                    'generation' => $generation,
                    'idempotency_key' => hash('sha256', implode('|', ['v1', $order->id, $provider->value, $generation, implode(',', $itemIds)])),
                    'status' => $blocked === null ? FulfillmentStatus::Pending : FulfillmentStatus::Held,
                    'hold_reason' => $blocked,
                    'payment_mode' => $plan->isCod && $cod > 0 ? PaymentMode::CashOnDelivery : PaymentMode::Prepaid,
                    'cod_amount' => $plan->isCod ? $cod : 0,
                    'currency' => strtoupper((string) config('fulfillment.currency', 'SAR')),
                    'items_total' => $itemsTotal,
                    'shipping_allocation' => $total === null ? 0 : round($total - $itemsTotal, 2),
                    'total_amount' => $total ?? $itemsTotal,
                    'created_by_type' => 'system',
                ]);
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
    private function hasLiveFulfillment(int $orderId, ProviderCode $provider): bool
    {
        return ProviderFulfillment::where('order_id', $orderId)
            ->where('provider', $provider->value)
            ->whereIn('status', FulfillmentStatus::activeValues())
            ->exists();
    }
}
