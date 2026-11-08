<?php

namespace App\Services\MyFatoorah;

use App\Enums\MyFatoorah\AttemptStatus;
use App\Models\MyFatoorahPaymentAttempt;
use App\Models\PaymentRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
        private readonly PaymentVerificationService $verification,
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

        // An earlier attempt whose fate we never learnt blocks a new one until it is settled one way or
        // the other. Done before the claim, and outside any transaction, because it makes a network call.
        $blocked = $this->resolveAttemptsInDoubt($paymentRequest, $config);
        if ($blocked !== null) {
            return $blocked;
        }

        $claim = $this->claimAttempt($paymentRequest, $amount, $expectedCurrency, $config);

        if (is_string($claim)) {
            return match ($claim) {
                'in_doubt' => [
                    'ok' => false,
                    'code' => 'attempt_in_doubt',
                    'error' => 'A payment for this order is already being created. Please wait a moment and try again.',
                ],
                'too_many' => [
                    'ok' => false,
                    'code' => 'too_many_attempts',
                    'error' => 'Too many payment attempts have been made for this order. Please contact support.',
                ],
                default => [
                    'ok' => false,
                    'code' => 'attempt_conflict',
                    'error' => 'Another payment attempt for this order is already being created.',
                ],
            };
        }

        $attempt = $claim;

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
                // A definite refusal is terminal, so the live slot is released and the customer may try
                // again at once. A transport failure is not: the slot stays held, which is what stops a
                // second invoice while the first call's fate is unknown.
                'live_slot' => $response->isUnknown() ? 1 : null,
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
                'live_slot' => null,
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
                'live_slot' => null,
                'last_error_code' => 'amount_changed',
                'last_error_message' => 'The order total changed before this attempt was paid.',
            ])->save();

            return null;
        }

        if ($candidate->expires_at !== null && $candidate->expires_at->isPast()) {
            $candidate->forceFill([
                'status' => AttemptStatus::Expired->value,
                'live_slot' => null,
            ])->save();

            return null;
        }

        return $candidate;
    }

    /**
     * Take ownership of the next attempt for this payment request.
     *
     * Correctness comes from two unique indexes rather than from locks:
     *
     *   mf_attempts_one_live_per_request  (payment_request_id, live_slot)
     *       At most one LIVE attempt per order — one that has not reached a terminal state. A second
     *       simultaneous checkout's insert is refused by the database, so one order can never have two
     *       invoices in flight. The slot is held for the whole life of the attempt, not just while its
     *       create call is outstanding: when it was released as soon as the payment page arrived, the
     *       window it protected lasted only as long as that call, and six simultaneous checkouts each
     *       slipped through it against a fast provider.
     *
     *   external_identifier
     *       The per-attempt reference. Two requests that compute the same attempt number collide here.
     *
     * Deliberately no row lock and no transaction. The lock-based version was wrong twice: a plain read
     * inside the transaction could not see a sibling's just-committed attempt (REPEATABLE READ gives the
     * transaction a snapshot from when it began), and making that read a locking one took gap locks on
     * the `payment_request_id` range, which made different orders block each other. Both were found by
     * the MySQL concurrency test. A unique index needs neither a snapshot nor a lock, and orders do not
     * interact at all.
     *
     * @return MyFatoorahPaymentAttempt|'in_doubt'|'too_many'|'conflict'
     */
    private function claimAttempt(PaymentRequest $paymentRequest, float $amount, string $currency, GatewayConfig $config): MyFatoorahPaymentAttempt|string
    {
        $expiryMinutes = max(5, (int) config('myfatoorah.payment_expiry_minutes', 60));
        $maxAttempts = max(1, (int) config('myfatoorah.max_attempts_per_payment', 10));

        for ($try = 0; $try < 5; $try++) {
            $used = (int) MyFatoorahPaymentAttempt::query()
                ->where('payment_request_id', $paymentRequest->id)
                ->max('attempt_number');

            if ($used >= $maxAttempts) {
                Log::warning('myfatoorah.attempt_cap_reached', [
                    'payment_request_id' => (string) $paymentRequest->id,
                    'attempts' => $used,
                ]);

                return 'too_many';
            }

            $next = $used + 1;
            $identifier = $this->externalIdentifier($paymentRequest->id, $next);

            try {
                return MyFatoorahPaymentAttempt::create([
                    'payment_request_id' => (string) $paymentRequest->id,
                    'attempt_number' => $next,
                    'external_identifier' => $identifier,
                    'expected_amount' => number_format($amount, 2, '.', ''),
                    'expected_currency' => $currency,
                    'status' => AttemptStatus::Created->value,
                    // Claims this order's single LIVE slot, and holds it until this attempt reaches a
                    // terminal state. While it is held, no second payment can start for the order.
                    'live_slot' => 1,
                    'mode' => $config->mode,
                    'platform' => $this->stringOrNull($paymentRequest->payment_platform),
                    'return_url' => $this->stringOrNull($paymentRequest->external_redirect_link),
                    'expires_at' => CarbonImmutable::now()->addMinutes($expiryMinutes),
                ]);
            } catch (QueryException $e) {
                if (!$this->isDuplicate($e)) {
                    throw $e;
                }

                /*
                 * Which rule did we hit? Read freshly — outside any transaction of ours, so this sees
                 * whatever a sibling has just committed.
                 *
                 * Reusability is checked FIRST, and that matters: while we were losing the race the
                 * winner may have finished, got its payment page and released the order's in-doubt
                 * slot. Going straight on to the next attempt number would then be legitimate by every
                 * other rule here and would still produce a second invoice — which is what the MySQL
                 * concurrency test caught. The right answer is the page the winner just made.
                 */
                $reusable = $this->findReusableAttempt($paymentRequest, $amount, $currency, $config->mode);

                if ($reusable !== null) {
                    return $reusable;
                }

                if ($this->attemptsInDoubtQuery($paymentRequest, $config->mode)->exists()) {
                    return 'in_doubt';
                }

                $existing = MyFatoorahPaymentAttempt::query()->where('external_identifier', $identifier)->first();

                if ($existing !== null && $existing->statusEnum()->isInFlight()) {
                    return $existing;
                }

                // Someone took this attempt number and finished with it. Try the next one.
            }
        }

        return 'conflict';
    }

    /**
     * Attempts we started and never learnt the fate of: created, with no payment URL to show.
     *
     * Not the same as "in flight". An attempt that has a payment URL is one the customer is looking at,
     * and `findReusableAttempt()` hands them back to it. These are the ones where the create call itself
     * did not come back, so an invoice may or may not exist at MyFatoorah.
     */
    private function attemptsInDoubtQuery(PaymentRequest $paymentRequest, string $mode): Builder
    {
        return MyFatoorahPaymentAttempt::query()
            ->where('payment_request_id', $paymentRequest->id)
            ->where('mode', $mode)
            ->where('status', AttemptStatus::Created->value)
            ->whereNull('payment_url');
    }

    /**
     * Ask MyFatoorah about every attempt in doubt for this order, and say whether a new payment may start.
     *
     * Returns null when the way is clear. Otherwise it returns the answer the caller should give, which
     * is never "here is a second invoice": either the earlier attempt turned out to be paid or still
     * payable, or we could not find out and the customer is asked to wait.
     *
     * @return array{ok: bool, payment_url?: string, attempt?: MyFatoorahPaymentAttempt, error?: string, code?: string}|null
     */
    private function resolveAttemptsInDoubt(PaymentRequest $paymentRequest, GatewayConfig $config): ?array
    {
        $inDoubt = $this->attemptsInDoubtQuery($paymentRequest, $config->mode)->orderBy('attempt_number')->get();

        if ($inDoubt->isEmpty()) {
            return null;
        }

        foreach ($inDoubt as $attempt) {
            if ($this->tooYoungToJudge($attempt)) {
                /*
                 * Its create call could still be running. A 404 from MyFatoorah right now would mean "not
                 * told yet", not "never created", and acting on that is how two simultaneous checkouts
                 * both end up with an invoice.
                 */
                return [
                    'ok' => false,
                    'code' => 'attempt_in_doubt',
                    'error' => 'A payment for this order is already being created. Please wait a moment and try again.',
                    'attempt' => $attempt,
                ];
            }

            $outcome = $this->verification->resolveUnstartedAttempt($attempt);

            if ($outcome === 'absent') {
                // MyFatoorah never created an invoice for it. Closing it frees the order for a new
                // payment, which is the common case after a timeout on the way out.
                $attempt->forceFill([
                    'status' => AttemptStatus::Failed->value,
                    'live_slot' => null,
                    'last_error_code' => 'never_created',
                    'last_error_message' => 'MyFatoorah has no invoice for this attempt, so nothing was charged.',
                ])->save();

                continue;
            }

            if ($outcome === 'unknown') {
                Log::warning('myfatoorah.attempt_in_doubt_unresolved', [
                    'attempt' => $attempt->external_identifier,
                    'payment_request_id' => (string) $paymentRequest->id,
                ]);

                return [
                    'ok' => false,
                    'code' => 'attempt_in_doubt',
                    'error' => 'A previous payment for this order has not been confirmed yet. Please wait a moment before trying again.',
                ];
            }

            // Resolved: the attempt now carries whatever MyFatoorah says. If that is paid, or still
            // payable, a second invoice must not be created.
            $attempt->refresh();

            if ($attempt->statusEnum() === AttemptStatus::Paid) {
                return [
                    'ok' => false,
                    'code' => 'already_paid',
                    'error' => 'This order has already been paid.',
                    'attempt' => $attempt,
                ];
            }

            if ($attempt->statusEnum()->isInFlight()) {
                return [
                    'ok' => false,
                    'code' => 'attempt_in_doubt',
                    'error' => 'A previous payment for this order is still being processed. Please wait a moment before trying again.',
                    'attempt' => $attempt,
                ];
            }
        }

        return null;
    }

    /**
     * Is this attempt still so new that its create call might not have finished?
     *
     * Within the grace window nothing MyFatoorah says about it can be trusted either way, because a
     * reference it has not been told about yet is indistinguishable from one it never had.
     */
    private function tooYoungToJudge(MyFatoorahPaymentAttempt $attempt): bool
    {
        $grace = max(1, (int) config('myfatoorah.attempt_in_doubt_grace_seconds', 70));

        return $attempt->created_at !== null && $attempt->created_at->addSeconds($grace)->isFuture();
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
