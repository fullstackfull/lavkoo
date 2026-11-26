<?php

namespace Tests\Feature\Notifications;

use App\Events\OrderStatusEvent;
use App\Listeners\OrderStatusListener;
use App\Models\Order;
use FilesystemIterator;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as OutboundRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Tests\Feature\Storefront\OrderNotificationTestCase;

/**
 * Owner decision 3 of 2026-11-26: an order status notification waits on the queue, is dispatched only once the transaction that changed the
 * status has committed, cannot be sent twice by a worker retry, and cannot be lost in silence.
 *
 * `OrderStatusListener` did not implement `ShouldQueue` and did not NAME it either, so it was absent from the 27-file queued-class census
 * ({@see \Tests\Feature\Queue\EveryQueuedClassDeclaresItsMarketTest}) and nothing in the suite noticed: every order status change on every
 * surface paid for the push inside the request, and the Google OAuth round trip as well whenever the 3300-second token cache was cold.
 *
 * **What the first two tests below MEASURE, rather than assert.** They are the evidence the owner's decision asked for, and they stay in
 * the suite because the cost of one send is what makes the queueing worth its complication:
 *
 *  - the fan-out: 38 fire sites in 6 controllers, counted by scanning the application (`test_the_count_of_places...`). It is the same 38
 *    in the same 6 files the Discovery-01 dossier counted independently with `rg`, in its section on the triggers and the queue
 *    (`docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E06-communications-compliance-activation.md`);
 *  - the unit cost: what ONE notification spends, run rather than reasoned about (`test_what_one_notification_costs_when_it_is_sent...`),
 *    by calling the listener the way the request used to call it and counting what left the server and what the database was asked.
 *
 * The rest prove the three queue properties the decision says are not optional, each on its own and each with teeth:
 *
 *  - **after commit** — `config/queue.php` sets `after_commit` on NO connection, so `ShouldQueue` alone does NOT give it here; the listener
 *    says `$afterCommit = true` for itself. Proved by a transaction that rolls back sending nothing, and by the send happening at
 *    `DB::transactionLevel() === 0`;
 *  - **no duplicate send** — `$tries = 1`, read off the job Laravel pushed rather than off the listener, because that is the copy a worker
 *    started with `--tries=3` obeys. A push carries no idempotency key, and the test below that sends one status change twice is the proof
 *    of that, not a complaint about it: it is I1-09's one remaining criterion and it stays open;
 *  - **no lost send** — the order comes back out of the payload in the worker, and an order that cannot be found raises
 *    `ModelNotFoundException` instead of quietly sending nothing, so the job fails into `failed_jobs` rather than vanishing.
 *
 * **What this file reported on the unqueued listener, kept so the fix is legible.** Run against it first, the two measurements above were
 * green and the five queue tests were red: nothing was pushed to the queue at all, the token mint and the FCM call both left the server
 * from inside the request, a status change whose transaction rolled back still told the customer it had happened, and the recorder below
 * read `DB::transactionLevel()` as 1 for both Google calls — the push left while the row it was about was still uncommitted.
 */
class OrderStatusNotificationWaitsOnTheQueueTest extends OrderNotificationTestCase
{
    /** The delivery man on the order, with their own device, so a notification sent to the rider is told apart from the buyer's. */
    private const RIDER = 7701;

    /**
     * Measured, by listening to the connection while one customer notification is built: five queries, and here is each of them.
     *
     *  1. the customer, lazy-loaded off the order for their device token and their language;
     *  2. the operator's own message row for this key and this kind of recipient (`notification_messages`);
     *  3. its translation for the language that recipient reads;
     *  4. **the order, read again** -- `PushNotificationTrait::textVariableDataFormat()` calls `Order::find()` for every message although
     *     only the `expected_delivery_date` key needs the row, and the caller is already holding the order. One wasted read per
     *     notification, reported rather than changed here: that line is shared by every sender of this platform;
     *  5. the attempt log asking ONCE per process whether its table exists (`NotificationAttemptLog::tableReady()`), which a test pays per
     *     case because each case gets a fresh container.
     *
     * It is one fewer than {@see self::QUERIES_OF_A_CUSTOMER_PUSH}, the order-PLACED figure, which also writes the notification row the
     * customer app's bell reads.
     */
    private const QUERIES_OF_AN_ORDER_STATUS_PUSH = 5;

