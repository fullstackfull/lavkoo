<?php

namespace App\Services\Coupon;

use App\Services\Storefront\CouponCountry;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Facades\Schema;

/**
 * What is left of a coupon: its total cap, its spending budget, and whose orders its per-customer limit counts (I1-14).
 *
 * `coupons.limit` is the PER-CUSTOMER limit and always has been. Nothing caps how many times a coupon may be redeemed in total, or how much
 * discount it may give away, so a code that leaks costs whatever the campaign's traffic decides. And the per-customer limit is counted over
 * EVERY order of the customer, in every market, which is one of the two readings Q-04 names -- the other is per store, and with six markets
 * they are different coupons.
 *
 * Three answers, and each ships as today's behaviour:
 *
 *  - `gcc.coupons.limits.enabled` OFF: nothing here is read at all. No cap, no budget, no ledger, and the limit is counted exactly as
 *    `OrderManager::countCouponUses` has always counted it.
 *  - a coupon with no `total_uses_limit` and no `budget` is uncapped and unbudgeted, which is every coupon that exists: an administrator
 *    who has typed no cap has capped nothing, and a zero would mean the opposite.
 *  - `gcc.coupons.limits.per_store` OFF: the limit and the first-order rule count every order of the customer, as today. Whether they
 *    should count within one market is the owner's question Q-04, so the mechanism is here and the default is unchanged.
 *
 * Where this is read, and where the answer is BINDING. This class is asked at eligibility time -- the apply route and the checkout's own
 * pricing, before the order's money is computed -- and that is a READ, so two checkouts that both pass it before either records a
 * redemption both get the discount. That hole is closed, by the owner's decision of 2026-11-26, in {@see CouponGrant}: the same three
 * questions are asked again INSIDE the order's transaction, under a lock on the coupon's row, and a refusal there rolls the order back and
 * is carried to the customer as its own sentence ({@see \App\Services\Checkout\OrderRefused}). What is read here stays the early, friendly
 * check; the atomic one is the authority.
 */
final class CouponAllowance
{
    /** The coupon has been redeemed as many times as it was allowed to be. */
    public const CAP_REACHED = 'this_coupon_has_been_fully_redeemed';

    /** The discounts this coupon has given away have reached the budget it was given. */
    public const BUDGET_SPENT = 'this_coupons_discount_budget_has_been_used_up';

    /** @var bool|null whether the cap and budget columns exist; not re-read per coupon of a page */
    private static ?bool $columnsReady = null;

    public static function isOn(): bool
    {
        return (bool) config('gcc.coupons.limits.enabled');
    }

    /** Whether the per-customer limit and the first-order rule are counted within one market rather than over every market (Q-04). */
    public static function countedPerStore(): bool
    {
        return self::isOn() && (bool) config('gcc.coupons.limits.per_store');
    }

    /** The market a limit counted per store counts within: the one the request is being served from, and null when it is counted over all of them. */
    public static function marketOfTheCount(): ?string
    {
        return self::countedPerStore() ? StorefrontCatalog::country() : null;
    }

    /**
     * Why this coupon may not be redeemed again, as a translation key, or null when it may.
     *
     * The discount about to be given is part of the question for the budget and not for the cap: a cap counts redemptions and a budget
     * counts money, so a coupon with 3 left of its budget refuses a 5 discount and would have allowed a 2. The amount is optional because
     * the coupon lists ask this question with no basket in front of them, and there the budget's own total is what decides.
     *
     * `$locked` is passed by the one caller that asks inside the order's transaction, under a lock on the coupon's row
     * ({@see CouponGrant}): it makes the ledger reads locking ones, which is what stops them answering from a snapshot taken before
     * another checkout committed its redemption. Every other caller asks outside a transaction and wants the plain read.
     */
    public static function refusalFor(mixed $coupon, float|string|null $discount = null, bool $locked = false): ?string
    {
        if (!self::isOn() || !self::columnsReady() || !CouponRedemption::isReady()) {
            return null;
        }

        $couponId = data_get($coupon, 'id');

        if ($couponId === null) {
            return null;
        }

        $cap = data_get($coupon, 'total_uses_limit');

        if (is_numeric($cap) && (int) $cap > 0 && CouponRedemption::totalUses($couponId, locked: $locked) >= (int) $cap) {
            return self::CAP_REACHED;
        }

        $budget = data_get($coupon, 'budget');

        if (is_numeric($budget) && (float) $budget > 0) {
            $spent = (float) CouponRedemption::spent($couponId, locked: $locked);

            if ($spent + (float) ($discount ?? 0) > (float) $budget) {
                return self::BUDGET_SPENT;
            }
        }

        return null;
    }

    /**
     * What this coupon has given away and what it was allowed to give away, for the administrator's screen, each written in the money of
     * the storefront the coupon belongs to -- the money its own amounts were typed in ({@see CouponCountry}).
     *
     * Null for a coupon with no budget, because a spend figure beside no budget is a number with nothing to read it against, and the screen
     * has the discount column for what a coupon costs per order.
     *
     * @return array{spent: string, budget: string, uses: int, cap: ?int}|null
     */
    public static function spendOf(mixed $coupon): ?array
    {
        if (!self::isOn() || !self::columnsReady() || !CouponRedemption::isReady()) {
            return null;
        }

        $budget = data_get($coupon, 'budget');

        if (!is_numeric($budget) || (float) $budget <= 0) {
            return null;
        }

        $couponId = data_get($coupon, 'id');
        $cap = data_get($coupon, 'total_uses_limit');

        return CouponCountry::inMoneyOf($coupon, static fn (): array => [
            'spent' => setCurrencySymbol(amount: usdToDefaultCurrency(amount: (float) CouponRedemption::spent($couponId)), currencyCode: getCurrencyCode()),
            'budget' => setCurrencySymbol(amount: usdToDefaultCurrency(amount: (float) $budget), currencyCode: getCurrencyCode()),
            'uses' => CouponRedemption::totalUses($couponId),
            'cap' => is_numeric($cap) && (int) $cap > 0 ? (int) $cap : null,
        ]);
    }

    /** Forgets what was learnt about the schema, for a test that builds its own tables. */
    public static function forget(): void
    {
        self::$columnsReady = null;
        CouponRedemption::forget();
    }

    private static function columnsReady(): bool
    {
        return self::$columnsReady ??= Schema::hasColumn('coupons', 'total_uses_limit') && Schema::hasColumn('coupons', 'budget');
    }
}
