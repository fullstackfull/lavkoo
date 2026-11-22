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
 * NOTHING IS STRANDED. Two separate things have to hold for a recorded address to be live: the per-account addresses only resolve an account while the
 * registry is on (App\Services\Payments\GatewayAccountRegistry::byWebhookToken), and the registry only answers while payment accounts are enabled. So
 * enforcement here requires the registry to be ENABLED as well as this switch to be on. An operator who turns payment accounts off has turned every
 * company address off with them, and the platform address then confirms every payment again — including the ones that had recorded a company address,
 * which would otherwise have no live address at all.
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
     * and yes for a payment that recorded no address when this is a platform-wide one. No only for a payment that positively expects another.
     */
    public function mayConfirm(string $address, PaymentRequest|array|null $payment): bool
    {
        if (!$this->isEnforced()) {
            return true;
        }

        return !$this->binding->expectsAnAddressOtherThan(payment: $payment, address: $address);
    }
}
