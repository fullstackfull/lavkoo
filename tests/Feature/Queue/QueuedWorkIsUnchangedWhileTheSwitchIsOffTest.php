<?php

namespace Tests\Feature\Queue;

use App\Jobs\Concerns\MarketOfTheWork;
use App\Jobs\DeliverSellerWebhook;
use App\Jobs\Fulfillment\CancelProviderFulfillment;
use App\Jobs\Fulfillment\DispatchProviderFulfillment;
use App\Jobs\Fulfillment\ProcessProviderEvent;
use App\Jobs\Fulfillment\ReconcileProviderFulfillment;
use App\Jobs\Fulfillment\RouteOrderFulfillment;
use App\Jobs\SendEmailJob;
use App\Listeners\RestockProductNotificationListener;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * With `gcc.storefronts.queues.market_from_record` off — which is how it ships — a queued job is run exactly as this platform runs it
 * today: in no market at all, on the queue it has always waited on, under the lock it has always held.
 *
 * This is the first test of the unit on purpose. Saudi Arabia is the only market selling, every job it queues is a Saudi job, and the whole
 * point of the switch is that until somebody turns it on the market a job carries cannot be the thing that changed. Both gates are proved
 * apart, because a capability that is only off because the OTHER switch is off is one flag away from shipping live by accident.
 */
class QueuedWorkIsUnchangedWhileTheSwitchIsOffTest extends QueuedMarketTestCase
{
    public function test_the_switch_ships_off(): void
    {
        $this->assertFalse((bool) config('gcc.storefronts.queues.market_from_record'), 'reading a market off a record is not on by default');
        $this->assertFalse(MarketOfTheWork::isEnabled());
    }

    public function test_no_record_is_read_for_its_market_while_the_switch_is_off(): void
    {
        $shipment = $this->shipmentOf(orderId: self::KUWAITI_ORDER);
        $event = $this->storedEventOf(fulfillmentId: $shipment);

        foreach ([
            ['gcc.storefronts.enabled' => false, 'gcc.storefronts.queues.market_from_record' => false],
            ['gcc.storefronts.enabled' => true, 'gcc.storefronts.queues.market_from_record' => false],
            ['gcc.storefronts.enabled' => false, 'gcc.storefronts.queues.market_from_record' => true],
        ] as $switches) {
            config($switches);

            $this->assertFalse(MarketOfTheWork::isEnabled(), 'both switches have to be on: ' . json_encode($switches));
            $this->assertNull(MarketOfTheWork::ofOrder(orderId: self::KUWAITI_ORDER));
            $this->assertNull(MarketOfTheWork::ofFulfillment(fulfillmentId: $shipment));
            $this->assertNull(MarketOfTheWork::ofFulfillmentEvent(eventId: $event));
            $this->assertNull(MarketOfTheWork::ofProduct(productId: self::KUWAITI_PRODUCT));
        }
    }

    public function test_every_job_that_names_a_record_is_run_unbound_while_the_switch_is_off(): void
    {
        $shipment = $this->shipmentOf(orderId: self::KUWAITI_ORDER);
        $event = $this->storedEventOf(fulfillmentId: $shipment);

        $jobs = [
            'route' => new RouteOrderFulfillment(orderId: self::KUWAITI_ORDER),
            'dispatch' => new DispatchProviderFulfillment(fulfillmentId: $shipment),
            'reconcile' => new ReconcileProviderFulfillment(fulfillmentId: $shipment),
            'cancel' => new CancelProviderFulfillment(fulfillmentId: $shipment),
            'event' => new ProcessProviderEvent(eventId: $event),
            'webhook' => new DeliverSellerWebhook(deliveryId: 1),
            'mail' => new SendEmailJob(mailTo: 'somebody@example.test', data: null),
        ];

        foreach ($jobs as $name => $job) {
            $this->assertNull($this->marketTheJobRunsIn($job), "the {$name} job binds no market while the switch is off");
            $this->assertNull($job->storefrontCountry, "the {$name} job records no market in its payload");
            $this->assertNull($job->queue, "the {$name} job waits on the queue it waits on today");
        }

        $this->assertNull($this->marketInForce(), 'the worker is left as it was found');
    }

    /**
     * The shipment lock is the one piece of existing behaviour the market could have displaced: the three shipment jobs declared their
     * middleware through a trait, and giving them a market meant declaring it in the job instead. The lock must still be the first
     * middleware and still be shared across the three classes, which is what stops a cancel racing a send still in flight.
     */
    public function test_the_shared_shipment_lock_is_still_the_first_middleware_and_still_shared(): void
    {
        $jobs = [
            new DispatchProviderFulfillment(fulfillmentId: 7),
            new ReconcileProviderFulfillment(fulfillmentId: 7),
            new CancelProviderFulfillment(fulfillmentId: 7),
        ];

        $keys = [];

        foreach ($jobs as $job) {
            $lock = $job->middleware()[0];

            $this->assertInstanceOf(WithoutOverlapping::class, $lock, 'the lock stays at index 0: a test of the cancellation reads it there');
            $keys[] = $lock->getLockKey($job);
        }

        $this->assertCount(1, array_unique($keys), 'a cancel must not run beside a send still in flight');
        $this->assertNotSame(
            $keys[0],
            (new CancelProviderFulfillment(fulfillmentId: 8))->middleware()[0]->getLockKey(new CancelProviderFulfillment(fulfillmentId: 8)),
            'different shipments do not block each other',
        );
    }

    public function test_the_restock_notice_is_run_unbound_while_the_switch_is_off(): void
    {
        $listener = new RestockProductNotificationListener();
        $event = (object) ['data' => ['product_id' => self::KUWAITI_PRODUCT, 'topic' => 'sixvalley']];

        $this->assertNull($this->marketTheListenerRunsIn(listener: $listener, event: $event));
        $this->assertNull($listener->viaQueue($event), 'it waits on the queue it waits on today');
    }

    /** A queued listener keeps its market in the middleware the dispatcher asks it for, so that is where it is read from. */
    private function marketTheListenerRunsIn(object $listener, object $event): ?string
    {
        $market = null;

        foreach ($listener->middleware($event) as $middleware) {
            $market = $middleware->handle($listener, fn (): ?string => $this->marketInForce());
        }

        return $market;
    }
}
