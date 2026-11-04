<?php

namespace App\Listeners;

use App\Events\OrderEditEvent;
use App\Traits\EmailTemplateTrait;
use App\Traits\PushNotificationTrait;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Listeners\Concerns\QueuedMailDelivery;


/**
 * Queued: this listener sends mail, and sending inline let an unreachable SMTP host
 * hold the whole request until the connect timed out — measured at ~60 seconds per
 * recipient on real data. The mail host comes from business_settings, and
 * EmailTemplateTrait::sendingMail() already swallows the exception, so the failure
 * was silent as well as slow.
 */
class OrderEditListener implements ShouldQueue
{
    use PushNotificationTrait, EmailTemplateTrait, QueuedMailDelivery;

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
    public function handle(OrderEditEvent $event): void
    {
        if ($event->notification) {
            $this->sendNotification($event);
        }
    }

    private function sendNotification(OrderEditEvent $event): void
    {
        $key = $event->notification->key;
        $type = $event->notification->type;
        $order = $event->notification->order;
        $this->sendOrderNotification(key: $key, type: $type, order: $order);
    }
}
