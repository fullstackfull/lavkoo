<?php

namespace App\Listeners;

use App\Traits\PushNotificationTrait;
use App\Events\CustomerRegisteredViaReferralEvent;

class SendReferralRegistrationNotificationListener
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
    public function handle(CustomerRegisteredViaReferralEvent $event): void
    {
        $referralCustomer = $event->referralCustomer;
        $referrer = $event->referredBy;
        $fcmToken = $referrer?->cm_firebase_token ?? '';

        $postData = [
            'title' => translate('Someone_registered_using_your_referral_code!'),
            'description' => translate('Someone_used_your_referral_code_once_order_is_placed_you_will_get_your_rewards'),
            'order_id' => '',
            'deliveryman_charge' => '',
            'expected_delivery_date' => '',
            'image' => '',
            'type' => 'referral_code_used'
        ];

        $this->sendPushNotificationToDevice(fcmToken: $fcmToken, data: $postData);
        $referralCustomer->registered_notify = 1;
        $referralCustomer->save();
    }
}
