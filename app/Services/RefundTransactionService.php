<?php

namespace App\Services;

use App\Services\Storefront\RefundOrderMoney;
use App\Services\Storefront\RefundPayout;

class RefundTransactionService
{
    public function __construct(
        private readonly RefundOrderMoney $orderMoney,
        private readonly RefundPayout $payout,
    ) {
    }

    /**
     * `amount` is the refund in the amounts the order is stored in, as it has always been: the seller's earnings, the reports and the exports read it and
     * it is not touched. Beside it the row records what the order was sold in and by whom, the merchant account the original payment was taken through,
     * and — while the refund payout switch is on — the share of that payment this refund gives back, in the currency it was taken in.
     */
    public function getData(object $request, object $refund, object $order): array
    {
        $payout = $this->payout->forRefund(order: $order, refund: $refund);

        return [
            'order_id' => $refund['order_id'],
            'payment_for' => 'Refund Request',
            'payer_id' => $order['seller_id'],
            'payment_receiver_id' => $refund['customer_id'],
            'paid_by' => $order['seller_is'],
            'paid_to' => 'customer',
            'payment_method' => $request['payment_method'],
            'payment_status' => $request['payment_method'] != null ? 'paid' : 'unpaid',
            'amount' => $refund['amount'],
            'transaction_type' => 'Refund',
            'order_details_id' => $refund['order_details_id'],
            'refund_id' => $refund['id'],
        ] + $this->orderMoney->of(order: $order, table: 'refund_transactions')
            + ($payout === null ? [] : $this->payout->recordOn(table: 'refund_transactions', payout: $payout));
    }

    public function getPDFData(string $companyPhone, string $companyEmail, string $companyName, string $companyWebLogo, object $refundTransactions): array
    {
        $totalAmount = 0;
        foreach ($refundTransactions as $transaction) {
            $totalAmount += $transaction['amount'];
        }
        return [
            'total_amount' => $totalAmount,
            'company_phone' => $companyPhone,
            'company_email' => $companyEmail,
            'company_name' => $companyName,
            'company_web_logo' => $companyWebLogo,
        ];
    }
}
