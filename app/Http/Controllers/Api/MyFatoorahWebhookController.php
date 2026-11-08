<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MyFatoorah\GatewayConfigRepository;
use App\Services\MyFatoorah\WebhookProcessor;
use App\Services\MyFatoorah\WebhookSignatureVerifier;
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
 * Deliberately outside the `api` middleware group: that group writes a telemetry row per request, and
 * these bodies carry a cardholder name and a masked card number.
 */
class MyFatoorahWebhookController extends Controller
{
    public function __construct(
        private readonly GatewayConfigRepository $configRepo,
        private readonly WebhookSignatureVerifier $verifier,
        private readonly WebhookProcessor $processor,
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

        if (!$this->authentic($request, $payload, $raw)) {
            return $this->unauthorised('signature');
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
     * Is this really from MyFatoorah?
     *
     * The secret is the one for the mode the gateway is currently in. An event type whose signed-field
     * order MyFatoorah does not publish cannot be verified at all; it is accepted only so it can be
     * RECORDED, and the processor acts on such an event solely by re-reading the API, never by
     * believing the body. The one event that moves money — PAYMENT_STATUS_CHANGED — is always verified.
     */
    private function authentic(Request $request, array $payload, string $raw): bool
    {
        $config = $this->configRepo->get();
        $signature = $request->header((string) config('myfatoorah.webhook.signature_header', 'MyFatoorah-Signature'));
        $eventCode = $this->verifier->eventCode($payload);

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

        if ($eventCode !== null && !$this->verifier->canVerify($eventCode)) {
            /*
             * Nobody can verify this one: MyFatoorah publishes the signed-field order only for
             * PAYMENT_STATUS_CHANGED. Letting it through is safe because the processor's only response to
             * an event it cannot authenticate is to re-read the API — it never believes the body. Refusing
             * it instead would just earn four retries of something we will never act on.
             */
            Log::info('myfatoorah.webhook_unverifiable_event', ['event_code' => $eventCode]);

            return true;
        }

        /*
         * A PAYMENT_STATUS_CHANGED whose signature does not check out, or a body with no event code at
         * all. This is the case that must stay closed: it is the only event that moves money. It is let
         * through only when an operator has deliberately switched the requirement off to debug a sandbox,
         * which the admin page warns about.
         */
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
