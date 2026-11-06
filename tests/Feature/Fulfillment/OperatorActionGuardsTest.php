<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\ProviderCode;
use App\Jobs\Fulfillment\CancelProviderFulfillment;
use App\Jobs\Fulfillment\DispatchProviderFulfillment;
use App\Jobs\Fulfillment\RouteOrderFulfillment;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;
use App\Services\Fulfillment\Data\SubmissionResult;
use App\Services\Fulfillment\FulfillmentRecovery;
use App\Services\Fulfillment\FulfillmentRouter;
use App\Services\Fulfillment\OperatorActions;
use App\Services\Fulfillment\ProviderOrderDispatcher;
use App\Services\Fulfillment\ReconciliationService;
use App\Services\Fulfillment\ShipmentTracker;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;

/**
 * A person overriding the safety rules must never be able to cause the very thing the rules prevent — a second
 * order at the provider — and must never overwrite a fact that arrived while they were looking at the page.
 */
class OperatorActionGuardsTest extends FulfillmentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableProvider(ProviderCode::M5azn);
        $this->enableProvider(ProviderCode::TorodPoint);
    }

    private function actions(): OperatorActions
    {
        return app(OperatorActions::class);
    }

    private function shipment(int $orderId = 100, FulfillmentStatus $status = FulfillmentStatus::Pending, array $columns = []): ProviderFulfillment
    {
        $this->makeOrder($orderId);
        $this->addLine($orderId, 2, 1, 35, ProviderCode::TorodPoint);
        app(FulfillmentRouter::class)->route($orderId);
        $shipment = $this->fulfillmentFor($orderId, ProviderCode::TorodPoint);
        $shipment->forceFill(['status' => $status] + $columns)->save();
        Queue::fake();

        return $shipment->refresh();
    }

    /** @return list<string> */
    private function providerCalls(): array
    {
        return $this->torod->calls;
    }

    // ---- release ----------------------------------------------------------------------------------------------

    public function test_releasing_a_failed_shipment_restarts_its_attempts_and_sends_it_now(): void
    {
        $shipment = $this->shipment(status: FulfillmentStatus::Failed, columns: ['attempts' => 5, 'reconcile_attempts' => 2]);

        $outcome = $this->actions()->release($shipment->id);

        $shipment->refresh();
        $this->assertSame(SubmissionResult::ACCEPTED, $outcome);
        $this->assertSame(FulfillmentStatus::Submitted, $shipment->status);
        $this->assertSame(1, $shipment->attempts, 'the five failed attempts were forgotten and this is the first of a fresh start');
        $this->assertSame(0, $shipment->reconcile_attempts);
        $this->assertCount(1, $this->providerCalls());
        Queue::assertNotPushed(DispatchProviderFulfillment::class);
    }

    public function test_releasing_a_shipment_a_worker_is_already_sending_changes_nothing(): void
    {
        $shipment = $this->shipment(status: FulfillmentStatus::Dispatching, columns: ['attempts' => 3, 'claim_token' => 'worker-1']);

        try {
            $this->actions()->release($shipment->id);
            $this->fail('A shipment in flight must not be releasable.');
        } catch (InvalidArgumentException) {
        }

        $shipment->refresh();
        $this->assertSame(FulfillmentStatus::Dispatching, $shipment->status);
        $this->assertSame(3, $shipment->attempts, 'the attempt counter of a live send is not reset');
        $this->assertSame('worker-1', $shipment->claim_token);
        Queue::assertNothingPushed();
    }

    public function test_a_hold_that_clears_with_its_cause_cannot_be_released_by_hand(): void
    {
        foreach ([HoldReason::MIXED_COD_BLOCKED, HoldReason::CURRENCY_UNCONVERTIBLE, HoldReason::ALREADY_WITH_PROVIDER] as $offset => $reason) {
            $shipment = $this->shipment(100 + $offset, FulfillmentStatus::Held, ['hold_reason' => $reason]);

            try {
                $this->actions()->release($shipment->id);
                $this->fail("Releasing a '{$reason}' hold must be refused.");
            } catch (InvalidArgumentException) {
            }

            $this->assertSame(FulfillmentStatus::Held, $shipment->refresh()->status);
        }
    }

    public function test_a_failure_inside_a_persons_send_lets_out_a_scrubbed_message_not_the_whole_one(): void
    {
        $shipment = $this->shipment(status: FulfillmentStatus::Failed);
        $this->app->instance(ProviderOrderDispatcher::class, new class extends ProviderOrderDispatcher {
            public function __construct()
            {
            }

            public function dispatch(int $fulfillmentId, bool $manual = false, ?bool $expectDryRun = null): string
            {
                throw new \RuntimeException("SQLSTATE[23000]: Duplicate entry 'sara@example.test' for key 'phone' (0551234567 / 0559876543)");
            }
        });

        try {
            app(OperatorActions::class)->release($shipment->id);
            $this->fail('the failure was swallowed');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('SQLSTATE[23000]', $exception->getMessage());
            foreach (['sara@example.test', '0551234567', '0559876543'] as $personal) {
                $this->assertStringNotContainsString($personal, $exception->getMessage());
            }
            $this->assertNull($exception->getPrevious(), 'nothing chains the original message to the report');
        }
    }

    public function test_a_provider_that_is_switched_off_still_reaches_the_controller_as_the_exception_it_handles(): void
    {
        $shipment = $this->shipment(status: FulfillmentStatus::Failed);
        $this->app->instance(ProviderOrderDispatcher::class, new class extends ProviderOrderDispatcher {
            public function __construct()
            {
            }

            public function dispatch(int $fulfillmentId, bool $manual = false, ?bool $expectDryRun = null): string
            {
                throw new \App\Services\Fulfillment\Exceptions\ProviderUnavailableException('Torod Point is switched off.');
            }
        });

        $this->expectException(\App\Services\Fulfillment\Exceptions\ProviderUnavailableException::class);
        $this->expectExceptionMessage('Torod Point is switched off.');

        app(OperatorActions::class)->release($shipment->id);
    }

    public function test_a_refusal_the_controller_expects_is_not_turned_into_a_scrubbed_failure(): void
    {
        $shipment = $this->shipment(status: FulfillmentStatus::Delivered);

        $this->expectException(InvalidArgumentException::class);

        $this->actions()->release($shipment->id);
    }

    // ---- confirm not created -----------------------------------------------------------------------------------

    public function test_confirming_not_created_starts_afresh_and_sends_it_again_on_the_spot(): void
    {
        $shipment = $this->shipment(status: FulfillmentStatus::ReconcilePending, columns: ['attempts' => 2, 'reconcile_attempts' => 6]);

        $outcome = $this->actions()->confirmNotCreated($shipment->id, 'checked the provider dashboard, no such order');

        $shipment->refresh();
        $this->assertSame(SubmissionResult::ACCEPTED, $outcome);
        $this->assertSame(FulfillmentStatus::Submitted, $shipment->status);
        $this->assertSame(1, $shipment->attempts, 'the earlier attempts were forgotten and this is the first of a fresh start');
        $this->assertDatabaseHas('provider_fulfillment_events', ['provider_fulfillment_id' => $shipment->id, 'source' => 'manual', 'outcome' => 'applied']);
        Queue::assertNotPushed(DispatchProviderFulfillment::class);
    }

    public function test_without_a_queue_worker_confirming_not_created_sends_the_shipment_on_the_spot(): void
    {
        $shipment = $this->shipment(status: FulfillmentStatus::ReconcilePending, columns: ['attempts' => 1]);
        config(['queue.default' => 'sync']);
        $this->torod->submitQueue = [SubmissionResult::accepted('O-AFTER-CONFIRM')];

        $this->actions()->confirmNotCreated($shipment->id, 'checked the provider dashboard, no such order');

        $this->assertSame(FulfillmentStatus::Submitted, $shipment->refresh()->status);
        $this->assertSame('O-AFTER-CONFIRM', $shipment->provider_reference);
        $this->assertCount(1, array_filter($this->providerCalls(), static fn (string $call): bool => str_starts_with($call, 'submit:')));
        Queue::assertNothingPushed();
    }

    public function test_confirming_not_created_is_refused_when_the_provider_already_gave_a_reference(): void
    {
        $shipment = $this->shipment(status: FulfillmentStatus::ReconcilePending, columns: ['provider_reference' => 'ORDER-77']);

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->actions()->confirmNotCreated($shipment->id, 'looked fine');
        } finally {
            $this->assertSame(FulfillmentStatus::ReconcilePending, $shipment->refresh()->status, 'a shipment the provider holds is never sent a second time');
            Queue::assertNothingPushed();
        }
    }

    public function test_confirming_not_created_after_reconciliation_proved_acceptance_does_not_undo_it(): void
    {
        $shipment = $this->shipment(status: FulfillmentStatus::ReconcilePending);
        $this->torod->reconcileQueue = [SubmissionResult::accepted('REF-FOUND-LATE')];
        $this->assertSame('accepted', app(ReconciliationService::class)->reconcile($shipment->id));

        try {
            $this->actions()->confirmNotCreated($shipment->id, 'the page was open before the reconcile ran');
            $this->fail('A confirmation made on stale information must be refused.');
        } catch (InvalidArgumentException) {
        }

        $shipment->refresh();
        $this->assertSame(FulfillmentStatus::Submitted, $shipment->status);
        $this->assertSame('REF-FOUND-LATE', $shipment->provider_reference);
        $this->assertSame([], array_filter($this->providerCalls(), static fn (string $call): bool => str_starts_with($call, 'submit:')), 'nothing was ever sent again');
    }

    // ---- link a reference --------------------------------------------------------------------------------------

    public function test_linking_a_reference_marks_the_shipment_submitted_and_honours_a_cancel_that_was_waiting(): void
    {
        $shipment = $this->shipment(status: FulfillmentStatus::ReconcilePending, columns: ['cancel_intent_at' => now()]);

        $this->actions()->linkReference($shipment->id, ' TP-5001 ', ' TRK-9 ', 'found it in the dashboard');

        $shipment->refresh();
        $this->assertSame(FulfillmentStatus::Submitted, $shipment->status);
        $this->assertSame('TP-5001', $shipment->provider_reference);
        $this->assertSame('TRK-9', $shipment->tracking_number);
        Queue::assertPushed(CancelProviderFulfillment::class, static fn (CancelProviderFulfillment $job): bool => $job->fulfillmentId === $shipment->id);
    }

    public function test_a_reference_already_carried_by_another_shipment_is_refused_cleanly(): void
    {
        $first = $this->shipment(100, FulfillmentStatus::ReconcilePending);
        $second = $this->shipment(101, FulfillmentStatus::ReconcilePending);
        $this->actions()->linkReference($first->id, 'TP-5001', null, 'checked');

        try {
            $this->actions()->linkReference($second->id, 'TP-5001', null, 'typo');
            $this->fail('Two shipments must never share one provider reference.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('already carries', $exception->getMessage());
        }

        $this->assertSame(FulfillmentStatus::ReconcilePending, $second->refresh()->status);
        $this->assertNull($second->provider_reference);
    }

    public function test_a_reference_cannot_be_linked_to_a_shipment_that_has_an_outcome_or_is_mid_send(): void
    {
        foreach ([FulfillmentStatus::Dispatching, FulfillmentStatus::Submitted, FulfillmentStatus::Delivered] as $offset => $status) {
            $shipment = $this->shipment(100 + $offset, $status);

            try {
                $this->actions()->linkReference($shipment->id, 'TP-' . $offset, null, 'checked');
                $this->fail("A {$status->value} shipment must not accept a linked reference.");
            } catch (InvalidArgumentException) {
            }

            $this->assertNull($shipment->refresh()->provider_reference);
        }
    }

    public function test_a_blank_reference_is_refused(): void
    {
        $shipment = $this->shipment(status: FulfillmentStatus::ReconcilePending);

        $this->expectException(InvalidArgumentException::class);
        $this->actions()->linkReference($shipment->id, '   ', null, 'checked');
    }

    // ---- redispatch --------------------------------------------------------------------------------------------

    public function test_a_cancelled_shipments_items_are_released_for_a_new_generation_only_with_a_stated_check(): void
    {
        $shipment = $this->shipment(status: FulfillmentStatus::Cancelled);

        $this->actions()->redispatchAsNewGeneration($shipment->id, 'M5AZN dashboard shows the original cancelled');

        $this->assertSame(ProviderFulfillmentItem::STATUS_UNROUTED, ProviderFulfillmentItem::where('order_id', 100)->firstOrFail()->status);
        $this->assertNull(ProviderFulfillmentItem::where('order_id', 100)->firstOrFail()->provider_fulfillment_id);
        Queue::assertPushed(RouteOrderFulfillment::class);
    }

    public function test_only_a_cancelled_shipment_can_be_redispatched(): void
    {
        $shipment = $this->shipment(status: FulfillmentStatus::Submitted);

        try {
            $this->actions()->redispatchAsNewGeneration($shipment->id, 'please');
            $this->fail('A live shipment must not be duplicated.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame($shipment->id, ProviderFulfillmentItem::where('order_id', 100)->firstOrFail()->provider_fulfillment_id, 'its items stay with it');
        Queue::assertNothingPushed();
    }

    // ---- the dispatcher's own last line of defence ---------------------------------------------------------------

    public function test_a_shipment_that_already_carries_a_provider_reference_is_never_sent(): void
    {
        $shipment = $this->shipment(columns: ['provider_reference' => 'TP-ALREADY']);

        $outcome = app(ProviderOrderDispatcher::class)->dispatch($shipment->id);

        $this->assertSame(ProviderOrderDispatcher::SKIPPED, $outcome);
        $shipment->refresh();
        $this->assertSame(FulfillmentStatus::Held, $shipment->status);
        $this->assertSame(HoldReason::ALREADY_WITH_PROVIDER, $shipment->hold_reason);
        $this->assertSame([], $this->providerCalls());
    }

    public function test_the_sweep_does_not_keep_picking_up_a_shipment_held_for_carrying_a_reference(): void
    {
        config(['queue.default' => 'sync']);
        $shipment = $this->shipment(columns: ['provider_reference' => 'TP-ALREADY']);
        app(ProviderOrderDispatcher::class)->dispatch($shipment->id);

        $this->assertSame(0, app(FulfillmentRecovery::class)->dispatchDue(), 'a hold that needs a person is not retried every minute');
        $this->assertSame([], $this->providerCalls());
    }

    public function test_tracker_release_reports_whether_it_applied(): void
    {
        $held = $this->shipment(100, FulfillmentStatus::Held, ['hold_reason' => HoldReason::MANUAL, 'attempts' => 4]);
        $moving = $this->shipment(101, FulfillmentStatus::InTransit, ['attempts' => 4]);
        $tracker = app(ShipmentTracker::class);

        $this->assertTrue($tracker->release($held->id, 'test', freshStart: true));
        $this->assertFalse($tracker->release($moving->id, 'test', freshStart: true));

        $this->assertSame(0, $held->refresh()->attempts);
        $this->assertSame(4, $moving->refresh()->attempts, 'a shipment that moved on keeps its counters');
    }
}
