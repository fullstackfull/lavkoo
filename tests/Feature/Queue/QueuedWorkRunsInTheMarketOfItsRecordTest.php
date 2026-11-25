<?php

namespace Tests\Feature\Queue;

use App\Jobs\Concerns\MarketOfTheWork;
use App\Jobs\Fulfillment\CancelProviderFulfillment;
use App\Jobs\Fulfillment\DispatchProviderFulfillment;
use App\Jobs\Fulfillment\ProcessProviderEvent;
use App\Jobs\Fulfillment\ReconcileProviderFulfillment;
use App\Jobs\Fulfillment\RouteOrderFulfillment;
use App\Listeners\RestockProductNotificationListener;
use App\Services\Storefront\StorefrontContext;
use Illuminate\Support\Facades\DB;

/**
 * With the switch on, a job that names a record is run in the market that RECORD belongs to — whatever market the request that queued it
 * was serving, and whether or not it was serving one at all.
 *
 * That ordering is the whole unit, and it is why the record is read instead of the dispatcher being trusted. A shipment of a Kuwaiti order
 * is queued from three places and not one of them knows Kuwait: the provider's webhook address, which is served across every country on
 * purpose; the recovery sweep, which is a console run for every market at once; and an operator's panel, which sees every country. A job
 * that took the dispatcher's answer in those three cases would report a Kuwaiti shipment as Saudi, as Emirati, or as nothing.
 */
