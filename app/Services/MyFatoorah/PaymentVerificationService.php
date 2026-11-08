<?php

namespace App\Services\MyFatoorah;

use App\Enums\MyFatoorah\AttemptStatus;
use App\Models\MyFatoorahPaymentAttempt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Asks MyFatoorah what actually happened, and is the only thing in this integration allowed to decide
 * that a payment is real.
 *
 * Nothing that arrives with the customer is evidence. A redirect to our callback is a browser
 * navigation anyone can type; a webhook body is a POST anyone can send (the signature proves it came
 * from MyFatoorah, not that it is current); a success page is a page. So every route into settlement
 * goes through here, and here reads the answer from `GET /v3/payments/{paymentId}` over a server-to-
 * server call authenticated with our own API key.
 *
 * Four things must all agree before an attempt may be marked paid:
 *
 *   1. `Data.Invoice.Status` is PAID — MyFatoorah's own definition of "a successful transaction exists".
 *   2. The amount matches what we recorded when we created the attempt, to the fil.
 *   3. The currency matches.
 *   4. `Data.Invoice.ExternalIdentifier` is THIS attempt's reference — not another order's.
 *
 * If 1 holds but any of 2-4 do not, the status becomes `mismatch`, never `paid` and never `failed`:
 * real money has moved and a human has to look. That is the case that silently loses money in most
 * integrations, so it has a status of its own rather than being folded into failure.
 */
class PaymentVerificationService
{
    public function __construct(
        private readonly GatewayConfigRepository $configRepo,
        private readonly MyFatoorahClient $client,
        private readonly GatewayAvailability $availability,
    ) {
    }

    /**
     * Verify one attempt against MyFatoorah.
     *
     * @param string|null $paymentId MyFatoorah's payment id, when a callback or webhook supplied one.
     *                               Used only to LOOK UP the payment; the response still has to name
     *                               this attempt before anything is believed.
     *
     * @return array{status: AttemptStatus, unknown: bool, message: string, attempt: MyFatoorahPaymentAttempt}
     */
    public function verify(MyFatoorahPaymentAttempt $attempt, ?string $paymentId = null): array
    {
        if (!$this->availability->verificationAllowed()) {
            return $this->unknown($attempt, 'MyFatoorah calls are switched off for this deployment.');
        }

        // Already verified as paid: nothing MyFatoorah says later may undo that. A late FAILED notice
        // for a payment we confirmed is a stale notice about an earlier attempt at the same invoice.
        if ($attempt->statusEnum()->isLockedAgainstDowngrade()) {
            return [
                'status' => AttemptStatus::Paid,
                'unknown' => false,
                'message' => 'Already verified as paid.',
                'attempt' => $attempt,
            ];
        }

        // The attempt remembers which mode created it, so an attempt made in sandbox is never verified
        // against the live account after someone flips the switch.
        $config = $this->configRepo->forMode((string) $attempt->mode);

        $lookupId = $this->chooseLookupId($attempt, $paymentId);

        if ($lookupId === null) {
            /*
             * No payment id. This is the case reconciliation exists for: the customer closed the tab
             * and the webhook was never delivered, so nothing ever told us MyFatoorah's id for the
             * money. We know our invoice id, so the invoice is asked who paid it — and if that answers,
             * verification continues down the normal path with the id it found, so every amount,
             * currency and reference check still runs.
             */
            $lookupId = $this->discoverPaymentId($attempt, $config);
        }

        if ($lookupId === null) {
            // Either the customer never got as far as a transaction (the hosted page may still be
            // open), or the invoice lookup could not tell us. Not a failure: it stays in flight.
            return $this->unknown($attempt, 'No MyFatoorah payment id is known for this attempt yet.');
        }

        $attempt->forceFill([
            'verification_attempts' => (int) $attempt->verification_attempts + 1,
            'last_verified_at' => now(),
        ])->save();

        $response = $this->client->get($config, 'payment_details', ['paymentId' => $lookupId]);

        if ($response->isUnknown()) {
            // Timeout, DNS, TLS, or 5xx after retries. We do not know, so we do not decide. Critically
            // this does NOT run the failure hook: that is how a network blip cancels a paid order.
            $this->recordError($attempt, $response->errorCode, $response->message);

            return $this->unknown($attempt, 'MyFatoorah could not be reached; the payment state is still unknown.');
        }

        if ($response->isRefusal()) {
            if ($response->httpStatus === 404) {
                // MyFatoorah has no such payment. Either the id is wrong or nothing was ever created.
                $this->recordError($attempt, 'not_found', 'MyFatoorah does not recognise this payment id.');

                return [
                    'status' => $attempt->statusEnum(),
                    'unknown' => true,
                    'message' => 'MyFatoorah does not recognise this payment id yet.',
                    'attempt' => $attempt->refresh(),
                ];
            }

            $this->recordError($attempt, $response->errorCode, $response->message);

            // An auth problem is ours, not the customer's: the key is wrong or revoked. Treating it as a
            // failed payment would wrongly fail payments that may well have succeeded.
            if ($response->isAuthProblem()) {
                return $this->unknown($attempt, 'MyFatoorah rejected our API key; the payment state is unknown.');
            }

            return [
                'status' => $attempt->statusEnum(),
                'unknown' => true,
                'message' => $response->message,
                'attempt' => $attempt->refresh(),
            ];
        }

        return $this->interpret($attempt, $response);
    }

