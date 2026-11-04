<?php

namespace App\Providers;

use App\Events\OrderEditDuePaymentEvent;
use App\Events\OrderEditEvent;
use App\Events\OrderEditReturnPaymentEvent;
use App\Events\RefundEvent;
use App\Events\ChattingEvent;
use App\Events\CashCollectEvent;
use App\Events\OrderPlacedEvent;
use App\Events\OrderStatusEvent;
use App\Listeners\OrderEditDuePaymentListener;
use App\Listeners\OrderEditListener;
use App\Listeners\OrderEditReturnPaymentListener;
use App\Listeners\RefundListener;
use App\Events\PasswordResetEvent;
use App\Listeners\ChattingListener;
use App\Events\AddFundToWalletEvent;
use App\Events\EmailVerificationEvent;
use App\Listeners\CashCollectListener;
use App\Listeners\OrderPlacedListener;
use App\Listeners\OrderStatusListener;
use App\Events\VendorRegistrationEvent;
use App\Listeners\PasswordResetListener;
use App\Events\CustomerRegistrationEvent;
use App\Events\CustomerStatusUpdateEvent;
use App\Events\WithdrawStatusUpdateEvent;
use App\Events\RequestProductRestockEvent;
use App\Listeners\AddFundToWalletListener;
use App\Events\DigitalProductDownloadEvent;
use App\Listeners\EmailVerificationListener;
use App\Events\DeliverymanPasswordResetEvent;
use App\Listeners\VendorRegistrationListener;
use App\Events\ProductRequestStatusUpdateEvent;
use App\Events\RestockProductNotificationEvent;
use App\Listeners\CustomerRegistrationListener;
use App\Listeners\CustomerStatusUpdateListener;
use App\Listeners\WithdrawStatusUpdateListener;
use App\Events\MaintenanceModeNotificationEvent;
use App\Listeners\RequestProductRestockListener;
use App\Listeners\DigitalProductDownloadListener;
use App\Events\CustomerRegisteredViaReferralEvent;
use App\Events\DigitalProductOtpVerificationEvent;
use App\Listeners\DeliverymanPasswordResetListener;
use App\Listeners\ProductRequestStatusUpdateListener;
use App\Listeners\RestockProductNotificationListener;
use App\Listeners\MaintenanceModeNotificationListener;
use App\Listeners\DigitalProductOtpVerificationListener;
use App\Listeners\SendReferralRegistrationNotificationListener;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        AddFundToWalletEvent::class => [
            AddFundToWalletListener::class,
        ],
        DigitalProductOtpVerificationEvent::class => [
            DigitalProductOtpVerificationListener::class,
        ],
        DeliverymanPasswordResetEvent::class => [
            DeliverymanPasswordResetListener::class,
        ],
        EmailVerificationEvent::class => [
            EmailVerificationListener::class,
        ],
        PasswordResetEvent::class => [
            PasswordResetListener::class,
        ],
        OrderPlacedEvent::class => [
            OrderPlacedListener::class,
        ],
        OrderEditEvent::class => [
            OrderEditListener::class,
        ],
        OrderEditDuePaymentEvent::class => [
            OrderEditDuePaymentListener::class,
        ],
        OrderEditReturnPaymentEvent::class => [
            OrderEditReturnPaymentListener::class,
        ],
        OrderStatusEvent::class => [
            OrderStatusListener::class,
        ],
        ChattingEvent::class => [
            ChattingListener::class,
        ],
        RefundEvent::class => [
            RefundListener::class,
        ],
        VendorRegistrationEvent::class => [
            VendorRegistrationListener::class,
        ],
        CustomerRegistrationEvent::class => [
            CustomerRegistrationListener::class,
        ],
        CustomerRegisteredViaReferralEvent::class => [
            SendReferralRegistrationNotificationListener::class,
        ],
        CustomerStatusUpdateEvent::class => [
            CustomerStatusUpdateListener::class,
        ],
        WithdrawStatusUpdateEvent::class => [
            WithdrawStatusUpdateListener::class,
        ],
        CashCollectEvent::class => [
            CashCollectListener::class,
        ],
        ProductRequestStatusUpdateEvent::class => [
            ProductRequestStatusUpdateListener::class,
        ],
        DigitalProductDownloadEvent::class => [
            DigitalProductDownloadListener::class,
        ],
        MaintenanceModeNotificationEvent::class => [
            MaintenanceModeNotificationListener::class,
        ],
        RequestProductRestockEvent::class => [
            RequestProductRestockListener::class,
        ],
        RestockProductNotificationEvent::class => [
            RestockProductNotificationListener::class,
        ],
    ];


    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot(): void
    {
        parent::boot();
    }
}
