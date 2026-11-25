<?php

namespace App\Services\Loyalty;

/**
 * What happens when a refund takes back more loyalty points than the customer still holds (E-14, the criterion that names its
 * own three answers: "refuse, clamp or allow and report: owner choice").
 *
 * The platform allows it, and the balance goes NEGATIVE. Both writers of a point balance refuse a debit above the balance for
 * `point_to_wallet` -- the customer cannot convert points they have not got -- and neither checks a `refund_order` debit at
 * all. Pinned since this work began, in
 * `tests/Feature/Loyalty/LoyaltyPointCharacterizationTest::test_current_behaviour_a_refund_can_take_the_points_below_zero`:
 * a customer holding 10 points whose order is refunded for 30 ends on **-20**, and the next order they place starts by
 * paying that off.
 *
 * It is a reachable state and not a theoretical one. A customer earns points on an order, converts them, and the order is
 * then refunded: the points are gone and the refund takes them anyway. Whether that is right is a decision about what the
 * platform is owed by a customer who has already spent what it is reclaiming, which is why the criterion was written with
 * three answers in it and why this class does not pick one:
 *
 *  - {@see ALLOW_AS_TODAY} -- **the default, and what ships**: the debit is written in full and the balance goes negative,
 *    exactly as now, to the point.
 *  - {@see REFUSE} -- the debit is refused and nothing is written, which is the shape a conversion already has. The refund's
 *    money is unaffected: only the points row is refused.
 *  - {@see CLAMP_TO_ZERO} -- the refund takes what is there and no more; the balance lands on zero and the row records the
 *    debit that was actually taken, so the ledger still adds up to the balance.
 *
 * Read `docs/gcc/OPEN-FINANCIAL-DECISIONS.md` section 5 before switching it. An unrecognised value is read as today's
 * behaviour, because a typo must not move a customer's points.
 */
final class WhenARefundWouldTakePointsBelowZero
{
    /** The balance goes negative: the platform exactly as it runs today. */
    public const ALLOW_AS_TODAY = 'allow_as_today';

    /** The points row is refused and nothing is written. */
    public const REFUSE = 'refuse';

    /** The refund takes what is there; the balance lands on zero. */
    public const CLAMP_TO_ZERO = 'clamp_to_zero';

    /** What the configuration says, falling back to today's behaviour for anything it does not recognise. */
    public static function rule(): string
    {
        $named = (string) config('gcc.loyalty.when_a_refund_would_take_points_below_zero');

        return in_array($named, [self::REFUSE, self::CLAMP_TO_ZERO], true) ? $named : self::ALLOW_AS_TODAY;
    }

    /**
     * The debit this refund may take, or NULL when it may take none and the row must not be written.
     *
     * Asked inside the customer's balance lock, with the balance that lock read, so two refunds of one customer cannot both
     * clamp against the same figure.
     *
     * @param float $held the points on the account now
     * @param float $debit the points the refund wants back
     */
    public static function debitFor(float $held, float $debit): ?float
    {
        if ($debit <= 0.0) {
            return $debit;
        }

        return match (self::rule()) {
            self::REFUSE => $held - $debit < 0.0 ? null : $debit,
            self::CLAMP_TO_ZERO => $held > 0.0 ? min($debit, $held) : null,
            default => $debit,
        };
    }
}
