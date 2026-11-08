<?php

namespace App\Services\MyFatoorah;

use App\Enums\MyFatoorah\AttemptStatus;
use App\Models\MyFatoorahPaymentAttempt;
use App\Models\PaymentRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns a `payment_requests` row into a MyFatoorah hosted payment page.
 *
 * The amount is read from the payment_requests row, which the checkout already computed from the cart
 * (items, shipping, discounts, tax) on the server. Nothing a browser or an app sends is used: the
 * caller may not pass an amount at all, which is why there is no amount parameter here.
 *
 * Creating a payment is the one operation that costs money if it happens twice, so three things guard it:
 *
 *  1. An attempt that is still usable is REUSED. A customer who double-taps "pay", or reloads the
 *     redirect, gets the same hosted page and the same invoice, not a second one.
 *  2. `external_identifier` is unique in the database and is derived from the payment request and the
 *     attempt number, so two simultaneous requests race on an INSERT and the loser reuses the winner's
 *     row instead of calling MyFatoorah.
 *  3. The POST is never retried by the client. If it times out we do not know whether an invoice was
 *     created, so the attempt is left `created` for reconciliation to resolve rather than re-sent.
 */
class PaymentInitiationService
{
    public function __construct(
        private readonly GatewayConfigRepository $configRepo,
        private readonly MyFatoorahClient $client,
        private readonly GatewayAvailability $availability,
    ) {
    }

