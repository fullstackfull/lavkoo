<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentGatewayAccount;
use App\Services\MyFatoorah\GatewayConfigRepository;
use App\Services\MyFatoorah\WebhookProcessor;
use App\Services\MyFatoorah\WebhookSignatureVerifier;
use App\Services\Payments\GatewayAccountRegistry;
use App\Services\Payments\GatewayAccountSecrets;
use App\Services\Payments\PaymentAccountBinding;
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
 * Authenticating a delivery is not the same as deciding what it may move, and three separate questions have to be answered before it is dispatched:
 *
 *   WHOSE GATEWAY — the account's gateway must be the gateway whose notifications this address reads. A webhook secret is a webhook secret whatever
 *   gateway an account is registered for, so without this an account of another gateway authenticates this gateway's notifications perfectly well.
 *
 *   WHOSE SECRET — the delivery must be signed with this account's own secret, and with nothing else.
 *
 *   WHOSE PAYMENT — the payment the delivery names must be one taken through THIS account. The notification names it by an identifier that carries no
 *   company (the reference, the gateway's payment id, the invoice id), and the attempts table has no company of its own, so the stamp the payment was
 *   given when it started is what answers this. Without it, one company's authentic delivery at its own address settles another company's payment.
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
    /** The gateway whose notifications this address reads: the one whose processor it dispatches to. */
    private const GATEWAY = GatewayConfigRepository::KEY;

    public function __construct(
        private readonly GatewayAccountRegistry $registry,
        private readonly GatewayAccountSecrets $secrets,
        private readonly WebhookSignatureVerifier $verifier,
        private readonly WebhookProcessor $processor,
        private readonly PaymentAccountBinding $binding,
    ) {
    }

    public function receive(Request $request, string $account): JsonResponse
    {
        $resolved = $this->registry->byWebhookToken(token: $account);

        if ($resolved === null || !$this->readsNotificationsOf(account: $resolved)) {
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

        if (!$this->namesAPaymentOfThisAccount(payload: $payload, account: $resolved)) {
            return $this->unauthorised(reason: 'payment');
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
     * May this address act for this account at all?
     *
     * Two conditions, and they are two different things. The registry's covered list is the owner's switch: a gateway that is not on it is read from the
     * platform row everywhere and has no per-company notification address. The gateway ON THE ACCOUNT is the dispatch decision: this address hands a
     * delivery to one gateway's processor, and a webhook secret authenticates a body whatever gateway the account it belongs to is registered for.
     */
    private function readsNotificationsOf(PaymentGatewayAccount $account): bool
    {
        return $this->registry->covers(gateway: (string) $account->gateway) && (string) $account->gateway === self::GATEWAY;
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
     * Does this delivery name a payment this account took?
     *
     * Asked before the delivery is recorded and before anything reads or writes the attempt, so a delivery about another company's payment leaves no
     * trace on that company's row — a write there is a cross-tenant write whether or not it settles anything.
     *
     * The payment behind the attempt must carry this account's stamp, which is the account the payment was STARTED against and is never re-derived, so
     * an account edited or disabled since does not change the answer. Two reads answer it (the attempt by its indexed identifier, then its payment row
     * by its key), so the attempts table needs no column of its own for this.
     *
     * A delivery that names no attempt of ours passes: it is this account's own authentic delivery about an invoice that is not ours — most often
     * another installation on the same merchant login — and the processor records it and acts on nothing. A payment carrying NO stamp was taken through
     * no company account at all; it belongs to the platform-wide address and is refused here.
     */
    private function namesAPaymentOfThisAccount(array $payload, PaymentGatewayAccount $account): bool
    {
        $attempt = $this->processor->attemptFor(payload: $payload);

        if ($attempt === null) {
            return true;
        }

        return $this->binding->isStampedWith(payment: $attempt->paymentRequest(), account: $account);
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
