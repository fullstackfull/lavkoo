<?php

namespace App\Services\Coupon;

use Illuminate\Support\Facades\DB;

/**
 * The coupon's limits judged where they can actually be enforced: inside the order's transaction, under a lock on the coupon's own row
 * (I1-14, the owner's decision of 2026-11-26).
 *
 * `CouponAllowance` answers the same three questions at eligibility time -- the apply route and the checkout's own pricing -- and that is
 * where a customer gets a friendly refusal before any money is computed. But that check is a READ: two checkouts that both read the last
 * remaining use before either records a redemption are both granted the discount, and a coupon capped at one redemption pays twice. The
 * ledger's unique key makes the RECORDED count exact; it does not make the GRANT atomic.
 *
 * This is what makes it atomic, and there are two locks in it rather than one, because one is not enough:
 *
 *  - **the coupon's row**, taken `FOR UPDATE` before anything is counted. It is the one row every checkout of that coupon must pass, so
 *    it serialises them: the second checkout waits here until the first has committed its order AND its ledger row, and then counts a
 *    world that includes it.
 *  - **the ledger rows of that coupon**, also read `FOR UPDATE`. InnoDB's REPEATABLE READ answers a plain read from the snapshot taken at
 *    the transaction's FIRST consistent read, so a count taken late in a long transaction can describe a world from before another
 *    checkout committed, and a cap judged on a stale count is no cap. A locking read is served the latest committed version instead, and
 *    its next-key lock also stops a redemption being inserted into the range behind it.
 *
 * **What each lock is worth, measured rather than argued** (MariaDB 10.11.14, the race file below):
 *
 *  - without the coupon row's lock the money still comes out right, by accident: the ledger's own locking read takes a gap lock, the two
 *    redemptions then collide as insert intentions, InnoDB kills one transaction and `DB::transaction(attempts: 3)` re-runs the closure,
 *    whose second attempt reads the committed redemption and refuses. 2 deadlocks per race against 0 with the lock. So the row lock buys a
 *    WAIT instead of a deadlock and a retry of everything the transaction had done, and the race file asserts the deadlock counter for
 *    exactly that reason.
 *  - without the ledger's locking reads the races stay green TOO, because nothing in today's order transaction reads before this gate, so
 *    there is no snapshot yet and the gate's own first read makes one. That is one ordinary refactor away from being false, which is why
 *    the two read modes have a test of their own that reads first and then compares them.
 *
 * Both are no-ops on SQLite, whose grammar compiles no lock clause, which is exactly why the proof of this is a two-process test against
 * MariaDB ({@see \Tests\Feature\Concurrency\CouponGrantConcurrencyTest}) and not a sequential one.
 *
 * Silent while `gcc.coupons.limits.enabled` is off, which is how the platform ships: no lock is taken, nothing is counted, and an order
 * carrying a coupon is written exactly as it is today.
 */
final class CouponGrant
{
    /** The customer has redeemed this coupon as many times as their own limit allows. */
    public const CUSTOMER_LIMIT_REACHED = 'sorry_this_coupon_is_not_valid_for_this_user';

    /**
     * Why the coupon this order is being written with may not be redeemed again, as a translation key, or null when it may.
     *
     * The discount judged is the WHOLE ORDER GROUP's, summed over the vendor groups that carry the code, because that is what the ledger
     * records for the group: a checkout of two vendors is one redemption of the sum, not two redemptions of a half.
     *
     * @param array<array-key, array<string, mixed>> $vendorWiseCartList the groups about to be written, as `getVendorWiseCartList` built them
     */
    public static function refusalForTheCart(array $vendorWiseCartList, int|string|null $customerId): ?string
    {
        if (!CouponAllowance::isOn() || !CouponRedemption::isReady()) {
            return null;
        }

        ['code' => $code, 'discount' => $discount] = self::couponOfTheCart($vendorWiseCartList);

        if ($code === null || $discount <= 0) {
            return null;
        }

        // The coupon's row first and the ledger second, in that order, in every transaction that reaches here. Two checkouts taking the
        // same two locks in the same order cannot wait on each other, which is the same discipline the customer row and the product rows
        // already follow in `generateOrder`.
        $coupon = DB::table('coupons')->where('code', $code)->lockForUpdate()->first();

        if ($coupon === null) {
            return null;
        }

        return CouponAllowance::refusalFor(coupon: $coupon, discount: $discount, locked: true)
            ?? self::theCustomersOwnLimit(coupon: $coupon, customerId: $customerId);
    }

    /**
     * The code the order group is about to carry and the discount it is about to be given.
     *
     * A group whose coupon was refused earlier in the request carries the code `0` and a discount of 0 (`getGroupWiseCouponArray`), so
     * such a group contributes nothing here -- which is why the code is taken from the groups rather than from the request.
     *
     * Public because a refusal has to be able to NAME the coupon it refused: the order writer puts the code and the discount into the
     * exception and the log, and an operator asking which campaign ran out has nothing else to go on -- the order the figures belong to was
     * rolled back.
     *
     * @param array<array-key, array<string, mixed>> $vendorWiseCartList
     * @return array{code: ?string, discount: float}
     */
    public static function couponOfTheCart(array $vendorWiseCartList): array
    {
        $code = null;
        $discount = 0.0;

        foreach ($vendorWiseCartList as $group) {
            $groupCode = $group['coupon_code'] ?? null;

            if (!is_string($groupCode) || trim($groupCode) === '' || $groupCode === '0') {
                continue;
            }

            $code ??= $groupCode;

            if ($groupCode === $code) {
                $discount += (float) ($group['coupon_discount'] ?? 0);
            }
        }

        return ['code' => $code, 'discount' => $discount];
    }

    /**
     * The per-customer limit, which is what `coupons.limit` has always been, asked again under the lock.
     *
     * Mirrors the eligibility condition rather than improving on it: `limit > uses` is what lets a coupon through there, so `limit <= uses`
     * is what refuses it here, and a `first_order` coupon is not asked at all -- its rule is whether the customer has ordered before, which
     * is a question about the orders table and not about this ledger.
     */
    private static function theCustomersOwnLimit(object $coupon, int|string|null $customerId): ?string
    {
        if (($coupon->coupon_type ?? null) === 'first_order') {
            return null;
        }

        $uses = CouponRedemption::usesByCustomer(
            customerId: $customerId,
            couponCode: $coupon->code ?? null,
            market: CouponAllowance::marketOfTheCount(),
            locked: true,
        );

        if ($uses === null) {
            return null;
        }

        return (float) ($coupon->limit ?? 0) > $uses ? null : self::CUSTOMER_LIMIT_REACHED;
    }
}
