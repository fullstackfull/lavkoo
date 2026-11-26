<?php

namespace Tests\Feature\Notifications;

use App\Events\RefundEvent;
use App\Listeners\RefundListener;
use App\Models\OrderDetail;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Storefront\OrderNotificationTestCase;

/**
 * I1-09: a refund notification waits on the queue instead of being sent inside the request that recorded the refund.
 *
 * `RefundListener` imported `ShouldQueue` and `InteractsWithQueue` and implemented neither, so it was not queued at all. A refund decision
 * therefore paid for the notification in the request that took it: `PushNotificationTrait::getAccessToken()` mints an FCM OAuth token (a
 * round trip to oauth2.googleapis.com, cached 3300s, so the first send of each hour pays it) and then the FCM call itself is made, twice
 * over for a seller order, which notifies the shop and the buyer. Four surfaces reach it -- the admin panel, the seller API, the customer
 * API and the website -- and every neighbouring mail listener had already been queued for exactly this fault.
 *
 * Why this test exists next to {@see \Tests\Feature\Queue\EveryQueuedClassDeclaresItsMarketTest}, which also reads the contract: that one
 * reads a DECLARATION. A listener that implements `ShouldQueue` can still lose the notification altogether if the event it is handed does
 * not come back out of the payload, and no declaration can tell. So this one drives the real event -- once with the queue held, to see that
 * nothing leaves the server in the request, and once through the queue, to see that the same push still leaves it. The sync queue serialises
 * the payload and unserialises it again ({@see \Illuminate\Queue\SyncQueue::executeJob}), so the second half is a real round trip rather
 * than a direct call.
 *
 * Not covered: the refund row that three of the four callers hand as a MODEL rather than an array. Its id is never read -- the sender puts
 * only `order_details_id` in the payload -- so what matters about it is that it loads in the worker, which is the one mechanism the order
 * and the order line below do exercise. Giving it its own case means this class building `refund_requests`, and a test that builds a
 * migrated table has to put it back or take it from every class that runs after it.
 */
class RefundNotificationWaitsOnTheQueueTest extends OrderNotificationTestCase
{
    /** With the queue held, the request that recorded the refund reaches no Google host at all -- neither the token mint nor FCM. */
    public function test_a_refund_sends_nothing_to_google_inside_the_request_that_recorded_it(): void
    {
        $order = $this->aRefundedKuwaitiOrderOfTheHouse();
        Queue::fake();

        event(new RefundEvent(status: 'refunded', order: $order, refund: ['id' => 5], orderDetails: null));

        $this->assertSame([], $this->everyRequestThatLeftTheServer(), 'the refund decision pays for no outbound call of its own');
        Queue::assertPushed(
            CallQueuedListener::class,
            static fn (CallQueuedListener $job): bool => $job->class === RefundListener::class,
        );
    }

    /** And the worker that picks it up sends it, in the language and the market of the order, exactly as the inline send did. */
    public function test_the_worker_running_it_sends_the_refund_push_to_the_buyer(): void
    {
        $order = $this->aRefundedKuwaitiOrderOfTheHouse();

        event(new RefundEvent(status: 'refunded', order: $order, refund: ['id' => 5], orderDetails: null));

        $push = $this->theOnePushSent();
        $this->assertSame('customer-device', $push['message']['token']);
        $this->assertSame(self::TITLE_IN_ARABIC, $push['message']['notification']['title']);
        $this->assertSame('Order ' . self::KW_ORDER . ' was refunded', $push['message']['notification']['body']);
        $this->assertSame('KW', $push['message']['data']['storefront'], 'the market of the order, read inside the worker');
    }

