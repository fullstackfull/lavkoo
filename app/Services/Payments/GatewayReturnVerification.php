<?php

namespace App\Services\Payments;

use App\Models\PaymentRequest;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Log;

/**
 * Whether a customer's return from a gateway may settle their payment (D2-11).
 *
 * A return is a request made by the customer's browser. It carries whatever the browser carries, and the browser is not the gateway. So two things, in
 * this order, before anything is settled:
 *
 *   1. The return is signed, and the signature checks out against a secret only this shop and the gateway hold — the account's own secret when the
 *      payment was taken through a company account, otherwise the platform settings row, which is what every payment uses today. A gateway with NO
 *      configured secret verifies nothing: an unsigned return and a return signed with an empty key are the same thing, and that thing is refused.
 *
 *   2. When the owner has turned confirmation on, the server asks the gateway itself what became of the transaction, and settles only on the gateway's
 *      own "paid" with the amount and currency of the stored payment. A return the server cannot confirm — a timeout, an unreachable gateway, a
 *      transaction the gateway does not know — settles nothing and fails nothing.
 *
 * The refusal is the default of each step: this answers "not confirmed" unless a step positively says otherwise.
 *
 * Settling once is not this class's job and is not duplicated here: App\Services\Payments\GatewayConfirmations holds the gateway's own reference in a
 * register under a unique key, so a confirmed return that is replayed settles nothing further.
 */
#[Singleton]
final class GatewayReturnVerification
{
    public function __construct(private readonly PaymentAccountBinding $binding)
    {
    }

    /** Whether the server asks the gateway for this gateway's returns. Off by default: a return is read exactly as it is today. */
    public function confirmsWithGateway(string $gateway): bool
    {
        return (bool) config('gcc.storefronts.payment_accounts.confirm_returns')
            && app(GatewayAccountRegistry::class)->covers(gateway: $gateway);
    }

    /**
     * The secret this gateway's returns are signed with: the account's own when the payment carries one, else the platform row's.
     *
     * @param callable():?string $platformSecret what the gateway reads today, called only when there is no account secret
     */
    public function signingSecret(PaymentRequest|array|null $payment, string $name, callable $platformSecret): ?string
    {
        $account = $this->binding->accountOf(payment: $payment);

        if ($account !== null) {
            return app(GatewayAccountSecrets::class)->read(account: $account, name: $name);
        }

        $secret = $platformSecret();

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /**
     * Whether a signature over this string is the gateway's.
     *
     * Constant time, and false whenever there is no secret to compare against: a missing secret would otherwise make `hash_hmac` key the digest with an
     * empty string, which anybody can compute, so "not configured" would read as "signed by the gateway".
     */
    public function signatureHolds(string $algorithm, string $signedString, ?string $provided, ?string $secret): bool
    {
        if (!is_string($secret) || $secret === '' || !is_string($provided) || trim($provided) === '') {
            return false;
        }

        return hash_equals(hash_hmac($algorithm, $signedString, $secret), trim($provided));
    }

    /**
     * What the gateway itself says about the transaction this return names.
     *
     * Unknown while confirmation is off for this gateway, so a caller may ask unconditionally and get the answer that changes nothing.
     */
    public function confirm(PaymentRequest|array|null $payment, string $gateway, string $reference): GatewayTransactionState
    {
        if (!$this->confirmsWithGateway(gateway: $gateway)) {
            return GatewayTransactionState::unknown();
        }

        $inquiry = $this->inquiryFor(gateway: $gateway);
        $account = $this->binding->accountOf(payment: $payment);

        if ($inquiry === null || !$inquiry->canAsk(account: $account)) {
            Log::warning('gateway.return_cannot_be_confirmed', ['gateway' => $gateway, 'account' => $account?->id]);

            return GatewayTransactionState::unknown();
        }

        return $inquiry->transaction(account: $account, reference: $reference);
    }

    /** Which implementation asks this gateway, or null for a gateway nothing can ask. */
    private function inquiryFor(string $gateway): ?GatewayTransactionInquiry
    {
        $class = (array) config('gcc.storefronts.payment_accounts.inquiries', []);
        $resolved = $class[$gateway] ?? null;

        return is_string($resolved) && is_subclass_of($resolved, GatewayTransactionInquiry::class) ? app($resolved) : null;
    }
}
