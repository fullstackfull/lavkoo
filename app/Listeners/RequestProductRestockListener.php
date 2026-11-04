<?php

namespace App\Listeners;

use App\Events\RequestProductRestockEvent;
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
class RequestProductRestockListener implements ShouldQueue
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
    public function handle(RequestProductRestockEvent $event): void
    {
        $this->sendNotification($event);
    }

    private function sendNotification(RequestProductRestockEvent $event): void
    {
        $data = $event->data;

        if (isset($data['firebase_token'])) {
            $postData = [
                'message' => [
                    'token' => $data['firebase_token'],
                    'data' => [
                        'title' => (string)$data['title'],
                        'body' => (string)$data['body'],
                        'image' => (string)($data['image'] ?? ''),
                    ],
                    'notification' => [
                        'title' => (string)$data['title'],
                        'body' => (string)$data['body'],
                    ]
                ]
            ];
            $this->sendNotificationToHttp($postData);
        }
    }
}
