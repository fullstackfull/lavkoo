<?php

namespace App\Traits;

use App\Services\Payments\PaymentAccountBinding;
use App\Services\Storefront\GatewayCurrencyPolicy;
use App\Services\Storefront\PaymentStorefront;
use App\Services\Storefront\StorefrontPaymentMethods;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Foundation\Application;
use InvalidArgumentException;
use App\Models\PaymentRequest;
use App\Services\Payments\PaymentGatewayCatalog;

trait Payment
{
    public static function generate_link(object $payer, object $payment_info, object $receiver): Application|bool|string|UrlGenerator|\Illuminate\Contracts\Foundation\Application
    {
        if ($payment_info->getPaymentAmount() <= 0) {
            throw new InvalidArgumentException(translate('Payment amount can not be 0'));
        }

        if (!is_array($payment_info->getAdditionalData())) {
            throw new InvalidArgumentException(translate('Additional data should be in a valid array'));
        }

        app(StorefrontPaymentMethods::class)->assertNotSwitchedOff(method: StorefrontPaymentMethods::DIGITAL_PAYMENT);
        app(GatewayCurrencyPolicy::class)->assertAcceptable(gateway: (string) $payment_info->getPaymentMethod(), currencyCode: (string) $payment_info->getCurrencyCode());

        $payment = new PaymentRequest();
        $payment->payment_amount = $payment_info->getPaymentAmount();
        $payment->success_hook = $payment_info->getSuccessHook();
        $payment->failure_hook = $payment_info->getFailureHook();
        $payment->payer_id = $payment_info->getPayerId();
        $payment->receiver_id = $payment_info->getReceiverId();
        $payment->currency_code = strtoupper($payment_info->getCurrencyCode());
        $payment->payment_method = $payment_info->getPaymentMethod();
        $payment->additional_data = json_encode(app(PaymentAccountBinding::class)->record(
            additionalData: PaymentStorefront::record(additionalData: $payment_info->getAdditionalData()),
            gateway: (string) $payment_info->getPaymentMethod(),
            currencyCode: (string) $payment_info->getCurrencyCode(),
        ));
        $payment->payer_information = json_encode($payer->information());
        $payment->receiver_information = json_encode($receiver->information());
        $payment->external_redirect_link = $payment_info->getExternalRedirectLink();
        $payment->attribute = $payment_info->getAttribute();
        $payment->attribute_id = $payment_info->getAttributeId();
        $payment->payment_platform = $payment_info->getPaymentPlatForm();
        $payment->save();

        $routes = PaymentGatewayCatalog::startRoutes();
        if (array_key_exists($payment->payment_method, $routes)) {
            return url("{$routes[$payment->payment_method]}/?payment_id={$payment->id}");
        } else {
            return false;
        }
    }
}