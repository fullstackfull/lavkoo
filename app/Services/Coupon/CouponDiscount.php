<?php

namespace App\Services\Coupon;

/**
 * What a coupon takes off the lines it applies to, and whether that is a figure of money or a share of them (the owner's decision of
 * 2026-11-26).
 *
 * `coupons.discount` holds two different kinds of number, with `coupons.discount_type` saying which: an AMOUNT of money, or a figure of
 * PER CENT. `OrderManager::getTotalCouponAmount` refused a coupon whose `discount` was larger than the eligible total -- a rule that is
 * right for the first kind and nonsense for the second, because 31 there means 31 per cent and it was being compared with an amount of
 * money. A 31% coupon was refused on a cart of 30 and applied to a cart of 100, and the customer was told the minimum purchase had not
 * been reached, which was neither the rule that refused them nor true of their cart.
 *
 * **Where the rounding is, which is the question three of the six markets turn on.** Nowhere. KWD, BHD and OMR carry three decimals and
 * SAR and AED two ({@see \App\Services\Storefront\StorefrontCurrency::decimalsOf}, `gcc.storefronts.currency.decimals`), and the
 * temptation on a money calculation is to round it to the currency's own precision. This does not, for two reasons that both point the
 * same way:
 *
 *  - the share is computed in the unit the CART is priced in, which on a platform that converts from a base currency is not the
 *    storefront's currency at all. Rounding it to the storefront's decimals would be rounding the wrong number, and rounding it to two
 *    would silently drop the third decimal of a dinar -- the exact defect C-15 spent a migration correcting in fulfilment.
 *  - rounding in either direction moves money across a limit. Rounded UP, a discount can pass a budget it does not fit; rounded DOWN, it
 *    can fit a budget it has overshot, and the figure the customer was quoted stops being the figure they are charged. The eligible
 *    total, the minimum purchase, the maximum discount and the budget are all compared against the EXACT share, and `orders.discount_amount`
 *    and `coupon_redemptions.discount` both store more decimals than any currency has, so nothing truncates it on the way to the record
 *    either.
 *
 * That is also what keeps Saudi Arabia byte for byte where it is: a riyal cart's percentage discount is the same unrounded quotient it has
 * always been.
 */
final class CouponDiscount
{
    /**
     * Whether a percentage coupon is judged by the discount it would give rather than by its figure of per cent.
     *
     * OFF, which is how the platform ships: the owner has approved the correction and not its release, and switching it on changes what a
     * customer is charged on every cart worth less than a percentage coupon's figure. See `config/gcc.php`.
     */
    public static function isJudgedByItsShare(): bool
    {
        return (bool) config('gcc.coupons.percentage_of_the_eligible_total');
    }

    /**
     * The discount this coupon gives on these eligible lines: its share of them when it is a percentage, capped by its maximum discount,
     * and its own figure when it is an amount of money.
     *
     * The arithmetic is the arithmetic that was already here, moved so that the answer exists BEFORE the limits are judged rather than
     * after. Nothing is cast: a decimal column read off the model is handed back as the model gave it, so a coupon at its cap answers the
     * same value to the apply route as it did before this method existed.
     *
     * `$maximumDiscount` admits null, and answers null for it, because that is what this line did before it was a method: `coupons.max_discount`
     * is NOT NULL on a migrated store, an older one may still allow it, and `$share > null` was true there -- so the coupon's discount became
     * null and the apply route answered "coupon not valid". A typed parameter that refused null would have turned that into a TypeError on a
     * money path, which is the trap this repository has already paid for once inside `generateOrder`.
     */
    public static function of(float|int|string $eligibleTotal, ?string $discountType, float|int|string $figure, float|int|string|null $maximumDiscount): float|int|string|null
    {
        if ($discountType !== 'percentage') {
            return $figure;
        }

        $share = ($eligibleTotal * $figure) / 100;

        return $share > $maximumDiscount ? $maximumDiscount : $share;
    }
}
