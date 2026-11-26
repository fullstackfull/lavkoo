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
         * When each invoice-lookup path last answered successfully ON THIS CONNECTION: endpoint key =>
         * ISO timestamp. A path that has never answered is simply absent from the map.
         *
         * MyFatoorah publishes no literal path for "Get Invoice by InvoiceId" or "Get Invoice by
         * ExternalIdentifier", so until one of them has actually worked a 404 from it is equally "no
         * such invoice", "no such path" and "not permitted".
         *
         * Held per path, not per mode, because they are separate paths with separate permissions: one of
         * them answering says nothing about the other. And only for the connection it was earned on —
         * the repository drops a proof whose API key, host or path template no longer match, because a
         * different key may be a different merchant and a different path is a different request.
         *
         * @var array<string, string>
         */
        public readonly array $invoiceLookupProof,
    ) {
    }

    /**
     * Has this invoice-lookup path ever answered, on the connection in use now? Only then does a 404
     * from it mean the invoice is not there.
     */
    public function invoiceLookupIsProven(string $endpointKey): bool
    {
        return isset($this->invoiceLookupProof[$endpointKey]);
    }

    public function invoiceLookupProvenAt(string $endpointKey): ?string
    {
        return $this->invoiceLookupProof[$endpointKey] ?? null;
    }

    /**
     * The same connection, authenticated as one company's gateway account instead of as the platform (D2-07, D2-15).
     *
     * Everything that is a property of the INTEGRATION is carried over -- the host (already checked against the allow-list, so a
     * registered account cannot point an outbound call anywhere of its own), the mode, the currency, the title and the method lists -- and
     * the two things that are a property of the ACCOUNT are replaced.
     *
     * `invoiceLookupProof` is deliberately EMPTIED rather than carried over. The proof records which lookup path has been seen to answer
     * on this connection, and the repository already drops a proof whose api key no longer matches, for the reason that a different key may
     * be a different merchant: carrying the platform's proof onto a company's key would make a 404 from that merchant read as "no such
     * invoice" when it may mean "that path is not permitted for this account".
     */
    public function authenticatedAs(string $apiKey, ?string $webhookSecret): self
    {
        return new self(
            activeInAdmin: $this->activeInAdmin,
            mode: $this->mode,
            apiKey: $apiKey,
            webhookSecret: $webhookSecret,
            baseUrl: $this->baseUrl,
            currency: $this->currency,
            title: $this->title,
            enabledMethods: $this->enabledMethods,
            accountMethods: $this->accountMethods,
            lastTestAt: $this->lastTestAt,
            lastTestResult: $this->lastTestResult,
            lastTestMessage: $this->lastTestMessage,
            accountStatus: $this->accountStatus,
            invoiceLookupProof: [],
        );
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
            // Which lookup paths have been seen to work here, and when. No fingerprints: those are
            // derived from the API key and are nobody's business outside this class.
            'invoice_lookup_proof' => $this->invoiceLookupProof,
        ];
    }
}