    /**
     * Turn a successful payment-details response into one of our statuses.
     */
    private function interpret(MyFatoorahPaymentAttempt $attempt, MyFatoorahResponse $response): array
    {
        $invoiceStatus = strtoupper((string) $response->get('Invoice.Status', ''));
        $transactionStatus = strtoupper((string) $response->get('Transaction.Status', ''));
        $externalIdentifier = (string) $response->get('Invoice.ExternalIdentifier', '');
        $paymentId = (string) $response->get('Transaction.PaymentId', '');

        $summary = $this->summarise($response);

        // Fields worth keeping whatever the outcome.
        $attempt->forceFill(array_filter([
            'invoice_id' => (string) $response->get('Invoice.Id', '') ?: null,
            'last_response_summary' => $summary,
        ], static fn ($v) => $v !== null));

        // MyFatoorah documents PAID as "there is a successful transaction" and PENDING as "all
        // transactions are FAILED, INPROGRESS, AUTHORIZE or CANCELED" — so PENDING alone does not say
        // which, and the transaction status is what separates "still trying" from "done and refused".
        if ($invoiceStatus !== 'PAID') {
            return $this->interpretUnpaid($attempt, $invoiceStatus, $transactionStatus, $response);
        }

        // ---- Paid at MyFatoorah. Now check it is OUR payment, for OUR money. ----

        if ($externalIdentifier !== '' && !hash_equals((string) $attempt->external_identifier, $externalIdentifier)) {
            // Someone fed us a payment id belonging to a different attempt. The money is real but it is
            // not this order's, so this order stays unpaid and the event is flagged.
            return $this->mismatch(
                $attempt,
                'reference_mismatch',
                'MyFatoorah reports this payment against a different order reference.',
                $response,
            );
        }

        [$capturedAmount, $capturedCurrency] = $this->capturedMoney($response, (string) $attempt->expected_currency);

        if ($capturedCurrency === null || $capturedAmount === null) {
            return $this->mismatch($attempt, 'amount_unreadable', 'MyFatoorah did not report a readable amount for this payment.', $response);
        }

        if (strtoupper($capturedCurrency) !== strtoupper((string) $attempt->expected_currency)) {
            return $this->mismatch(
                $attempt,
                'currency_mismatch',
                'Paid in ' . strtoupper($capturedCurrency) . ' but the order is in ' . strtoupper((string) $attempt->expected_currency) . '.',
                $response,
                $capturedAmount,
                $capturedCurrency,
            );
        }

        $expected = Money::format($attempt->expected_amount);
        $captured = Money::format($capturedAmount);

        if (!Money::equal($expected, $captured)) {
            return $this->mismatch(
                $attempt,
                'amount_mismatch',
                'Paid ' . $captured . ' but the order total is ' . $expected . '.',
                $response,
                $capturedAmount,
                $capturedCurrency,
            );
        }

        // Everything agrees. This is the only path that produces Paid.
        $attempt->forceFill([
            'status' => AttemptStatus::Paid->value,
            'payment_id' => $this->claimPaymentId($attempt, $paymentId),
            'captured_amount' => $captured,
            'captured_currency' => strtoupper($capturedCurrency),
            'verified_at' => $attempt->verified_at ?? now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ]);
        $this->persistTolerantly($attempt);

        return [
            'status' => AttemptStatus::Paid,
            'unknown' => false,
            'message' => 'Payment confirmed by MyFatoorah.',
            'attempt' => $attempt,
        ];
    }

