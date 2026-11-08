<?php

namespace App\Services\MyFatoorah;

use App\Enums\MyFatoorah\GatewayStatus;

/**
 * An immutable read of the MyFatoorah configuration as it is right now.
 *
 * Secrets are already decrypted here, so an instance of this must never be serialised into a response,
 * a log line, a queued job payload or an exception message. `toDisplayArray()` is the only shape that
 * may leave the server, and it reports which secrets EXIST without revealing any of them.
 */
final class GatewayConfig
{
    public function __construct(
        public readonly bool $activeInAdmin,
        public readonly string $mode,                 // 'test' | 'live'
        public readonly ?string $apiKey,              // the key for $mode only
        public readonly ?string $webhookSecret,       // the webhook secret for $mode only
        public readonly string $baseUrl,
        public readonly string $currency,
        public readonly string $title,
        public readonly array $enabledMethods,        // ApiName list the admin chose to display, [] = all
        public readonly array $accountMethods,        // last known catalogue from MyFatoorah, cached
        public readonly ?string $lastTestAt,
        public readonly ?string $lastTestResult,      // 'ok' | 'failed'
        public readonly ?string $lastTestMessage,
        public readonly ?string $accountStatus,
        /**
         * When the invoice-lookup endpoint last answered successfully for this mode, or null if it
         * never has. MyFatoorah does not publish the literal paths for "Get Invoice by InvoiceId" or
         * "Get Invoice by ExternalIdentifier", so until one of them has actually worked we cannot read
         * a 404 from it as "no such invoice" — it is equally "no such path" or "not permitted".
         */
        public readonly ?string $invoiceLookupProvenAt,
    ) {
    }

    /** Has the invoice-lookup endpoint ever answered? Only then can a 404 from it mean anything. */
    public function invoiceLookupIsProven(): bool
    {
        return $this->invoiceLookupProvenAt !== null;
    }

    public function hasApiKey(): bool
    {
        return is_string($this->apiKey) && $this->apiKey !== '';
    }

    public function hasWebhookSecret(): bool
    {
        return is_string($this->webhookSecret) && $this->webhookSecret !== '';
    }

    public function isSandbox(): bool
    {
        return $this->mode !== 'live';
    }

    /**
     * Why the gateway can or cannot take a payment right now, in the order a human would check.
     *
     * The webhook secret is treated as mandatory on purpose: without it a delivery cannot be
     * authenticated, and an integration that accepts unauthenticated payment notifications is worse
     * than one that is switched off. `digital_payment` is the store-wide switch that already gates
     * every other gateway, and it is honoured here so MyFatoorah cannot be the one exception.
     */
    /**
     * @param list<string>|null $activeCurrencies the store's active currency codes, or null if they
     *                                            could not be read — see GatewayAvailability
     */
    public function status(bool $environmentEnabled, bool $digitalPaymentOn, ?array $activeCurrencies): GatewayStatus
    {
        if (!$environmentEnabled) {
            return GatewayStatus::DisabledByEnvironment;
        }

        if (!$this->activeInAdmin) {
            return GatewayStatus::DisabledInAdmin;
        }

        if (!$digitalPaymentOn) {
            return GatewayStatus::DigitalPaymentOff;
        }

        if (!$this->hasApiKey()) {
            return GatewayStatus::MissingApiKey;
        }

        if (!$this->hasWebhookSecret() && (bool) config('myfatoorah.webhook.require_signature', true)) {
            return GatewayStatus::MissingWebhookSecret;
        }

        // null only: an empty list is a real answer (the store has no currency this gateway can settle
        // in) and must fail, while "could not be read" must not take a working method off the checkout.
        if ($activeCurrencies !== null && !in_array($this->currency, $activeCurrencies, true)) {
            return GatewayStatus::UnsupportedCurrency;
        }

        return GatewayStatus::Ready;
    }

    /**
     * Safe for the admin panel, for logs and for any API: settings and the PRESENCE of secrets, never a
     * secret itself and never a partially masked one (a prefix of an API key is still a clue).
     */
    public function toDisplayArray(): array
    {
        return [
            'active' => $this->activeInAdmin,
            'mode' => $this->mode,
            'base_url' => $this->baseUrl,
            'currency' => $this->currency,
            'title' => $this->title,
            'has_api_key' => $this->hasApiKey(),
            'has_webhook_secret' => $this->hasWebhookSecret(),
            'enabled_methods' => $this->enabledMethods,
            'account_methods' => $this->accountMethods,
            'last_test_at' => $this->lastTestAt,
            'last_test_result' => $this->lastTestResult,
            'last_test_message' => $this->lastTestMessage,
            'account_status' => $this->accountStatus,
            'invoice_lookup_proven_at' => $this->invoiceLookupProvenAt,
        ];
    }
}