    // ---- the measurement ---------------------------------------------------------------------------------------------------------------

    /**
     * How many places fire this event, and from which surfaces: the fan-out that multiplies the unit cost measured below.
     *
     * Counted by scanning the application rather than by trusting a figure in a document, and pinned per file so that a surface added or
     * removed is read by a person. `app/Http/Controllers/Admin/Order/OrderEditController.php` and its vendor twin IMPORT the event and
     * fire it nowhere, so they are deliberately not in this list: an unused import is not a surface.
     *
     * The worst single request is not one of these 38. `Admin\Order\OrderController::bulkUpdateStatus` calls `updateStatus` once per id
     * with a limit of 100 (`BULK_LIMIT`), and `updateStatus` fires up to three of them per order — the customer always, the delivery man
     * on a cancellation, the shop on a cancellation or a delivery — so one administrator pressing one button fires up to 300.
     */
    public function test_the_count_of_places_that_fire_an_order_status_notification_is_the_measured_fan_out(): void
    {
        $this->assertSame([
            'app/Http/Controllers/Admin/Order/OrderController.php' => 11,
            'app/Http/Controllers/RestAPI/v2/delivery_man/DeliveryManController.php' => 4,
            'app/Http/Controllers/RestAPI/v2/seller/OrderController.php' => 5,
            'app/Http/Controllers/RestAPI/v2/seller/RefundController.php' => 2,
            'app/Http/Controllers/RestAPI/v3/seller/OrderController.php' => 9,
            'app/Http/Controllers/Vendor/Order/OrderController.php' => 7,
        ], $this->everyPlaceThatFiresAnOrderStatusEvent(), 'the fan-out of the order status notification changed: re-measure what it costs');

        $this->assertSame(38, array_sum($this->everyPlaceThatFiresAnOrderStatusEvent()));
    }

    /**
     * What ONE notification costs when it is sent in the request, measured by calling the listener exactly as the request called it.
     *
     * Two outbound HTTPS calls for the first send of the hour: `PushNotificationTrait::getAccessToken()` mints an FCM OAuth token at
     * oauth2.googleapis.com (cached 3300 seconds, so the first send of each hour pays it) and then the FCM call itself is made. Every send
     * within the cache's life costs one. Both are blocking, both are to a host on the other side of the internet, and before this change
     * both were on the critical path of an administrator pressing a button.
     *
     * The query count is the platform's own figure for a customer push ({@see self::QUERIES_OF_A_CUSTOMER_PUSH}), asserted here so that the
     * two measurements are taken together.
     */
    public function test_what_one_notification_costs_when_it_is_sent_inside_the_request(): void
    {
        $order = $this->aKuwaitiOrderOfTheHouse();
        $this->countTheQueriesOfTheNotification();

        $this->sendItTheWayTheRequestUsedTo($order, key: 'pending', type: 'customer');

        $this->assertSame([
            'https://oauth2.googleapis.com/token',
            self::FCM_SEND_URL,
        ], $this->everyRequestThatLeftTheServer(), 'the first send of the hour pays for the token as well as for the push');
        $this->assertSame(self::QUERIES_OF_AN_ORDER_STATUS_PUSH, $this->queriesOfTheNotification);

        $this->sendItTheWayTheRequestUsedTo($order, key: 'pending', type: 'customer');

        $this->assertCount(3, $this->everyRequestThatLeftTheServer(), 'the second send reuses the cached token and costs one call');
    }

