<?php

namespace App\Services\Payments;

use App\Models\PaymentGatewayAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Asks Paymob what became of one of its transactions (D2-11), with the secret key of the account the payment was taken through.
 *
 * Paymob's retrieve-transaction endpoint answers the same fields its browser return carries — success, amount_cents, currency — with the difference
 * that this answer came over a connection the server made, authenticated with a key only the server and Paymob hold. A browser carries whatever it is
 * handed; this does not.
 *
 * The host is pinned to the list of Paymob regions the controller already knows, so a settings row an attacker can reach cannot send a live secret key
 * to a host of their own — the same control App\Services\MyFatoorah\MyFatoorahClient applies to its own base URL.
 */
final class PaymobTransactionInquiry implements GatewayTransactionInquiry
{
    /** Paymob's regional hosts, as App\Http\Controllers\Payment_Methods\PaymobController lists them. A host outside this list is never called. */
    public const HOSTS = [
        'https://accept.paymob.com',
        'https://pakistan.paymob.com',
        'https://ksa.paymob.com',
        'https://oman.paymob.com',
        'https://uae.paymob.com',
    ];

    private const PATH = '/api/acceptance/transactions/';

    public function canAsk(?PaymentGatewayAccount $account): bool
    {
        return $account !== null && $this->secretOf(account: $account) !== null && $this->host() !== null;
    }

    public function transaction(?PaymentGatewayAccount $account, string $reference): GatewayTransactionState
    {
        if ($account === null || trim($reference) === '' || !$this->canAsk(account: $account)) {
            return GatewayTransactionState::unknown();
        }

        $url = $this->host() . self::PATH . rawurlencode(trim($reference));

        try {
            $response = Http::withHeaders(['Authorization' => 'Token ' . $this->secretOf(account: $account), 'Accept' => 'application/json'])
                ->connectTimeout((int) config('gcc.storefronts.payment_accounts.inquiry.connect_timeout', 10))
                ->timeout((int) config('gcc.storefronts.payment_accounts.inquiry.timeout', 30))
                // A redirect would re-send the Authorization header, and the secret key with it, to wherever it points.
                ->withoutRedirecting()
                ->get($url);
        } catch (ConnectionException) {
            // A dropped connection says nothing about whether the money moved.
            Log::warning('paymob.inquiry_unreachable', ['account' => (int) $account->id]);

            return GatewayTransactionState::unknown();
        }

        if (!$response->successful()) {
            Log::warning('paymob.inquiry_refused_to_answer', ['account' => (int) $account->id, 'status' => $response->status()]);

            return GatewayTransactionState::unknown();
        }

        return $this->read(body: $response->json(), reference: $reference);
    }

    /** @param mixed $body what Paymob answered */
    private function read(mixed $body, string $reference): GatewayTransactionState
    {
        if (!is_array($body)) {
            return GatewayTransactionState::unknown();
        }

        $success = $body['success'] ?? null;
        $pending = $body['pending'] ?? null;
        $amount = $body['amount_cents'] ?? null;
        $currency = $body['currency'] ?? null;

        if ($this->isTrue(value: $pending)) {
            return GatewayTransactionState::unknown();
        }

        if (!$this->isTrue(value: $success)) {
            // Paymob said so itself: this transaction did not take the money.
            return $success === null ? GatewayTransactionState::unknown() : GatewayTransactionState::refused(reference: $reference);
        }

        if (!is_numeric($amount) || !is_string($currency) || $currency === '') {
            // Paid, but the answer does not say how much of what. Unusable as a confirmation.
            return GatewayTransactionState::unknown();
        }

        return GatewayTransactionState::paid(reference: $reference, minorUnits: $amount, currency: strtoupper($currency));
    }

    /** Paymob writes its booleans as true, "true" and 1 depending on the field and the endpoint. */
    private function isTrue(mixed $value): bool
    {
        return $value === true || $value === 1 || (is_string($value) && strcasecmp($value, 'true') === 0);
    }

    private function secretOf(PaymentGatewayAccount $account): ?string
    {
        return app(GatewayAccountSecrets::class)->read(account: $account, name: 'secret_key');
    }

    /** The configured region's host, only while it is one Paymob publishes. */
    private function host(): ?string
    {
        $configured = (string) config('gcc.storefronts.payment_accounts.inquiry.paymob_host', self::HOSTS[0]);

        return in_array($configured, self::HOSTS, true) ? $configured : null;
    }
}
