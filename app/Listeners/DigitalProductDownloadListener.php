<?php

namespace App\Listeners;

use App\Events\DigitalProductDownloadEvent;
use App\Traits\EmailTemplateTrait;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Listeners\Concerns\QueuedMailDelivery;


/**
 * Queued: this listener sends mail, and sending inline let an unreachable SMTP host
 * hold the whole request until the connect timed out — measured at ~60 seconds per
 * recipient on real data. The mail host comes from business_settings, and
 * EmailTemplateTrait::sendingMail() already swallows the exception, so the failure
 * was silent as well as slow.
 */
class DigitalProductDownloadListener implements ShouldQueue
{
    use EmailTemplateTrait, QueuedMailDelivery;

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
    public function handle(DigitalProductDownloadEvent $event): void
    {
        $this->sendMail($event);
    }

    private function sendMail(DigitalProductDownloadEvent $event): void
    {
        $email = $event->email;
        $data = $event->data;
        $this->sendingMail(sendMailTo: $email, userType: $data['userType'], templateName: $data['templateName'], data: $data);
    }
}