    /**
     * And what the three-recipient shape costs, which is what a cancellation of a shop's order is: three sends, three FCM calls, one
     * token mint in front of them, all four inside the one request.
     *
     * The three keys are the three this fixture's operator has a message for. A recipient whose message row is missing or switched off
     * costs nothing at all, because `pushNotificationMessage()` returns false before the send -- so this is the cost of a cancellation on
     * a platform whose operator has not switched any of the three messages off, which is the platform as the migration ships it.
     */
    public function test_three_recipients_of_one_status_change_cost_three_outbound_calls(): void
    {
        $order = $this->aKuwaitiOrderOfAShopWithARider();

        $this->sendItTheWayTheRequestUsedTo($order, key: 'pending', type: 'customer');
        $this->sendItTheWayTheRequestUsedTo($order, key: 'new_order_assigned_message', type: 'delivery_man');
        $this->sendItTheWayTheRequestUsedTo($order, key: 'new_order_message', type: 'seller');

        $this->assertCount(3, $this->pushesSent(), 'one FCM call per recipient, all three in the request');
        $this->assertCount(4, $this->everyRequestThatLeftTheServer(), 'and one token mint in front of them');
        $this->assertSame(
            ['customer-device', 'rider-device', 'seller-device'],
            array_map(static fn (OutboundRequest $push): string => (string) $push['message']['token'], $this->pushesSent()),
        );
    }

    /**
     * What queueing COSTS, measured rather than waved at: exactly one more query, and it is the order being read again.
     *
     * `SerializesModels` writes the order down as a class and an id, so the worker's first act is
     * `Order::newQueryWithoutScopes()->whereKey($id)->useWritePdo()->firstOrFail()`. That is the price of the payload carrying a reference
     * instead of a copy, and it is what makes the next two tests possible: a worker that re-reads cannot notify about a stale snapshot,
     * and cannot notify at all about an order that has gone.
     *
     * It is a query in the WORKER. What the request now pays is the dispatch alone -- no FCM call, no token mint, nothing to Google.
     */
    public function test_queueing_the_notification_costs_exactly_one_more_query_and_it_is_the_order_read_again(): void
    {
        $order = $this->aKuwaitiOrderOfTheHouse();
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        event(new OrderStatusEvent(key: 'pending', type: 'customer', order: $order));

        $this->assertCount(self::QUERIES_OF_AN_ORDER_STATUS_PUSH + 1, $queries, 'the queue hop is one query, not a handful');

        // The identifier quoting is the engine's: SQLite writes "orders" and MariaDB writes `orders`, so the quotes come out before the
        // comparison rather than the assertion being written for whichever engine it was first run on.
        $this->assertStringContainsString(
            'from orders',
            str_replace(['`', '"'], '', $queries[0]),
            'and it is the first thing the worker does: load the order it was handed an id for',
        );
    }

    // ---- it no longer costs the request anything ---------------------------------------------------------------------------------------

    /** With the queue held, the request that changed the status reaches no Google host at all — neither the token mint nor FCM. */
    public function test_a_status_change_sends_nothing_to_google_inside_the_request_that_made_it(): void
    {
        $order = $this->aKuwaitiOrderOfTheHouse();
        Queue::fake();

        event(new OrderStatusEvent(key: 'pending', type: 'customer', order: $order));

        $this->assertSame([], $this->everyRequestThatLeftTheServer(), 'the status change pays for no outbound call of its own');
        Queue::assertPushed(
            CallQueuedListener::class,
            static fn (CallQueuedListener $job): bool => $job->class === OrderStatusListener::class,
        );
    }

    /** And the three-recipient shape queues three jobs and still sends none of them from the request. */
    public function test_the_three_recipients_of_one_status_change_queue_three_jobs_and_send_none_of_them(): void
    {
        $order = $this->aKuwaitiOrderOfAShopWithARider();
        Queue::fake();

        event(new OrderStatusEvent(key: 'pending', type: 'customer', order: $order));
        event(new OrderStatusEvent(key: 'new_order_assigned_message', type: 'delivery_man', order: $order));
        event(new OrderStatusEvent(key: 'new_order_message', type: 'seller', order: $order));

        Queue::assertPushed(CallQueuedListener::class, 3);
        $this->assertSame([], $this->everyRequestThatLeftTheServer(), 'three recipients, and nothing sent from the request');
    }

    /** The worker that picks one up still sends it, in the language and the market of the order, exactly as the inline send did. */
    public function test_the_worker_running_it_sends_the_push_to_the_buyer_in_the_market_of_the_order(): void
    {
        event(new OrderStatusEvent(key: 'pending', type: 'customer', order: $this->aKuwaitiOrderOfTheHouse()));

        $push = $this->theOnePushSent();
        $this->assertSame('customer-device', $push['message']['token']);
        $this->assertSame(self::TITLE_IN_ARABIC, $push['message']['notification']['title']);
        $this->assertSame('Order ' . self::KW_ORDER . ' is pending', $push['message']['notification']['body']);
        $this->assertSame('KW', $push['message']['data']['storefront'], 'the market of the order, read inside the worker');
    }

