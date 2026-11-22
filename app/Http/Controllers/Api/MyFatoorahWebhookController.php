<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MyFatoorah\GatewayConfigRepository;
use App\Services\MyFatoorah\WebhookProcessor;
use App\Services\MyFatoorah\WebhookSignatureVerifier;
use App\Services\Payments\ConfirmationAddresses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * POST /api/myfatoorah/webhook — MyFatoorah's V2 webhook.
 *
 * The URL is public and unguessable to nobody, so authentication is the signature in the
 * `MyFatoorah-Signature` header: HMAC-SHA256 of a documented string, keyed with the portal secret, in
 * base64. An unsigned or wrongly signed delivery gets a flat 401 that says nothing about why.
 *
 * It answers quickly and almost always 200. MyFatoorah retries up to five times over three minutes, and
 * a 500 on something we will never be able to process (an event type we cannot verify, a payment from
 * another installation on the same merchant account) would just buy four more copies of it. A 200 means
 * "received", not "the order is done" — the work is small enough to do inline, but it is the recording
 * that the 200 promises.
 *
 * Authenticating a delivery is not the same as deciding what it may move. This is the PLATFORM-WIDE
 * address of this gateway, and a payment taken through a company's gateway account was given that
 * company's own address when it started, so a delivery about such a payment names a payment this
 * address is not expected to confirm (GCC-21). `confirmationIsExpectedHere()` is that question, and the
 * whole of its policy lives in App\Services\Payments\ConfirmationAddresses. A payment that recorded no
 * address of its own expects THIS one and is confirmed here exactly as it is today, which is every
 * payment on a shipped installation.
 *
 * Deliberately outside the `api` middleware group: that group writes a telemetry row per request, and
 * these bodies carry a cardholder name and a masked card number.
 */
class MyFatoorahWebhookController extends Controller
{
    public function __construct(
        private readonly GatewayConfigRepository $configRepo,
        private readonly WebhookSignatureVerifier $verifier,
        private readonly WebhookProcessor $processor,
        private readonly ConfirmationAddresses $addresses,
    ) {
    }

    public function receive(Request $request): JsonResponse
    {
        $raw = $request->getContent();

        if (strlen($raw) > (int) config('myfatoorah.webhook.max_body_bytes', 65536)) {
            return response()->json(['success' => false], 413);
        }

        if (!$this->ipAllowed($request)) {
            return $this->unauthorised('ip');
        }

        $payload = json_decode($raw, true);

        if (!is_array($payload) || $payload === []) {
            return response()->json(['success' => false, 'message' => 'Invalid payload'], 422);
        }

        if (!$this->authentic($request, $payload)) {
            return $this->unauthorised('signature');
        }

        if (!$this->confirmationIsExpectedHere($payload)) {
            return $this->unauthorised('payment');
        }

        try {
            $outcome = $this->processor->handle($payload, $raw);
        } catch (\Throwable $e) {
            // A real fault on our side. 500 so MyFatoorah retries, which is what we want for a
            // transient database problem.
            Log::error('myfatoorah.webhook_failed', ['exception' => $e::class]);

            return response()->json(['success' => false], 500);
        }

        return response()->json([
            'success' => true,
            'status' => $outcome['result'],
        ], $outcome['http'] ?? 200);
    }

