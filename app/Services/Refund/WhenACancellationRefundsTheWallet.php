<?php

namespace App\Services\Refund;

use ArrayAccess;

/**
 * Whether cancelling an order pays the customer's wallet back, and how much (E-13, finding E04-F10).
 *
 * Two cancellations behave differently today, and the difference is deliberate rather than accidental: a POS sale paid with the wallet
 * was debited at the counter and cancelling it returns the money, while any other wallet-paid order keeps the debit until somebody
 * refunds it by hand. Which of those is right is the owner's to say -- the automatic answer means an operator's click moves money, and
 * the manual one means a forgotten refund stays with the platform -- so all three answers are reachable here and the default is the
 * platform exactly as it runs today (docs/gcc/OPEN-FINANCIAL-DECISIONS.md section 3).
 *
 * This class holds the rule, the amount and the ledger reference, which both panels asked separately and identically before it existed.
 * Paying once per cancellation is not this class's doing and never was: the move to the status IS the claim (RF-04), one conditional
 * UPDATE inside the refund's own transaction, so two cancels arriving together pay once whichever rule is in force. The reference is
 * keyed on what the order IS and not on the rule, so changing the rule can never make one cancellation look like two.
 */
final class WhenACancellationRefundsTheWallet
{
    /** The default: only a POS sale paid with the wallet refunds itself, which is the platform as it runs today. */
    public const MANUAL_AS_TODAY = 'manual_as_today';

    /** Option A: every cancelled order with money on it pays the wallet back, whatever it was paid with. */
    public const EVERY_PAID_CANCELLATION = 'every_paid_cancellation';

    /** Option C: automatic where the money came from the wallet, by hand where it has to go back the way it came in. */
    public const WALLET_PAYMENTS_ONLY = 'wallet_payments_only';

    private const CANCELED = 'canceled';

    private const POS = 'POS';

    private const WALLET = 'wallet';

    /** Which rule is in force. An unrecognised value is read as today's behaviour, so a typo in the environment cannot move money. */
    public static function rule(): string
    {
        $named = (string) config('gcc.refunds.when_a_cancellation_refunds_the_wallet');

        return in_array($named, [self::EVERY_PAID_CANCELLATION, self::WALLET_PAYMENTS_ONLY], true) ? $named : self::MANUAL_AS_TODAY;
    }

    /**
     * Whether this status change pays the customer's wallet back.
     *
     * A guest order (customer 0) never does: there is no wallet to pay. Both options also refuse an amount of nothing, so an order that
     * was never paid for cannot write a ledger row worth zero; today's rule carries no such condition and is left exactly as it is.
     *
     * @param array<string, mixed>|ArrayAccess<string, mixed> $order
     */
    public static function refunds(array|ArrayAccess $order, string $newStatus): bool
    {
        if ($newStatus !== self::CANCELED || (int) self::of($order, 'customer_id') === 0) {
            return false;
        }

        $paidWithTheWallet = self::of($order, 'payment_method') === self::WALLET;

        return match (self::rule()) {
            self::EVERY_PAID_CANCELLATION => self::amountFor($order) > 0.0,
            self::WALLET_PAYMENTS_ONLY => $paidWithTheWallet && self::amountFor($order) > 0.0,
            default => $paidWithTheWallet && self::of($order, 'order_type') === self::POS,
        };
    }

    /**
     * What the refund is worth, in the currency the wallet is kept in.
     *
     * A POS sale paid with the wallet is paid in full at the counter, and the whole order amount is what cancelling it has always
     * credited: that figure does not move. Every order the options newly cover is credited what was actually PAID on it, capped at what
     * it costs -- crediting a customer more than they handed over is the one mistake nobody can take back quietly.
     *
     * @param array<string, mixed>|ArrayAccess<string, mixed> $order
     */
    public static function amountFor(array|ArrayAccess $order): float
    {
        $orderAmount = (float) self::of($order, 'order_amount');

        if (self::isAPosWalletSale($order)) {
            return (float) usdToDefaultCurrency(amount: $orderAmount);
        }

        return (float) usdToDefaultCurrency(amount: min($orderAmount, (float) self::of($order, 'paid_amount')));
    }

    /**
     * The reference the ledger row carries. A POS cancellation keeps the reference it has always carried, so a refund already written
     * stays the one the reconciler, the reports and the existing tests know; anything else is an order cancellation and says so.
     *
     * @param array<string, mixed>|ArrayAccess<string, mixed> $order
     */
    public static function referenceFor(array|ArrayAccess $order): string
    {
        $prefix = self::of($order, 'order_type') === self::POS ? 'pos_order_cancel_' : 'order_cancel_';

        return $prefix . self::of($order, 'id');
    }

    /** @param array<string, mixed>|ArrayAccess<string, mixed> $order */
    private static function isAPosWalletSale(array|ArrayAccess $order): bool
    {
        return self::of($order, 'order_type') === self::POS && self::of($order, 'payment_method') === self::WALLET;
    }

    /** @param array<string, mixed>|ArrayAccess<string, mixed> $order */
    private static function of(array|ArrayAccess $order, string $key): mixed
    {
        return $order[$key] ?? null;
    }
}
