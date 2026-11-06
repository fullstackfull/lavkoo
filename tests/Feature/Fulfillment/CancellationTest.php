<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Jobs\Fulfillment\CancelProviderFulfillment;
use App\Jobs\Fulfillment\DispatchProviderFulfillment;
use App\Jobs\Fulfillment\ReconcileProviderFulfillment;
use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\Data\CancelLease;
use App\Services\Fulfillment\Data\CancellationResult;
use App\Services\Fulfillment\Data\SubmissionResult;
use App\Services\Fulfillment\Exceptions\ProviderUnavailableException;
use App\Services\Fulfillment\FulfillmentAggregator;
use App\Services\Fulfillment\FulfillmentCancellation;
use App\Services\Fulfillment\FulfillmentRecovery;
use App\Services\Fulfillment\FulfillmentRouter;
use App\Services\Fulfillment\OperatorActions;
use App\Services\Fulfillment\ProviderOrderDispatcher;
use App\Services\Fulfillment\ReconciliationService;
use App\Services\Fulfillment\ShipmentTracker;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A cancellation is a request to a provider, and a provider may be unreachable, refuse, not support it at all, or
 * not yet hold the order. These tests pin down what is recorded in each case — above all that "requested" is written
 * only when the provider acknowledged, that a wish made too early is not lost, and that nothing asks twice at once.
 */
