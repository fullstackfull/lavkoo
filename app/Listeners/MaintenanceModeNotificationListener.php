<?php

namespace App\Listeners;

use App\Events\MaintenanceModeNotificationEvent;
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
class MaintenanceModeNotificationListener implements ShouldQueue
{
    use EmailTemplateTrait, PushNotificationTrait, QueuedMailDelivery;

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
    public function handle(MaintenanceModeNotificationEvent $event): void
    {
        $this->sendNotification($event);
    }

    private function sendNotification(MaintenanceModeNotificationEvent $event): void
    {
        $data = $event->data;
        $this->sendNotificationToHttp([
            'message' => [
                'topic' => $data['topic'],
                'data' => [
                    'title' => (string)$data['title'],
                    'body' => (string)$data['description'],
                    'image' => $data['image'] ?? '',
                    'type' => (string)$data['type'],
                    'is_read' => '0'
                ],
//                'notification' => [
//                    'title' => (string)$data['title'],
//                    'body' => (string)$data['description'],
//                ]
            ]
        ]);
    }
}
