<?php

namespace Tests\Feature\Fulfillment;

use App\Jobs\Fulfillment\CancelProviderFulfillment;
use App\Jobs\Fulfillment\DispatchProviderFulfillment;
use App\Jobs\Fulfillment\ProcessProviderEvent;
use App\Jobs\Fulfillment\ReconcileProviderFulfillment;
use App\Jobs\Fulfillment\RouteOrderFulfillment;
use App\Services\Fulfillment\FulfillmentCancellation;
use App\Services\Fulfillment\FulfillmentRouter;
use App\Services\Fulfillment\ProviderOrderDispatcher;
use App\Services\Fulfillment\ReconciliationService;
use App\Services\Fulfillment\WebhookEventProcessor;
use RuntimeException;

/**
 * A job that throws is reported by the queue worker, to the log and to `failed_jobs`, with its whole message: so whatever a job lets
 * out must already have had phone numbers, e-mail addresses and credentials taken out of it.
 */
class JobFailureHygieneTest extends FulfillmentTestCase
{
    private const MESSAGE = 'Lock wait timeout while saving: Invalid receiver phone 0551234567 for sara@example.test (password=hunter2)';

    /** @return array<string, array{callable}> */
    public static function everyFulfillmentJob(): array
    {
        return [
            'dispatch' => [static fn () => [new DispatchProviderFulfillment(1), ProviderOrderDispatcher::class]],
            'reconcile' => [static fn () => [new ReconcileProviderFulfillment(1), ReconciliationService::class]],
            'cancel' => [static fn () => [new CancelProviderFulfillment(1), FulfillmentCancellation::class]],
            'event' => [static fn () => [new ProcessProviderEvent(1), WebhookEventProcessor::class]],
            'route' => [static fn () => [new RouteOrderFulfillment(1), FulfillmentRouter::class]],
        ];
    }

    /** @dataProvider everyFulfillmentJob */
    #[\PHPUnit\Framework\Attributes\DataProvider('everyFulfillmentJob')]
    public function test_a_job_that_throws_lets_out_only_where_it_failed_and_a_scrubbed_message(callable $job): void
    {
        [$job, $service] = $job();
        $this->app->instance($service, $this->failingStandIn($service));

        try {
            $this->app->call([$job, 'handle']);
            $this->fail('the job swallowed the failure');
        } catch (RuntimeException $failure) {
            foreach (['0551234567', 'sara@example.test', 'hunter2'] as $leaked) {
                $this->assertStringNotContainsString($leaked, $failure->getMessage() . $failure->getTraceAsString(), "'{$leaked}' would be written to the log and to failed_jobs");
            }
            $this->assertStringContainsString('Lock wait timeout', $failure->getMessage(), 'what a person debugging needs is kept');
            $this->assertStringContainsString(RuntimeException::class . ' at ', $failure->getMessage(), 'and where it failed');
            $this->assertNull($failure->getPrevious(), 'the original exception, with its message, is not carried along');
        }
    }

    /** A collaborator of the real class whose one method fails the way a database or a provider failure would. */
    private function failingStandIn(string $service): object
    {
        $fails = static fn () => throw new RuntimeException(self::MESSAGE);

        return match ($service) {
            ProviderOrderDispatcher::class => new class($fails) extends ProviderOrderDispatcher {
                public function __construct(private readonly \Closure $fails) {}

                public function dispatch(int $fulfillmentId, bool $manual = false, ?bool $expectDryRun = null): string
                {
                    ($this->fails)();
                }
            },
            ReconciliationService::class => new class($fails) extends ReconciliationService {
                public function __construct(private readonly \Closure $fails) {}

                public function reconcile(int $fulfillmentId): string
                {
                    ($this->fails)();
                }
            },
            FulfillmentCancellation::class => new class($fails) extends FulfillmentCancellation {
                public function __construct(private readonly \Closure $fails) {}

                public function cancel(int $fulfillmentId, bool $manual = false): string
                {
                    ($this->fails)();
                }
            },
            WebhookEventProcessor::class => new class($fails) extends WebhookEventProcessor {
                public function __construct(private readonly \Closure $fails) {}

                public function process(int $eventId): string
                {
                    ($this->fails)();
                }
            },
            FulfillmentRouter::class => new class($fails) extends FulfillmentRouter {
                public function __construct(private readonly \Closure $fails) {}

                public function route(int $orderId): array
                {
                    ($this->fails)();
                }
            },
        };
    }
}
