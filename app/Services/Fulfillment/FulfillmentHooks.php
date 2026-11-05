<?php

namespace App\Services\Fulfillment;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The three places existing code tells the fulfillment pipeline "something changed".
 *
 * Each is a latency optimisation, not the source of truth: the scheduled sweeps find the same work by
 * state, so a missed hook costs seconds, not correctness. And each is best-effort by design — a failure
 * here must never fail a checkout or an admin's status change, so every call is wrapped.
 */
class FulfillmentHooks
{
    public function __construct(private readonly FulfillmentQueue $queue)
    {
    }

    /** @param list<int|string> $orderIds orders just committed by the checkout */
    public function ordersPlaced(array $orderIds): void
    {
        $this->safely(function () use ($orderIds): void {
            $withProviderItems = DB::table('provider_fulfillment_items')->whereIn('order_id', $orderIds)->distinct()->pluck('order_id');
            foreach ($withProviderItems as $orderId) {
                $this->queue->dispatchRoute((int) $orderId);
            }
        });
    }

    /**
     * An order's status or payment status was written (admin panel, vendor panel, API…).
     *
     * @param array<string, mixed> $changes the columns that were written
     */
    public function orderChanged(int|string $orderId, array $changes): void
    {
        if (!array_key_exists('order_status', $changes) && !array_key_exists('payment_status', $changes)) {
            return;
        }

        $this->safely(function () use ($orderId, $changes): void {
            $hasProviderWork = DB::table('provider_fulfillment_items')->where('order_id', $orderId)->exists();
            if (!$hasProviderWork) {
                return;
            }

            $this->queue->dispatchRoute((int) $orderId);

            if (in_array($changes['order_status'] ?? null, OrderEligibility::CLOSED_STATUSES, true)) {
                $ids = DB::table('provider_fulfillments')->where('order_id', $orderId)->whereNotIn('status', \App\Enums\Fulfillment\FulfillmentStatus::terminalValues())->pluck('id');
                foreach ($ids as $id) {
                    $this->queue->dispatchCancel((int) $id);
                }
            }
        });
    }

    private function safely(callable $work): void
    {
        try {
            if (FulfillmentSchema::ready()) {
                $work();
            }
        } catch (Throwable $exception) {
            Log::warning('Fulfillment hook failed; the scheduled sweep will pick the order up.', ['error' => $exception->getMessage()]);
        }
    }
}
