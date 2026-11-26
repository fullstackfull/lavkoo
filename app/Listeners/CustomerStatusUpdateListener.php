<?php

namespace App\Listeners;

use App\Events\CustomerStatusUpdateEvent;
use App\Traits\EmailTemplateTrait;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Listeners\Concerns\QueuedMailDelivery;

/**
 * Blocking or unblocking a customer emails them.
 *
 * Queued, because sending inline made that email define the request: the mail host
 * comes from business_settings, and when it is unreachable the SMTP connect blocks
 * until it times out — measured at 60 seconds for a single customer on this data.
 * The admin sat through a minute per toggle, and any batch died mid-way.
 *
 * The exception in sendingMail() is already swallowed, so the failure was silent
 * as well as slow. Queuing needs no new infrastructure, but it does need the queue
 * worker to be running.
 *
 * This paragraph used to read "Queuing follows RefundListener, which this project already runs on the queue", and that was NOT true when it
 * was written: RefundListener named ShouldQueue and implemented nothing, so a refund push was sent inside the request that recorded the
 * refund. It is queued now, so the sentence would be true -- but it is taken out rather than corrected, because a justification that rests
 * on another class is a justification that can go stale silently. The reason this listener is queued is the measured 60-second SMTP connect
 * above, which stands on its own.
 */
class CustomerStatusUpdateListener implements ShouldQueue
{
    use EmailTemplateTrait, QueuedMailDelivery;
    public function __construct()
    {
        //
    }

    public function handle(CustomerStatusUpdateEvent $event): void
    {
        $this->sendMail($event);
    }

    private function sendMail(CustomerStatusUpdateEvent $event):void{
        $email = $event->email;
        $data = $event->data;
        $this->sendingMail(sendMailTo: $email,userType: $data['userType'],templateName: $data['templateName'],data: $data);
    }
}