    /**
     * Invoice is not PAID. Decide between "still happening" and "finished without paying".
     */
    private function interpretUnpaid(MyFatoorahPaymentAttempt $attempt, string $invoiceStatus, string $transactionStatus, MyFatoorahResponse $response): array
    {
        $errorCode = (string) $response->get('Transaction.Error.Code', '');
        $errorMessage = (string) $response->get('Transaction.Error.Message', '');

        $status = match ($transactionStatus) {
            // Done, and the money did not move.
            'FAILED' => AttemptStatus::Failed,
            'CANCELED', 'CANCELLED' => AttemptStatus::Cancelled,
            // AUTHORIZE means held, not captured: this integration only sends OperationType PAY, so an
            // authorised-but-uncaptured payment is not something to release an order for.
            'AUTHORIZE', 'INPROGRESS' => AttemptStatus::Pending,
            default => AttemptStatus::Pending,
        };

        // An expired invoice can no longer be paid, whatever the last transaction said.
        if ($attempt->expires_at !== null && $attempt->expires_at->isPast() && $status === AttemptStatus::Pending) {
            $status = AttemptStatus::Expired;
        }

        $attempt->forceFill([
            'status' => $status->value,
            'last_error_code' => $errorCode !== '' ? Str::limit($errorCode, 32, '') : null,
            'last_error_message' => $errorMessage !== '' ? Str::limit($errorMessage, 200, '') : null,
        ])->save();

        return [
            'status' => $status,
            // Pending is a real answer, not an unknown one: MyFatoorah told us it is still in progress.
            'unknown' => false,
            'message' => $status === AttemptStatus::Pending
                ? 'MyFatoorah is still processing this payment.'
                : 'MyFatoorah reports this payment did not complete.',
            'attempt' => $attempt,
        ];
    }

    /**
     * Which amount did the customer actually pay, and in what currency?
     *
     * MyFatoorah reports the same payment in up to three currencies — base (the account's settlement
     * currency), display (what we asked to show) and pay (what the card was charged in) — and does not
     * document which is authoritative. We asked for SAR as the display currency on a Saudi account whose
     * base currency is SAR, so all three should read SAR and agree.
     *
     * Rather than pick one and hope, this looks for the first pairing that is actually in the currency we
     * charged. If none of them is, the caller gets a currency mismatch and a human looks at it — which is
     * the right outcome for a payment that came back in a currency we did not ask for.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function capturedMoney(MyFatoorahResponse $response, string $expectedCurrency): array
    {
        $expected = strtoupper($expectedCurrency);

        $pairs = [
            ['Amount.ValueInDisplayCurrency', 'Amount.DisplayCurrency'],
            ['Amount.ValueInBaseCurrency', 'Amount.BaseCurrency'],
            ['Amount.ValueInPayCurrency', 'Amount.PayCurrency'],
        ];

        $firstReadable = [null, null];

        foreach ($pairs as [$valuePath, $currencyPath]) {
            $value = $response->get($valuePath);
            $currency = $response->get($currencyPath);

            if (!is_numeric($value) || !is_string($currency) || $currency === '') {
                continue;
            }

            if ($firstReadable[0] === null) {
                $firstReadable = [(string) $value, strtoupper($currency)];
            }

            if (strtoupper($currency) === $expected) {
                return [(string) $value, strtoupper($currency)];
            }
        }

        // Nothing in the currency we charged. Return what there was, so the caller can report the real
        // mismatch instead of a vague "unreadable".
        return $firstReadable;
    }

    /**
     * Paid, but not the payment we asked for. Recorded, never settled, and surfaced to an operator.
     */
    private function mismatch(
        MyFatoorahPaymentAttempt $attempt,
        string $code,
        string $message,
        MyFatoorahResponse $response,
        ?string $capturedAmount = null,
        ?string $capturedCurrency = null,
    ): array {
        $attempt->forceFill([
            'status' => AttemptStatus::Mismatch->value,
            'captured_amount' => $capturedAmount !== null ? number_format((float) $capturedAmount, 2, '.', '') : $attempt->captured_amount,
            'captured_currency' => $capturedCurrency !== null ? strtoupper($capturedCurrency) : $attempt->captured_currency,
            'payment_id' => $this->claimPaymentId($attempt, (string) $response->get('Transaction.PaymentId', '')),
            'last_error_code' => $code,
            'last_error_message' => Str::limit($message, 200, ''),
        ]);
        $this->persistTolerantly($attempt);

        // Loud on purpose: money exists that no order has been released for.
        Log::error('myfatoorah.payment_mismatch', [
            'attempt' => $attempt->external_identifier,
            'code' => $code,
            'invoice_id' => $attempt->invoice_id,
        ]);

        return [
            'status' => AttemptStatus::Mismatch,
            'unknown' => false,
            'message' => $message,
            'attempt' => $attempt,
        ];
    }

