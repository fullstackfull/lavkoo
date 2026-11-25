<?php

namespace App\Services\Payments;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One paid payment builds one order, even when two deliveries of its callback are being handled at the same instant (D1-328).
 *
 * The settlement hook already refuses a payment whose order exists (`PaymentSettlementScope::hasOrderFor`). That is a READ, and a read is
 * not a claim. Two deliveries of one paid callback -- a gateway's server call arriving while the shopper's browser returns, a gateway
 * retrying because the first answer was slow, the recovery sweep running beside either -- both read "no order for this reference" before
 * either has written one, and both then build a full order. Two orders for one payment: the stock taken twice, the commission charged
 * twice, two orders' worth of notifications, and nothing in the record saying which one the customer paid for.
 *
 * The payment's own row is the lock, in the shape `App\Services\Refund\RefundClaims` uses for the four payments that had the same defect:
 * the second delivery waits on that row until the first has COMMITTED its order, and the re-read then finds it. No new table, no new
 * column and no setting, because the row that must not be settled twice already exists.
 *
 * **Where it is asked matters more than what it does.** It is asked inside the transaction `OrderManager::generateOrder` writes the order
 * in, and nowhere else. Inside that transaction the lock is held until the order is committed and released the moment it is -- which is
 * before the notifications and emails of a placed order are sent. Taking the lock around the whole settlement instead would mean a
 * notification listener that throws rolls back an order the customer has already PAID for: `OrderPlacedEvent` is fired outside that
 * transaction and is not caught, and a test in this repository arms a listener to throw. A build that fails gives the claim back, because
 * nothing was ever marked: the next delivery, or the recovery sweep, can still settle that payment, exactly as today.
 *
 * The lock is taken by PRIMARY KEY, never by the reference. `payment_requests.transaction_id` carries no index that this repository
 * declares, and `SELECT ... WHERE transaction_id = ? FOR UPDATE` without one locks every row it scans -- which would serialise every
 * settlement on the platform and invent deadlocks where there were none.
 *
 * On SQLite `lockForUpdate` does nothing, so nothing here promises what that engine cannot keep: the re-read still closes the finished
 * replay, and the race is proved on MySQL and MariaDB.
 */
#[Singleton]
final class OneOrderPerPayment
{
    /**
     * Whether this settlement may build the orders of its payment. True for everything that is not a paid gateway settlement: a
     * cash-on-delivery, offline or wallet checkout carries no gateway reference and is not what this guards.
     *
     * @param mixed $paymentId the `payment_requests` row of the payment, whose lock serialises the deliveries of its callback
     * @param mixed $transactionRef the gateway reference the orders of this payment carry
     */
    public function mayBuild(mixed $paymentId, mixed $transactionRef, mixed $paymentStatus): bool
    {
        $reference = is_scalar($transactionRef) ? trim((string) $transactionRef) : '';

        if ($reference === '' || $paymentStatus !== 'paid') {
            return true;
        }

        $this->lockThePaymentRow(paymentId: $paymentId);

        return !PaymentSettlementScope::hasOrderFor(transactionId: $reference);
    }

    /** Takes the lock on the payment's own row, where there is one to take. A payment this platform cannot lock keeps today's behaviour. */
    private function lockThePaymentRow(mixed $paymentId): void
    {
        if (!is_scalar($paymentId) || (string) $paymentId === '' || !Schema::hasTable('payment_requests')) {
            return;
        }

        DB::table('payment_requests')->where('id', $paymentId)->lockForUpdate()->first(['id']);
    }
}
