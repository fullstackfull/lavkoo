<?php

namespace App\Listeners;

use App\Events\OrderStatusEvent;
use App\Services\Storefront\OrderNotificationContext;
use App\Traits\PushNotificationTrait;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Queued, and dispatched only once the transaction that changed the status has committed.
 *
 * This file did not implement `ShouldQueue` and did not NAME it either, so it was absent from the queued-class census
 * ({@see \Tests\Feature\Queue\EveryQueuedClassDeclaresItsMarketTest}, which counts the FILES that name the contract) and from the staff
 * inventory ({@see \Tests\Support\StaffJobInventory}, whose scan reads `implements ... ShouldQueue`). Nothing in the suite noticed, and
 * every order status change on every surface paid for the push inside the request.
 *
 * **Measured before the change** by `Tests\Feature\Notifications\OrderStatusNotificationWaitsOnTheQueueTest`, which keeps all of these as
 * assertions so they are re-measured rather than remembered:
 *
 *  - `OrderStatusEvent` is fired from **38 places in 6 controllers**: the admin panel (11), the vendor panel (7), the seller API v3 (9),
 *    the seller API v2 (5, plus 2 in its refund controller) and the delivery-man API v2 (4). The Discovery-01 dossier counted the same 38
 *    in the same 6 files independently.
 *  - **One notification cost 2 outbound HTTPS calls** the first time in any 3300 seconds -- `PushNotificationTrait::getAccessToken()`
 *    minting an FCM OAuth token at oauth2.googleapis.com, then the FCM call itself -- and 1 call for every send after that, plus 5
 *    queries, one of which is a re-read of the order the caller is already holding.
 *  - **A status change with three recipients cost four**: three FCM calls and the token mint, all in the request. That is the shape of a
 *    cancellation of a shop's order, which tells the buyer, the shop and the delivery man.
 *  - The worst single request is not one of the 38. `Admin\Order\OrderController::bulkUpdateStatus` calls `updateStatus` once per id with
 *    a limit of 100, and `updateStatus` fires up to three notifications per order, so one button could pay for up to **300** FCM calls.
 *  - And the push left the server at `DB::transactionLevel() === 1` -- inside the open transaction -- which is the half below.
 *
 * **`ShouldQueue` alone is not enough here, and that is why `$afterCommit` is declared.** No connection in `config/queue.php` sets
 * `after_commit`, and Laravel's default is false, so a listener that only implements `ShouldQueue` is dispatched the moment the event is
 * fired -- which, inside a transaction, means a notification about a status the database may yet roll back. The property is copied onto the
 * pushed job by `Illuminate\Events\Dispatcher::propagateListenerOptions`, and `Illuminate\Queue\Queue::shouldDispatchAfterCommit` then
 * hands the push to the transaction manager, which discards it on a rollback and runs it on a commit. Declared on the class rather than on
 * the connection, because the connection is the operator's file and this is a property of this notification.
 *
 * It binds no market in queue middleware, as the other order listeners do not: the market of this notification is the market the ORDER was
 * sold in, which {@see OrderNotificationContext} reads from the order itself. The panel or the API that changed the status may be serving
 * every market or none.
 */
class OrderStatusListener implements ShouldQueue
{
    use PushNotificationTrait;

    /**
     * Dispatch only after the transaction that changed the status has committed, and immediately when there is none open.
     *
     * {@see \Illuminate\Database\DatabaseTransactionsManager::addCallback}, which calls straight through when no transaction is active, so
     * the 38 fire sites that are outside one are unaffected.
     */
    public bool $afterCommit = true;

    /**
     * One attempt, whatever the worker was started with.
     *
     * A second run of this job is a replay, and whether it sends the customer a second notification is now the channel's answer rather
     * than nobody's: {@see \App\Services\Notification\MessageIdentity} is claimed before the send where an operator has turned
     * `gcc.notifications.idempotency` on, and the platform's default is still two pushes. The retry that is wanted here is the channel's
     * own, inside
     * {@see \App\Services\Notification\SendsWithinABudget}, which stores each attempt and marks the last as final. An operator running
     * `queue:work --tries=3` must not turn one cancellation into three pushes, so the class answers for itself.
     *
     * The trade this makes, stated rather than left implicit: a transient FCM failure is not retried by the worker. What stops it being a
     * SILENT loss is `NotificationAttemptLog`, which records every attempt with the provider's own error -- and a job that fails before
     * `handle()` is entered, because the order it names has been deleted, lands in `failed_jobs` rather than vanishing.
     */
    public int $tries = 1;

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(OrderStatusEvent $event): void
    {
        $this->sendNotification($event);
    }

    private function sendNotification(OrderStatusEvent $event): void
    {
        $key = $event->key;
        $type = $event->type;
        $order = $event->order;
        OrderNotificationContext::within(order: $order, userType: $type, callback: fn () => $this->sendOrderNotification(key: $key, type: $type, order: $order));
    }
}
