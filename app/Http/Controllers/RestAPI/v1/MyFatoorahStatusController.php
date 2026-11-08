<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Enums\MyFatoorah\AttemptStatus;
use App\Http\Controllers\Controller;
use App\Models\MyFatoorahPaymentAttempt;
use Illuminate\Support\Facades\DB;
use App\Models\PaymentRequest;
use App\Services\MyFatoorah\PaymentSettlementService;
use App\Services\MyFatoorah\PaymentVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * GET /api/v1/payment/myfatoorah/status?reference=…  — what the mobile app asks after a payment.
 *
 * This endpoint exists because the app cannot be allowed to decide the outcome itself. It used to read
 * the result out of the redirect URL (`url.contains('success')`), which is a string in a browser bar:
 * it is wrong whenever the customer loses connectivity on the way back, and it can be forged outright.
 * So the app now asks here, and this asks MyFatoorah.
 *
 * Three states, and the third is the important one:
 *
 *   paid     verified server-to-server and applied. Only now may the app show success.
 *   failed   MyFatoorah gave a definite no.
 *   pending  we genuinely do not know yet (3-D Secure, a wallet hand-off, a timeout, a lost webhook).
 *            The app must show "confirming", NOT failure — a customer whose money has gone being told
 *            the payment failed is the worst outcome available, and it is what every naive
 *            implementation does on a timeout.
 *
 * Authorisation: the reference is a 35-character unguessable token tied to one attempt, and a logged-in
 * customer may only read an attempt belonging to their own payment request. Guests have no account to
 * check against, so for them the reference is the credential — which is why it is unguessable and why
 * this returns no customer details, no amounts belonging to anyone else, and nothing about the payment
 * method beyond its name.
 */
class MyFatoorahStatusController extends Controller
{
    public function __construct(
        private readonly PaymentVerificationService $verification,
        private readonly PaymentSettlementService $settlement,
    ) {
    }

    public function status(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reference' => 'required|string|max:64',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'A payment reference is required.'], 422);
        }

        $attempt = MyFatoorahPaymentAttempt::query()
            ->where('external_identifier', (string) $request['reference'])
            ->first();

        if ($attempt === null) {
            return response()->json(['message' => 'No such payment.'], 404);
        }

        $paymentRequest = PaymentRequest::find($attempt->payment_request_id);

        if ($paymentRequest === null) {
            return response()->json(['message' => 'No such payment.'], 404);
        }

        if (!$this->mayRead($paymentRequest)) {
            // Same 404 as "no such payment": confirming that a reference exists but belongs to someone
            // else would turn this into a way to probe for valid references.
            return response()->json(['message' => 'No such payment.'], 404);
        }

        // Re-ask MyFatoorah while the attempt is still in flight. This is what lets the app recover a
        // payment whose webhook was lost and whose redirect never arrived, without any extra plumbing.
        //
        // Rate-limited per attempt, not just per IP: this endpoint needs no login (a guest checkout has
        // no token), so without a floor a flood of requests for one reference — from anywhere — turns
        // into the same flood at MyFatoorah. Inside the floor the stored status is returned, which is
        // the right answer anyway because a webhook may well have just updated it.
        if ($attempt->statusEnum()->isInFlight() && $this->mayAskProviderAgain($attempt)) {
            $verified = $this->verification->verify($attempt);
            $attempt = $verified['attempt'];

            if ($verified['status'] === AttemptStatus::Paid) {
                $this->settlement->settle($attempt);
                $attempt->refresh();
            }
        } elseif ($attempt->statusEnum() === AttemptStatus::Paid && $attempt->hook_completed_at === null) {
            // Verified paid but the order never got created. Finish it now rather than making the
            // customer wait for the next sweep.
            $this->settlement->settle($attempt);
            $attempt->refresh();
        }

