<?php

namespace App\Services\Payments;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which merchant account took an order's money, read from the payment itself (D1-10).
 *
 * The order keeps the reference of the payment that paid for it (`orders.transaction_ref`, which is the gateway's `payment_requests.transaction_id`), and
 * that payment carries the stamp App\Services\Payments\PaymentAccountBinding wrote when it started. The stamp is what is read: never a fresh resolution of
 * the registry, because an account can be edited, disabled or deleted and a market can be given a different account between the sale and the refund, and a
 * figure worked out again on the day of the refund is how a refund comes to name an account that never saw the money.
 *
 * Nothing is answered while per-company payment accounts are off (gcc.storefronts.payment_accounts.enabled, off by default), so a shop that does not run
 * them issues no statement here at all and behaves exactly as it does today. A payment stamped while they were on and refunded after they were switched
 * off therefore records nothing: the switch stops the mechanism rather than half-running it, which is the dormancy this change is held to.
 *
 * Two paid payments under one reference answer nothing rather than pick one, the same rule App\Services\Storefront\RefundFromOriginalPayment uses for the
 * amount, so the account and the amount of one order can never be read from two different payments.
 *
 * What is answered is the stamp, not an account that still exists: an account row and its company can be deleted after a payment, and the refund still
 * records the ids the payment was taken through, because that is what took the money. It is a record and reads as one. The half of D1-10 that is not
 * built -- a refund actually LEAVING through that account -- cannot read these ids as a routing instruction without first checking that the account is
 * still there, and the columns carry no foreign key: PaymentAccountBinding::accountOf() is the reader that fails closed, and is what that half wants.
 *
 * The reference is one mutable text column and binds nothing else, so the payment has to answer for the order as well as carry its reference
 * (PaymentBelongsToOrder): its stamp must name the market the order was sold from, and the orders carrying the reference must be one cart. A refund can
 * otherwise come to name the merchant account of a company that never sold to this buyer. That second question is one more read of `orders` while
 * per-company accounts are on, and it is the only statement this adds.
 */
#[Singleton]
final class OrderPaymentAccount
{
    public function __construct(
        private readonly GatewayAccountRegistry $registry,
        private readonly PaymentAccountBinding $binding,
        private readonly PaymentBelongsToOrder $belongsToOrder,
    ) {
    }

    /** What the order's original payment was stamped with, or null when there is no such payment, no stamp, or per-company accounts are off. */
    public function of(object|array|null $order): ?PaymentTransactionRecord
    {
        if ($order === null || !$this->registry->isEnabled()) {
            return null;
        }

        $reference = trim((string) data_get($order, 'transaction_ref', ''));

        if ($reference === '' || !Schema::hasTable('payment_requests')) {
            return null;
        }

        $payments = DB::table('payment_requests')->where('transaction_id', $reference)->where('is_paid', 1)->limit(2)->get(['additional_data']);

        if ($payments->count() !== 1) {
            return null;
        }

        $stamp = $this->binding->of(payment: (array) $payments->first());

        if (!$this->belongsToOrder->stamp(stamp: $stamp, order: $order) || !$this->belongsToOrder->oneCartUnder(reference: $reference)) {
            return null;
        }

        return $stamp;
    }
}
