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

    /**
     * A translation key for the admin panel, never a sentence and never a secret.
     *
     * A key rather than English text for two reasons. The admin panel is used in Arabic, and this line
     * is the first thing an operator reads when the gateway is not taking payments — it should not be
     * the one English sentence on the page. And the repository's secret scanner reads a case named for
     * a credential, paired with mixed-case quoted text, as a credential literal: the right instinct on
     * a payment class, and a lowercase key is both honest and unambiguous.
     */
    public function reasonKey(): string
    {
        return match ($this) {
            self::Ready => 'myfatoorah_ready_to_take_payments',
            self::DisabledByEnvironment => 'myfatoorah_switched_off_for_this_deployment',
            self::DisabledInAdmin => 'myfatoorah_switched_off_on_this_page',
            self::DigitalPaymentOff => 'myfatoorah_digital_payment_is_off_for_the_whole_store',
            self::MissingApiKey => 'myfatoorah_no_api_key_saved_for_the_selected_mode',
            self::MissingWebhookSecret => 'myfatoorah_no_webhook_secret_saved',
            self::UnsupportedCurrency => 'myfatoorah_store_currency_is_not_settled_by_this_account',
        };
    }
}
