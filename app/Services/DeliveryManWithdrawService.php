<?php

namespace App\Services;

use App\Models\DeliverymanWallet;
use App\Models\WithdrawRequest;
use Illuminate\Support\Facades\DB;

class DeliveryManWithdrawService
{
    /** What the `approved` column of a withdrawal request means: 0 is still pending, and these two are the decisions. */
    private const APPROVED = 1;

    private const DENIED = 2;

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

    /**
     * Settles one withdrawal request of a delivery man, once, whichever screen decides it.
     *
     * A decision moves money: an approval takes the amount out of the balance and records it as withdrawn, a denial releases the
     * reservation the request made. Both screens used to compute the new totals from a wallet read outside any transaction and then write
     * them back, and neither looked at whether the request was still pending. So re-posting a decision applied the movement again --
     * `total_withdraw` grew, `pending_withdraw` went negative and the balance fell a second time for one payout -- and two decisions at
     * once each wrote totals worked out from the same stale read, losing one of them.
     *
     * The request's own row is the gate: the status is written WHERE it is still pending, and nothing else happens unless that update
     * claimed exactly one row, so a repeat decides nothing. The wallet is read under its row lock inside the same transaction that writes
     * it, so a second decision waits and then reads what the first committed.
     *
     * @return bool false when the request was no longer pending, and nothing was written
     */
    public function settle(object $request, object $withdraw): bool
    {
        // 0 is what the column means by "still pending", so writing it back decides nothing and would leave the row claimable again.
        // The request's rules already bound this; the service is asked too, because it is what moves the money.
        if (!in_array((int) $request['approved'], [self::APPROVED, self::DENIED], true)) {
            return false;
        }

        return DB::transaction(function () use ($request, $withdraw): bool {
            $wallet = DeliverymanWallet::query()
                ->where('delivery_man_id', $withdraw['delivery_man_id'])
                ->lockForUpdate()
                ->first();

            $claimed = WithdrawRequest::query()
                ->where(['id' => $withdraw['id'], 'approved' => 0])
                ->update($this->getDeliveryManWithdrawData(request: $request));

            if ($claimed !== 1) {
                return false;
            }

            $movement = $this->getUpdateData(request: $request, wallet: $wallet, withdraw: $withdraw)['wallet'];

            if ($wallet !== null && $movement !== []) {
                $wallet->forceFill($movement)->save();
            }

            return true;
        });
    }
}
