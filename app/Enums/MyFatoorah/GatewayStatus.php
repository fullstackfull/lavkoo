<?php

namespace App\Enums\MyFatoorah;

/**
 * Why the gateway may or may not take a payment right now. The admin panel shows the reason; the
 * checkout and the mobile API only care whether it is `Ready`.
 */
enum GatewayStatus: string
{
    case Ready = 'ready';
    case DisabledByEnvironment = 'disabled_by_environment';
    case DisabledInAdmin = 'disabled_in_admin';
    case DigitalPaymentOff = 'digital_payment_off';
    case MissingApiKey = 'missing_api_key';
    case MissingWebhookSecret = 'missing_webhook_secret';
    case UnsupportedCurrency = 'unsupported_currency';

    public function isReady(): bool
    {
        return $this === self::Ready;
    }

    /** A short, non-sensitive sentence for the admin panel. Never contains a key or a host. */
    public function reason(): string
    {
        return match ($this) {
            self::Ready => 'Ready to take payments.',
            self::DisabledByEnvironment => 'Switched off for this deployment (MYFATOORAH_ENABLED is off).',
            self::DisabledInAdmin => 'Switched off on this page.',
            self::DigitalPaymentOff => 'Digital payment is switched off for the whole store.',
            self::MissingApiKey => 'No API key saved for the selected mode.',
            self::MissingWebhookSecret => 'No webhook secret key saved.',
            self::UnsupportedCurrency => 'The store currency is not one MyFatoorah settles in for this account.',
        };
    }
}
