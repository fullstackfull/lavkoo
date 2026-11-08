<?php

namespace App\Enums\MyFatoorah;

/**
 * The life of one MyFatoorah payment attempt as THIS application sees it.
 *
 * Deliberately not the same vocabulary as MyFatoorah's own invoice/transaction statuses: those describe
 * what happened at the gateway, these describe what we are allowed to do next. The difference matters
 * for `mismatch`, which has no MyFatoorah equivalent — the gateway says paid, but not the amount or the
 * currency we asked for, so the money is real and the order must NOT be released.
 */
enum AttemptStatus: string
{
    /** Row written, nothing sent to MyFatoorah yet. */
    case Created = 'created';

    /** MyFatoorah returned a payment URL; the customer is at the gateway. Nothing is owed yet. */
    case Redirected = 'redirected';

    /** The gateway has the payment but has not finished with it (3-D Secure, wallet hand-off, bank delay). */
    case Pending = 'pending';

    /** Verified server-to-server: paid, for the amount and currency we asked for. The only status that may release an order. */
    case Paid = 'paid';

    /** The gateway finished and the money did not move. */
    case Failed = 'failed';

    /** The customer walked away from the gateway. */
    case Cancelled = 'cancelled';

    /** The invoice can no longer be paid. */
    case Expired = 'expired';

    /**
     * Paid at the gateway, but the amount, the currency or the reference does not match this attempt.
     * Needs a human: the money exists, so it can be neither ignored nor spent.
     */
    case Mismatch = 'mismatch';

    /** Nothing further will happen to this attempt on its own. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Paid, self::Failed, self::Cancelled, self::Expired, self::Mismatch], true);
    }

    /** Should reconciliation keep asking MyFatoorah about this attempt? */
    public function isInFlight(): bool
    {
        return in_array($this, [self::Created, self::Redirected, self::Pending], true);
    }

    /**
     * Statuses a confirmed payment may NOT be moved away from. A late failure notice for an attempt we
     * have already verified as paid is a stale notice, not a reversal.
     */
    public function isLockedAgainstDowngrade(): bool
    {
        return $this === self::Paid;
    }
}