        return response()->json($this->present($attempt, $paymentRequest->refresh()));
    }

    /**
     * Has enough time passed since this attempt was last checked with MyFatoorah?
     *
     * An attempt that has never been checked is always allowed through, so the first poll after the
     * customer returns is never delayed.
     */
    private function mayAskProviderAgain(MyFatoorahPaymentAttempt $attempt): bool
    {
        if ($attempt->last_verified_at === null) {
            return true;
        }

        $floor = max(0, (int) config('myfatoorah.min_seconds_between_status_checks', 3));

        return $attempt->last_verified_at->addSeconds($floor)->isPast();
    }

    /**
     * The payload the app acts on. Nothing sensitive: no API keys, no webhook secret, no card data, no
     * internal ids beyond the order ids the customer already owns.
     */
    private function present(MyFatoorahPaymentAttempt $attempt, PaymentRequest $paymentRequest): array
    {
        $status = $attempt->statusEnum();

        $appStatus = match (true) {
            // Paid only counts as success for the app once the order actually exists, so the app never
            // shows "order placed" for an order that is not there.
            $status === AttemptStatus::Paid && $attempt->hook_completed_at !== null => 'paid',
            $status === AttemptStatus::Paid => 'confirming',
            $status === AttemptStatus::Failed => 'failed',
            $status === AttemptStatus::Cancelled => 'cancelled',
            $status === AttemptStatus::Expired => 'expired',
            // Money moved but not for this order. The customer must not be told it failed, because they
            // have been charged; an operator is already looking at it.
            $status === AttemptStatus::Mismatch => 'confirming',
            default => 'pending',
        };

        return [
            'reference' => $attempt->external_identifier,
            'status' => $appStatus,
            // True while the app should keep asking. The app uses this instead of its own guesswork.
            'is_final' => in_array($appStatus, ['paid', 'failed', 'cancelled', 'expired'], true),
            'paid' => $appStatus === 'paid',
            'amount' => (string) $attempt->expected_amount,
            'currency' => (string) $attempt->expected_currency,
            'order_ids' => $this->orderIds($paymentRequest),
            'message_key' => $this->messageKey($appStatus),
        ];
    }

    /** Orders created for this payment, so the app can jump straight to them. */
    private function orderIds(PaymentRequest $paymentRequest): array
    {
        $reference = (string) ($paymentRequest->transaction_id ?? '');

        if ($reference === '') {
            return [];
        }

        try {
            // Query builder rather than the Order model, for the same reason the settlement check uses
            // it: a global scope added later would quietly drop orders the customer does own.
            return DB::table('orders')
                ->where('transaction_ref', $reference)
                ->pluck('id')
                ->map(static fn ($id) => (int) $id)
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * A translation key rather than a sentence: the app already has Arabic and English and should show
     * its own wording, not a server string in whatever language the server happened to be in.
     */
    private function messageKey(string $appStatus): string
    {
        return match ($appStatus) {
            'paid' => 'payment_successful',
            'failed' => 'payment_failed',
            'cancelled' => 'payment_cancelled',
            'expired' => 'payment_session_expired',
            'confirming' => 'confirming_your_payment',
            default => 'payment_is_being_processed',
        };
    }

    /**
     * A logged-in customer may only read their own payment. A guest checkout has no customer to compare
     * against, so possession of the reference is the authorisation — it is unguessable and this response
     * exposes nothing that is not already the holder's own.
     */
    private function mayRead(PaymentRequest $paymentRequest): bool
    {
        $customerId = $this->currentCustomerId();

        if ($customerId === null) {
            return true;
        }

        $payerId = (string) ($paymentRequest->payer_id ?? '');

        // A guest payment carries a guest id in payer_id, which will not match a logged-in customer;
        // allow it, because the customer may have signed in during checkout.
        return $payerId === '' || $payerId === (string) $customerId || $this->isGuestPayment($paymentRequest);
    }

    /**
     * Who is asking, if anyone.
     *
     * Each guard is resolved defensively. Building the API guard constructs Passport's crypt keys, and
     * if those are missing or unreadable it throws — which would turn "what happened to my payment?"
     * into a 500 at the exact moment a customer needs an answer. A guard that cannot be built means
     * "nobody is signed in", which falls back to the reference being the credential.
     */
    private function currentCustomerId(): ?int
    {
        foreach (['api', 'customer'] as $guard) {
            try {
                $id = auth($guard)->id();

                if ($id !== null) {
                    return (int) $id;
                }
            } catch (\Throwable) {
                // This guard is not usable here; try the next.
            }
        }

        return null;
    }

    private function isGuestPayment(PaymentRequest $paymentRequest): bool
    {
        $additional = json_decode((string) $paymentRequest->additional_data, true);

        return is_array($additional) && (int) ($additional['is_guest_in_order'] ?? $additional['is_guest'] ?? 0) === 1;
    }
}
