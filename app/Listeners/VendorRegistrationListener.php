<?php

namespace App\Listeners;

use App\Events\VendorRegistrationEvent;
use App\Traits\EmailTemplateTrait;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Listeners\Concerns\QueuedMailDelivery;

/**
 * Approving, rejecting or suspending a vendor emails them.
 *
 * Queued for the same reason as CustomerStatusUpdateListener: sending inline made
 * an unreachable SMTP host block the admin's request until the connect timed out
 * — measured at ~60 seconds per recipient on this data.
 */
class VendorRegistrationListener implements ShouldQueue
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
    public function handle(VendorRegistrationEvent $event): void
    {
        $this->sendMail($event);
    }

    private function sendMail(VendorRegistrationEvent $event):void{
        $email = $event->email;
        $data = $event->data;
        $this->sendingMail(sendMailTo: $email,userType: $data['userType'],templateName: $data['templateName'],data: $data);
    }
}
