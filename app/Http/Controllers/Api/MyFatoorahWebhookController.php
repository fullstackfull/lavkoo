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

        if (!$this->authentic($request, $payload)) {
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
