<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentGatewayAccount;
use App\Services\MyFatoorah\WebhookProcessor;
use App\Services\MyFatoorah\WebhookSignatureVerifier;
use App\Services\Payments\GatewayAccountRegistry;
use App\Services\Payments\GatewayAccountSecrets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/payments/account/{account}/webhook — one company's own notification address (D2-09).
 *
 * The account is resolved from the path token BEFORE anything is read from the body, and the signing secret is taken FROM THAT ACCOUNT. That order is
 * the whole point of the route: a delivery signed with company A's secret computes a different signature against company B's, so it is refused here,
 * and there is no shared secret any company's delivery could be validated against instead.
 *
 * What the platform-wide address at POST /api/myfatoorah/webhook does is unchanged — it is the address every notification goes to today — and this
 * address does nothing at all until a company has an account with a token and a secret of its own.
 *
 * The rest of the treatment is deliberately the same as the platform address': one flat 401 for every refusal so a prober learns nothing from the
 * difference, a body cap, an event type whose signature cannot be computed refused rather than acted on, and the delivery recorded under a dedupe key
 * before any work, so one delivery is processed once (D2-12). The key is scoped to the account, so two companies' accounts on one merchant login do not
 * swallow each other's deliveries.
 *
 * Outside the `api` middleware group, as the platform address is: that group writes a telemetry row per request and these bodies carry a cardholder
 * name and a masked card number.
 */
class PaymentAccountWebhookController extends Controller
{
    public function __construct(
        private readonly GatewayAccountRegistry $registry,
        private readonly GatewayAccountSecrets $secrets,
        private readonly WebhookSignatureVerifier $verifier,
        private readonly WebhookProcessor $processor,
    ) {
    }

    public function receive(Request $request, string $account): JsonResponse
    {
        $resolved = $this->registry->byWebhookToken(token: $account);

        if ($resolved === null || !$this->registry->covers(gateway: (string) $resolved->gateway)) {
            return $this->unauthorised(reason: 'account');
        }

        $raw = $request->getContent();

        if (strlen($raw) > (int) config('myfatoorah.webhook.max_body_bytes', 65536)) {
            return response()->json(['success' => false], 413);
        }

        $payload = json_decode($raw, true);

        if (!is_array($payload) || $payload === []) {
            return response()->json(['success' => false, 'message' => 'Invalid payload'], 422);
        }

        if (!$this->authentic(request: $request, payload: $payload, account: $resolved)) {
            return $this->unauthorised(reason: 'signature');
        }

        if (!$this->inScope(payload: $payload, account: $resolved)) {
            // Authentic, and not something this account is registered for. Recorded as out of scope and acted on in no way.
            return response()->json(['success' => true, 'status' => 'out_of_scope'], 200);
        }

        try {
            $outcome = $this->processor->handle($payload, $raw, 'a' . (int) $resolved->id);
        } catch (\Throwable $e) {
            Log::error('payments.account_webhook_failed', ['account' => (int) $resolved->id, 'exception' => $e::class]);

            return response()->json(['success' => false], 500);
        }

        return response()->json(['success' => true, 'status' => $outcome['result']], $outcome['http'] ?? 200);
    }

    /**
     * Is this delivery signed with THIS account's secret?
     *
     * One secret, the account's own, and no fallback to any other: an account with no secret authenticates nothing, and the platform row's secret is
     * not tried either — a company's address that accepted the platform secret would be a company's address in name only.
     */
    private function authentic(Request $request, array $payload, PaymentGatewayAccount $account): bool
    {
        $secret = $this->secrets->webhookSecret(account: $account);

        if ($secret === null) {
            return false;
        }

        $signature = $request->header((string) config('myfatoorah.webhook.signature_header', 'MyFatoorah-Signature'));

        return $this->verifier->verify($payload, $signature, $secret);
    }

    /**
     * Whether this account is registered for this event.
     *
     * An account that names no scope takes every event its gateway's processor can act on, which is the behaviour of the platform address. An account
     * that names one takes those event codes and no others.
     */
    private function inScope(array $payload, PaymentGatewayAccount $account): bool
    {
        $scope = json_decode((string) $account->getAttribute('webhook_events'), true);

        if (!is_array($scope) || $scope === []) {
            return true;
        }

        $code = $this->verifier->eventCode($payload);

        return $code !== null && in_array($code, array_map('intval', array_filter($scope, 'is_numeric')), true);
    }

    /** One flat refusal for every reason, so a prober learns nothing from the difference. */
    private function unauthorised(string $reason): JsonResponse
    {
        Log::warning('payments.account_webhook_rejected', ['reason' => $reason]);

        return response()->json(['success' => false, 'message' => 'Unauthorized request'], 401);
    }
}
