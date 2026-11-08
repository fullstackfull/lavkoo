<?php

namespace App\Http\Controllers\Payment_Methods;

use App\Enums\MyFatoorah\AttemptStatus;
use App\Models\MyFatoorahPaymentAttempt;
use App\Models\PaymentRequest;
use App\Services\MyFatoorah\PaymentInitiationService;
use App\Services\MyFatoorah\PaymentSettlementService;
use App\Services\MyFatoorah\PaymentVerificationService;
use App\Traits\Processor;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * The two web endpoints of the MyFatoorah flow.
 *
 *   GET payment/myfatoorah/pay?payment_id=<uuid>      send the customer to the hosted payment page
 *   GET payment/myfatoorah/callback?reference=…       where MyFatoorah returns them afterwards
 *
 * The callback is the part worth reading carefully. MyFatoorah appends `?paymentId=…` to it, and that is
 * all it carries — no status, no amount, nothing signed. It is a browser navigation, so anyone can type
 * it. So this controller treats it as nothing more than a prompt to go and ask MyFatoorah what
 * happened over a server-to-server call, and the customer sees whatever that call says, not whatever
 * the URL claimed.
 *
 * This is also why the callback carries OUR reference rather than a database id: the reference names an
 * attempt without revealing anything about how many payments the store has taken, and the verification
 * then refuses to proceed unless MyFatoorah agrees that reference owns the payment.
 */
class MyFatoorahController extends Controller
{
    use Processor;

    public function __construct(
        private readonly PaymentInitiationService $initiation,
        private readonly PaymentVerificationService $verification,
        private readonly PaymentSettlementService $settlement,
    ) {
    }

