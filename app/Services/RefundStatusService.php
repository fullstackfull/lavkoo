<?php

namespace App\Services;

use App\Enums\LedgerActor;
use App\Services\Storefront\LedgerOrigin;
use App\Services\Storefront\RefundPayout;
use App\Traits\CustomerTrait;
use App\Services\Customer\CustomerWalletWriter;

class RefundStatusService
{
    use CustomerTrait;

    /** What a wallet credit of a refund is referenced by, with the refund's own id after it (RF-02). */
    public const REFUND_REFERENCE_PREFIX = 'refund_request_';

    public function __construct(private readonly RefundPayout $payout)
    {
    }

    /**
     * @param object $request
     * @param object $refund
     * @param string $changeBy
     * @return array
     */
    public function getRefundStatusData(object $request, object $refund, string $changeBy):array
    {
        return [
            'refund_request_id' => $refund['id'],
            'change_by' => $changeBy,
            'change_by_id' => $changeBy === 'seller' ? auth('seller')->id() : 1,
            'status' => $request['refund_status'],
            'message' => $request['approved_note'] ?? $request['rejected_note'] ?? null,
            'approved_note' => $request['approved_note'] ?? null,
            'rejected_note' => $request['rejected_note'] ?? null,
        ];
    }

   /**
    * @param object|array<string, mixed>|null $order the order being refunded, which says what its buyer actually paid and in which currency
    */
   public function getRefundStatusProcessData(object $request, object $orderDetails, object $refund, string|float|int $loyaltyPoint, object|array|null $order = null): array
{
    $refundStatuses = [];

    $baseStatus = [
        'refund_request_id' => $refund['id'],
        'change_by' => 'admin',
        'change_by_id' => auth('admin')->id(),
    ];

    $refundData = [];

    if ($request['refund_status'] == 'pending') {
        $orderDetails['refund_request'] = 1;
        $refundStatuses[] = [
            ...$baseStatus,
            'status' => 'pending'
        ];
    }elseif ($request['refund_status'] == 'approved') {
        $orderDetails['refund_request'] = 2;
        $refundData['approved_note'] = $request['approved_note'];
        $refundStatuses[] = [
            ...$baseStatus,
            'status' => 'approved',
            'message' => $request['approved_note']
        ];
    }

    elseif ($request['refund_status'] == 'rejected') {
        $orderDetails['refund_request'] = 3;
        $refundData['rejected_note'] = $request['rejected_note'];
        $refundStatuses[] = [
            ...$baseStatus,
            'status' => 'rejected',
            'message' => $request['rejected_note']
        ];
    }elseif ($request['refund_status'] == 'refunded') {
        $refundStatuses[] = [
            ...$baseStatus,
            'status' => 'approved',
            'message' => $request['approved_note'] ?? 'Auto approved before refund'
        ];
        $refundStatuses[] = [
            ...$baseStatus,
            'status' => 'refunded',
            'message' => $request['payment_info']
        ];

        $orderDetails['refund_request'] = 4;
        $refundData['payment_info'] = $request['payment_info'];

        // RF-02: the credit names the refund it pays. It used to carry the literal `order_refund`, which is also its transaction type, so
        // nothing in the ledger said which refund a credit belonged to and two credits for one refund were indistinguishable from two
        // refunds. The reconciliation looks for a repeated reference, so naming the refund is what lets it see one paid twice at all.
        $walletAddRefund = getWebConfig(name: 'wallet_add_refund');
        if ($walletAddRefund == 1 && $request['payment_method'] == 'customer_wallet') {
            $this->createWalletTransaction(
                user_id: $refund['customer_id'],
                amount: $this->whatTheRefundGivesBack(order: $order, refund: $refund),
                transaction_type: 'order_refund',
                reference: self::REFUND_REFERENCE_PREFIX . $refund['id'],
                origin: LedgerOrigin::refund(id: $refund['id'], by: LedgerActor::Admin)
            );
        }
    }

    $refundData['status'] = $request['refund_status'];
    $refundData['change_by'] = 'admin';

    return [
        'refund' => $refundData,
        'orderDetails' => $orderDetails,
        'refundStatus' => $refundStatuses,
    ];
}

    /**
     * The money the buyer gets back, in the platform's own currency.
     *
     * Converting the stored amount here (`usdToDefaultCurrency`) applies the rate of the day the refund is paid out to a sale made at another rate: a
     * purchase of 375 at 3.75 gives back 300 once the rate stands at 3.00. While the switch of App\Services\Storefront\RefundPayout is on, and the
     * payment was taken in the platform's own currency, the buyer is given back their share of what they actually paid instead. In every other case —
     * the switch off, a cash-on-delivery order, a payment in another currency, a database that has not run the migrations — this is the figure the
     * refund has always given back, unchanged.
     *
     * @param object|array<string, mixed>|null $order
     */
    private function whatTheRefundGivesBack(object|array|null $order, object $refund): float
    {
        $payout = $this->payout->forRefund(order: $order, refund: $refund);
        $paid = $payout === null ? null : $this->payout->walletCredit($payout);

        return $paid === null ? (float) usdToDefaultCurrency(amount: $refund['amount']) : (float) $paid;
    }
}
