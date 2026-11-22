<?php

namespace App\Listeners;

use App\Events\OrderStatusEvent;
use App\Services\Storefront\OrderNotificationContext;
use App\Traits\PushNotificationTrait;

class OrderStatusListener
{
    use PushNotificationTrait;

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
