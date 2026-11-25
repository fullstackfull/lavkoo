<?php

namespace App\Services\Refund;

use App\Models\MyFatoorahPaymentAttempt;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Which order, and which refund request, a refund sent through a payment gateway belongs to (D2-15).
 *
 * The gateway refund screen knew the PAYMENT and nothing else. `MyFatoorah\RefundService::request()` has always taken `$orderId` and
 * `$refundRequestId` and the columns for them exist on `myfatoorah_refunds`, but the only caller passed neither, so every refund the
 * platform sent through a gateway was recorded against a payment attempt and against nothing a customer, an accountant or a seller could
 * find it by. A refund is the one money movement somebody always comes back to ask about, and the row could not answer "which sale".
 *
 * Both links are WORKED OUT, never guessed, and this class answers null wherever the answer is not certain:
 *
 *  - the ORDER is found through the payment the attempt belongs to: `payment_requests.transaction_id` is the reference an order of that
 *    payment carries in `orders.transaction_ref`, which is the same join the settlement guard uses to decide whether a payment already
 *    has an order ({@see \App\Services\Payments\PaymentSettlementScope::hasOrderFor()}). A payment that produced SEVERAL orders -- a
 *    multi-vendor basket becomes one order per vendor under one payment -- answers null, because a single `order_id` column cannot say
 *    "these three" and naming one of three would file the refund against an arbitrary vendor's sale;
 *  - the REFUND REQUEST is found among that order's own requests, by the amount being refunded, and only when exactly one request of
 *    that order asks for exactly that amount and has been approved or paid. Two lines returned for the same amount answer null: which of
 *    them the money is for is not a thing to deduce, and recording the wrong one is worse than recording none.
 *
 * It reads and writes nothing. A platform whose refund tables have not been migrated, or whose order carries no reference, is answered
 * null and the gateway refund is recorded exactly as it is recorded today.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO: it does not touch the vendor or commission ledger. The ledger is moved by the refund DECISION
 * ({@see \App\Http\Controllers\Admin\Order\RefundController::settleRefund()}, which claims the decision under `RefundClaims` and reverses
 * the commission through `RefundReversalService`), and the gateway send is the money leaving afterwards. Moving it here as well would
 * reverse one refund twice, which is the defect this linkage exists to make visible rather than to create.
 */
#[Singleton]
final class GatewayRefundLink
{
    /** Statuses of a refund request whose amount is a figure somebody has agreed to pay back. */
    private const AGREED = ['approved', 'refunded'];

    /** How closely a refund request's amount must match what is being sent, in the unit the rows are kept in. */
    private const TOLERANCE = 0.005;

    /**
     * The order of this payment attempt, or null when the payment produced no order or more than one.
     *
     * @return array{order_id: ?int, refund_request_id: ?int}
     */
    public function of(MyFatoorahPaymentAttempt $attempt, float $amount): array
    {
        $orderId = $this->orderOf($attempt);

        return [
            'order_id' => $orderId,
            'refund_request_id' => $orderId === null ? null : $this->refundRequestOf(orderId: $orderId, amount: $amount),
        ];
    }

    private function orderOf(MyFatoorahPaymentAttempt $attempt): ?int
    {
        $reference = $this->referenceOf($attempt);

        if ($reference === null || !Schema::hasTable('orders')) {
            return null;
        }

        // The query builder, not the Order model: a staff country scope on the model would hide an order that exists, and a refund
        // recorded against nothing because the operator is scoped to another market is a worse record than one recorded against nothing
        // because the payment really has no order.
        $orders = DB::table('orders')->where('transaction_ref', $reference)->orderBy('id')->pluck('id');

        if ($orders->count() === 1) {
            return (int) $orders->first();
        }

        if ($orders->count() > 1) {
            Log::info('refund.gateway_payment_has_several_orders', ['attempt' => $attempt->getKey(), 'reference' => $reference, 'orders' => $orders->all()]);
        }

        return null;
    }

    /** The gateway reference the orders of this payment carry, read from the payment row the attempt was made for. */
    private function referenceOf(MyFatoorahPaymentAttempt $attempt): ?string
    {
        $paymentRequestId = $attempt->payment_request_id ?? null;

        if (!is_scalar($paymentRequestId) || (string) $paymentRequestId === '' || !Schema::hasTable('payment_requests')) {
            return null;
        }

        $reference = DB::table('payment_requests')->where('id', $paymentRequestId)->value('transaction_id');

        return is_scalar($reference) && (string) $reference !== '' ? (string) $reference : null;
    }

    private function refundRequestOf(int $orderId, float $amount): ?int
    {
        if ($amount <= 0 || !Schema::hasTable('refund_requests')) {
            return null;
        }

        $candidates = DB::table('refund_requests')
            ->where('order_id', $orderId)
            ->whereIn('status', self::AGREED)
            ->orderBy('id')
            ->get(['id', 'amount'])
            ->filter(static fn (object $request): bool => abs((float) $request->amount - $amount) <= self::TOLERANCE)
            ->values();

        if ($candidates->count() === 1) {
            return (int) $candidates->first()->id;
        }

        if ($candidates->count() > 1) {
            Log::info('refund.gateway_amount_matches_several_requests', ['order' => $orderId, 'amount' => $amount, 'requests' => $candidates->pluck('id')->all()]);
        }

        return null;
    }
}
