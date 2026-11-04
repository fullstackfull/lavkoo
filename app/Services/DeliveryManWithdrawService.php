<?php

namespace App\Services;

class DeliveryManWithdrawService
{
    /**
     * @param object $request
     * @return array
     */
    public function getDeliveryManWithdrawData(object $request) : array
    {
        return  [
            'approved' => $request['approved'],
            'transaction_note' => $request['note']
        ];
    }

    /**
     * @param object $request
     * @param object $wallet
     * @param object $withdraw
     * @return array[]
     */
    public function getUpdateData(object $request, ?object $wallet, object $withdraw): array
    {
        $withdrawData = [
            'approved' => $request['approved'],
            'transaction_note' => $request['note'],
        ];
        $walletData = [];
        $amount = $withdraw['amount'] ?? 0;
        $totalWithdraw = $wallet ? ($wallet->total_withdraw ?? 0) : 0;
        $pendingWithdraw = $wallet ? ($wallet->pending_withdraw ?? 0) : 0;
        $currentBalance = $wallet ? ($wallet->current_balance ?? 0) : 0;

        if ($request['approved'] == 1) {
            $walletData['total_withdraw'] = $totalWithdraw + $amount;
            $walletData['pending_withdraw'] = $pendingWithdraw - $amount;
            $walletData['current_balance'] = $currentBalance - $amount;
        } else {
            $walletData['pending_withdraw'] = $pendingWithdraw - $amount;
        }

        return [
            'wallet' => $walletData,
            'withdraw' => $withdrawData,
        ];
    }
}