class QueuedWorkRunsInTheMarketOfItsRecordTest extends QueuedMarketTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->switchOnTheMarketOfTheRecord();
    }

    public function test_a_shipment_is_worked_in_the_market_of_the_order_it_belongs_to(): void
    {
        $kuwaiti = $this->shipmentOf(orderId: self::KUWAITI_ORDER);
        $emirati = $this->shipmentOf(orderId: self::EMIRATI_ORDER);

        $this->assertSame('KW', $this->marketTheJobRunsIn(new DispatchProviderFulfillment(fulfillmentId: $kuwaiti)));
        $this->assertSame('KW', $this->marketTheJobRunsIn(new ReconcileProviderFulfillment(fulfillmentId: $kuwaiti)));
        $this->assertSame('KW', $this->marketTheJobRunsIn(new CancelProviderFulfillment(fulfillmentId: $kuwaiti)));
        $this->assertSame('AE', $this->marketTheJobRunsIn(new DispatchProviderFulfillment(fulfillmentId: $emirati)));

        $this->assertNull($this->marketInForce(), 'the worker is left as it was found');
    }

    public function test_the_record_decides_and_not_the_request_that_queued_the_job(): void
    {
        $kuwaiti = $this->shipmentOf(orderId: self::KUWAITI_ORDER);

        $queuedFromTheSaudiStorefront = $this->storefront('SA')->serve(
            fn (): object => new DispatchProviderFulfillment(fulfillmentId: $kuwaiti),
        );

        $this->assertSame('SA', $queuedFromTheSaudiStorefront->storefrontCountry, 'the payload still records where it was queued');
        $this->assertSame(
            'KW',
            $this->marketTheJobRunsIn($queuedFromTheSaudiStorefront),
            'a Kuwaiti shipment is worked as Kuwait even when a Saudi screen pushed it',
        );
    }

    /**
     * The webhook case, which is the one the dispatcher cannot answer at all: `api/fulfillment-webhooks` is a cross-country path, so
     * nothing is bound while the event is being stored and queued.
     */
    public function test_a_stored_provider_event_is_applied_in_the_market_of_its_order(): void
    {
        $emiratiShipment = $this->shipmentOf(orderId: self::EMIRATI_ORDER);
        $kuwaitiShipment = $this->shipmentOf(orderId: self::KUWAITI_ORDER);

        // The event is given the id of the EMIRATI shipment while being about the KUWAITI one, so a reader that took an event id for a
        // shipment id answers AE rather than null. Left to the engine, the first event and the first shipment are both id 1 and such a
        // confusion reads the right market by coincidence -- which is how this test's first teeth check came back inert.
        $job = new ProcessProviderEvent(eventId: $this->storedEventOf(fulfillmentId: $kuwaitiShipment, eventId: $emiratiShipment));

        $this->assertNull($job->storefrontCountry, 'a webhook binds no storefront, so the payload records none');
        $this->assertSame('KW', $this->marketTheJobRunsIn($job), 'the event names the shipment, the shipment the order, the order the market');
    }

    public function test_an_order_is_routed_in_the_market_it_was_sold_in(): void
    {
        $this->assertSame('KW', $this->marketTheJobRunsIn(new RouteOrderFulfillment(orderId: self::KUWAITI_ORDER)));
        $this->assertSame('SA', $this->marketTheJobRunsIn(new RouteOrderFulfillment(orderId: self::SAUDI_ORDER)));
    }

    public function test_two_shipments_of_two_markets_in_one_worker_do_not_share_a_market(): void
    {
        $jobs = [
            new DispatchProviderFulfillment(fulfillmentId: $this->shipmentOf(orderId: self::KUWAITI_ORDER)),
            new DispatchProviderFulfillment(fulfillmentId: $this->shipmentOf(orderId: self::EMIRATI_ORDER)),
            new DispatchProviderFulfillment(fulfillmentId: $this->shipmentOf(orderId: self::UNRECORDED_ORDER)),
        ];

        $this->assertSame(['KW', 'AE', null], array_map($this->marketTheJobRunsIn(...), $jobs));
        $this->assertNull($this->marketInForce());
    }

    /** A recovery or a console run that is itself serving a market gets it back, even though the job bound another. */
    public function test_the_market_the_process_was_serving_is_put_back(): void
    {
        $job = new DispatchProviderFulfillment(fulfillmentId: $this->shipmentOf(orderId: self::KUWAITI_ORDER));

        $this->storefront('AE')->serve(function () use ($job): void {
            $this->assertSame('KW', $this->marketTheJobRunsIn($job));
            $this->assertSame('AE', app(StorefrontContext::class)->country, 'the job did not take the process with it');
        });
    }

    /**
     * An order that records no market — sold before the snapshot existed, or while the switches were off — leaves the job exactly where it
     * was: on the market of the request that queued it, and unbound when there was none. The record is not invented and the default market
     * is not assumed, because assuming it would report a legacy order's money as a market's own.
     */
    public function test_an_order_recording_no_market_falls_back_to_the_request_that_queued_the_job(): void
    {
        $shipment = $this->shipmentOf(orderId: self::UNRECORDED_ORDER);

        $this->assertNull($this->marketTheJobRunsIn(new DispatchProviderFulfillment(fulfillmentId: $shipment)));

        $queuedFromKuwait = $this->storefront('KW')->serve(fn (): object => new DispatchProviderFulfillment(fulfillmentId: $shipment));

        $this->assertSame('KW', $this->marketTheJobRunsIn($queuedFromKuwait));
    }

    /** A shipment, an event or an order that is no longer there names no market, rather than failing the job that was asked about it. */
    public function test_a_record_that_has_gone_names_no_market(): void
    {
        $this->assertNull(MarketOfTheWork::ofFulfillment(fulfillmentId: 9_999));
        $this->assertNull(MarketOfTheWork::ofFulfillmentEvent(eventId: 9_999));
        $this->assertNull(MarketOfTheWork::ofOrder(orderId: 9_999));
        $this->assertNull(MarketOfTheWork::ofProduct(productId: 9_999));

        $orphan = $this->storedEventOf(fulfillmentId: 9_998, eventId: 9_997);

        $this->assertNull(MarketOfTheWork::ofFulfillmentEvent(eventId: $orphan), 'an event whose shipment has gone names no order');
    }

    /** A market that is not a two-letter code, or is not configured at all, is not bound: a typo must not become a storefront. */
    public function test_a_record_holding_something_that_is_not_a_market_is_not_bound(): void
    {
        foreach (['', '  ', 'x'] as $recorded) {
            DB::table('orders')->where('id', self::KUWAITI_ORDER)->update(['storefront_country' => $recorded]);

            $this->assertNull(
                MarketOfTheWork::ofOrder(orderId: self::KUWAITI_ORDER),
                'a record holding ' . var_export($recorded, true) . ' names no market',
            );
        }
    }

    /** A market is compared as it is stored. On a real engine the column is ascii_bin, so a lower-cased code is read back and upper-cased once, here. */
    public function test_a_market_recorded_in_lower_case_is_read_as_the_market_it_names(): void
    {
        DB::table('orders')->where('id', self::KUWAITI_ORDER)->update(['storefront_country' => 'kw']);

        $this->assertSame('KW', MarketOfTheWork::ofOrder(orderId: self::KUWAITI_ORDER));
    }

    /** An installation whose orders have no market column is every installation today: nothing is read and every job runs as it runs now. */
    public function test_a_database_that_has_not_run_the_orders_migration_reads_no_market(): void
    {
        $this->buildTheWorldQueuedWorkReadsItsMarketFrom(ordersRecordTheirMarket: false);

        $shipment = $this->shipmentOf(orderId: self::KUWAITI_ORDER);

        $this->assertNull(MarketOfTheWork::ofOrder(orderId: self::KUWAITI_ORDER));
        $this->assertNull($this->marketTheJobRunsIn(new DispatchProviderFulfillment(fulfillmentId: $shipment)));
    }

    public function test_a_restock_notice_is_written_in_the_market_of_the_product(): void
    {
        $listener = new RestockProductNotificationListener();

        $this->assertSame('KW', $this->marketOfTheRestockNotice(listener: $listener, productId: self::KUWAITI_PRODUCT));
        $this->assertSame('SA', $this->marketOfTheRestockNotice(listener: $listener, productId: self::SAUDI_PRODUCT));
    }

    /** Each market's work can be worked, paced and paused on its own, which is what the per-market queue is for. The default market keeps today's queue. */
    public function test_a_shipment_of_another_market_waits_on_that_markets_own_queue(): void
    {
        config(['gcc.storefronts.queues.per_market' => true]);

        $kuwaiti = $this->storefront('KW')->serve(
            fn (): object => new DispatchProviderFulfillment(fulfillmentId: $this->shipmentOf(orderId: self::KUWAITI_ORDER)),
        );
        $saudi = $this->storefront('SA')->serve(
            fn (): object => new DispatchProviderFulfillment(fulfillmentId: $this->shipmentOf(orderId: self::SAUDI_ORDER)),
        );

        $this->assertSame('storefront-kw', $kuwaiti->queue);
        $this->assertNull($saudi->queue, 'the default market keeps the queue every job waits on today');
    }

    private function marketOfTheRestockNotice(object $listener, int $productId): ?string
    {
        $event = (object) ['data' => ['product_id' => $productId, 'topic' => 'sixvalley']];
        $market = null;

        foreach ($listener->middleware($event) as $middleware) {
            $market = $middleware->handle($listener, fn (): ?string => $this->marketInForce());
        }

        return $market;
    }
}
