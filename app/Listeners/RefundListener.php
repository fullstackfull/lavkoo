<?php

namespace App\Listeners;

use App\Events\RefundEvent;
use App\Services\Storefront\OrderNotificationContext;
use App\Traits\PushNotificationTrait;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Queued: telling a customer or a seller about a refund costs up to two blocking HTTP calls to Google, and they used to be made inside the
 * request that recorded the refund decision.
 *
 * This file NAMED `ShouldQueue` and `InteractsWithQueue` from the day it was written and implemented neither, so none of it was queued. A
 * refund decision therefore paid, in the administrator's or the seller's own request: `PushNotificationTrait::getAccessToken()` minting an
 * FCM OAuth token (a round trip to oauth2.googleapis.com, cached for 3300s, so the first send of every hour pays it) and then the FCM call
 * itself, twice over for a seller order, which notifies the shop and the buyer. `SendsWithinABudget` will repeat both if an operator ever
 * raises `gcc.notifications.attempts`, sleeping `gcc.notifications.retry_delay_ms` between them -- in the request. That is the same fault
 * the neighbouring mail listeners were queued for, and the refund path is the one that must not be slow: it is reached from the admin
 * panel, the seller API, the customer API and the website.
 *
 * `InteractsWithQueue` is gone rather than added. It exists to let a listener release or re-try its own job, and the retry of a push belongs
 * to the channel ({@see \App\Services\Notification\SendsWithinABudget}), which counts and records every attempt.
 *
 * It binds no market in queue middleware, as the other order listeners do not: the market of a refund is the market the ORDER was sold in,
 * which {@see OrderNotificationContext} reads from the order itself. A refund stays open after a market has stopped selling, so the market
 * of the request that recorded it is the wrong answer even when there is one.
 */
class RefundListener implements ShouldQueue
{
    use PushNotificationTrait;

    /**
     * One attempt, whatever the worker was started with.
     *
     * A push carries no idempotency key yet (I1-09), so a second run of this job sends the customer a second notification about one refund.
     * The retry that is wanted here is the channel's own, inside `SendsWithinABudget`, which stores each attempt and marks the last as
     * final. An operator running `queue:work --tries=3` must not turn one refund into three pushes, so the class answers for itself.
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
    public function handle(RefundEvent $event): void
    {
        $this->sendNotification($event);
    }

    private function sendNotification(RefundEvent $event):void{
        $status = $event->status;
        $order = $event->order;
        $orderDetails = $event?->orderDetails;

        $data = [
            'refund' => $event->refund,
            'order_details_id' => $orderDetails?->id,
        ];

        if ($order['seller_is'] == 'seller') {
            if ($status != 'rejected' && $status != 'refunded') {
                $key = 'refund_request_status_changed_by_admin';
            } elseif ($status == 'rejected') {
                $key = 'refund_request_canceled_message';
            }elseif ($status == 'refund_request') {
                $key = 'refund_request_message';
            } else {
                $key = 'order_refunded_message';
            }
            OrderNotificationContext::within(order: $order, userType: 'seller', callback: fn () => $this->sendOrderNotification(key: $key, type: 'seller', order: $order, data: $data));
        }

        if ($status == 'refunded') {
            OrderNotificationContext::within(order: $order, userType: 'customer', callback: fn () => $this->sendOrderNotification(key: 'order_refunded_message', type: 'customer', order: $order, data: $data));
        } elseif ($status == 'rejected') {
            OrderNotificationContext::within(order: $order, userType: 'customer', callback: fn () => $this->sendOrderNotification(key: 'refund_request_canceled_message', type: 'customer', order: $order, data: $data));
        }
    }
}
