<?php

namespace App\Services\Checkout;

/**
 * Why the order writer refused the order it was just asked for, carried back to the surface that asked (I1-14).
 *
 * `OrderManager::generateOrder()` answers a refusal with an EMPTY LIST of order ids, and every one of its callers reads that one answer
 * as one thing: "an item in your cart just went out of stock". That was true while stock was the only thing the transaction could refuse
 * over. It is not true once a coupon's cap or budget is enforced inside the transaction, and a customer whose coupon is exhausted being
 * sent to go and look for the item that ran out is the kind of message that costs a support ticket and a lost sale.
 *
 * The return value is NOT changed, deliberately. Six surfaces guard on `empty($orderIds)` and a seventh (the payment settlement path)
 * reads it as a list; returning a refusal OBJECT instead would make `empty()` false at every one of them, and a caller that was missed
 * would treat a refused order as a placed one -- the worst failure this change could have. So the ids stay a list, an empty list still
 * means refused, and the REASON travels beside it. A surface that has not been taught the new vocabulary keeps printing exactly what it
 * prints today.
 *
 * Static, and cleared at the top of every `generateOrder()` call, because it is state of ONE call: a console command or a queue worker
 * that places two orders in one process must not be told the first one's reason about the second. The clearing is what makes that true,
 * not the lifetime of a request.
 */
final class OrderRefused
{
    /** The translation key of the rule that refused the order, or null when the last order writer's call refused nothing. */
    private static ?string $reason = null;

    /** Forgotten at the start of every order, so a reason can never outlive the call that set it. */
    public static function forget(): void
    {
        self::$reason = null;
    }

    public static function because(string $reasonKey): void
    {
        self::$reason = $reasonKey;
    }

    /** The translation key of the refusal, or null when the refusal was one this does not name (stock, which every caller already words). */
    public static function reason(): ?string
    {
        return self::$reason;
    }

    /**
     * What the customer is told: the rule that refused, and that their cart is untouched.
     *
     * Null when no reason was carried, which is the signal for a caller to print the sentence it has always printed. The two halves are
     * separate keys because the second is the sentence the website already appends to its stock refusal, and a customer reading "the
     * coupon is spent" needs to be told in the same breath that nothing was taken from them.
     */
    public static function message(): ?string
    {
        return self::$reason === null ? null : translate(self::$reason) . ' ' . translate('your_cart_has_not_been_changed');
    }
}
