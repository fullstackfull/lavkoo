<?php

namespace Tests\Support\Concurrency;

use App\Models\Product;
use App\Models\User;
use App\Services\Checkout\OrderRefused;
use App\Services\Coupon\CouponAllowance;
use App\Utils\OrderManager;
use Illuminate\Support\Facades\DB;

/**
 * What a worker process does in the coupon race: one cash-on-delivery checkout that takes a coupon, through the real order writer.
 *
 * Beside {@see CheckoutOperations} rather than inside it, because the two drive DIFFERENT worlds. That one stubs the tax set-up into the
 * cache (`useTaxIncludedPrices`), which is what its own test builds; the coupon world builds the tax set-up in the database and the test
 * process reads it from there, so a worker that stubbed it would be pricing a cart its test never priced.
 */
final class CouponOperations
{
    /**
     * @param array{customer: int, coupon: string, address?: int|null, mark?: string, wait_for?: string} $args
     * @return array{orders: list<int>, refusal: ?string} the ids of the orders written -- empty when the writer refused the order -- and
     *                                                    the rule that refused it, which is what the surfaces word for the customer
     */
    public static function placeAnOrderTakingACoupon(array $args): array
    {
        // Configuration is per PROCESS: the test's own `config()` call does not reach a worker, and the ledger and its limits ship off.
        config(['gcc.coupons.limits.enabled' => true, 'gcc.coupons.limits.per_store' => false]);
        CouponAllowance::forget();

        self::holdInsideTheOrderTransaction($args);

        $customer = User::findOrFail($args['customer']);
        request()->merge(['user' => $customer]);
        request()->setUserResolver(static fn (): User => $customer);

        $orders = OrderManager::generateOrder(data: [
            'is_guest' => 0,
            'customer_id' => $customer->id,
            'order_status' => 'pending',
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'unpaid',
            'transaction_ref' => '',
            'coupon_code' => (string) $args['coupon'],
            'requestObj' => request(),
            'address_id' => $args['address'] ?? null,
        ]);

        return ['orders' => array_values($orders), 'refusal' => OrderRefused::reason()];
    }

    /**
     * Records one redemption of a coupon and commits it, from this process's own connection.
     *
     * For the test that measures what a transaction elsewhere can still SEE: a row another connection has committed is the thing a plain
     * count inside an open transaction may miss, and only a second connection can commit one.
     *
     * @param array{coupon: string, group: string, discount: float|int, customer?: int} $args
     * @return array{recorded: string}
     */
    public static function recordOneRedemption(array $args): array
    {
        $coupon = DB::table('coupons')->where('code', (string) $args['coupon'])->first(['id']);

        DB::table('coupon_redemptions')->insert([
            'coupon_id' => $coupon->id,
            'coupon_code' => (string) $args['coupon'],
            'customer_id' => $args['customer'] ?? null,
            'order_group_id' => (string) $args['group'],
            'discount' => $args['discount'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['recorded' => (string) $args['group']];
    }

    /**
     * Holds this process INSIDE its order transaction, at the moment the order writer locks the products -- which is immediately after the
     * eligibility quote and immediately before the coupon gate.
     *
     * That is the interleaving the gate exists for, and it has to be arranged rather than hoped for: off the common start barrier alone the
     * faster process commits its redemption before the slower one QUOTES, and then the slower one is refused by the eligibility check,
     * which is the read that was never atomic and proves nothing about this. With the hold, both processes reach the gate with both
     * transactions open and neither committed.
     *
     * Only for a run whose carts hold DIFFERENT products: two carts holding one product contend on the product lock, so the held process
     * would be waiting for a mark the other cannot reach until the first lets its own locks go.
     */
    private static function holdInsideTheOrderTransaction(array $args): void
    {
        if (!isset($args['mark'], $args['wait_for'])) {
            return;
        }

        $met = false;

        Product::retrieved(static function () use (&$met, $args): void {
            if ($met || DB::transactionLevel() === 0) {
                return;
            }

            $met = true;
            Rendezvous::mark((string) $args['mark']);
            Rendezvous::waitFor((string) $args['wait_for']);
        });
    }
}
