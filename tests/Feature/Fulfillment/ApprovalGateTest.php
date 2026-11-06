<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\ProviderCode;
use App\Jobs\Fulfillment\DispatchProviderFulfillment;
use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\Data\SubmissionResult;
use App\Services\Fulfillment\FulfillmentRecovery;
use App\Services\Fulfillment\FulfillmentRouter;
use App\Services\Fulfillment\OperatorActions;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\ProviderOrderDispatcher;
use App\Services\Fulfillment\Providers\M5azn\M5aznAdapter;
use App\Services\Fulfillment\Providers\TorodPoint\TorodPointAdapter;
use App\Services\Fulfillment\ShipmentTracker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * "Send orders" is not "send them by themselves". Automatic sending is a separate switch that stays off until the provider has
 * been verified live: while it is off, an order that is due waits for a person, and releasing it sends that one order.
 */
class ApprovalGateTest extends FulfillmentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->enableProvider(ProviderCode::TorodPoint, ['auto_dispatch' => false]);
        $this->enableProvider(ProviderCode::M5azn, ['auto_dispatch' => false]);
    }

    private function dispatcher(): ProviderOrderDispatcher
    {
        return app(ProviderOrderDispatcher::class);
    }

    private function torodFulfillment(int $orderId = 100, array $orderOverrides = []): ProviderFulfillment
    {
        DB::table('products')->where('id', 2)->exists() || $this->makeProduct(2, ProviderCode::TorodPoint);
        $this->makeOrder($orderId, $orderOverrides);
        $this->addLine($orderId, 2, 1, 35, ProviderCode::TorodPoint);
        app(FulfillmentRouter::class)->route($orderId);

        return $this->fulfillmentFor($orderId, ProviderCode::TorodPoint);
    }

    public function test_the_shipped_defaults_keep_automatic_sending_off_for_both_providers(): void
    {
        $this->assertFalse(M5aznAdapter::defaultSettings()['auto_dispatch']);
        $this->assertFalse(TorodPointAdapter::defaultSettings()['auto_dispatch']);
    }

    public function test_a_due_order_waits_for_approval_and_nothing_is_sent(): void
    {
        $fulfillment = $this->torodFulfillment();

        $outcome = $this->dispatcher()->dispatch($fulfillment->id);

        $fresh = $fulfillment->fresh();
        $this->assertSame(ProviderOrderDispatcher::SKIPPED, $outcome);
        $this->assertSame(FulfillmentStatus::Held, $fresh->status);
        $this->assertSame(HoldReason::AWAITING_APPROVAL, $fresh->hold_reason);
        $this->assertSame(0, $fresh->attempts);
        $this->assertSame([], $this->torod->calls);
    }

    public function test_the_sweep_never_sends_an_order_that_is_waiting_for_approval(): void
    {
        $fulfillment = $this->torodFulfillment();
        $this->dispatcher()->dispatch($fulfillment->id);
        config(['queue.default' => 'sync']);

        foreach (range(1, 3) as $ignored) {
            app(FulfillmentRecovery::class)->dispatchDue();
        }

        $this->assertSame(HoldReason::AWAITING_APPROVAL, $fulfillment->fresh()->hold_reason);
        $this->assertSame([], $this->torod->calls, 'the sweep must not approve what only a person may approve');
        $this->assertNotContains(HoldReason::AWAITING_APPROVAL, ProviderOrderDispatcher::AUTO_RELEASABLE);
    }

    public function test_releasing_the_order_is_the_approval_and_sends_that_one_order_at_once_even_when_a_queue_runs(): void
    {
        config(['queue.default' => 'database']);
        $first = $this->torodFulfillment(100);
        $second = $this->torodFulfillment(101);
        $this->dispatcher()->dispatch($first->id);
        $this->dispatcher()->dispatch($second->id);
        $this->torod->submitQueue = [SubmissionResult::accepted('O-APPROVED')];

        $outcome = app(OperatorActions::class)->release($first->id);

        $this->assertSame(SubmissionResult::ACCEPTED, $outcome, 'the person is told what the send came to, not that a job was queued');
        // The approval is not left in a queue payload, where a lost or failed job would drop it without a word.
        Queue::assertNotPushed(DispatchProviderFulfillment::class, static fn (DispatchProviderFulfillment $job): bool => $job->manual);
        $this->assertSame(FulfillmentStatus::Submitted, $first->fresh()->status);
        $this->assertSame('O-APPROVED', $first->fresh()->provider_reference);
        $this->assertSame(HoldReason::AWAITING_APPROVAL, $second->fresh()->hold_reason, 'approving one order approves no other');
        $this->assertCount(1, $this->torod->calls);
    }

    public function test_without_a_queue_worker_releasing_the_order_sends_it_on_the_spot(): void
    {
        config(['queue.default' => 'sync']);
        $fulfillment = $this->torodFulfillment();
        $this->dispatcher()->dispatch($fulfillment->id);
        $this->torod->submitQueue = [SubmissionResult::accepted('O-SYNC')];

        app(OperatorActions::class)->release($fulfillment->id);

        $this->assertSame(FulfillmentStatus::Submitted, $fulfillment->fresh()->status, 'a release that only waits for the next sweep is held again by it');
        $this->assertSame('O-SYNC', $fulfillment->fresh()->provider_reference);
        $this->assertCount(1, $this->torod->calls);
        Queue::assertNothingPushed();
    }

    public function test_without_a_queue_worker_a_release_approves_only_the_order_the_person_chose(): void
    {
        config(['queue.default' => 'sync']);
        $chosen = $this->torodFulfillment(100);
        $other = $this->torodFulfillment(101);
        $this->dispatcher()->dispatch($chosen->id);
        $this->dispatcher()->dispatch($other->id);
        $this->torod->submitQueue = [SubmissionResult::accepted('O-ONE')];

        app(OperatorActions::class)->release($chosen->id);
        app(FulfillmentRecovery::class)->dispatchDue();

        $this->assertSame(FulfillmentStatus::Submitted, $chosen->fresh()->status);
        $this->assertSame(HoldReason::AWAITING_APPROVAL, $other->fresh()->hold_reason);
        $this->assertCount(1, $this->torod->calls);
    }

    /** @return iterable<string, array{string}> */
    public static function holdsASweepCanPutOnAnOrderJustReleased(): iterable
    {
        yield 'awaiting approval' => [HoldReason::AWAITING_APPROVAL];
        yield 'placed before go-live' => [HoldReason::BEFORE_GO_LIVE];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('holdsASweepCanPutOnAnOrderJustReleased')]
    public function test_a_sweep_that_holds_the_order_between_the_release_and_the_send_does_not_swallow_the_approval(string $hold): void
    {
        $fulfillment = $this->torodFulfillment();
        $this->dispatcher()->dispatch($fulfillment->id);
        app(ShipmentTracker::class)->release($fulfillment->id, 'operator', freshStart: true);
        // The sweep gets there first and, being no person, holds the order again.
        app(ShipmentTracker::class)->hold($fulfillment->id, $hold);
        $this->torod->submitQueue = [SubmissionResult::accepted('O-APPROVED')];

        $outcome = $this->dispatcher()->dispatch($fulfillment->id, manual: true);

        $this->assertSame(SubmissionResult::ACCEPTED, $outcome, 'the person approved it; the click is not lost');
        $this->assertSame(FulfillmentStatus::Submitted, $fulfillment->fresh()->status);
    }

    public function test_a_send_that_is_not_the_one_the_page_promised_is_refused_before_anything_is_claimed_or_sent(): void
    {
        $fulfillment = $this->torodFulfillment();
        $this->dispatcher()->dispatch($fulfillment->id);
        $this->assertSame(HoldReason::AWAITING_APPROVAL, $fulfillment->fresh()->hold_reason);

        // The page said "nothing is sent" (a rehearsal), and the setting says otherwise by the time the button is pressed.
        $refused = app(OperatorActions::class)->release($fulfillment->id, expectDryRun: true);

        $this->assertSame(ProviderOrderDispatcher::MODE_CHANGED, $refused);
        $this->assertSame([], $this->torod->calls, 'nothing reached the provider');
        $fresh = $fulfillment->fresh();
        $this->assertNull($fresh->claim_token, 'and nothing was claimed');
        $this->assertSame(0, $fresh->attempts);
        $this->assertNotSame(FulfillmentStatus::Dispatching, $fresh->status);
    }

    public function test_a_rehearsal_the_page_promised_is_refused_too_when_the_setting_has_become_a_real_send_and_the_other_way_round(): void
    {
        $fulfillment = $this->torodFulfillment();
        $this->enableProvider(ProviderCode::TorodPoint, ['auto_dispatch' => false, 'dry_run' => true]);

        $realSendPromised = $this->dispatcher()->dispatch($fulfillment->id, manual: true, expectDryRun: false);

        $this->assertSame(ProviderOrderDispatcher::MODE_CHANGED, $realSendPromised);
        $this->assertSame([], $this->torod->calls);
        $this->assertSame(FulfillmentStatus::Pending, $fulfillment->fresh()->status);

        $this->enableProvider(ProviderCode::TorodPoint, ['auto_dispatch' => false, 'dry_run' => false]);
        $rehearsalPromised = $this->dispatcher()->dispatch($fulfillment->id, manual: true, expectDryRun: true);

        $this->assertSame(ProviderOrderDispatcher::MODE_CHANGED, $rehearsalPromised);
        $this->assertSame([], $this->torod->calls);
        $this->assertSame(FulfillmentStatus::Pending, $fulfillment->fresh()->status);
    }

    public function test_a_send_that_is_what_the_page_promised_goes_ahead_and_so_does_one_that_promised_nothing(): void
    {
        $promised = $this->torodFulfillment(100);
        $unpromised = $this->torodFulfillment(101);
        $this->torod->submitQueue = [SubmissionResult::accepted('O-PROMISED'), SubmissionResult::accepted('O-UNPROMISED')];

        $this->assertSame(SubmissionResult::ACCEPTED, $this->dispatcher()->dispatch($promised->id, manual: true, expectDryRun: false));
        $this->assertSame(SubmissionResult::ACCEPTED, $this->dispatcher()->dispatch($unpromised->id, manual: true));

        $this->assertCount(2, $this->torod->calls);
        $this->assertSame([false, false], $this->torod->dryRunRequested);
    }

    public function test_a_hold_a_person_does_not_answer_is_still_not_sent_by_a_manual_dispatch(): void
    {
        $fulfillment = $this->torodFulfillment();
        $this->dispatcher()->dispatch($fulfillment->id);
        app(ShipmentTracker::class)->hold($fulfillment->id, HoldReason::MIXED_COD_BLOCKED);

        $outcome = $this->dispatcher()->dispatch($fulfillment->id, manual: true);

        $this->assertSame(ProviderOrderDispatcher::SKIPPED, $outcome);
        $this->assertSame(HoldReason::MIXED_COD_BLOCKED, $fulfillment->fresh()->hold_reason);
        $this->assertSame([], $this->torod->calls);
    }

    /** @return iterable<string, array{SubmissionResult|null, string}> */
    public static function whatAReleaseCanComeTo(): iterable
    {
        yield 'the provider accepts it' => [SubmissionResult::accepted('O-1'), SubmissionResult::ACCEPTED];
        yield 'the provider refuses it' => [SubmissionResult::rejected('bad_sku', 'unknown sku'), SubmissionResult::REJECTED];
        yield 'the provider could not be reached' => [SubmissionResult::retryable('timeout_before_send', 'refused'), SubmissionResult::RETRYABLE];
        yield 'the answer is lost' => [SubmissionResult::ambiguous('timeout', 'no answer'), SubmissionResult::AMBIGUOUS];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('whatAReleaseCanComeTo')]
    public function test_a_release_says_what_the_send_came_to_and_never_hides_an_unknown_answer(SubmissionResult $answer, string $expected): void
    {
        $fulfillment = $this->torodFulfillment();
        $this->dispatcher()->dispatch($fulfillment->id);
        $this->torod->submitQueue = [$answer];

        $this->assertSame($expected, app(OperatorActions::class)->release($fulfillment->id));
        $this->assertCount(1, $this->torod->calls);
    }

    public function test_a_release_that_the_dispatcher_holds_again_says_so(): void
    {
        $fulfillment = $this->torodFulfillment();
        $this->dispatcher()->dispatch($fulfillment->id);
        $this->torod->preflight = \App\Services\Fulfillment\Data\PreflightResult::hold(HoldReason::CITY_UNMAPPED);

        $outcome = app(OperatorActions::class)->release($fulfillment->id);

        $this->assertSame(ProviderOrderDispatcher::SKIPPED, $outcome);
        $this->assertSame(HoldReason::CITY_UNMAPPED, $fulfillment->fresh()->hold_reason);
        $this->assertSame([], $this->torod->calls);
    }

    public function test_a_dry_run_needs_no_approval_because_nothing_leaves_the_host(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['auto_dispatch' => false, 'dry_run' => true]);
        $fulfillment = $this->torodFulfillment();
        $this->torod->submitQueue = [SubmissionResult::dryRun(['payload_hash' => 'abc'])];

        $this->dispatcher()->dispatch($fulfillment->id);

        $this->assertSame(FulfillmentStatus::DryRun, $fulfillment->fresh()->status);
    }

    public function test_a_checked_dry_run_is_released_after_dry_run_is_turned_off_and_only_then_sent(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['auto_dispatch' => false, 'dry_run' => true]);
        $fulfillment = $this->torodFulfillment();
        $this->torod->submitQueue = [SubmissionResult::dryRun(['payload_hash' => 'abc', 'payload_preview' => ['invoiceNumber' => '100-TP1']]), SubmissionResult::accepted('O-CHECKED')];
        $this->dispatcher()->dispatch($fulfillment->id);
        $this->assertSame(FulfillmentStatus::DryRun, $fulfillment->fresh()->status);
        $this->assertSame(['invoiceNumber' => '100-TP1'], $fulfillment->fresh()->meta['payload_preview'], 'what an operator reads before approving');

        $this->dispatcher()->dispatch($fulfillment->id);
        $this->assertSame(FulfillmentStatus::DryRun, $fulfillment->fresh()->status, 'a recorded dry run is never sent by itself, not even by the sweep');
        $this->assertCount(1, $this->torod->calls);

        $this->enableProvider(ProviderCode::TorodPoint, ['auto_dispatch' => false, 'dry_run' => false]);
        $this->assertSame(FulfillmentStatus::DryRun, $fulfillment->fresh()->status, 'turning dry run off sends nothing either');
        app(OperatorActions::class)->release($fulfillment->id);
        $this->assertArrayNotHasKey('payload_preview', $fulfillment->fresh()->meta ?? [], 'a request that was never sent is not kept once the shipment is released');
        $this->assertArrayNotHasKey('payload_hash', $fulfillment->fresh()->meta ?? []);
        $this->dispatcher()->dispatch($fulfillment->id, manual: true);

        $this->assertSame(FulfillmentStatus::Submitted, $fulfillment->fresh()->status);
        $this->assertSame('O-CHECKED', $fulfillment->fresh()->provider_reference);
        $this->assertCount(2, $this->torod->calls);
    }

    /** @return iterable<string, array{string}> */
    public static function waysOutOfADryRun(): iterable
    {
        foreach (['cancelLocally', 'hold', 'markFailed', 'scheduleRetry', 'awaitReconciliation', 'aProviderStatus', 'aProviderStatusThatNeedsAttention'] as $way) {
            yield $way => [$way];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('waysOutOfADryRun')]
    public function test_the_request_a_dry_run_recorded_leaves_with_the_dry_run_however_it_ends(string $way): void
    {
        $fulfillment = $this->torodFulfillment();
        $tracker = app(ShipmentTracker::class);
        $tracker->markDryRun($fulfillment->id, ['payload_hash' => 'abc', 'payload_preview' => ['receiverName' => 'Sara Alharbi'], 'keep' => 'me']);
        $this->assertArrayHasKey('payload_preview', $fulfillment->fresh()->meta);

        match ($way) {
            'cancelLocally' => $tracker->cancelLocally($fulfillment->id, 'parent_order_closed'),
            'hold' => $tracker->hold($fulfillment->id, HoldReason::CITY_UNMAPPED),
            'markFailed' => $tracker->markFailed($fulfillment->id, 'x', 'refused'),
            'scheduleRetry' => $tracker->scheduleRetry($fulfillment->id, 'x', 'busy', now()->addMinute()),
            'awaitReconciliation' => $tracker->awaitReconciliation($fulfillment->id, 'x', 'unknown', now()->addMinute()),
            'aProviderStatus' => $tracker->applyProviderStatus($fulfillment->id, FulfillmentStatus::Delivered, now(), 'poll', 'DELIVERED'),
            'aProviderStatusThatNeedsAttention' => $tracker->applyProviderStatus($fulfillment->id, FulfillmentStatus::NeedsAttention, now(), 'webhook', 'OPENED_ISSUE', attentionNote: 'look at it'),
        };

        $this->assertSame(['keep' => 'me'], $fulfillment->fresh()->meta, $way);
    }

    public function test_a_claim_given_back_takes_the_recorded_request_along(): void
    {
        $fulfillment = $this->torodFulfillment();
        $fulfillment->forceFill(['status' => FulfillmentStatus::Dispatching, 'claim_token' => 'token-1', 'attempts' => 1, 'meta' => ['payload_preview' => ['receiverName' => 'Sara Alharbi'], 'payload_hash' => 'abc', 'keep' => 'me']])->save();

        app(ShipmentTracker::class)->unclaim($fulfillment->id, 'token-1');

        $this->assertSame(FulfillmentStatus::Pending, $fulfillment->fresh()->status);
        $this->assertSame(['keep' => 'me'], $fulfillment->fresh()->meta);
    }

    public function test_a_claim_given_back_leaves_alone_a_record_that_holds_no_request(): void
    {
        $fulfillment = $this->torodFulfillment();
        $fulfillment->forceFill(['status' => FulfillmentStatus::Dispatching, 'claim_token' => 'token-1', 'attempts' => 1, 'meta' => null])->save();

        app(ShipmentTracker::class)->unclaim($fulfillment->id, 'token-1');

        $this->assertSame(FulfillmentStatus::Pending, $fulfillment->fresh()->status);
        $this->assertNull($fulfillment->fresh()->meta);
    }

    public function test_a_dry_run_recorded_again_keeps_its_request(): void
    {
        $fulfillment = $this->torodFulfillment();
        $tracker = app(ShipmentTracker::class);

        $tracker->markDryRun($fulfillment->id, ['payload_hash' => 'one', 'payload_preview' => ['invoiceNumber' => '100-TP1']]);
        $tracker->markDryRun($fulfillment->id, ['payload_hash' => 'two', 'payload_preview' => ['invoiceNumber' => '100-TP1']]);

        $this->assertSame('two', $fulfillment->fresh()->meta['payload_hash']);
        $this->assertSame(['invoiceNumber' => '100-TP1'], $fulfillment->fresh()->meta['payload_preview']);
    }

    public function test_a_request_recorded_by_a_dry_run_does_not_outlive_the_order_being_accepted(): void
    {
        $fulfillment = $this->torodFulfillment();
        $fulfillment->forceFill(['status' => FulfillmentStatus::Pending, 'meta' => ['payload_preview' => ['receiverName' => 'x'], 'payload_hash' => 'abc', 'keep' => 'me']])->save();

        app(ShipmentTracker::class)->recordAcceptance($fulfillment->id, SubmissionResult::accepted('O-1'));

        $this->assertSame(FulfillmentStatus::Submitted, $fulfillment->fresh()->status);
        $this->assertSame(['keep' => 'me'], $fulfillment->fresh()->meta, 'the request describes an order that was never sent; the rest of the record is untouched');
    }

    public function test_with_automatic_sending_on_orders_go_out_by_themselves_as_before(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['auto_dispatch' => true]);
        $fulfillment = $this->torodFulfillment();
        $this->torod->submitQueue = [SubmissionResult::accepted('O-AUTO')];

        $this->dispatcher()->dispatch($fulfillment->id);

        $this->assertSame(FulfillmentStatus::Submitted, $fulfillment->fresh()->status);
    }

    public function test_switching_automatic_sending_on_does_not_sweep_out_orders_that_were_waiting(): void
    {
        $waiting = $this->torodFulfillment(100);
        $this->dispatcher()->dispatch($waiting->id);
        DB::table('orders')->where('id', 100)->update(['created_at' => now()->subHour()]);

        $this->enableProvider(ProviderCode::TorodPoint, ['auto_dispatch' => true]);
        config(['queue.default' => 'sync']);
        app(FulfillmentRecovery::class)->dispatchDue();

        $this->assertSame(HoldReason::AWAITING_APPROVAL, $waiting->fresh()->hold_reason);
        $this->assertSame([], $this->torod->calls);
    }

    public function test_go_live_is_recorded_when_automatic_sending_goes_on_and_not_before(): void
    {
        $connections = app(ProviderConnectionManager::class);
        $this->assertNull($connections->config(ProviderCode::TorodPoint)->get('go_live_at'), 'dispatch on + manual approval is not yet automatic');

        $this->enableProvider(ProviderCode::TorodPoint, ['auto_dispatch' => true]);
        $this->assertNotNull($connections->config(ProviderCode::TorodPoint)->get('go_live_at'));

        $this->enableProvider(ProviderCode::TorodPoint, ['auto_dispatch' => false]);
        $this->assertNull($connections->config(ProviderCode::TorodPoint)->get('go_live_at'), 'turning it off ends the go-live');
    }

    public function test_the_environment_guards_still_come_first(): void
    {
        $fulfillment = $this->torodFulfillment();
        config(['fulfillment.external_writes_enabled' => false]);

        $this->dispatcher()->dispatch($fulfillment->id, manual: true);

        $this->assertSame(HoldReason::EXTERNAL_WRITES_DISABLED, $fulfillment->fresh()->hold_reason, 'an approval cannot send past a host that may not write');
        $this->assertSame([], $this->torod->calls);
    }
}
