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

    public function test_releasing_the_order_is_the_approval_and_sends_that_one_order_at_once(): void
    {
        $first = $this->torodFulfillment(100);
        $second = $this->torodFulfillment(101);
        $this->dispatcher()->dispatch($first->id);
        $this->dispatcher()->dispatch($second->id);
        $this->torod->submitQueue = [SubmissionResult::accepted('O-APPROVED')];

        app(OperatorActions::class)->release($first->id);

        Queue::assertPushed(DispatchProviderFulfillment::class, static fn (DispatchProviderFulfillment $job): bool => $job->fulfillmentId === $first->id && $job->manual === true);
        Queue::assertNotPushed(DispatchProviderFulfillment::class, static fn (DispatchProviderFulfillment $job): bool => $job->fulfillmentId === $second->id && $job->manual);
        $this->dispatcher()->dispatch($first->id, manual: true);

        $this->assertSame(FulfillmentStatus::Submitted, $first->fresh()->status);
        $this->assertSame('O-APPROVED', $first->fresh()->provider_reference);
        $this->assertSame(HoldReason::AWAITING_APPROVAL, $second->fresh()->hold_reason, 'approving one order approves no other');
        $this->assertCount(1, $this->torod->calls);
    }

    public function test_a_dry_run_needs_no_approval_because_nothing_leaves_the_host(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['auto_dispatch' => false, 'dry_run' => true]);
        $fulfillment = $this->torodFulfillment();
        $this->torod->submitQueue = [SubmissionResult::dryRun(['payload_hash' => 'abc'])];

        $this->dispatcher()->dispatch($fulfillment->id);

        $this->assertSame(FulfillmentStatus::DryRun, $fulfillment->fresh()->status);
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
