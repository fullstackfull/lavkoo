<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the coupon the order is being written with has reached a limit of its own by the time the order is actually written (I1-14).
 *
 * The coupon's cap, budget and per-customer limit are judged at eligibility time as well, where they produce a friendly refusal before
 * any money is computed. That check is a READ, and two checkouts both pass a read before either records a redemption. This exception is
 * what turns that race into a refused order instead of two discounts that together exceed the limit: it is raised inside the order
 * transaction, under a lock on the coupon's own row, so everything written for that order rolls back with it.
 *
 * It carries the translation key of the rule that refused, so the customer is told their coupon is spent rather than that something in
 * their cart went out of stock. {@see \App\Services\Checkout\OrderRefused} is how that key reaches the surface.
 */
class CouponNoLongerAvailableException extends RuntimeException
{
    public function __construct(
        /** The translation key of the rule that refused: a cap, a budget or the customer's own limit. */
        public readonly string $reason,
        public readonly ?string $couponCode = null,
        public readonly float $discount = 0.0,
    ) {
        parent::__construct(sprintf(
            'The coupon %s may not be redeemed again (%s); a discount of %s was refused.',
            $couponCode ?: '(none)',
            $reason,
            (string) $discount,
        ));
    }
}
