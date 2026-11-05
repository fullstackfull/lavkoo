<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;
use App\Models\ProviderSyncState;
use App\Services\Fulfillment\Exceptions\ProviderUnavailableException;
use App\Services\Fulfillment\Support\PiiRedactor;
use App\Services\Fulfillment\Support\ProviderSyncRecorder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The scheduled safety net behind every event-driven path.
 *
 * Hooks and queued jobs make things fast; these sweeps make them true. An order whose status changed
 * through a code path that never fired a hook, a worker that died mid-request, a webhook that never
 * arrived, a hold whose cause has been fixed — each is found by state in the database and brought
 * forward. Every sweep is idempotent, so running it twice, or alongside the event-driven path, is safe.
 *
 * Under the `sync` queue driver there is no worker, so the sweep does the work itself; it runs in the
 * scheduler's console process, never in a customer's request.
 */
class FulfillmentRecovery
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ProviderConnectionManager $connections,
        private readonly FulfillmentRouter $router,
        private readonly ProviderOrderDispatcher $dispatcher,
        private readonly ReconciliationService $reconciliation,
        private readonly FulfillmentCancellation $cancellation,
        private readonly WebhookEventProcessor $events,
        private readonly ShipmentTracker $tracker,
        private readonly FulfillmentQueue $queue,
        private readonly ProviderSyncRecorder $sync,
    ) {
    }

    /** Route orders that have unrouted provider items and have become eligible. */
    public function routeEligibleOrders(int $limit = 200): int
    {
        $statuses = (array) config('fulfillment.dispatch.order_statuses', ['confirmed', 'processing']);
        foreach (ProviderCode::cases() as $provider) {
            $statuses = array_merge($statuses, $this->connections->config($provider)->list('dispatch_order_statuses'));
        }

        $orderIds = $this->rotatingIds('route', 'items.order_id', $limit, static fn () => DB::table('provider_fulfillment_items as items')
            ->join('orders', 'orders.id', '=', 'items.order_id')
            ->where('items.status', ProviderFulfillmentItem::STATUS_UNROUTED)
            ->whereIn('orders.order_status', array_values(array_unique($statuses)))
            ->distinct());

        $created = 0;
        foreach ($orderIds as $orderId) {
            $created += count($this->router->route((int) $orderId));
        }

        return $created;
    }

    /** Items of orders that were cancelled before being routed will never ship: stop scanning them. */
    public function closeUnroutedItemsOfClosedOrders(): int
    {
        $orderIds = DB::table('provider_fulfillment_items as items')
            ->join('orders', 'orders.id', '=', 'items.order_id')
            ->where('items.status', ProviderFulfillmentItem::STATUS_UNROUTED)
            ->whereIn('orders.order_status', OrderEligibility::CLOSED_STATUSES)
            ->distinct()
            ->pluck('items.order_id');

        return $orderIds->isEmpty() ? 0 : ProviderFulfillmentItem::whereIn('order_id', $orderIds)
            ->where('status', ProviderFulfillmentItem::STATUS_UNROUTED)
            ->update(['status' => ProviderFulfillmentItem::STATUS_CANCELLED]);
    }

    /**
     * A cancelled parent order cancels its OWN provider fulfillments — each independently, nothing else. Also the
     * retry path for every cancellation that is wanted but not done: a failed ask that is due again, an acceptance
     * that arrived after the wish was noted, an ask the queue never delivered.
     *
     * A row only qualifies while there is something to do. One that is waiting for a send to resolve, was asked and is
     * waiting for the provider to report, or needs a person is left out — otherwise a handful of such rows would fill the
     * `limit` every minute and starve the ones that can still be cancelled.
     */
    public function cancelForClosedOrders(int $limit = 100): int
    {
        $ids = DB::table('provider_fulfillments as pf')
            ->join('orders', 'orders.id', '=', 'pf.order_id')
            ->whereIn('pf.status', FulfillmentStatus::activeValues())
            ->where(static fn ($wanted) => $wanted
                ->whereIn('orders.order_status', OrderEligibility::CLOSED_STATUSES)
                ->orWhereNotNull('pf.cancel_intent_at'))
            ->where(static fn ($actionable) => $actionable
                ->whereIn('pf.status', FulfillmentStatus::cancellableLocallyValues())
                ->orWhere(static fn ($unknown) => $unknown
                    ->whereIn('pf.status', FulfillmentStatus::sendOutcomeUnknownValues())
                    ->whereNull('pf.cancel_intent_at'))
                ->orWhere(static fn ($withProvider) => $withProvider
                    ->whereNotIn('pf.status', FulfillmentStatus::preSubmissionValues())
                    ->whereNull('pf.cancel_requested_at')
                    ->whereNull('pf.cancel_blocked_reason')
                    ->where(static fn ($due) => $due->whereNull('pf.cancel_next_at')->orWhere('pf.cancel_next_at', '<=', now()))))
            ->orderBy('pf.id')
            ->limit($limit)
            ->pluck('pf.id');

        foreach ($ids as $id) {
            $this->inlineOrQueue(
                fn () => $this->cancellation->cancel((int) $id),
                fn () => $this->queue->dispatchCancel((int) $id),
            );
        }

        return $ids->count();
    }

    /** Pending, due retries, and holds whose cause may have been fixed. */
    public function dispatchDue(int $limit = 100): int
    {
        $ids = $this->rotatingIds('dispatch', 'id', $limit, static fn () => ProviderFulfillment::query()
            ->where(static function ($query): void {
                $query->where('status', FulfillmentStatus::Pending->value)
                    ->orWhere(static function ($retry): void {
                        $retry->where('status', FulfillmentStatus::RetryWait->value)
                            ->where(static fn ($due) => $due->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()));
                    })
                    ->orWhere(static function ($held): void {
                        $held->where('status', FulfillmentStatus::Held->value)->whereIn('hold_reason', ProviderOrderDispatcher::AUTO_RELEASABLE);
                    });
            }));

        foreach ($ids as $id) {
            $this->inlineOrQueue(
                fn () => $this->dispatcher->dispatch((int) $id),
                fn () => $this->queue->dispatchFulfillment((int) $id),
            );
        }

        return $ids->count();
    }

    /**
     * A worker that claimed a fulfillment and never settled it (killed, timed out, crashed) may or may not
     * have reached the provider. Unknown ⇒ reconcile; it is never simply re-sent.
     */
    public function recoverStuckDispatching(): int
    {
        $cutoff = now()->subMinutes((int) config('fulfillment.retry.stuck_dispatching_minutes', 10));
        $stuck = ProviderFulfillment::where('status', FulfillmentStatus::Dispatching->value)->where('claimed_at', '<', $cutoff)->get();

        foreach ($stuck as $fulfillment) {
            $this->tracker->awaitReconciliation($fulfillment->id, 'worker_lost_mid_request', 'The worker holding this dispatch did not finish; the provider may or may not have received it.', now());
        }

        return $stuck->count();
    }

    public function reconcileDue(int $limit = 50): int
    {
        $ids = ProviderFulfillment::where('status', FulfillmentStatus::ReconcilePending->value)
            ->where(static fn ($query) => $query->whereNull('last_error_code')->orWhere('last_error_code', '!=', 'manual_reconciliation_required'))
            ->where(static fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            $this->inlineOrQueue(
                fn () => $this->reconciliation->reconcile((int) $id),
                fn () => $this->queue->dispatchReconcile((int) $id),
            );
        }

        return $ids->count();
    }

    /** Holds caused by money rules that may have been resolved since (a rate was added, the customer paid). */
    public function reevaluateMoneyHolds(): int
    {
        $ids = ProviderFulfillment::where('status', FulfillmentStatus::Held->value)
            ->whereIn('hold_reason', [HoldReason::MIXED_COD_BLOCKED, HoldReason::CURRENCY_UNCONVERTIBLE])
            ->pluck('id');

        $released = 0;
        foreach ($ids as $id) {
            $released += $this->router->reevaluateHold((int) $id) ? 1 : 0;
        }

        return $released;
    }

    public function retryUnmatchedEvents(): int
    {
        return $this->events->retryUnmatched();
    }

    /**
     * Ask each provider where its open fulfillments stand, for the ones we have not heard about in a
     * while — the net under a webhook that never arrived. Reads only.
     */
    public function pollOpenStatuses(int $perProvider = 50): int
    {
        $polled = 0;

        foreach (ProviderCode::cases() as $code) {
            if (!$this->connections->canCall($code)) {
                continue;
            }

            $adapter = $this->registry->adapter($code);
            $open = ProviderFulfillment::where('provider', $code->value)
                ->whereIn('status', [
                    FulfillmentStatus::Submitted->value, FulfillmentStatus::Processing->value, FulfillmentStatus::InTransit->value,
                    FulfillmentStatus::OutForDelivery->value, FulfillmentStatus::DeliveryPostponed->value, FulfillmentStatus::DeliveryFailed->value,
                    FulfillmentStatus::PartiallyDelivered->value, FulfillmentStatus::NeedsAttention->value,
                ])
                ->whereNotNull('provider_reference')
                ->where(static fn ($query) => $query->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subMinutes(30)))
                ->orderBy('last_synced_at')
                ->limit($perProvider)
                ->get();

            foreach ($open as $fulfillment) {
                try {
                    $snapshot = $adapter->fetchStatus($fulfillment);
                } catch (ProviderUnavailableException) {
                    continue 2;
                } catch (Throwable $exception) {
                    Log::warning('Status poll failed.', ['fulfillment_id' => $fulfillment->id, 'error' => PiiRedactor::scrub($exception->getMessage())]);
                    continue;
                }

                DB::table('provider_fulfillments')->where('id', $fulfillment->id)->update(['last_synced_at' => now()]);
                $polled++;

                if ($snapshot === null) {
                    continue;
                }

                $status = $adapter->mapStatus($snapshot->providerStatus);
                if ($status !== null) {
                    $this->tracker->applyProviderStatus($fulfillment->id, $status, $snapshot->observedAt, 'poll', $snapshot->providerStatus);
                } else {
                    $this->tracker->noteUnmappedStatus($fulfillment->id, $snapshot->providerStatus);
                }
            }

            $this->sync->reconciled($code->value);
        }

        return $polled;
    }

    /**
     * Every sweep, each isolated from the others. A sweep that throws (one bad order, a database hiccup) is logged
     * and reported as -1, and the rest still run: otherwise a single poison order would stop dispatching,
     * reconciliation and cancellation for everyone, every minute, until someone noticed. The heartbeat
     * records the run either way, so a scheduler that stopped is visible in the Integration Center.
     *
     * @return array<string, int> sweep name => items handled, or -1 when that sweep failed
     */
    public function runAll(): array
    {
        $counts = [];
        foreach ([
            'closed_unrouted_items' => fn (): int => $this->closeUnroutedItemsOfClosedOrders(),
            'routed' => fn (): int => $this->routeEligibleOrders(),
            'money_holds_released' => fn (): int => $this->reevaluateMoneyHolds(),
            'cancelled_for_closed_orders' => fn (): int => $this->cancelForClosedOrders(),
            'stuck_recovered' => fn (): int => $this->recoverStuckDispatching(),
            'dispatched' => fn (): int => $this->dispatchDue(),
            'reconciled' => fn (): int => $this->reconcileDue(),
            'unmatched_events_retried' => fn (): int => $this->retryUnmatchedEvents(),
        ] as $name => $sweep) {
            $counts[$name] = $this->isolated($name, $sweep);
        }

        $failed = array_keys(array_filter($counts, static fn (int $handled): bool => $handled < 0));
        $failed === []
            ? $this->sync->succeeded(ProviderSyncRecorder::SYSTEM, ProviderSyncRecorder::SWEEP, $counts)
            : $this->sync->failed(ProviderSyncRecorder::SYSTEM, ProviderSyncRecorder::SWEEP, 'Sweeps failed: ' . implode(', ', $failed));

        return $counts;
    }

    /** @param callable(): int $sweep */
    private function isolated(string $name, callable $sweep): int
    {
        try {
            return $sweep();
        } catch (Throwable $exception) {
            Log::error('Fulfillment sweep failed; the other sweeps continue.', ['sweep' => $name, 'error' => PiiRedactor::scrub($exception->getMessage())]);

            return -1;
        }
    }

    /**
     * Up to `$limit` ids of the rows a sweep looks at, resuming after where the previous run stopped and wrapping round.
     *
     * A fixed "first N by id" would let N rows that cannot progress (an order that is paused, a provider switched off, a
     * hold nobody fixed) be re-read every minute and starve every row behind them. Rotating means each row is reached
     * within ceil(rows / limit) runs however many are stuck.
     *
     * @param callable(): mixed $query builds a fresh query each call (query builders are mutable)
     * @return Collection<int, int>
     */
    private function rotatingIds(string $sweep, string $column, int $limit, callable $query): Collection
    {
        $state = ProviderSyncState::firstOrNew(['provider' => ProviderSyncRecorder::SYSTEM, 'capability' => 'cursor:' . $sweep]);
        $after = (int) ($state->cursor['after'] ?? 0);

        $ids = $query()->where($column, '>', $after)->orderBy($column)->limit($limit)->pluck($column)->map(static fn ($id): int => (int) $id);
        if ($ids->count() < $limit) {
            $ids = $ids->merge($query()->where($column, '<=', $after)->orderBy($column)->limit($limit - $ids->count())->pluck($column)->map(static fn ($id): int => (int) $id));
        }

        if ($ids->isNotEmpty()) {
            $state->forceFill(['cursor' => ['after' => $ids->last()]])->save();
        }

        return $ids->unique()->values();
    }

    private function inlineOrQueue(callable $inline, callable $queued): void
    {
        $this->queue->runsInline() ? $inline() : $queued();
    }
}
