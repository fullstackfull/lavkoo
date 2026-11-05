<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\ProviderCode;
use App\Jobs\Fulfillment\DispatchProviderFulfillment;
use App\Jobs\Fulfillment\ReconcileProviderFulfillment;
use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\Data\PreflightResult;
use App\Services\Fulfillment\Data\SubmissionResult;
use App\Services\Fulfillment\Exceptions\ProviderUnavailableException;
use App\Services\Fulfillment\FulfillmentRecovery;
use App\Services\Fulfillment\FulfillmentRouter;
use App\Services\Fulfillment\ProviderOrderDispatcher;
use App\Services\Fulfillment\ReconciliationService;
use App\Services\Fulfillment\ShipmentTracker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class DispatcherTest extends FulfillmentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableProvider(ProviderCode::M5azn);
        $this->enableProvider(ProviderCode::TorodPoint);
    }

    private function dispatcher(): ProviderOrderDispatcher
    {
        return app(ProviderOrderDispatcher::class);
    }

    private function torodFulfillment(int $orderId = 100, array $orderOverrides = []): ProviderFulfillment
    {
        $this->makeOrder($orderId, $orderOverrides);
        $this->addLine($orderId, 2, 1, 35, ProviderCode::TorodPoint);
        $this->addLine($orderId, 3, 2, 25, ProviderCode::TorodPoint);
        app(FulfillmentRouter::class)->route($orderId);

        return $this->fulfillmentFor($orderId, ProviderCode::TorodPoint);
    }

    public function test_an_accepted_dispatch_records_the_provider_reference_and_submits(): void
    {
        $fulfillment = $this->torodFulfillment();
        $this->torod->submitQueue = [SubmissionResult::accepted('O001', providerInternalId: '55', trackingNumber: 'TRK1', providerCost: 12.5)];

        $outcome = $this->dispatcher()->dispatch($fulfillment->id);

        $fresh = $fulfillment->fresh();
        $this->assertSame(SubmissionResult::ACCEPTED, $outcome);
        $this->assertSame(FulfillmentStatus::Submitted, $fresh->status);
        $this->assertSame('O001', $fresh->provider_reference);
        $this->assertSame('55', $fresh->provider_internal_id);
        $this->assertSame('TRK1', $fresh->tracking_number);
        $this->assertSame(1, $fresh->attempts);
        $this->assertNotNull($fresh->submitted_at);
        $this->assertNull($fresh->claim_token);
    }

    public function test_the_provider_is_asked_exactly_once_however_many_times_the_job_runs(): void
    {
        $fulfillment = $this->torodFulfillment();

        foreach (range(1, 5) as $ignored) {
            $this->dispatcher()->dispatch($fulfillment->id);
        }

        $this->assertCount(1, array_filter($this->torod->calls, static fn (string $call): bool => str_starts_with($call, 'submit:')));
    }

    public function test_the_provider_receives_exactly_its_own_items_and_never_another_providers(): void
    {
        $this->makeOrder(100);
        $this->addLine(100, 1, 1, 40, ProviderCode::M5azn);
        $this->addLine(100, 2, 1, 35, ProviderCode::TorodPoint);
        app(FulfillmentRouter::class)->route(100);

        $this->dispatcher()->dispatch($this->fulfillmentFor(100, ProviderCode::M5azn)->id);
        $this->dispatcher()->dispatch($this->fulfillmentFor(100, ProviderCode::TorodPoint)->id);

        $this->assertSame(['submit:100-MZ1:1'], $this->m5azn->calls);
        $this->assertSame(['submit:100-TP1:2'], $this->torod->calls);
    }

    public function test_a_timeout_after_sending_is_never_answered_with_a_second_send(): void
    {
        $fulfillment = $this->torodFulfillment();
        $this->torod->submitQueue = [SubmissionResult::ambiguous('timeout', 'no response')];

        $this->dispatcher()->dispatch($fulfillment->id);
        $this->dispatcher()->dispatch($fulfillment->id);
        $this->dispatcher()->dispatch($fulfillment->id);

        $this->assertSame(FulfillmentStatus::ReconcilePending, $fulfillment->fresh()->status);
        $this->assertCount(1, array_filter($this->torod->calls, static fn (string $call): bool => str_starts_with($call, 'submit:')), 'an ambiguous submit must never be re-sent');
        Queue::assertPushed(ReconcileProviderFulfillment::class);
    }

    public function test_reconciliation_that_finds_the_order_records_acceptance_without_resending(): void
    {
        $fulfillment = $this->torodFulfillment();
        $this->torod->submitQueue = [SubmissionResult::ambiguous('timeout', 'no response')];
        $this->dispatcher()->dispatch($fulfillment->id);
        $this->torod->reconcileQueue = [SubmissionResult::accepted('O-FOUND')];

        $result = app(ReconciliationService::class)->reconcile($fulfillment->id);

        $fresh = $fulfillment->fresh();
        $this->assertSame('accepted', $result);
        $this->assertSame(FulfillmentStatus::Submitted, $fresh->status);
        $this->assertSame('O-FOUND', $fresh->provider_reference);
        $this->assertCount(1, array_filter($this->torod->calls, static fn (string $call): bool => str_starts_with($call, 'submit:')));
    }

    public function test_reconciliation_that_cannot_find_the_order_backs_off_and_eventually_asks_for_a_human(): void
    {
        $fulfillment = $this->torodFulfillment();
        $this->torod->submitQueue = [SubmissionResult::ambiguous('timeout', 'no response')];
        $this->dispatcher()->dispatch($fulfillment->id);

        foreach (range(1, 6) as $ignored) {
            DB::table('provider_fulfillments')->where('id', $fulfillment->id)->update(['next_attempt_at' => now()->subMinute()]);
            app(ReconciliationService::class)->reconcile($fulfillment->id);
        }

        $fresh = $fulfillment->fresh();
        $this->assertSame(FulfillmentStatus::ReconcilePending, $fresh->status, 'not found is not proof it does not exist');
        $this->assertSame('manual_reconciliation_required', $fresh->last_error_code);
        $this->assertNull($fresh->next_attempt_at);
        $this->assertCount(1, array_filter($this->torod->calls, static fn (string $call): bool => str_starts_with($call, 'submit:')));
    }

    public function test_a_late_acceptance_is_still_recorded_after_recovery_took_the_claim(): void
    {
        $fulfillment = $this->torodFulfillment();
        DB::table('provider_fulfillments')->where('id', $fulfillment->id)->update([
            'status' => FulfillmentStatus::ReconcilePending->value, 'claim_token' => null,
        ]);

        app(ShipmentTracker::class)->recordAcceptance($fulfillment->id, SubmissionResult::accepted('O-LATE'));

        $this->assertSame(FulfillmentStatus::Submitted, $fulfillment->fresh()->status);
        $this->assertSame('O-LATE', $fulfillment->fresh()->provider_reference);
    }

    public function test_a_worker_lost_mid_request_is_reconciled_not_resent(): void
    {
        $fulfillment = $this->torodFulfillment();
        DB::table('provider_fulfillments')->where('id', $fulfillment->id)->update([
            'status' => FulfillmentStatus::Dispatching->value, 'claim_token' => 'dead-worker', 'claimed_at' => now()->subMinutes(30),
        ]);

        $recovered = app(FulfillmentRecovery::class)->recoverStuckDispatching();

        $this->assertSame(1, $recovered);
        $this->assertSame(FulfillmentStatus::ReconcilePending, $fulfillment->fresh()->status);
        $this->assertSame([], $this->torod->calls, 'recovery must not call the provider to "retry"');
    }

    public function test_a_known_not_accepted_failure_is_retried_with_backoff_then_succeeds(): void
    {
        $fulfillment = $this->torodFulfillment();
        $this->torod->submitQueue = [SubmissionResult::retryable('rate_limited', '429'), SubmissionResult::accepted('O-2')];

        $this->dispatcher()->dispatch($fulfillment->id);
        $waiting = $fulfillment->fresh();
        $this->assertSame(FulfillmentStatus::RetryWait, $waiting->status);
        $this->assertTrue($waiting->next_attempt_at->isFuture());

        $this->assertSame(ProviderOrderDispatcher::SKIPPED, $this->dispatcher()->dispatch($fulfillment->id), 'not due yet');

        DB::table('provider_fulfillments')->where('id', $fulfillment->id)->update(['next_attempt_at' => now()->subSecond()]);
        $this->dispatcher()->dispatch($fulfillment->id);

        $this->assertSame(FulfillmentStatus::Submitted, $fulfillment->fresh()->status);
        $this->assertSame(2, $fulfillment->fresh()->attempts);
    }

    public function test_retries_are_bounded_and_end_in_failed_for_a_human(): void
    {
        $fulfillment = $this->torodFulfillment();
        $this->torod->submitQueue = array_fill(0, 10, SubmissionResult::retryable('rate_limited', '429'));

        foreach (range(1, 8) as $ignored) {
            DB::table('provider_fulfillments')->where('id', $fulfillment->id)->update(['next_attempt_at' => now()->subSecond()]);
            $this->dispatcher()->dispatch($fulfillment->id);
        }

        $fresh = $fulfillment->fresh();
        $this->assertSame(FulfillmentStatus::Failed, $fresh->status);
        $this->assertSame('attempts_exhausted', $fresh->last_error_code);
        $this->assertSame(5, $fresh->attempts);
    }

    public function test_a_definitive_rejection_fails_once_and_is_not_retried(): void
    {
        $fulfillment = $this->torodFulfillment();
        $this->torod->submitQueue = [SubmissionResult::rejected('invalid_city', 'City not supported')];

        $this->dispatcher()->dispatch($fulfillment->id);
        $this->dispatcher()->dispatch($fulfillment->id);

        $fresh = $fulfillment->fresh();
        $this->assertSame(FulfillmentStatus::Failed, $fresh->status);
        $this->assertSame('invalid_city', $fresh->last_error_code);
        $this->assertCount(1, $this->torod->calls);
    }

    public function test_one_providers_failure_never_touches_the_others_fulfillment(): void
    {
        $this->makeOrder(100);
        $this->addLine(100, 1, 1, 40, ProviderCode::M5azn);
        $this->addLine(100, 2, 1, 35, ProviderCode::TorodPoint);
        app(FulfillmentRouter::class)->route(100);
        $this->torod->submitQueue = [SubmissionResult::accepted('O-OK')];
        $this->m5azn->submitQueue = [SubmissionResult::rejected('bad_sku', 'unknown sku')];

        $this->dispatcher()->dispatch($this->fulfillmentFor(100, ProviderCode::TorodPoint)->id);
        $this->dispatcher()->dispatch($this->fulfillmentFor(100, ProviderCode::M5azn)->id);

        $this->assertSame(FulfillmentStatus::Submitted, $this->fulfillmentFor(100, ProviderCode::TorodPoint)->status);
        $this->assertSame(FulfillmentStatus::Failed, $this->fulfillmentFor(100, ProviderCode::M5azn)->status);
        $this->assertSame([], array_filter($this->torod->calls, static fn (string $call): bool => str_starts_with($call, 'cancel:')), 'a failure elsewhere must not cancel a successful fulfillment');
    }

    public function test_an_adapter_that_blows_up_leaves_the_outcome_unknown_not_resendable(): void
    {
        $fulfillment = $this->torodFulfillment();
        $throwing = new class extends \Tests\Support\FakeTorodAdapter {
            public int $submits = 0;

            public function submit(ProviderFulfillment $fulfillment, bool $dryRun): SubmissionResult
            {
                $this->submits++;
                throw new \RuntimeException('connection reset while reading the response');
            }
        };
        $this->app->instance(\Tests\Support\FakeTorodAdapter::class, $throwing);

        $this->dispatcher()->dispatch($fulfillment->id);
        $this->dispatcher()->dispatch($fulfillment->id);

        $this->assertSame(FulfillmentStatus::ReconcilePending, $fulfillment->fresh()->status);
        $this->assertSame(1, $throwing->submits, 'we cannot know whether it was sent, so it must not be sent again');
        $this->assertSame('adapter_exception', $fulfillment->fresh()->last_error_code);
    }

    public function test_what_an_adapter_exception_says_about_the_customer_never_reaches_the_log(): void
    {
        $fulfillment = $this->torodFulfillment();
        $throwing = new class extends \Tests\Support\FakeTorodAdapter {
            public function submit(ProviderFulfillment $fulfillment, bool $dryRun): SubmissionResult
            {
                throw new \RuntimeException('HTTP 422: invalid receiver phone 0551234567 for sara@example.test');
            }
        };
        $this->app->instance(\Tests\Support\FakeTorodAdapter::class, $throwing);
        $logged = [];
        Log::listen(static function ($message) use (&$logged): void {
            $logged[] = $message->message . ' ' . json_encode($message->context);
        });

        $this->dispatcher()->dispatch($fulfillment->id);

        $this->assertNotEmpty($logged, 'the failure is logged');
        $joined = implode("\n", $logged);
        $this->assertStringContainsString('invalid receiver phone', $joined, 'the useful part survives');
        $this->assertStringNotContainsString('0551234567', $joined);
        $this->assertStringNotContainsString('sara@example.test', $joined);
    }

    public function test_when_integrations_are_switched_off_after_claiming_nothing_is_lost(): void
    {
        $fulfillment = $this->torodFulfillment();
        $this->torod = new class extends \Tests\Support\FakeTorodAdapter {
            public function submit(ProviderFulfillment $fulfillment, bool $dryRun): SubmissionResult
            {
                throw new ProviderUnavailableException('kill switch');
            }
        };
        $this->app->instance(\Tests\Support\FakeTorodAdapter::class, $this->torod);

        $outcome = $this->dispatcher()->dispatch($fulfillment->id);

        $fresh = $fulfillment->fresh();
        $this->assertSame(ProviderOrderDispatcher::UNAVAILABLE, $outcome);
        $this->assertSame(FulfillmentStatus::Pending, $fresh->status);
        $this->assertSame(0, $fresh->attempts, 'nothing was sent, so no attempt is consumed');
    }

    public function test_a_disabled_provider_holds_instead_of_sending(): void
    {
        app(\App\Services\Fulfillment\ProviderConnectionManager::class)->save(ProviderCode::TorodPoint, false, null, ['dispatch_enabled' => true, 'dry_run' => false]);
        $fulfillment = $this->torodFulfillment();

        $this->dispatcher()->dispatch($fulfillment->id);

        $fresh = $fulfillment->fresh();
        $this->assertSame(FulfillmentStatus::Held, $fresh->status);
        $this->assertSame(HoldReason::PROVIDER_DISABLED, $fresh->hold_reason);
        $this->assertSame([], $this->torod->calls);
    }

    public function test_the_global_kill_switch_stops_everything(): void
    {
        $fulfillment = $this->torodFulfillment();
        config(['fulfillment.enabled' => false]);

        $this->dispatcher()->dispatch($fulfillment->id);

        $this->assertSame(HoldReason::PROVIDER_DISABLED, $fulfillment->fresh()->hold_reason);
        $this->assertSame([], $this->torod->calls);
    }

    public function test_an_unready_provider_holds_with_what_is_missing(): void
    {
        $this->torod->dispatchUsable = false;
        $fulfillment = $this->torodFulfillment();

        $this->dispatcher()->dispatch($fulfillment->id);

        $fresh = $fulfillment->fresh();
        $this->assertSame(HoldReason::PROVIDER_NOT_READY, $fresh->hold_reason);
        $this->assertStringContainsString('credentials', (string) $fresh->last_error_message);
    }

    public function test_a_preflight_hold_is_idempotent_and_releases_itself_when_fixed(): void
    {
        $fulfillment = $this->torodFulfillment();
        $this->torod->preflight = PreflightResult::hold(HoldReason::CITY_UNMAPPED, 'الرياض');

        $this->dispatcher()->dispatch($fulfillment->id);
        $firstEvents = DB::table('provider_fulfillment_events')->count();
        $this->dispatcher()->dispatch($fulfillment->id);
        $this->dispatcher()->dispatch($fulfillment->id);

        $this->assertSame(HoldReason::CITY_UNMAPPED, $fulfillment->fresh()->hold_reason);
        $this->assertSame($firstEvents, DB::table('provider_fulfillment_events')->count(), 're-checking an unchanged hold must not spam the history');
        $this->assertSame([], $this->torod->calls);

        $this->torod->preflight = null;
        $this->dispatcher()->dispatch($fulfillment->id);

        $this->assertSame(FulfillmentStatus::Submitted, $fulfillment->fresh()->status);
    }

    public function test_orders_placed_before_go_live_are_held_not_shipped_retroactively(): void
    {
        $fulfillment = $this->torodFulfillment();
        $this->enableProvider(ProviderCode::TorodPoint, ['dispatch_enabled' => true, 'dry_run' => true]);
        $this->enableProvider(ProviderCode::TorodPoint, ['dispatch_enabled' => true, 'dry_run' => false]);
        DB::table('orders')->where('id', 100)->update(['created_at' => now()->subDays(3)]);

        $this->dispatcher()->dispatch($fulfillment->id);

        $this->assertSame(HoldReason::BEFORE_GO_LIVE, $fulfillment->fresh()->hold_reason);
        $this->assertSame([], $this->torod->calls);

        // The sweep never releases it on its own...
        config(['queue.default' => 'sync']);
        app(FulfillmentRecovery::class)->dispatchDue();
        $this->assertSame([], $this->torod->calls);

        // ...an operator does, and only then does the manual dispatch bypass the cut-off: the release sends on the spot, queue or not.
        config(['queue.default' => 'database']);
        $outcome = app(\App\Services\Fulfillment\OperatorActions::class)->release($fulfillment->id);

        $this->assertSame(SubmissionResult::ACCEPTED, $outcome);
        $this->assertSame(FulfillmentStatus::Submitted, $fulfillment->fresh()->status);
        $this->assertCount(1, $this->torod->calls);
    }

    public function test_an_order_cancelled_before_dispatch_cancels_the_fulfillment_locally_without_calling_the_provider(): void
    {
        $fulfillment = $this->torodFulfillment();
        DB::table('orders')->where('id', 100)->update(['order_status' => 'canceled']);

        $this->dispatcher()->dispatch($fulfillment->id);

        $this->assertSame(FulfillmentStatus::Cancelled, $fulfillment->fresh()->status);
        $this->assertSame([], $this->torod->calls);
    }

    public function test_an_order_that_stops_being_eligible_is_left_alone_for_the_sweep(): void
    {
        $fulfillment = $this->torodFulfillment();
        DB::table('orders')->where('id', 100)->update(['is_pause' => true]);

        $outcome = $this->dispatcher()->dispatch($fulfillment->id);

        $this->assertSame(ProviderOrderDispatcher::SKIPPED, $outcome);
        $this->assertSame(FulfillmentStatus::Pending, $fulfillment->fresh()->status);
    }

    public function test_a_dry_run_validates_without_sending_and_never_claims_submission(): void
    {
        $fulfillment = $this->torodFulfillment();
        $this->torod->submitQueue = [SubmissionResult::dryRun(['payload_hash' => 'abc'])];

        $this->dispatcher()->dispatch($fulfillment->id);

        $fresh = $fulfillment->fresh();
        $this->assertSame(FulfillmentStatus::DryRun, $fresh->status);
        $this->assertNull($fresh->provider_reference);
        $this->assertNull($fresh->submitted_at);
        $this->assertSame('abc', $fresh->metaValue('payload_hash'));
    }

    /** @return iterable<string, array{bool}> */
    public static function modesDecidedAtTheClaim(): iterable
    {
        yield 'a rehearsal stays a rehearsal when the switch is flipped to live between the claim and the send' => [true];
        yield 'a claimed real send stays a real send when the switch is flipped to rehearse in between' => [false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modesDecidedAtTheClaim')]
    public function test_the_adapter_is_told_the_mode_decided_at_the_claim_whatever_the_switch_says_by_then(bool $dryRunAtTheClaim): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['dispatch_enabled' => true, 'dry_run' => $dryRunAtTheClaim]);
        $fulfillment = $this->torodFulfillment();
        $this->torod->submitQueue = [$dryRunAtTheClaim ? SubmissionResult::dryRun(['payload_hash' => 'abc']) : SubmissionResult::accepted('O1')];
        $flipped = false;
        // The first time the claimed shipment is read back (after the claim, before the send) an administrator flips the switch.
        Event::listen('eloquent.retrieved: ' . ProviderFulfillment::class, function (ProviderFulfillment $model) use (&$flipped, $dryRunAtTheClaim): void {
            if (!$flipped && $model->status === FulfillmentStatus::Dispatching) {
                $flipped = true;
                $this->enableProvider(ProviderCode::TorodPoint, ['dry_run' => !$dryRunAtTheClaim]);
            }
        });

        $this->dispatcher()->dispatch($fulfillment->id);

        $this->assertTrue($flipped, 'the switch was not flipped between the claim and the send');
        $this->assertSame([$dryRunAtTheClaim], $this->torod->dryRunRequested);
        $this->assertSame($dryRunAtTheClaim ? FulfillmentStatus::DryRun : FulfillmentStatus::Submitted, $fulfillment->fresh()->status);
    }

    public function test_the_dispatch_sweep_picks_up_pending_due_retries_and_fixed_holds(): void
    {
        $pending = $this->torodFulfillment(100);
        $this->torod->submitQueue = [SubmissionResult::accepted('A'), SubmissionResult::accepted('B')];
        $this->makeOrder(101);
        $this->addLine(101, 2, 1, 35, ProviderCode::TorodPoint);
        app(FulfillmentRouter::class)->route(101);
        $retry = $this->fulfillmentFor(101, ProviderCode::TorodPoint);
        $retry->forceFill(['status' => FulfillmentStatus::RetryWait, 'next_attempt_at' => now()->subMinute()])->save();
        config(['queue.default' => 'sync']);

        $count = app(FulfillmentRecovery::class)->dispatchDue();

        $this->assertSame(2, $count);
        $this->assertSame(FulfillmentStatus::Submitted, $pending->fresh()->status);
        $this->assertSame(FulfillmentStatus::Submitted, $retry->fresh()->status);
    }

    public function test_with_a_real_queue_the_sweep_queues_jobs_rather_than_calling_the_provider(): void
    {
        $this->torodFulfillment(100);

        app(FulfillmentRecovery::class)->dispatchDue();

        Queue::assertPushed(DispatchProviderFulfillment::class);
        $this->assertSame([], $this->torod->calls);
    }
}