    /**
     * Start a payment and redirect to the hosted page.
     *
     * No amount is accepted from the request. The figure charged is the one on the payment_requests row,
     * which the checkout computed from the cart on the server.
     */
    public function pay(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'payment_id' => 'required|uuid',
        ]);

        if ($validator->fails()) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_400, null, $this->error_processor($validator)), 400);
        }

        $paymentRequest = PaymentRequest::where('id', $request['payment_id'])->first();

        if ($paymentRequest === null) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_204), 200);
        }

        $result = $this->initiation->start($paymentRequest, $request->header('X-Locale'));

        if (!$result['ok']) {
            Log::warning('myfatoorah.pay_refused', [
                'payment_request_id' => (string) $paymentRequest->id,
                'code' => $result['code'] ?? null,
            ]);

            // Same shape the other gateways use for a refusal, so the existing checkout and app handle it.
            return $this->failureResponse($paymentRequest, (string) ($result['error'] ?? 'This payment could not be started.'));
        }

        return redirect()->away($result['payment_url']);
    }

    /**
     * Where MyFatoorah sends the customer back.
     *
     * Reachable without a session: it is a return from an external site, and on mobile it may even be a
     * different browser from the one that started the payment. Authority comes from the reference
     * matching an attempt AND MyFatoorah confirming that attempt owns the payment — not from a login.
     */
    public function callback(Request $request)
    {
        $reference = (string) $request->query('reference', '');
        $paymentId = $request->query('paymentId') ?? $request->query('paymentid') ?? $request->query('PaymentId');

        $attempt = $reference !== ''
            ? MyFatoorahPaymentAttempt::query()->where('external_identifier', $reference)->first()
            : null;

        if ($attempt === null) {
            // Nothing to verify and nowhere trustworthy to send them. Deliberately vague: the reference
            // is either wrong or made up, and confirming which would help someone probing.
            Log::warning('myfatoorah.callback_unknown_reference');

            return redirect()->route('payment-fail');
        }

        $paymentRequest = PaymentRequest::find($attempt->payment_request_id);

        if ($paymentRequest === null) {
            return redirect()->route('payment-fail');
        }

        // The one thing that decides the outcome: a server-to-server read.
        $verified = $this->verification->verify($attempt, is_string($paymentId) ? $paymentId : null);

        if ($verified['status'] === AttemptStatus::Paid) {
            $settled = $this->settlement->settle($verified['attempt']);

            if ($settled['settled'] || $settled['already']) {
                // `payment_response` is the shared helper every other gateway returns through, so the
                // storefront and the app both already know how to read this redirect.
                return $this->payment_response($paymentRequest->refresh(), 'success');
            }

            // Paid, but the order could not be completed. Telling the customer it failed would be a lie —
            // their money is gone — so they get the pending page and an operator gets the alert.
            return $this->pendingResponse($paymentRequest, $verified['attempt']);
        }

        if ($verified['unknown'] || $verified['status'] === AttemptStatus::Pending) {
            /*
             * We do not know yet, or MyFatoorah says it is still working. The failure hook is NOT run:
             * doing so on a timeout or a 3-D Secure delay is what cancels orders customers have paid for.
             * The webhook or the reconciliation sweep will finish this.
             */
            return $this->pendingResponse($paymentRequest, $verified['attempt']);
        }

        if ($verified['status'] === AttemptStatus::Mismatch) {
            // Money moved, but not for this order. Never shown as success; flagged for an operator.
            return $this->pendingResponse($paymentRequest, $verified['attempt']);
        }

        // A definite failure from MyFatoorah.
        $this->runFailureHook($paymentRequest, $verified['attempt']);

        return $this->payment_response($paymentRequest->refresh(), 'fail');
    }

    /**
     * The failure hook, run only when nothing else about this order is still open.
     *
     * One `payment_requests` row can have several MyFatoorah attempts: a customer whose card is declined
     * tries again, and both attempts share the row and therefore share this hook. So "attempt 1 failed"
     * is not the same as "the payment failed", and running the hook on the first is how a checkout the
     * customer is still in the middle of gets torn down — or worse, how a stale callback for a long-dead
     * attempt cancels an order a later attempt has already paid for.
     *
     * Two guards, and the second is the one that was missing: the payment must not be paid, and no other
     * attempt for the same row may still be live.
     */
    private function runFailureHook(PaymentRequest $paymentRequest, MyFatoorahPaymentAttempt $failedAttempt): void
    {
        if ((int) ($paymentRequest->is_paid ?? 0) === 1) {
            return;
        }

        if ($this->anotherAttemptIsStillOpen($paymentRequest, $failedAttempt)) {
            Log::info('myfatoorah.failure_hook_withheld', [
                'attempt' => $failedAttempt->external_identifier,
                'payment_request_id' => (string) $paymentRequest->id,
            ]);

            return;
        }

        $hook = (string) ($paymentRequest->failure_hook ?? '');

        if ($hook === '' || !function_exists($hook)) {
            return;
        }

        try {
            call_user_func($hook, $paymentRequest);
        } catch (\Throwable $e) {
            Log::error('myfatoorah.failure_hook_failed', ['exception' => $e::class]);
        }
    }

    /**
     * Is any other attempt for this payment request still able to succeed, or already succeeded?
     *
     * `settled_at` is included deliberately: an attempt that has been marked paid but whose order is
     * still being created has not finished, and failing the row underneath it would be the worst
     * possible timing.
     */
    private function anotherAttemptIsStillOpen(PaymentRequest $paymentRequest, MyFatoorahPaymentAttempt $failedAttempt): bool
    {
        return MyFatoorahPaymentAttempt::query()
            ->where('payment_request_id', $paymentRequest->id)
            ->whereKeyNot($failedAttempt->getKey())
            ->where(function ($query): void {
                $query->inFlight()
                    ->orWhere('status', AttemptStatus::Paid->value)
                    ->orWhereNotNull('settled_at');
            })
            ->exists();
    }

    /**
     * Neither success nor failure yet.
     *
     * This needs a destination of its own. The shared `payment_response` helper can only say success or
     * fail, and sending a customer whose money may well have left their account to a page that says
     * "payment failed" is the worst thing this integration could do. So pending goes to its own page,
     * which tells them it is being confirmed and keeps checking.
     */
    private function pendingResponse(PaymentRequest $paymentRequest, MyFatoorahPaymentAttempt $attempt)
    {
        return redirect()->route('myfatoorah.pending', ['reference' => $attempt->external_identifier]);
    }

    /**
     * The "we are confirming your payment" page.
     *
     * Re-checks on each load, so a customer who leaves it open sees the real answer as soon as the
     * webhook or a verification resolves it, and is redirected to the normal success or failure page at
     * that point.
     */
    public function pending(Request $request)
    {
        $reference = (string) $request->query('reference', '');

        $attempt = $reference !== ''
            ? MyFatoorahPaymentAttempt::query()->where('external_identifier', $reference)->first()
            : null;

        if ($attempt === null) {
            return redirect()->route('payment-fail');
        }

        $paymentRequest = PaymentRequest::find($attempt->payment_request_id);

        if ($paymentRequest === null) {
            return redirect()->route('payment-fail');
        }

        // Ask again. Cheap, and it is what turns this page from a dead end into a resolution.
        if ($attempt->statusEnum()->isInFlight()) {
            $verified = $this->verification->verify($attempt);
            $attempt = $verified['attempt'];

            if ($verified['status'] === AttemptStatus::Paid) {
                $this->settlement->settle($attempt);
                $attempt->refresh();
            }
        } elseif ($attempt->statusEnum() === AttemptStatus::Paid && $attempt->hook_completed_at === null) {
            $this->settlement->settle($attempt);
            $attempt->refresh();
        }

        if ($attempt->statusEnum() === AttemptStatus::Paid && $attempt->hook_completed_at !== null) {
            return $this->payment_response($paymentRequest->refresh(), 'success');
        }

        if (in_array($attempt->statusEnum(), [AttemptStatus::Failed, AttemptStatus::Cancelled, AttemptStatus::Expired], true)) {
            return $this->payment_response($paymentRequest->refresh(), 'fail');
        }

        return response()
            ->view('payment.myfatoorah-pending', [
                'reference' => $attempt->external_identifier,
                'amount' => (string) $attempt->expected_amount,
                'currency' => (string) $attempt->expected_currency,
                'retryUrl' => route('myfatoorah.pending', ['reference' => $attempt->external_identifier]),
            ])
            // Never cached: the whole point is that the answer changes.
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    private function failureResponse(PaymentRequest $paymentRequest, string $message)
    {
        if ($paymentRequest->payment_platform === 'app') {
            return response()->json(['message' => $message], 403);
        }

        return redirect()->route('payment-fail')->with('error', $message);
    }
}