    // ---- after commit ------------------------------------------------------------------------------------------------------------------

    /**
     * A status change that rolls back notifies nobody.
     *
     * This is the half `ShouldQueue` does not give on its own: `config/queue.php` sets `after_commit` on no connection, and Laravel's
     * default is false, so a listener that only implements `ShouldQueue` is dispatched the moment the event is fired — inside the
     * transaction. The listener says `$afterCommit = true` for itself, which `Illuminate\Events\Dispatcher::propagateListenerOptions`
     * copies onto the job it pushes, and `Illuminate\Queue\Queue::shouldDispatchAfterCommit` then hands the push to the transaction
     * manager instead of to the queue. A rollback discards it.
     *
     * The queue is NOT faked here, because `Illuminate\Support\Testing\Fakes\QueueFake::push` records the job immediately and knows nothing
     * about `after_commit`: a proof written with it would pass whether the property were there or not. The sync queue honours it
     * ({@see \Illuminate\Queue\SyncQueue::push}), so with the real queue a rolled-back transaction sends nothing at all and the teeth are
     * real on the engine this runs on.
     */
    public function test_a_status_change_whose_transaction_rolls_back_notifies_nobody(): void
    {
        $order = $this->aKuwaitiOrderOfTheHouse();

        try {
            DB::transaction(function () use ($order): void {
                DB::table('orders')->where('id', $order->id)->update(['order_status' => 'canceled']);
                event(new OrderStatusEvent(key: 'pending', type: 'customer', order: $order));

                throw new RuntimeException('the status change failed after the notification was raised');
            });
            $this->fail('the transaction was expected to roll back');
        } catch (RuntimeException) {
            // The rollback is the subject of the test.
        }

        $this->assertSame([], $this->everyRequestThatLeftTheServer(), 'a status change that never happened told nobody it did');
        $this->assertSame('pending', (string) Order::findOrFail($order->id)->order_status, 'and the status really did roll back');
    }

    /**
     * And the committed case, which is the other half of "after commit": the send happens, and it happens with no transaction open.
     *
     * The transaction level is read INSIDE the outbound call, which is the only place that can tell the difference. Without the property
     * the push leaves at level 1 — the row it is about is not yet committed, so a worker reading it over another connection would not find
     * it, and the device learns of a status no other process can see yet.
     */
    public function test_the_push_leaves_only_once_the_transaction_has_committed(): void
    {
        $order = $this->aKuwaitiOrderOfTheHouse();
        $levels = $this->recordTheTransactionLevelOfEveryOutboundCall();

        DB::transaction(function () use ($order): void {
            DB::table('orders')->where('id', $order->id)->update(['order_status' => 'confirmed']);
            event(new OrderStatusEvent(key: 'pending', type: 'customer', order: $order));
        });

        $this->assertCount(1, $this->pushesSent(), 'the committed status change does notify the buyer');
        $this->assertSame([0, 0], $levels(), 'both Google calls were made with no transaction open');
        $this->assertSame('confirmed', (string) Order::findOrFail($order->id)->order_status);
    }

    // ---- no duplicate send -------------------------------------------------------------------------------------------------------------

    /**
     * One attempt, and the worker is told so by the job rather than by its own command line.
     *
     * `tries` is read off the job Laravel pushed, not off the listener, because that is the copy a worker started with `--tries=3` obeys.
     * The push is not idempotent — see the next test — so one status change must not become three notifications because an operator chose
     * a retry count for the whole worker.
     */
    public function test_the_queued_notification_carries_one_attempt_so_a_worker_retry_cannot_duplicate_a_push(): void
    {
        $order = $this->aKuwaitiOrderOfTheHouse();
        Queue::fake();

        event(new OrderStatusEvent(key: 'pending', type: 'customer', order: $order));

        Queue::assertPushed(CallQueuedListener::class, function (CallQueuedListener $job): bool {
            $this->assertSame(OrderStatusListener::class, $job->class);
            $this->assertSame(1, $job->tries, 'a worker started with --tries=3 must not turn one status change into three pushes');
            $this->assertTrue($job->afterCommit, 'and the job itself has to carry the after-commit decision to the queue');

            return true;
        });
    }

