<?php

namespace App\Services\Coupon;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger a coupon's limits, total cap and budget are counted from: one row per coupon and order group (I1-14).
 *
 * Until now a redemption was a COUNT rather than a record: `OrderManager::countCouponUses` reads the orders carrying the code and groups
 * them by `order_group_id`. That answers the per-customer limit and nothing else. It has no market, so a limit cannot be counted per store
 * (Q-04) without re-reading every order's storefront; it has no amount, so the discount a coupon has actually given away is written nowhere;
 * and it is a count over a table two checkouts both write to, so there is nothing a cap can contend on.
 *
 * Written from inside the order transaction, beside the storefront snapshot and the shipping decision, and written ONCE PER ORDER GROUP:
 * the unique key on (coupon_id, order_group_id) is what makes a replayed checkout write nothing further. The discount is re-summed from the
 * group's own order rows on each call rather than added to, because a group of two vendors reaches this twice and a row that accumulated
 * would double the spend of every multi-vendor order.
 *
 * Silent before its migration runs and silent while `gcc.coupons.limits.enabled` is off, which is how the platform ships: nothing is read,
 * nothing is written, and every coupon behaves exactly as it does today.
 */
final class CouponRedemption
{
    public const TABLE = 'coupon_redemptions';

    /** What `orders.discount_type` says when `orders.discount_amount` is a coupon's discount and not another kind. */
    private const COUPON_DISCOUNT = 'coupon_discount';

    /** @var bool|null whether the ledger table exists; not re-read for every order of a batch */
    private static ?bool $tableReady = null;

    /** Whether the ledger is in use at all: the switch is on and the table is there. */
    public static function isReady(): bool
    {
        return (bool) config('gcc.coupons.limits.enabled') && self::tableReady();
    }

