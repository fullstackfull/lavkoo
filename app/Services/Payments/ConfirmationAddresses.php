<?php

namespace App\Services\Payments;

use App\Models\PaymentRequest;
use Illuminate\Container\Attributes\Singleton;

/**
 * Which address confirms which payment (GCC-21): a payment expects one confirmation address, and that address, and only it, confirms that payment.
 *
 * The one place that policy is decided, so a gateway's public address asks a question rather than carrying a rule of its own. The address a payment
 * expects is the one it RECORDED when it started (App\Services\Payments\PaymentAccountBinding), read as stored and never re-derived, so an account
 * edited, disabled or deleted since does not move it.
 *
 * WHICH WAY THIS FAILS. The refusal is keyed on a payment positively expecting SOME OTHER address, never on a payment having recorded none. A payment
 * with nothing recorded is a payment from before any of this, or from a market with no account, or of a gateway notified at no address of its own, and
 * for every one of those the platform address is the right answer and not a missing one. `expectsAnAddressOtherThan()` names the three stored shapes
 * that read that way and what each means.
 *
 * NOTHING IS STRANDED, and that takes TWO positive facts and not one. A payment is declined here only where it positively expects another address AND
 * that address is still an address the payment can be confirmed at. The platform address is the address of LAST RESORT: where the address a payment
 * recorded is no longer live, this one confirms it, exactly as it did before any of this.
 *
 * An earlier version of this class leant on the registry's master flag alone, on the grounds that turning payment accounts off turns every company
 * address off with them. That is true and it is not enough: `GatewayAccountRegistry::byWebhookToken()` resolves an ACTIVE row whose token matches
 * exactly, the route adds the owner's covered list, and a company's address authenticates with that account's own secret and no other. So an operator
 * who deactivated an account, deleted it, took its gateway off the covered list, retyped its token or had not yet given it a webhook secret left a
 * payment that had recorded its address with no live address at all, while this class went on declining it here. Measured, with the rule on, against
 * the base branch in a second worktree: six disturbances, each an ordinary administrative act, each confirmed and paid before this condition and each
 * left unconfirmed without it. Found by reviewer A and reviewer B of the stage-C round, from opposite ends.
 *
 * `theAddressItExpectsIsLive()` is that second fact, and it asks the question the route itself answers rather than a copy of it: the account the
 * payment was STAMPED with, the address that account is named at RIGHT NOW (App\Services\Payments\GatewayAccountDirectory::webhookUrlOf, which
 * applies the route's four conditions), and the secret without which that address authenticates nothing.
 *
 * A COMPANY'S OWN ADDRESS KEEPS KEYING ON THE ACCOUNT, not on the recorded address text, and that asymmetry is deliberate.
 * App\Http\Controllers\Api\PaymentAccountWebhookController asks whether the payment carries THIS ACCOUNT's stamp, and it must: an account's webhook
 * token is editable in place, so a token retyped after a payment started leaves the recorded URL naming an address that no longer resolves. The
 * account survives that edit and the URL does not, so the account is the durable question there and the address is the durable question here, where
 * the alternative is a public address acting on a payment it was never given.
 */
#[Singleton]
final class ConfirmationAddresses
{
    public function __construct(
        private readonly GatewayAccountRegistry $registry,
        private readonly PaymentAccountBinding $binding,
        private readonly GatewayAccountDirectory $directory,
        private readonly GatewayAccountSecrets $secrets,
    ) {
    }

    /**
     * Is the one-address rule in force on this installation?
     *
     * Both halves, and the registry's is not a formality — see the class note on what turning payment accounts off would otherwise strand. Off by
     * default on both counts, so a shipped installation answers false here.
     */
    public function isEnforced(): bool
    {
        return $this->registry->isEnabled() && (bool) config('gcc.storefronts.payment_accounts.one_confirmation_address');
    }

    /**
     * May this address confirm this payment?
     *
     * Yes while the rule is not in force, which is today's behaviour for every address and every payment. Yes for a payment that expects this address,
     * and yes for a payment that recorded no address when this is a platform-wide one. Yes, too, for a payment whose recorded address is no longer one
     * it can be confirmed at — see the class note on why that is not a loophole but the half of the owner's decision that keeps money from being
     * stranded. No only for a payment that positively expects another address AND can still be confirmed there.
     */
    public function mayConfirm(string $address, PaymentRequest|array|null $payment): bool
    {
        if (!$this->isEnforced()) {
            return true;
        }

        if (!$this->binding->expectsAnAddressOtherThan(payment: $payment, address: $address)) {
            return true;
        }

        return !$this->theAddressItExpectsIsLive(payment: $payment);
    }

    /**
     * Is the address this payment expects still an address this payment can be confirmed at?
     *
     * Three things, and each of them is a property of the account and of the address — never of the delivery, which this class does not see:
     *
     *   THE ACCOUNT IS STILL THERE. The stamp names it by id and is never re-derived, so this is the account the payment was taken through. A deleted
     *   row, or a stamp that names no account, answers null and the address is not live.
     *
     *   THE ADDRESS IS THE ONE THAT ACCOUNT IS NAMED AT NOW. `GatewayAccountDirectory::webhookUrlOf()` applies the four conditions the route applies,
     *   so this one comparison covers the owner's covered list, the gateway on the account, the token, and the account being active. A token retyped
     *   after the payment started names a different address, so the recorded one is not live; the account survives the edit and its NEW address
     *   confirms the payment, because a company's address keys on the account.
     *
     *   THE ACCOUNT HOLDS THE SECRET its address authenticates with. An account with none authenticates nothing
     *   (App\Http\Controllers\Api\PaymentAccountWebhookController::authentic), so its address reads no delivery, and a payment bound to it has no
     *   live address of its own. Reading the attribute off the stamped account costs no query.
     *
     * The direction is deliberate. Each of these makes the platform address confirm MORE than a bare address comparison would, and every one of them
     * is a case the base branch confirms. An address that is live is refused here; an address that is not is handed back to the address of last
     * resort. A condition this method does not know about can therefore only strand, never over-confirm, which is why the account's event scope is
     * recorded as open rather than guessed at: it is a property of the delivery as well as of the account, and this class is not given the delivery.
     */
    private function theAddressItExpectsIsLive(PaymentRequest|array|null $payment): bool
    {
        $account = $this->binding->accountOf(payment: $payment);

        if ($account === null) {
            return false;
        }

        return $this->directory->webhookUrlOf(account: $account) === $this->binding->of(payment: $payment)?->confirmationAddress
            && $this->secrets->webhookSecret(account: $account) !== null;
    }
}