class CancellationTest extends FulfillmentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableProvider(ProviderCode::M5azn);
        $this->enableProvider(ProviderCode::TorodPoint);
    }

    private function cancellation(): FulfillmentCancellation
    {
        return app(FulfillmentCancellation::class);
    }

    private function pendingShipment(int $orderId = 100): ProviderFulfillment
    {
        $this->makeOrder($orderId);
        $this->addLine($orderId, 2, 1, 35, ProviderCode::TorodPoint);
        $this->addLine($orderId, 3, 2, 25, ProviderCode::TorodPoint);
        app(FulfillmentRouter::class)->route($orderId);

        return $this->fulfillmentFor($orderId, ProviderCode::TorodPoint);
    }

    /** A shipment the provider holds. */
    private function submittedShipment(int $orderId = 100): ProviderFulfillment
    {
        $shipment = $this->pendingShipment($orderId);
        app(ProviderOrderDispatcher::class)->dispatch($shipment->id);
        $this->torod->calls = [];

        return $shipment->refresh();
    }

    /** @return list<string> */
    private function cancelCalls(): array
    {
        return array_values(array_filter($this->torod->calls, static fn (string $call): bool => str_starts_with($call, 'cancel:')));
    }

    public function test_a_shipment_the_provider_never_received_is_cancelled_locally_and_nothing_is_called(): void
    {
        $shipment = $this->pendingShipment();

        $this->assertSame(FulfillmentCancellation::CANCELLED_LOCALLY, $this->cancellation()->cancel($shipment->id));

        $this->assertSame(FulfillmentStatus::Cancelled, $shipment->refresh()->status);
        $this->assertSame([], $this->torod->calls);
    }

    public function test_requested_is_recorded_only_when_the_provider_acknowledges_and_only_once(): void
    {
        $shipment = $this->submittedShipment();
        $this->assertFalse($shipment->isCancelRequested());

        $this->assertSame(CancellationResult::REQUESTED, $this->cancellation()->cancel($shipment->id));
        $shipment->refresh();

        $this->assertTrue($shipment->isCancelRequested());
        $this->assertSame(FulfillmentStatus::Submitted, $shipment->status, 'the status keeps following what the provider reports');
        $this->assertSame(1, $shipment->cancel_attempts);
        $this->assertNull($shipment->cancel_blocked_reason);

        $this->assertSame(CancelLease::ALREADY_REQUESTED, $this->cancellation()->cancel($shipment->id));
        $this->assertCount(1, $this->cancelCalls(), 'an acknowledged request is not sent again');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function refusals(): array
    {
        return [
            'refused' => [CancellationResult::REFUSED, 'Package already picked'],
            'unsupported' => [CancellationResult::UNSUPPORTED, 'Cancel it in the provider dashboard'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_provider_that_refuses_or_cannot_cancel_blocks_automatic_cancellation_and_claims_no_request(string $outcome, string $message): void
    {
        $shipment = $this->submittedShipment();
        $this->torod->cancelQueue = [$outcome === CancellationResult::REFUSED ? CancellationResult::refused($message) : CancellationResult::unsupported($message)];

        $this->assertSame($outcome, $this->cancellation()->cancel($shipment->id));
        $shipment->refresh();

        $this->assertNull($shipment->cancel_requested_at, 'a refusal is not a request');
        $this->assertTrue($shipment->isCancelBlocked());
        $this->assertStringContainsString($message, (string) $shipment->cancel_blocked_reason);
        $this->assertSame(FulfillmentAggregator::ATTENTION_REQUIRED, app(FulfillmentAggregator::class)->summarize($shipment->order_id)->status);

        $this->assertSame(CancelLease::BLOCKED, $this->cancellation()->cancel($shipment->id), 'a blocked cancellation is not retried by itself');
        $this->assertCount(1, $this->cancelCalls());
    }

    public function test_a_failed_attempt_is_retried_with_a_backoff_and_blocked_once_the_budget_is_spent(): void
    {
        config(['fulfillment.retry.max_attempts' => 3, 'fulfillment.retry.backoff_seconds' => [60, 300]]);
        $shipment = $this->submittedShipment();
        $this->torod->cancelQueue = [CancellationResult::failed('timeout'), CancellationResult::failed('timeout'), CancellationResult::failed('timeout')];

        $this->assertSame(CancellationResult::FAILED, $this->cancellation()->cancel($shipment->id));
        $shipment->refresh();
        $this->assertSame(1, $shipment->cancel_attempts);
        $this->assertEqualsWithDelta(60, now()->diffInSeconds($shipment->cancel_next_at, absolute: true), 2);
        $this->assertFalse($shipment->isCancelBlocked());

        $this->assertSame(CancelLease::WAITING, $this->cancellation()->cancel($shipment->id), 'not before the backoff has run out');
        $this->assertCount(1, $this->cancelCalls());

        $this->travel(2)->minutes();
        $this->assertSame(CancellationResult::FAILED, $this->cancellation()->cancel($shipment->id));
        $this->assertEqualsWithDelta(300, now()->diffInSeconds($shipment->refresh()->cancel_next_at, absolute: true), 2);

        $this->travel(6)->minutes();
        $this->assertSame(CancellationResult::FAILED, $this->cancellation()->cancel($shipment->id));
        $shipment->refresh();
        $this->assertSame(3, $shipment->cancel_attempts);
        $this->assertTrue($shipment->isCancelBlocked(), 'after the last attempt a person must take over');
        $this->assertNull($shipment->cancel_next_at);
        $this->assertCount(3, $this->cancelCalls());
    }

    public function test_an_operator_asking_again_lifts_a_block_and_restarts_the_retry_budget(): void
    {
        $shipment = $this->submittedShipment();
        $this->torod->cancelQueue = [CancellationResult::refused('Package already picked'), CancellationResult::requested()];
        $this->cancellation()->cancel($shipment->id);
        $this->assertTrue($shipment->refresh()->isCancelBlocked());

        $this->assertSame(CancellationResult::REQUESTED, $this->cancellation()->cancel($shipment->id, manual: true));

        $shipment->refresh();
        $this->assertFalse($shipment->isCancelBlocked());
        $this->assertTrue($shipment->isCancelRequested());
        $this->assertSame(1, $shipment->cancel_attempts);
    }

    public function test_an_unreachable_provider_gives_the_attempt_back_and_is_asked_again_later(): void
    {
        $shipment = $this->submittedShipment();
        $this->torod->cancelQueue = [new ProviderUnavailableException('integrations are switched off')];

        $this->assertSame(FulfillmentCancellation::UNAVAILABLE, $this->cancellation()->cancel($shipment->id));
        $shipment->refresh();

        $this->assertSame(0, $shipment->cancel_attempts, 'nothing was sent, so no attempt was spent');
        $this->assertNull($shipment->cancel_requested_at);
        $this->assertFalse($shipment->isCancelBlocked());
        $this->assertTrue($shipment->cancel_next_at->isFuture());

        $this->travel(6)->minutes();
        $this->assertSame(CancellationResult::REQUESTED, $this->cancellation()->cancel($shipment->id));
    }

    public function test_an_adapter_that_throws_counts_as_a_failed_attempt_instead_of_leaving_the_lease_hanging(): void
    {
        $shipment = $this->submittedShipment();
        $this->torod->cancelQueue = [new \RuntimeException('boom')];

        $this->assertSame(CancellationResult::FAILED, $this->cancellation()->cancel($shipment->id));

        $this->assertSame(1, $shipment->refresh()->cancel_attempts);
        $this->assertNotNull($shipment->cancel_next_at);
    }

    public function test_two_callers_cannot_both_hold_the_right_to_ask_the_provider(): void
    {
        $shipment = $this->submittedShipment();
        $tracker = app(ShipmentTracker::class);

        $first = $tracker->leaseCancelAttempt($shipment->id, manual: false);
        $second = $tracker->leaseCancelAttempt($shipment->id, manual: false);
        $operator = $tracker->leaseCancelAttempt($shipment->id, manual: true);

        $this->assertTrue($first->granted);
        $this->assertFalse($second->granted);
        $this->assertSame(CancelLease::WAITING, $second->reason);
        $this->assertFalse($operator->granted, 'an operator does not override an attempt that is in progress');
    }

    public function test_a_cancel_wanted_while_the_outcome_is_unknown_is_noted_then_sent_once_the_provider_is_proven_to_hold_the_order(): void
    {
        $shipment = $this->pendingShipment();
        app(ShipmentTracker::class)->awaitReconciliation($shipment->id, 'timeout', 'no answer', now()->addMinutes(2));

        $this->assertSame(FulfillmentCancellation::DEFERRED, $this->cancellation()->cancel($shipment->id));
        $shipment->refresh();
        $this->assertNotNull($shipment->cancel_intent_at);
        $this->assertFalse($shipment->wantsCancel(), 'the provider is not yet known to hold it');
        $this->assertSame([], $this->torod->calls);

        $this->torod->reconcileQueue = [SubmissionResult::accepted('REF-FOUND')];
        $this->assertSame('accepted', app(ReconciliationService::class)->reconcile($shipment->id));

        $this->assertTrue($shipment->refresh()->wantsCancel());
        Queue::assertPushed(CancelProviderFulfillment::class, static fn (CancelProviderFulfillment $job): bool => $job->fulfillmentId === $shipment->id);

        $this->assertSame(CancellationResult::REQUESTED, $this->cancellation()->cancel($shipment->id));
        $this->assertCount(1, $this->cancelCalls());
    }

    public function test_a_cancel_wanted_in_the_middle_of_a_send_is_honoured_when_that_send_is_accepted(): void
    {
        $shipment = $this->pendingShipment();
        $this->torod->whileSubmitting = fn (ProviderFulfillment $sending) => $this->assertSame(
            FulfillmentCancellation::DEFERRED,
            $this->cancellation()->cancel($sending->id),
        );

        app(ProviderOrderDispatcher::class)->dispatch($shipment->id);

        $this->assertSame(FulfillmentStatus::Submitted, $shipment->refresh()->status);
        $this->assertNotNull($shipment->cancel_intent_at);
        Queue::assertPushed(CancelProviderFulfillment::class, static fn (CancelProviderFulfillment $job): bool => $job->fulfillmentId === $shipment->id);
    }

    public function test_the_sweep_cancels_a_closed_orders_shipment_at_the_provider_exactly_once(): void
    {
        config(['queue.default' => 'sync']);
        $shipment = $this->submittedShipment();
        DB::table('orders')->where('id', 100)->update(['order_status' => 'canceled']);

        $this->assertSame(1, app(FulfillmentRecovery::class)->cancelForClosedOrders());
        $this->assertSame(0, app(FulfillmentRecovery::class)->cancelForClosedOrders(), 'asked and acknowledged: nothing left to do');

        $this->assertCount(1, $this->cancelCalls());
        $this->assertTrue($shipment->refresh()->isCancelRequested());
    }

    public function test_the_sweep_retries_a_failed_cancellation_when_it_is_due_and_leaves_a_blocked_one_alone(): void
    {
        config(['queue.default' => 'sync', 'fulfillment.retry.backoff_seconds' => [60]]);
        $retrying = $this->submittedShipment(100);
        $blocked = $this->submittedShipment(101);
        DB::table('orders')->whereIn('id', [100, 101])->update(['order_status' => 'canceled']);
        $this->torod->cancelQueue = [CancellationResult::failed('timeout'), CancellationResult::refused('Package already picked'), CancellationResult::requested()];

        $this->assertSame(2, app(FulfillmentRecovery::class)->cancelForClosedOrders());
        $this->assertTrue($blocked->refresh()->isCancelBlocked());
        $this->assertSame(0, app(FulfillmentRecovery::class)->cancelForClosedOrders(), 'one is waiting out its backoff, the other needs a person');

        $this->travel(2)->minutes();
        $this->assertSame(1, app(FulfillmentRecovery::class)->cancelForClosedOrders());
        $this->assertTrue($retrying->refresh()->isCancelRequested());
        $this->assertCount(3, $this->cancelCalls());
    }

    public function test_shipments_that_are_only_waiting_do_not_use_up_the_sweeps_limit(): void
    {
        config(['queue.default' => 'sync']);
        foreach ([100, 101, 102] as $orderId) {
            $waiting = $this->pendingShipment($orderId);
            app(ShipmentTracker::class)->awaitReconciliation($waiting->id, 'timeout', 'no answer', now()->addHour());
        }
        $cancellable = $this->submittedShipment(103);
        DB::table('orders')->whereIn('id', [100, 101, 102, 103])->update(['order_status' => 'canceled']);

        $recovery = app(FulfillmentRecovery::class);
        $this->assertSame(4, $recovery->cancelForClosedOrders(limit: 4), 'the first pass writes the wish down for the three waiting ones, and cancels the fourth');
        $this->assertTrue($cancellable->refresh()->isCancelRequested());

        $this->assertSame(0, $recovery->cancelForClosedOrders(limit: 1), 'once noted, the waiting ones are no longer picked');
    }

    public function test_dispatch_reconcile_and_cancel_jobs_share_one_lock_per_shipment(): void
    {
        $jobs = [new DispatchProviderFulfillment(7), new ReconcileProviderFulfillment(7), new CancelProviderFulfillment(7)];
        $keys = array_map(static function (object $job): string {
            /** @var WithoutOverlapping $middleware */
            $middleware = $job->middleware()[0];

            return $middleware->getLockKey($job);
        }, $jobs);

        $this->assertCount(1, array_unique($keys), 'a cancel must not run beside a send still in flight');

        $other = (new CancelProviderFulfillment(8))->middleware()[0]->getLockKey(new CancelProviderFulfillment(8));
        $this->assertNotSame($keys[0], $other, 'different shipments do not block each other');
    }

    public function test_an_operators_cancel_runs_on_the_spot_without_a_queue_worker_and_reports_what_happened(): void
    {
        config(['queue.default' => 'sync']);
        $shipment = $this->submittedShipment();

        $outcome = app(OperatorActions::class)->requestCancel($shipment->id);

        $this->assertSame(CancellationResult::REQUESTED, $outcome);
        $this->assertCount(1, $this->cancelCalls());
    }

    public function test_an_operators_cancel_is_queued_as_a_manual_request_when_there_is_a_queue(): void
    {
        $shipment = $this->submittedShipment();

        $this->assertSame(OperatorActions::CANCEL_QUEUED, app(OperatorActions::class)->requestCancel($shipment->id));

        Queue::assertPushed(CancelProviderFulfillment::class, static fn (CancelProviderFulfillment $job): bool => $job->fulfillmentId === $shipment->id && $job->manual);
        $this->assertSame([], $this->cancelCalls());
    }

    public function test_a_provider_reported_cancellation_finishes_the_shipment_whatever_the_request_flags_say(): void
    {
        $shipment = $this->submittedShipment();
        $this->cancellation()->cancel($shipment->id);

        app(ShipmentTracker::class)->applyProviderStatus($shipment->id, FulfillmentStatus::Cancelled, now(), 'webhook', 'cancelled');

        $shipment->refresh();
        $this->assertSame(FulfillmentStatus::Cancelled, $shipment->status);
        $this->assertFalse($shipment->isCancelRequested(), 'a finished shipment has nothing left to request');
    }
}