    /**
     * The payload the four callers really hand it, through the queue: a persisted order and the persisted order line.
     *
     * This is the half a declaration cannot prove. `RefundEvent` uses `SerializesModels`, which was dead code while nothing queued the
     * listener; now every model on the event is written down as a class and an id and loaded again in the worker, and `order_details_id` --
     * read off the line and sent to the device so the app can open the right line -- only survives if that load works. A line the worker
     * cannot find throws `ModelNotFoundException` instead, and the notification is lost rather than late.
     */
    public function test_the_order_and_the_line_survive_the_round_trip_through_the_queue(): void
    {
        $order = $this->aRefundedKuwaitiOrderOfTheHouse();
        $line = OrderDetail::where('order_id', self::KW_ORDER)->firstOrFail();

        event(new RefundEvent(status: 'refunded', order: $order, refund: ['id' => 5], orderDetails: $line));

        $push = $this->theOnePushSent();
        $this->assertSame((string) $line->id, (string) $push['message']['data']['order_details_id'], 'the line the app has to open');
        $this->assertSame((string) self::KW_ORDER, (string) $push['message']['data']['order_id']);
    }

    /**
     * One attempt, and the worker is told so by the job rather than by its own command line.
     *
     * A push carries no idempotency key (I1-09's remaining criterion), so a second run of this job is a second notification to the customer
     * about one refund. The retry that is wanted lives in the channel, {@see \App\Services\Notification\SendsWithinABudget}, which records
     * every attempt and marks only the last as final. `tries` is read off the job Laravel pushed, not off the listener, because that is the
     * copy a worker started with `--tries=3` would obey.
     */
    public function test_the_queued_refund_notification_carries_one_attempt_so_a_worker_retry_cannot_duplicate_a_push(): void
    {
        $order = $this->aRefundedKuwaitiOrderOfTheHouse();
        Queue::fake();

        event(new RefundEvent(status: 'refunded', order: $order, refund: ['id' => 5], orderDetails: null));

        Queue::assertPushed(CallQueuedListener::class, function (CallQueuedListener $job): bool {
            $this->assertSame(RefundListener::class, $job->class);
            $this->assertSame(1, $job->tries, 'a worker started with --tries=3 must not turn one refund into three pushes');

            return true;
        });
    }

    /** A seller order has two recipients, and the request pays for neither: one job is queued and it is the worker that sends both. */
    public function test_a_seller_refund_queues_one_job_rather_than_sending_to_the_shop_and_the_buyer_in_the_request(): void
    {
        $order = $this->aRefundedKuwaitiOrderOfAShop();
        Queue::fake();

        event(new RefundEvent(status: 'refunded', order: $order, refund: ['id' => 5], orderDetails: null));

        Queue::assertPushed(CallQueuedListener::class, 1);
        $this->assertSame([], $this->everyRequestThatLeftTheServer(), 'two recipients, and still nothing sent from the request');
    }

    /** The same order with the queue running: both recipients are reached, so queueing did not drop the shop's copy. */
    public function test_the_worker_notifies_both_the_shop_and_the_buyer_of_a_seller_refund(): void
    {
        $order = $this->aRefundedKuwaitiOrderOfAShop();

        event(new RefundEvent(status: 'refunded', order: $order, refund: ['id' => 5], orderDetails: null));

        $pushes = $this->pushesSent();
        $this->assertCount(2, $pushes);
        $this->assertSame('seller-device', $pushes[0]['message']['token']);
        $this->assertSame('customer-device', $pushes[1]['message']['token']);
    }

    private function aRefundedKuwaitiOrderOfTheHouse(): \App\Models\Order
    {
        $this->switchStorefrontsOn();

        return $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'seller_is' => 'admin',
        ]);
    }

    private function aRefundedKuwaitiOrderOfAShop(): \App\Models\Order
    {
        $this->switchStorefrontsOn();

        return $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'seller_id' => $this->sellerWhoReads('sa')->id,
            'seller_is' => 'seller',
        ]);
    }

    /** @return list<string> every URL this request reached, so a send that is no longer inline cannot hide behind a different host */
    private function everyRequestThatLeftTheServer(): array
    {
        return Http::recorded()
            ->map(static fn (array $exchange): string => $exchange[0]->url())
            ->values()
            ->all();
    }
}