    /**
     * Why `tries = 1` is the control and not a preference: the SAME notification sent twice is two identical pushes to the device.
     *
     * This pins I1-09's one remaining criterion — replay idempotency — as OPEN rather than closing it. There is no idempotency key in the
     * payload and no record keyed on one, so nothing downstream can tell a replay from a second real status change. Queueing the send
     * removes the duplicate a worker retry would have caused; it does not give the message an identity.
     *
     * What would close it is a key the sender derives from the order, the recipient and the message, stored and checked before the send.
     * That is a change to {@see \App\Services\Notification\SendsWithinABudget} and to `notification_send_attempts`, not to this listener,
     * and it is not made here.
     */
    public function test_one_status_change_sent_twice_is_two_identical_pushes_because_a_push_has_no_idempotency_key(): void
    {
        $order = $this->aKuwaitiOrderOfTheHouse();

        event(new OrderStatusEvent(key: 'pending', type: 'customer', order: $order));
        event(new OrderStatusEvent(key: 'pending', type: 'customer', order: $order));

        $pushes = $this->pushesSent();
        $this->assertCount(2, $pushes, 'nothing suppresses the second send of one status change');
        $this->assertSame($pushes[0]['message'], $pushes[1]['message'], 'and the device cannot tell them apart either');
        $this->assertArrayNotHasKey('idempotency_key', $pushes[0]['message']['data'], 'I1-09 replay idempotency is not built');
    }

    // ---- no lost send ------------------------------------------------------------------------------------------------------------------

    /**
     * The half a declaration cannot prove: the order comes back out of the payload in the worker.
     *
     * `OrderStatusEvent` uses `SerializesModels`, which was dead code while nothing queued this listener. Now the order is written down as
     * a class and an id and loaded again by the worker, and everything the notification is built from — the recipient's language, the
     * shop's name, the rider's device — only survives if that load works. The sync queue serialises the payload and unserialises it
     * again ({@see \Illuminate\Queue\SyncQueue::executeJob}), so this is a real round trip.
     *
     * **Two INERT teeth checks, written in rather than left as false coverage, because both were measured on SQLite:**
     *
     *  - taking `SerializesModels` off `OrderStatusEvent` leaves this test GREEN. PHP's own `serialize()` writes the whole Order object
     *    into the payload and the sync queue hands it back, so the model identifier is not what this test depends on. What makes
     *    `SerializesModels` load-bearing is the NEXT test, where the order no longer exists;
     *  - the `storefront` assertion below does NOT prove the market binding. Taking `OrderNotificationContext::within()` out of the
     *    listener also leaves this test green, because `PushNotificationTrait` adds the market through `marketOf()` independently of the
     *    context. The test with teeth for the binding is
     *    {@see self::test_the_worker_running_it_sends_the_push_to_the_buyer_in_the_market_of_the_order}, which fails on the TITLE: it is
     *    `translate('order')` in the language the context puts in force, so without the binding it comes back in English.
     *
     * What this test does prove, measured by taking the `delivery_men` row away and watching it go red: the delivery man is resolved off
     * the order INSIDE the worker. Without that there is no device to send to and no row for the rider's app to read.
     */
    public function test_the_order_and_its_relations_survive_the_round_trip_through_the_queue(): void
    {
        $order = Order::with(['customer', 'seller.shop', 'deliveryMan'])->findOrFail($this->aKuwaitiOrderOfAShopWithARider()->id);

        event(new OrderStatusEvent(key: 'new_order_assigned_message', type: 'delivery_man', order: $order));

        $push = $this->theOnePushSent();
        $this->assertSame('rider-device', $push['message']['token'], 'the delivery man was loaded in the worker');
        $this->assertSame('Order ' . self::KW_ORDER . ' is assigned to you', $push['message']['notification']['body']);
        $this->assertSame('KW', $push['message']['data']['storefront']);
        $this->assertSame(
            1,
            DB::table('deliveryman_notifications')->where('order_id', self::KW_ORDER)->count(),
            'and the row the rider app reads was written by the worker, not by the request',
        );
    }

