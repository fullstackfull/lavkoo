<?php

namespace App\Services\Payments;

use App\Models\PaymentGatewayAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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

    /** Paymob's inquiry by the reference WE gave the intention, which is the payment's own id (`special_reference`). */
    private const INQUIRY_PATH = '/api/ecommerce/orders/transaction_inquiry';

    /**
     * Whether Paymob can be asked at all.
     *
     * THE SECRET FALLS BACK TO THE PLATFORM SETTINGS ROW, and that is a repair rather than a convenience.
     * This used to require a company account, and `GatewayAccountRegistry::covers()` is true for Paymob
     * from the moment the gateway is on the list -- it does not ask whether per-company accounts are
     * switched on. So on today's platform, where no payment carries an account stamp, switching
     * `GCC_PAYMENT_CONFIRM_RETURNS` on made every Paymob return unconfirmable, and an unconfirmable
     * return settles nothing: the switch would have refused every Paymob payment on the platform the day
     * it was turned on. The platform row is the credential every Paymob payment is actually taken with
     * (`PaymobController::credentialsFor` falls back to it in the same way), so asking with it is asking
     * with the right key.
     */
    public function canAsk(?PaymentGatewayAccount $account): bool
    {
        return $this->secret(account: $account) !== null && $this->host() !== null;
    }

    public function transaction(?PaymentGatewayAccount $account, string $reference): GatewayTransactionState
    {
        if (trim($reference) === '' || !$this->canAsk(account: $account)) {
            return GatewayTransactionState::unknown();
        }

        $url = $this->host() . self::PATH . rawurlencode(trim($reference));

        try {
            $response = Http::withHeaders(['Authorization' => 'Token ' . $this->secret(account: $account), 'Accept' => 'application/json'])
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

    /**
     * What became of the transaction of OUR OWN reference, which is what a recovery sweep has to ask with.
     *
     * A customer who closed the browser at Paymob never came back with Paymob's transaction id, so there
     * is nothing on the payment row to ask `transaction()` about. What the shop does hold is the reference
     * it gave the intention when the payment started -- the payment's own id, sent as `special_reference`
     * for exactly this reason -- and Paymob's inquiry endpoint answers on it.
     *
     * The answer is read by the same `read()` as the other endpoint's, so a sweep and a browser return
     * cannot come to different conclusions about one transaction; and the reference the state carries is
     * PAYMOB's transaction id out of the answer, never ours, because that is what the confirmation
     * register holds and what one-transaction-settles-one-payment is keyed on.
     */
    public function transactionOfOurReference(?PaymentGatewayAccount $account, string $ourReference): GatewayTransactionState
    {
        if (trim($ourReference) === '' || !$this->canAsk(account: $account)) {
            return GatewayTransactionState::unknown();
        }

        try {
            $response = Http::withHeaders(['Authorization' => 'Token ' . $this->secret(account: $account), 'Accept' => 'application/json'])
                ->connectTimeout((int) config('gcc.storefronts.payment_accounts.inquiry.connect_timeout', 10))
                ->timeout((int) config('gcc.storefronts.payment_accounts.inquiry.timeout', 30))
                ->withoutRedirecting()
                ->post($this->host() . self::INQUIRY_PATH, ['merchant_order_id' => trim($ourReference)]);
        } catch (ConnectionException) {
            Log::warning('paymob.inquiry_unreachable', ['account' => $account?->id, 'reference' => $ourReference]);

            return GatewayTransactionState::unknown();
        }

        if (!$response->successful()) {
            // 404 is Paymob saying it has no transaction of this reference, which is the ordinary answer
            // for a customer who abandoned before paying. It is not a refusal and it settles nothing.
            Log::info('paymob.inquiry_has_no_transaction_of_our_reference', ['status' => $response->status(), 'reference' => $ourReference]);

            return GatewayTransactionState::unknown();
        }

        $body = $response->json();
        $paymobsOwnReference = is_array($body) ? ($body['id'] ?? null) : null;

        if (!is_scalar($paymobsOwnReference) || (string) $paymobsOwnReference === '') {
            return GatewayTransactionState::unknown();
        }

        return $this->read(body: $body, reference: (string) $paymobsOwnReference);
    }

    /** The key Paymob is asked with: the account's own where the payment carries one, else the platform settings row's. */
    private function secret(?PaymentGatewayAccount $account): ?string
    {
        if ($account !== null) {
            $fromAccount = app(GatewayAccountSecrets::class)->read(account: $account, name: 'secret_key');

            if (is_string($fromAccount) && $fromAccount !== '') {
                return $fromAccount;
            }
        }

        return $this->platformSecret();
    }

    /**
     * The secret key of the one platform-wide settings row, read the way the gateway's own controller reads
     * it: the live blob while the row's mode is live, the test blob while it is test, and nothing for a row
     * whose mode is neither -- a malformed row must never hand a test key to a live gateway or the reverse.
     */
    private function platformSecret(): ?string
    {
        if (!Schema::hasTable('addon_settings')) {
            return null;
        }

        $row = DB::table('addon_settings')->where('key_name', 'paymob_accept')->where('settings_type', 'payment_config')->first();
        $blob = match ($row->mode ?? null) {
            'live' => $row->live_values ?? null,
            'test' => $row->test_values ?? null,
            default => null,
        };
        $values = json_decode((string) $blob, true);
        $secret = is_array($values) ? ($values['secret_key'] ?? null) : null;

        return is_scalar($secret) && (string) $secret !== '' ? (string) $secret : null;
    }

    /** The configured region's host, only while it is one Paymob publishes. */
    private function host(): ?string
    {
        $configured = (string) config('gcc.storefronts.payment_accounts.inquiry.paymob_host', self::HOSTS[0]);

        return in_array($configured, self::HOSTS, true) ? $configured : null;
    }
}
