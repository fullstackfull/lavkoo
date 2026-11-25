<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Whether the orders a paid callback built add up to the money the gateway actually took (D2, "a settlement refuses or flags
 * an order total that differs from the amount paid").
 *
 * Nothing compared them. The settlement reads a payment, builds the orders of the cart as it finds it NOW, and marks them
 * paid -- so a basket that moved between the payment starting and the callback arriving, a price or a coupon that changed in
 * between, or a conversion that answers a different figure on the way back than it did on the way out, all produce an order
 * whose total is not what the customer was charged, and the only record either way says `payment_status = paid`. The figures
 * that were reproduced when this was recorded are 275 paid against a 375 order.
 *
 * **It reports and never refuses.** The gateway has taken the money by the time this runs. Refusing the order would leave the
 * customer paid with nothing to show for it, which is strictly worse than a recorded discrepancy a person can reconcile
 * against a real payment -- the same judgement the settlement already makes about stock (`stock_policy` allow_oversell). So
 * this writes one critical line naming the payment, both figures, the currency and the orders, and returns. It cannot throw:
 * a check that breaks a settlement is worse than no check.
 *
 * **The comparison mirrors the charge rather than inventing one.** Under the single-currency model the payment was started
 * with the stored total and no conversion (`PaymentController::getCustomerPaymentRequest`), so the two figures are compared
 * directly. Under `multi_currency` the charge was `usdToAnotherCurrencyConverter()` of that total, so the sum is put through
 * the SAME function before it is compared; anything else would flag every legitimate order on a multi-currency store. The
 * tolerance is one minor unit of the currency in force, because that function rounds to `decimal_point_settings`.
 *
 * The orders are summed with the QUERY BUILDER and never the model: `App\Models\Order` carries `StaffCountryOrderScope`, and a
 * sum that sees fewer orders than the payment built would report a discrepancy that is not there.
 */
final class WhatWasPaidMatchesTheOrder
{
    /**
     * @param list<int|string> $orderIds the orders this settlement built
     */
    public function report(mixed $paymentId, mixed $paymentAmount, ?string $currencyCode, array $orderIds): void
    {
        try {
            $this->compare(paymentId: $paymentId, paymentAmount: $paymentAmount, currencyCode: $currencyCode, orderIds: $orderIds);
        } catch (Throwable $failure) {
            // The money has moved and the orders exist. Nothing this check can discover is worth undoing that.
            report($failure);
        }
    }

    /** The money the orders add up to, in the currency the gateway was asked for. Public so a test can read the figure rather than the log. */
    public function chargedFor(array $orderIds, ?string $currencyCode): float
    {
        $ordered = (float) DB::table('orders')->whereIn('id', $orderIds)->sum('order_amount');

        if (getWebConfig(name: 'currency_model') !== 'multi_currency') {
            return $ordered;
        }

        return (float) usdToAnotherCurrencyConverter(currencyCode: (string) $currencyCode, amount: $ordered);
    }

    private function compare(mixed $paymentId, mixed $paymentAmount, ?string $currencyCode, array $orderIds): void
    {
        if ($orderIds === [] || !is_numeric($paymentAmount)) {
            return;
        }

        $paid = (float) $paymentAmount;
        $ordered = $this->chargedFor(orderIds: $orderIds, currencyCode: $currencyCode);

        if (abs($paid - $ordered) <= $this->oneMinorUnit()) {
            return;
        }

        Log::critical('A settled payment does not match the orders it built.', [
            'payment_id' => is_scalar($paymentId) ? (string) $paymentId : null,
            'paid' => $paid,
            'orders_total' => $ordered,
            'currency' => $currencyCode,
            'order_ids' => $orderIds,
        ]);
    }

    /** One of the smallest coin of the money in force: what the converter's own rounding can move the figure by. */
    private function oneMinorUnit(): float
    {
        return 1 / (10 ** max(defaultCurrencyDecimalPoints(), 0));
    }
}
