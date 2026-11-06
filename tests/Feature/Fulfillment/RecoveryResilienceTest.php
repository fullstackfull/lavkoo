<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Jobs\Fulfillment\DispatchProviderFulfillment;
use App\Models\ProviderFulfillment;
use App\Models\ProviderSyncState;
use App\Services\Fulfillment\FulfillmentRecovery;
use App\Services\Fulfillment\FulfillmentRouter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/**
 * The per-minute sweep is the safety net under every event-driven path. One poisoned order or a database
 * hiccup in one sweep must not switch off dispatching, reconciliation and cancellation for everyone.
 */
class RecoveryResilienceTest extends FulfillmentTestCase
{
    private function pendingFulfillment(): ProviderFulfillment
    {
        $this->makeProduct(1, ProviderCode::TorodPoint);
        $this->makeOrder(100);
        $this->addLine(100, 1, 1, 50, ProviderCode::TorodPoint);
        app(FulfillmentRouter::class)->route(100);
        Queue::fake();

        return ProviderFulfillment::where('order_id', 100)->firstOrFail();
    }

    public function test_a_failing_sweep_does_not_stop_the_later_ones_and_the_heartbeat_reports_it(): void
    {
        $fulfillment = $this->pendingFulfillment();
        $failing = new class(...$this->recoveryDependencies()) extends FulfillmentRecovery {
            public function routeEligibleOrders(int $limit = 200): int
            {
                throw new RuntimeException('one poisoned order');
            }
        };
        Log::spy();

        $counts = $failing->runAll();

        $this->assertSame(-1, $counts['routed']);
        $this->assertGreaterThanOrEqual(1, $counts['dispatched'], 'the sweeps after the failing one still ran');
        Queue::assertPushed(DispatchProviderFulfillment::class, fn (DispatchProviderFulfillment $job): bool => $job->fulfillmentId === $fulfillment->id);
        $heartbeat = ProviderSyncState::where('provider', 'system')->where('capability', 'sweep')->firstOrFail();
        $this->assertNotNull($heartbeat->last_failure_at);
        $this->assertStringContainsString('routed', (string) $heartbeat->last_error);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_a_clean_run_leaves_a_success_heartbeat_with_what_it_did(): void
    {
        $this->pendingFulfillment();

        $counts = app(FulfillmentRecovery::class)->runAll();

        $this->assertSame(0, count(array_filter($counts, static fn (int $handled): bool => $handled < 0)));
        $heartbeat = ProviderSyncState::where('provider', 'system')->where('capability', 'sweep')->firstOrFail();
        $this->assertNotNull($heartbeat->last_success_at);
        $this->assertNull($heartbeat->last_error);
        $this->assertEqualsCanonicalizing(array_keys($counts), array_keys((array) $heartbeat->stats), 'every sweep is reported (a JSON column does not keep key order)');
    }

    public function test_the_command_exits_non_zero_when_a_sweep_failed_so_monitoring_notices(): void
    {
        $this->pendingFulfillment();
        $this->app->instance(FulfillmentRecovery::class, new class(...$this->recoveryDependencies()) extends FulfillmentRecovery {
            public function cancelForClosedOrders(int $limit = 100): int
            {
                throw new RuntimeException('database went away');
            }
        });

        $this->artisan('fulfillment:recover')->assertFailed();
    }

    public function test_the_sweep_does_nothing_and_does_not_fail_while_the_kill_switch_is_off(): void
    {
        $this->pendingFulfillment();
        config(['fulfillment.enabled' => false]);

        $this->artisan('fulfillment:recover')->assertSuccessful();

        $this->assertSame(0, ProviderSyncState::where('provider', 'system')->count(), 'no heartbeat: the switch is off, which is not a stalled scheduler');
        $this->assertSame(FulfillmentStatus::Pending, ProviderFulfillment::firstOrFail()->status);
    }

    /** @return list<object> the real collaborators of FulfillmentRecovery, for the test doubles above */
    private function recoveryDependencies(): array
    {
        return array_map(
            fn (\ReflectionParameter $parameter): object => $this->app->make((string) $parameter->getType()),
            (new \ReflectionClass(FulfillmentRecovery::class))->getConstructor()->getParameters(),
        );
    }
}