    /**
     * Records the redemption the order belongs to, or updates the discount of the one already recorded for its group.
     *
     * Reads everything off the order row it was just written from, which is the one source that cannot disagree with the order: the code
     * the order carries, the market it was sold in and the money it was priced in. The coupon's id comes from the code, through the plain
     * query builder rather than the model -- a LEDGER narrowed by whichever global scopes the Coupon model carries would count a different
     * number for a restricted employee than for the customer, which is the wrong direction for a rule.
     */
    public static function record(int|string|null $orderId): void
    {
        if ($orderId === null || !self::isReady()) {
            return;
        }

        $order = DB::table('orders')->where('id', $orderId)->first(self::orderColumns());

        if ($order === null || !is_string($order->coupon_code ?? null) || trim((string) $order->coupon_code) === '' || (string) $order->coupon_code === '0') {
            return;
        }

        $groupId = (string) ($order->order_group_id ?? '');
        $coupon = DB::table('coupons')->where('code', $order->coupon_code)->first(['id']);

        if ($groupId === '' || $coupon === null) {
            return;
        }

        DB::table(self::TABLE)->updateOrInsert(
            ['coupon_id' => $coupon->id, 'order_group_id' => $groupId],
            [
                'coupon_code' => (string) $order->coupon_code,
                'customer_id' => $order->customer_id ?? null,
                'storefront_country' => self::marketOf($order),
                'discount' => self::discountOfTheGroup($groupId, (string) $order->coupon_code),
                'currency_code' => self::currencyOf($order),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    /**
     * How many times this customer has redeemed this code: within one market when the counting is per store (Q-04), over every market
     * otherwise. Null while the ledger is not in use, which tells the caller to count orders as it always has.
     *
     * `$locked` is for the one caller that asks inside the order's transaction ({@see CouponGrant}); see `spent()` for why a plain read
     * there would answer from a snapshot taken before the other checkout committed.
     */
    public static function usesByCustomer(int|string|null $customerId, mixed $couponCode, ?string $market = null, bool $locked = false): ?int
    {
        if (!self::isReady() || $customerId === null || !is_string($couponCode) || $couponCode === '') {
            return null;
        }

        $uses = DB::table(self::TABLE)->where('customer_id', $customerId)->where('coupon_code', $couponCode);

        if ($market !== null) {
            $uses->where('storefront_country', $market);
        }

        return (int) ($locked ? $uses->lockForUpdate()->count() : $uses->count());
    }

    /** How many times this coupon has been redeemed in total, by anybody, in any market. */
    public static function totalUses(int|string|null $couponId, bool $locked = false): int
    {
        if ($couponId === null || !self::isReady()) {
            return 0;
        }

        $uses = DB::table(self::TABLE)->where('coupon_id', $couponId);

        return (int) ($locked ? $uses->lockForUpdate()->count() : $uses->count());
    }

    /**
     * The discount this coupon has given away, as an exact decimal string.
     *
     * A string and not a float, because it is compared with a budget and summed over rows of `decimal(18,12)`: the point of storing twelve
     * decimal places is lost the moment the total goes through a float. The sum is over the rows of ONE coupon, which belongs to one
     * storefront and so is in one currency ({@see \App\Services\Storefront\CouponCountry}) -- a sum across coupons would add dinars to
     * riyals, which is why there is no method for one.
     *
     * **Its SPELLING is the engine's, not this method's**, and a caller comparing it as text will disagree with itself per server: MariaDB
     * answers a SUM over `decimal(18,12)` as `5.000000000000` and SQLite as `5`. Measured, on a test that asserted the text and passed on
     * SQLite. Compare it as a number.
     *
     * **`$locked` is not an optimisation and it is not caution.** Inside a transaction, InnoDB at REPEATABLE READ answers a plain read from
     * the snapshot taken at that transaction's FIRST read, so a sum taken after another checkout committed its redemption can still be the
     * sum from before it -- the budget would be judged against a world that no longer exists. A locking read sees the latest committed
     * version and holds the range against an insert behind it. SQLite compiles no lock clause, so this changes nothing there.
     */
    public static function spent(int|string|null $couponId, bool $locked = false): string
    {
        if ($couponId === null || !self::isReady()) {
            return '0';
        }

        $rows = DB::table(self::TABLE)->where('coupon_id', $couponId);
        $spent = $locked ? $rows->lockForUpdate()->sum('discount') : $rows->sum('discount');

        return is_string($spent) ? $spent : (string) (float) $spent;
    }

    /** Forgets what was learnt about the schema, for a test that builds its own tables. */
    public static function forget(): void
    {
        self::$tableReady = null;
    }

    /**
     * The discount the whole order group was given, re-summed from the group's own rows.
     *
     * `orders.discount_amount` is the column, narrowed by `discount_type` -- the order has ONE discount column and it holds whichever
     * discount the group was given, with the type saying which (`OrderManager` writes `coupon_discount` only when a coupon gave it). A sum
     * that ignored the type would count a free-shipping discount as a coupon's spend.
     */
    private static function discountOfTheGroup(string $groupId, string $couponCode): string
    {
        $sum = DB::table('orders')
            ->where('order_group_id', $groupId)
            ->where('coupon_code', $couponCode)
            ->where('discount_type', self::COUPON_DISCOUNT)
            ->sum('discount_amount');

        return is_string($sum) ? $sum : (string) (float) $sum;
    }

    /** @return list<string> the order columns to read, leaving out the ones a store whose migrations have not run does not have */
    private static function orderColumns(): array
    {
        $columns = ['id', 'order_group_id', 'coupon_code', 'customer_id'];

        foreach (['storefront_country', 'currency_code'] as $optional) {
            if (Schema::hasColumn('orders', $optional)) {
                $columns[] = $optional;
            }
        }

        return $columns;
    }

    private static function marketOf(object $order): ?string
    {
        $market = $order->storefront_country ?? null;

        return is_string($market) && trim($market) !== '' ? strtoupper(trim($market)) : null;
    }

    private static function currencyOf(object $order): ?string
    {
        $currency = $order->currency_code ?? null;

        return is_string($currency) && trim($currency) !== '' ? strtoupper(trim($currency)) : null;
    }

    private static function tableReady(): bool
    {
        return self::$tableReady ??= Schema::hasTable(self::TABLE);
    }
}