    /**
     * @return array{ok: bool, payment_url?: string, attempt?: MyFatoorahPaymentAttempt, error?: string, code?: string}
     */
    public function start(PaymentRequest $paymentRequest, ?string $language = null): array
    {
        // Already paid: never create a second invoice for money we have.
        if ((int) ($paymentRequest->is_paid ?? 0) === 1) {
            return ['ok' => false, 'code' => 'already_paid', 'error' => 'This payment has already been completed.'];
        }

        $availability = $this->availability->check();
        if (!$availability['ready']) {
            // The gateway is off, unconfigured, or the store's currency is not one it settles in. Refusing
            // here is what makes "disable it in the admin panel" actually stop new payments.
            return ['ok' => false, 'code' => 'gateway_unavailable', 'error' => $availability['reason']];
        }

        $config = $this->configRepo->get();

        $expectedCurrency = strtoupper((string) $paymentRequest->currency_code);
        if ($expectedCurrency !== $config->currency) {
            return [
                'ok' => false,
                'code' => 'currency_mismatch',
                'error' => 'MyFatoorah is configured for ' . $config->currency . ' and this payment is in ' . $expectedCurrency . '.',
            ];
        }

        $amount = round((float) $paymentRequest->payment_amount, 2);
        if ($amount <= 0) {
            return ['ok' => false, 'code' => 'bad_amount', 'error' => 'A payment amount must be greater than zero.'];
        }

        // An attempt we can send the customer back to, rather than a new invoice.
        $reusable = $this->findReusableAttempt($paymentRequest, $amount, $expectedCurrency, $config->mode);
        if ($reusable !== null) {
            return ['ok' => true, 'payment_url' => (string) $reusable->payment_url, 'attempt' => $reusable];
        }

        $attempt = $this->claimAttempt($paymentRequest, $amount, $expectedCurrency, $config);
        if ($attempt === null) {
            return ['ok' => false, 'code' => 'attempt_conflict', 'error' => 'Another payment attempt for this order is already being created.'];
        }

        // If this row was claimed by us but a concurrent request already filled in a URL, use it.
        if (!empty($attempt->payment_url) && $attempt->statusEnum()->isInFlight()) {
            return ['ok' => true, 'payment_url' => (string) $attempt->payment_url, 'attempt' => $attempt];
        }

        $response = $this->client->post($config, 'create_payment', $this->buildBody($paymentRequest, $attempt, $config, $language));

        if (!$response->succeeded) {
            $attempt->forceFill([
                'last_error_code' => $response->errorCode,
                'last_error_message' => $response->message,
                // A transport failure leaves the attempt `created`: an invoice MAY exist at MyFatoorah
                // and reconciliation has to be able to find it. A definite refusal means no invoice was
                // created, so the attempt is closed and the customer may try again cleanly.
                'status' => $response->isUnknown() ? AttemptStatus::Created->value : AttemptStatus::Failed->value,
            ])->save();

            Log::warning('myfatoorah.create_payment_failed', [
                'attempt' => $attempt->external_identifier,
                'code' => $response->errorCode,
                'unknown' => $response->isUnknown(),
            ]);

            return [
                'ok' => false,
                'code' => $response->errorCode ?? 'create_failed',
                'error' => $response->message,
                'attempt' => $attempt,
            ];
        }

        $paymentUrl = (string) $response->get('PaymentURL', '');
        if (!$this->isSafePaymentUrl($paymentUrl)) {
            // Nothing else in the response matters if we cannot send the customer anywhere safe.
            $attempt->forceFill([
                'status' => AttemptStatus::Failed->value,
                'last_error_code' => 'bad_payment_url',
                'last_error_message' => 'MyFatoorah did not return a usable HTTPS payment URL.',
                'invoice_id' => $this->stringOrNull($response->get('InvoiceId')),
            ])->save();

            return ['ok' => false, 'code' => 'bad_payment_url', 'error' => 'MyFatoorah did not return a usable payment page.'];
        }

        $attempt->forceFill([
            'invoice_id' => $this->stringOrNull($response->get('InvoiceId')),
            // Normally null at this point and filled in by the callback; set when a non-3DS payment
            // completed inside the create call.
            'payment_id' => $this->stringOrNull($response->get('PaymentId')),
            'payment_url' => $paymentUrl,
            'status' => AttemptStatus::Redirected->value,
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();

        return ['ok' => true, 'payment_url' => $paymentUrl, 'attempt' => $attempt];
    }

    /**
     * An attempt the customer can still be sent back to: same money, same mode, still in flight, not
     * expired, and with a page to show. Returning one of these instead of creating another invoice is
     * what stops a double-tap from becoming two payments.
     */
    private function findReusableAttempt(PaymentRequest $paymentRequest, float $amount, string $currency, string $mode): ?MyFatoorahPaymentAttempt
    {
        $candidate = MyFatoorahPaymentAttempt::query()
            ->where('payment_request_id', $paymentRequest->id)
            ->inFlight()
            ->whereNotNull('payment_url')
            ->where('mode', $mode)
            ->orderByDesc('attempt_number')
            ->first();

        if ($candidate === null) {
            return null;
        }

        $sameMoney = Money::equal($amount, $candidate->expected_amount)
            && strtoupper((string) $candidate->expected_currency) === $currency;

        if (!$sameMoney) {
            // The basket changed while the customer was away. The old invoice is for the wrong amount and
            // must not be reused; it is abandoned so it cannot later be settled against this order.
            $candidate->forceFill([
                'status' => AttemptStatus::Cancelled->value,
                'last_error_code' => 'amount_changed',
                'last_error_message' => 'The order total changed before this attempt was paid.',
            ])->save();

            return null;
        }

        if ($candidate->expires_at !== null && $candidate->expires_at->isPast()) {
            $candidate->forceFill([
                'status' => AttemptStatus::Expired->value,
            ])->save();

            return null;
        }

        return $candidate;
    }

    /**
     * Take ownership of the next attempt number for this payment request.
     *
     * The unique index on `external_identifier` is the referee. Two web requests that arrive together
     * compute the same identifier; one inserts and the other catches the integrity error and reads the
     * winner's row. Neither calls MyFatoorah twice.
     */
    private function claimAttempt(PaymentRequest $paymentRequest, float $amount, string $currency, GatewayConfig $config): ?MyFatoorahPaymentAttempt
    {
        $expiryMinutes = max(5, (int) config('myfatoorah.payment_expiry_minutes', 60));

        for ($i = 0; $i < 5; $i++) {
            $next = 1 + (int) MyFatoorahPaymentAttempt::query()
                ->where('payment_request_id', $paymentRequest->id)
                ->max('attempt_number');

            $identifier = $this->externalIdentifier($paymentRequest->id, $next);

            try {
                return MyFatoorahPaymentAttempt::create([
                    'payment_request_id' => (string) $paymentRequest->id,
                    'attempt_number' => $next,
                    'external_identifier' => $identifier,
                    'expected_amount' => number_format($amount, 2, '.', ''),
                    'expected_currency' => $currency,
                    'status' => AttemptStatus::Created->value,
                    'mode' => $config->mode,
                    'platform' => $this->stringOrNull($paymentRequest->payment_platform),
                    'return_url' => $this->stringOrNull($paymentRequest->external_redirect_link),
                    'expires_at' => CarbonImmutable::now()->addMinutes($expiryMinutes),
                ]);
            } catch (QueryException $e) {
                if (!$this->isDuplicate($e)) {
                    throw $e;
                }

                $existing = MyFatoorahPaymentAttempt::query()->where('external_identifier', $identifier)->first();

                // The winner's row is usable as-is if it is still in flight; otherwise loop and take the
                // next number (the customer is retrying after a failure).
                if ($existing !== null && $existing->statusEnum()->isInFlight()) {
                    return $existing;
                }
            }
        }

        return null;
    }

    /**
     * Our reference for one attempt. Deterministic so two simultaneous requests collide, short enough
     * for MyFatoorah's field, and carrying no customer data.
     */
    private function externalIdentifier(string $paymentRequestId, int $attemptNumber): string
    {
        return 'pk-' . substr(hash('sha256', $paymentRequestId), 0, 32) . '-' . $attemptNumber;
    }

    private function buildBody(PaymentRequest $paymentRequest, MyFatoorahPaymentAttempt $attempt, GatewayConfig $config, ?string $language): array
    {
        $payer = json_decode((string) $paymentRequest->payer_information, true);
        $payer = is_array($payer) ? $payer : [];

        $body = [
            'Order' => [
                'Amount' => (float) $attempt->expected_amount,
                'Currency' => $attempt->expected_currency,
                // Echoed back to us in every webhook and in the payment details. This is how a
                // notification is tied to one attempt without trusting anything else in it.
                'ExternalIdentifier' => $attempt->external_identifier,
            ],
            'IntegrationUrls' => [
                // MyFatoorah appends ?paymentId=... to this.
                'Redirection' => route('myfatoorah.callback', ['reference' => $attempt->external_identifier]),
                // Set per invoice as well as in the portal, so a freshly configured account still
                // notifies us even before someone remembers to paste the URL into the dashboard.
                'Webhook' => route('myfatoorah.webhook'),
            ],
            'Language' => $this->language($language),
            'NotificationOption' => 'LINK',
            'PaymentExpiry' => CarbonImmutable::parse($attempt->expires_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            'MetaData' => [
                // Diagnostics only. Never read back as authority: UDFs are not signed.
                'UDF1' => (string) $paymentRequest->id,
                'UDF2' => (string) ($paymentRequest->attribute ?? 'order'),
                'UDF3' => $config->mode,
            ],
        ];

        $customer = $this->customer($payer);
        if ($customer !== []) {
            $body['Customer'] = $customer;
        }

        // Only restrict the hosted page when the operator deliberately chose a subset; otherwise show
        // everything the merchant account has enabled, which is what the account owner expects.
        if ($config->enabledMethods !== []) {
            $body['DisplayPaymentMethods'] = array_values($config->enabledMethods);
        }

        return $body;
    }

    /**
     * Customer fields, only where we actually have them. MyFatoorah validates `Mobile.Number` against a
     * pattern, so a half-filled guest record is left out rather than rejected as a whole request.
     */
    private function customer(array $payer): array
    {
        $customer = [];

        $name = trim((string) ($payer['name'] ?? ''));
        if ($name !== '') {
            $customer['Name'] = Str::limit($name, 100, '');
        }

        $email = trim((string) ($payer['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $customer['Email'] = $email;
        }

        $phone = preg_replace('/[^0-9+]/', '', (string) ($payer['phone'] ?? '')) ?? '';
        if ($phone !== '') {
            [$countryCode, $number] = $this->splitSaudiPhone($phone);

            if ($number !== null) {
                $customer['Mobile'] = array_filter([
                    'CountryCode' => $countryCode,
                    'Number' => $number,
                ]);
            }
        }

        return $customer;
    }

    /**
     * Split a stored phone number into the country code and subscriber number MyFatoorah wants.
     *
     * Their `Number` pattern allows 6 to 14 digits and `CountryCode` at most 4, so a number that does
     * not fit is dropped rather than guessed at: a rejected `Customer` block would fail the whole
     * create-payment call, and the customer's phone is not worth losing a checkout over.
     *
     * @return array{0: string|null, 1: string|null} country code (no +), subscriber number
     */
    private function splitSaudiPhone(string $phone): array
    {
        $digits = preg_replace('/\D/', '', ltrim($phone, '+')) ?? '';

        // Longest prefix first: '00966' also starts with '00', and '966...' also matches the bare case.
        if (str_starts_with($digits, '00966')) {
            $local = substr($digits, 5);
        } elseif (str_starts_with($digits, '966')) {
            $local = substr($digits, 3);
        } else {
            $local = $digits;
        }

        // Saudi subscriber numbers are stored with and without the trunk '0'. MyFatoorah wants it off.
        $local = ltrim($local, '0');
        $length = strlen($local);

        if ($length < 6 || $length > 14) {
            return [null, null];
        }

        return ['966', $local];
    }

    private function language(?string $language): string
    {
        $candidate = strtolower((string) ($language ?: app()->getLocale()));

        return str_starts_with($candidate, 'ar') ? 'AR' : 'EN';
    }

    /** The customer may only ever be sent to an HTTPS page on a MyFatoorah host. */
    private function isSafePaymentUrl(string $url): bool
    {
        if (!str_starts_with($url, 'https://')) {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        // The hosted page is served from the portal hosts, not the API hosts, so match the family.
        return (bool) preg_match('/(^|\.)myfatoorah\.com$/', $host);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }

        return (string) $value;
    }

    private function isDuplicate(QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[1] ?? '');
        $message = strtolower($e->getMessage());

        return $code === '1062'
            || str_contains($message, 'unique')
            || str_contains($message, 'duplicate');
    }
}