    /**
     * Is this really from MyFatoorah? Nothing gets past here on anything but a verified signature.
     *
     * An earlier version let an event type through unverified on the grounds that the processor would
     * only re-read the API rather than believe the body. That was wrong twice over: the re-read is
     * itself an outbound provider call, so anyone who could reach this URL could spend our MyFatoorah
     * rate limit and fill our tables, and "we only do something small with untrusted input" is not a
     * basis for accepting untrusted input on a payment endpoint.
     *
     * So an event type whose signed-field order MyFatoorah does not publish — everything except
     * PAYMENT_STATUS_CHANGED — cannot be authenticated and is therefore refused. Refund state is not
     * lost by that: it is confirmed by an operator against the portal, which is how this integration
     * treats refunds anyway.
     */
    private function authentic(Request $request, array $payload): bool
    {
        $config = $this->configRepo->get();
        $signature = $request->header((string) config('myfatoorah.webhook.signature_header', 'MyFatoorah-Signature'));

        if ($this->verifier->verify($payload, $signature, $config->webhookSecret)) {
            return true;
        }

        // The signature did not check out against the active mode's secret. Try the other mode's: a
        // store that has just switched test→live can still be receiving deliveries for invoices created
        // moments earlier under the other key, and dropping those would strand real payments.
        $other = $this->configRepo->forMode($config->mode === 'live' ? 'test' : 'live');

        if ($other->hasWebhookSecret() && $this->verifier->verify($payload, $signature, $other->webhookSecret)) {
            return true;
        }

        return $this->signatureRequirementWaived($config->mode);
    }

    /**
     * Is THIS the address the payment this delivery names expects its confirmation at (GCC-21)?
     *
     * This is the public, platform-wide address, and a payment taken through a company's gateway account was given that company's own address when it
     * started. A delivery about such a payment is therefore not this address's to act on, and the rule it is held to is the one
     * App\Services\Payments\ConfirmationAddresses states: a payment expects one confirmation address, and that address, and only it, confirms it.
     *
     * Asked AFTER the signature and BEFORE the processor, which is where it has to be. The processor is the only writer of the event table and the only
     * caller of verification and settlement, so a refusal here leaves no event row, no column of the attempt, no status, no ledger row and no order
     * side effect — the same ordering, and the same reason for it, as the company address' own question
     * (App\Http\Controllers\Api\PaymentAccountWebhookController).
     *
     * A delivery naming NO attempt of ours passes, as it does at a company address: it is an invoice of another installation on the same merchant
     * login, and the processor records it and acts on nothing. A payment that recorded NO address expects this one and passes — the three shapes that
     * read that way, and why each of them is a correct answer rather than a missing record, are named on
     * App\Services\Payments\PaymentAccountBinding::expectsAnAddressOtherThan().
     *
     * The refusal is the same flat 401 every other refusal here gives. MyFatoorah will retry it and give up, which is right: the confirmation of that
     * payment is not lost by this address declining it, because the gateway was given the company's address for that payment and delivers there.
     *
     * The rule being off is answered before the attempt is looked up, so a delivery on an installation that has not turned it on issues exactly the
     * queries it issues today — not one read more.
     */
    private function confirmationIsExpectedHere(array $payload): bool
    {
        if (!$this->addresses->isEnforced()) {
            return true;
        }

        $attempt = $this->processor->attemptFor(payload: $payload);

        if ($attempt === null) {
            return true;
        }

        return $this->addresses->mayConfirm(address: route('myfatoorah.webhook'), payment: $attempt->paymentRequest());
    }

    /**
     * Is the signature requirement waived for this delivery?
     *
     * Only ever in sandbox. The switch exists so a sandbox integration can be debugged when the portal's
     * secure key has not been set up yet; on a live account it does nothing at all, however it is
     * configured. An accepted-unsigned production webhook is an open door to marking orders paid, and
     * that must not be reachable by an environment variable.
     */
    private function signatureRequirementWaived(string $mode): bool
    {
        if ($mode === 'live') {
            return false;
        }

        return !(bool) config('myfatoorah.webhook.require_signature', true);
    }

    private function ipAllowed(Request $request): bool
    {
        $allowList = (array) config('myfatoorah.webhook.ip_allowlist', []);

        if ($allowList === []) {
            // MyFatoorah publishes no source ranges, so an allow-list is opt-in for operators who have
            // been given them. Empty means "rely on the signature", which is the documented control.
            return true;
        }

        return IpUtils::checkIp((string) $request->ip(), $allowList);
    }

    /** One flat refusal for every reason, so a prober learns nothing from the difference. */
    private function unauthorised(string $reason): JsonResponse
    {
        Log::warning('myfatoorah.webhook_rejected', ['reason' => $reason]);

        return response()->json(['success' => false, 'message' => 'Unauthorized request'], 401);
    }
}