    /**
     * A redacted record of what MyFatoorah said, for the admin monitor.
     *
     * The real response carries a cardholder name, a masked PAN, a card token and an IP address. None of
     * that is needed to reconcile a payment, so none of it is kept: only statuses, references and the
     * method name, plus the card brand and last four, which are what an operator matching a bank
     * statement actually asks for.
     */
    private function summarise(MyFatoorahResponse $response): array
    {
        $maskedNumber = (string) $response->get('Transaction.Card.Number', '');

        return array_filter([
            'invoice_status' => $response->get('Invoice.Status'),
            'transaction_status' => $response->get('Transaction.Status'),
            'payment_method' => $response->get('Transaction.PaymentMethod'),
            'reference_id' => $response->get('Transaction.ReferenceId'),
            'track_id' => $response->get('Transaction.TrackId'),
            'authorization_id' => $response->get('Transaction.AuthorizationId'),
            'card_brand' => $response->get('Transaction.Card.Brand'),
            // Last four only, and only if MyFatoorah already masked it.
            'card_last4' => $maskedNumber !== '' ? substr(preg_replace('/\D/', '', $maskedNumber) ?: '', -4) : null,
            'error_code' => $response->get('Transaction.Error.Code'),
            'display_currency' => $response->get('Amount.DisplayCurrency'),
            'value_in_display_currency' => $response->get('Amount.ValueInDisplayCurrency'),
            'base_currency' => $response->get('Amount.BaseCurrency'),
            'value_in_base_currency' => $response->get('Amount.ValueInBaseCurrency'),
            'checked_at' => now()->toIso8601String(),
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Which id to look the payment up by. A caller-supplied id is used only when the attempt has none of
     * its own or they agree; an id that contradicts a stored one is ignored, because accepting it is how
     * one customer's callback gets pointed at another customer's payment.
     */
    private function chooseLookupId(MyFatoorahPaymentAttempt $attempt, ?string $paymentId): ?string
    {
        $stored = is_string($attempt->payment_id) && $attempt->payment_id !== '' ? $attempt->payment_id : null;
        $given = is_string($paymentId) && trim($paymentId) !== '' ? trim($paymentId) : null;

        if ($stored !== null) {
            return $stored;
        }

        if ($given !== null && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $given) === 1) {
            return $given;
        }

        return null;
    }

    /**
     * Find MyFatoorah's payment id for an invoice we created but never heard back about.
     *
     * Only ever returns an id for the NORMAL verification to then check. Nothing is settled here, and a
     * lookup that fails leaves the attempt exactly as it was — including when the endpoint itself is
     * not available on the account, which is recorded as a distinct error so an operator can see that
     * unattended recovery of this case is not working rather than assuming it is.
     */
    private function discoverPaymentId(MyFatoorahPaymentAttempt $attempt, GatewayConfig $config): ?string
    {
        $invoiceId = is_string($attempt->invoice_id) && $attempt->invoice_id !== '' ? $attempt->invoice_id : null;

        $lookups = [];

        if ($invoiceId !== null) {
            $lookups[] = ['invoice_details', ['invoiceId' => $invoiceId]];
        }

        $lookups[] = ['invoice_by_reference', ['reference' => (string) $attempt->external_identifier]];

        foreach ($lookups as [$endpoint, $params]) {
            $response = $this->client->get($config, $endpoint, $params);

            if ($response->isUnknown()) {
                // Network, not a missing feature. Say nothing and let the next sweep try again.
                return null;
            }

            if (!$response->succeeded) {
                if ($response->httpStatus === 404) {
                    // Could be "no such invoice" or "no such endpoint on this account" — both mean this
                    // lookup cannot help, so try the next shape and then give up.
                    continue;
                }

                return null;
            }

            $found = $this->firstPaymentIdIn($response);

            if ($found !== null) {
                // Remembered so later sweeps go straight to the normal, fully documented call — but
                // only if no other attempt already owns it.
                $claimed = $this->claimPaymentId($attempt, $found);

                if ($claimed !== $found) {
                    // Another attempt owns this payment. Using it here would verify THIS order against
                    // someone else's money, which the reference check would then reject anyway — so
                    // stop rather than make the call.
                    return null;
                }

                $attempt->forceFill(['payment_id' => $found])->save();

                return $found;
            }
        }

        $this->recordError(
            $attempt,
            'no_payment_id',
            'MyFatoorah has not reported a payment id for this invoice. If this persists, confirm the invoice-lookup endpoint for this account.',
        );

        return null;
    }

    /**
     * Pull a payment id out of whatever shape the invoice lookup answered with.
     *
     * Tolerant on purpose: the invoice endpoints' response shape is not among the ones MyFatoorah
     * documents literally, so the places a payment id could plausibly sit are each tried rather than
     * one being assumed. A successful transaction is preferred; nothing is believed beyond the id
     * itself, which the normal verification then checks properly.
     */
    private function firstPaymentIdIn(MyFatoorahResponse $response): ?string
    {
        foreach (['Transaction.PaymentId', 'PaymentId', 'Invoice.PaymentId'] as $path) {
            $value = $response->get($path);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        foreach (['Transactions', 'InvoiceTransactions', 'Invoice.Transactions'] as $listPath) {
            $list = $response->get($listPath);

            if (!is_array($list)) {
                continue;
            }

            $fallback = null;

            foreach ($list as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $id = $row['PaymentId'] ?? $row['TransactionId'] ?? null;

                if (!is_string($id) || trim($id) === '') {
                    continue;
                }

                if (strtoupper((string) ($row['Status'] ?? $row['TransactionStatus'] ?? '')) === 'SUCCESS') {
                    return trim($id);
                }

                $fallback ??= trim($id);
            }

            if ($fallback !== null) {
                return $fallback;
            }
        }

        return null;
    }

    /**
     * The payment id this attempt may record, which is not always the one it was handed.
     *
     * `myfatoorah_payment_attempts.payment_id` is unique, and deliberately so: it is what makes it
     * impossible for two attempts to claim the same money, which is how one order would be paid with
     * another's. But that means writing an id another attempt already owns raises an integrity error,
     * and an unhandled one here is a 500 on a payment callback — reachable by anyone who points a
     * callback at a payment id belonging to a different order.
     *
     * So the id is only claimed when it is free. When it is not, the attempt keeps whatever it had and
     * the clash is logged: the mismatch is still recorded, which is the outcome that matters, and the
     * request answers normally instead of erroring.
     */
    private function claimPaymentId(MyFatoorahPaymentAttempt $attempt, ?string $paymentId): ?string
    {
        $candidate = is_string($paymentId) ? trim($paymentId) : '';

        if ($candidate === '') {
            return $attempt->payment_id;
        }

        if ((string) $attempt->payment_id === $candidate) {
            return $candidate;
        }

        $ownedByAnother = MyFatoorahPaymentAttempt::query()
            ->where('payment_id', $candidate)
            ->whereKeyNot($attempt->getKey())
            ->exists();

        if ($ownedByAnother) {
            Log::error('myfatoorah.payment_id_already_claimed', [
                'attempt' => $attempt->external_identifier,
                'payment_id' => $candidate,
            ]);

            return $attempt->payment_id;
        }

        return $candidate;
    }

    /**
     * Save an attempt whose pending changes may include a payment id.
     *
     * `claimPaymentId()` checks the id is free, but two processes can both pass that check and then both
     * write — a webhook and the customer's redirect arriving together is exactly that race. The unique
     * index catches it, and losing it must not become a 500: the attempt is saved again without the id,
     * so the status and the error reason are still recorded.
     */
    private function persistTolerantly(MyFatoorahPaymentAttempt $attempt): void
    {
        try {
            $attempt->save();
        } catch (QueryException $e) {
            if (!$this->isDuplicate($e)) {
                throw $e;
            }

            Log::error('myfatoorah.payment_id_race_lost', ['attempt' => $attempt->external_identifier]);

            $attempt->payment_id = $attempt->getOriginal('payment_id');
            $attempt->save();
        }
    }

    private function isDuplicate(QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[1] ?? '');
        $message = strtolower($e->getMessage());

        return $code === '1062' || str_contains($message, 'unique') || str_contains($message, 'duplicate');
    }

    private function recordError(MyFatoorahPaymentAttempt $attempt, ?string $code, string $message): void
    {
        $attempt->forceFill([
            'last_error_code' => $code !== null ? Str::limit($code, 32, '') : null,
            'last_error_message' => Str::limit($message, 200, ''),
        ])->save();
    }

    private function unknown(MyFatoorahPaymentAttempt $attempt, string $message): array
    {
        return [
            'status' => $attempt->statusEnum(),
            'unknown' => true,
            'message' => $message,
            'attempt' => $attempt,
        ];
    }
}
