<?php

namespace App\Services\Fulfillment;

use App\Jobs\Fulfillment\CancelProviderFulfillment;
use App\Jobs\Fulfillment\DispatchProviderFulfillment;
use App\Jobs\Fulfillment\ProcessProviderEvent;
use App\Jobs\Fulfillment\ReconcileProviderFulfillment;
use App\Jobs\Fulfillment\RouteOrderFulfillment;
use Illuminate\Foundation\Bus\PendingDispatch;

/**
 * Hands work to the queue — and only to a real queue.
 *
 * On the `sync` driver a "dispatched" job would run inside the request that dispatched it, which for a
 * checkout means a provider HTTP call inside the customer's request. So under `sync` this does nothing,
 * and the work is picked up by the scheduled sweeps (`fulfillment:route`, `fulfillment:recover`)
 * instead. A real queue is recommended; the scheduler alone is enough to be correct, just slower.
 */
class FulfillmentQueue
{
    public function runsInline(): bool
    {
        $connection = config('fulfillment.queue.connection') ?: config('queue.default');

        return $connection === 'sync' || config("queue.connections.{$connection}.driver") === 'sync';
    }

    public function dispatchFulfillment(int $fulfillmentId, int $delaySeconds = 0, bool $manual = false): void
    {
        if (!$this->runsInline()) {
            $this->configure(DispatchProviderFulfillment::dispatch($fulfillmentId, $manual), $delaySeconds);
        }
    }

    public function dispatchReconcile(int $fulfillmentId, int $delaySeconds = 0): void
    {
        if (!$this->runsInline()) {
            $this->configure(ReconcileProviderFulfillment::dispatch($fulfillmentId), $delaySeconds);
        }
    }

    public function dispatchEvent(int $eventId): void
    {
        if (!$this->runsInline()) {
            $this->configure(ProcessProviderEvent::dispatch($eventId));
        }
    }

    public function dispatchRoute(int $orderId): void
    {
        if (!$this->runsInline()) {
            $this->configure(RouteOrderFulfillment::dispatch($orderId));
        }
    }

    public function dispatchCancel(int $fulfillmentId, bool $manual = false): void
    {
        if (!$this->runsInline()) {
            $this->configure(CancelProviderFulfillment::dispatch($fulfillmentId, $manual));
        }
    }

    /** The job is queued when this PendingDispatch goes out of scope at the end of the call. */
    private function configure(PendingDispatch $pending, int $delaySeconds = 0): void
    {
        $pending
            ->onConnection(config('fulfillment.queue.connection') ?: null)
            ->onQueue((string) config('fulfillment.queue.name', 'fulfillment'));

        if ($delaySeconds > 0) {
            $pending->delay(now()->addSeconds($delaySeconds));
        }
    }
}
