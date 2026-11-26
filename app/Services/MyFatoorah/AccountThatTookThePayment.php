<?php

namespace App\Services\MyFatoorah;

use App\Models\MyFatoorahPaymentAttempt;
use App\Models\PaymentRequest;
use App\Services\Payments\GatewayAccountRegistry;
use App\Services\Payments\GatewayAccountSecrets;
use App\Services\Payments\PaymentAccountBinding;
use Illuminate\Container\Attributes\Singleton;

/**
 * Which merchant account a refund of this payment is drawn on (D2-15, D2-07's third verb, D1-10).
 *
 * Everything else about a company's gateway account was wired and this was not, and
 * {@see \App\Services\Payments\PaymentAccountBinding} says so in its own docblock: a payment was started, verified and settled through the
 * account it was stamped with, and then REFUNDED out of the one platform-wide settings row. A refund drawn on the wrong merchant account
 * is money leaving the wrong company's balance, under the wrong trading licence, in a statement the company that took the payment cannot
 * reconcile -- and on this gateway the account is also the one that decides which MERCHANT the provider refunds from, so it is not a
 * bookkeeping detail.
 *
 * **It answers the ACCOUNT THE PAYMENT WAS TAKEN THROUGH, read off the payment's own stamp, never a fresh resolution.** An account edited,
 * disabled or re-pointed since the payment started does not move the account the money came in on.
 *
 * **Where it cannot answer, the refund is REFUSED rather than drawn on the platform account.** That is the direction that matters and it
 * is the opposite of every other fallback in this family:
 *
 *  - a payment with NO stamp -- every payment on this platform today, every Saudi payment, every payment while the accounts are off -- was
 *    taken through the platform settings row, so that row is the correct and only answer and nothing changes for it;
 *  - a payment stamped with an account whose ROW IS GONE, or whose row holds no api_key, is a payment this platform cannot refund through
 *    the account that took it. Falling back would send company A's refund out of the platform merchant account, which is precisely the
 *    defect. An operator is told which account is missing instead.
 *
 * The connection it hands back is the platform connection for the ACCOUNT's environment, authenticated with the account's own key, so the
 * host allow-list, the retry policy and every other setting of this integration are unchanged -- an account cannot point this platform's
 * outbound calls at a host of its own by being registered.
 */
#[Singleton]
final class AccountThatTookThePayment
{
    public function __construct(
        private readonly GatewayConfigRepository $platform,
        private readonly PaymentAccountBinding $binding,
        private readonly GatewayAccountSecrets $secrets,
    ) {
    }

    /**
     * The connection a refund of this payment must be sent on.
     *
     * @return array{ok: true, config: GatewayConfig}|array{ok: false, code: string, error: string} the refusal shape
     *         {@see RefundService::request()} already returns, so it threads straight through to the operator
     */
    public function connectionFor(MyFatoorahPaymentAttempt $attempt): array
    {
        $payment = $this->paymentOf($attempt);
        $stampedAccountId = $this->binding->of(payment: $payment)?->accountId;

        if ($stampedAccountId === null) {
            return ['ok' => true, 'config' => $this->platform->forMode((string) $attempt->mode)];
        }

        $account = $this->binding->accountOf(payment: $payment);

        if ($account === null) {
            return [
                'ok' => false,
                'code' => 'account_gone',
                'error' => 'This payment was taken through gateway account ' . $stampedAccountId
                    . ', which no longer exists. Restore the account before refunding, so the money goes back out of the merchant account it came in on.',
            ];
        }

        $apiKey = $this->secrets->read(account: $account, name: 'api_key');

        if (!is_string($apiKey) || $apiKey === '') {
            return [
                'ok' => false,
                'code' => 'account_has_no_key',
                'error' => 'Gateway account ' . $account->id . ' took this payment and holds no API key, so the refund cannot be sent'
                    . ' through it. Enter the account\'s key rather than refunding out of another account.',
            ];
        }

        $environment = GatewayAccountRegistry::environment((string) $account->environment);

        return [
            'ok' => true,
            'config' => $this->platform->forMode($environment)->authenticatedAs(
                apiKey: $apiKey,
                webhookSecret: $this->secrets->webhookSecret(account: $account),
            ),
        ];
    }

    /** The payment row this attempt was made for, or null for an attempt that names none. */
    private function paymentOf(MyFatoorahPaymentAttempt $attempt): ?PaymentRequest
    {
        $paymentRequestId = $attempt->payment_request_id ?? null;

        if (!is_scalar($paymentRequestId) || (string) $paymentRequestId === '') {
            return null;
        }

        return PaymentRequest::query()->whereKey($paymentRequestId)->first();
    }
}