    /**
     * An order the worker cannot find FAILS the job rather than sending nothing in silence.
     *
     * This is the loss that queueing introduces and the reason the round trip above is tested at all: `SerializesModels` restores the order
     * with `firstOrFail()`, so an order deleted between the status change and the worker raises `ModelNotFoundException` before `handle()`
     * is entered. With `tries = 1` the job goes straight to `failed_jobs`, where an operator can see it. The alternative — the listener
     * swallowing it, as `PushNotificationTrait::sendOrderNotification`'s own catch block swallows everything inside `handle()` — is the
     * silence I1-09 exists to end.
     *
     * The payload is serialised and restored directly rather than through a worker, because what is being proved is a property of the
     * payload: that it carries a reference and not a copy, so a vanished order cannot be notified about from a stale snapshot.
     */
    public function test_an_order_deleted_before_the_worker_reaches_it_fails_the_job_instead_of_sending_nothing(): void
    {
        $event = new OrderStatusEvent(key: 'pending', type: 'customer', order: $this->aKuwaitiOrderOfTheHouse());
        $payload = serialize($event);

        DB::table('orders')->where('id', self::KW_ORDER)->delete();

        $this->expectException(ModelNotFoundException::class);
        unserialize($payload);
    }

    // ---- the world -------------------------------------------------------------------------------------------------------------------

    private function aKuwaitiOrderOfTheHouse(): Order
    {
        $this->switchStorefrontsOn();

        return $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'seller_is' => 'admin',
            'order_status' => 'pending',
        ]);
    }

    private function aKuwaitiOrderOfAShopWithARider(): Order
    {
        $this->switchStorefrontsOn();
        $rider = $this->deliveryMan(id: self::RIDER, token: 'rider-device', language: 'sa');

        return $this->sellOrderIn(country: 'KW', orderId: self::KW_ORDER, orderColumns: [
            'customer_id' => $this->customerWhoReadsArabic()->id,
            'seller_id' => $this->sellerWhoReads('sa')->id,
            'seller_is' => 'seller',
            'delivery_man_id' => $rider->id,
            'deliveryman_charge' => self::CHARGE_STORED,
            'order_status' => 'pending',
        ]);
    }

    /** The listener called the way the 38 fire sites called it before it was queued: in the request, with nothing between. */
    private function sendItTheWayTheRequestUsedTo(Order $order, string $key, string $type): void
    {
        (new OrderStatusListener())->handle(new OrderStatusEvent(key: $key, type: $type, order: $order));
    }

    /** @return list<string> every URL this request reached, so a send that is no longer inline cannot hide behind a different host */
    private function everyRequestThatLeftTheServer(): array
    {
        return Http::recorded()
            ->map(static fn (array $exchange): string => $exchange[0]->url())
            ->values()
            ->all();
    }

    /**
     * Answers Google exactly as the base case does, and notes the transaction level each call was made at.
     *
     * @return callable(): list<int>
     */
    private function recordTheTransactionLevelOfEveryOutboundCall(): callable
    {
        $levels = [];

        Http::swap(new Factory($this->app['events']));
        Http::fake(function (OutboundRequest $request) use (&$levels) {
            $levels[] = DB::transactionLevel();

            return str_contains($request->url(), 'oauth2.googleapis.com')
                ? Http::response(['access_token' => 'minted-access-token'])
                : Http::response(['name' => 'projects/lavkoo-test/messages/1']);
        });

        return function () use (&$levels): array {
            return $levels;
        };
    }

    /**
     * Every place in the application that raises an order status notification, and how many times each one does it.
     *
     * @return array<string, int> the file, relative to the project root, => the number of fire sites in it, sorted by file
     */
    private function everyPlaceThatFiresAnOrderStatusEvent(): array
    {
        $found = [];

        foreach (['app', 'Modules'] as $directory) {
            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $fires = preg_match_all(
                    '/(?:event\s*\(\s*new\s+OrderStatusEvent|OrderStatusEvent::dispatch)\s*\(/',
                    (string) file_get_contents($file->getPathname()),
                );

                if ($fires > 0) {
                    $found[str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname())] = $fires;
                }
            }
        }

        ksort($found);

        return $found;
    }
}
