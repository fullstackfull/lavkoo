<?php

namespace App\Services\Referral;

use App\Models\ReferralRewardGrant;
use App\Models\User;
use App\Models\WalletTransaction;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pays a first-order referral reward at most once per (referee, beneficiary).
 *
 * Every referral writer hands its own credit call to creditOnce(), so the amount each path computes is unchanged.
 * The grant row and the wallet credit commit or roll back together, and the unique index on the grant row, not a
 * status check, decides the single winner across writers, repeated deliveries and concurrent requests.
 */
class ReferralRewardService
{
    private const GRANTS_TABLE = 'referral_reward_grants';

    /**
     * @param Closure(): (bool|WalletTransaction) $credit the path's existing wallet writer call
     * @return bool|WalletTransaction false when the reward was already granted or the writer credited nothing
     */
    public function creditOnce(int|string $refereeId, int|string $beneficiaryId, int|string $orderId, Closure $credit): bool|WalletTransaction
    {
        if (!Schema::hasTable(self::GRANTS_TABLE)) {
            return $credit();
        }

        return DB::transaction(function () use ($refereeId, $beneficiaryId, $orderId, $credit): bool|WalletTransaction {
            // Serialises this credit with any other locked write to the beneficiary's wallet balance.
            User::whereKey($beneficiaryId)->lockForUpdate()->value('id');

            $grantKey = ['referee_id' => $refereeId, 'beneficiary_id' => $beneficiaryId];
            $inserted = ReferralRewardGrant::insertOrIgnore($grantKey + ['order_id' => $orderId, 'created_at' => now(), 'updated_at' => now()]);
            if ($inserted === 0) {
                return false;
            }

            $walletTransaction = $credit();
            if ($walletTransaction === false) {
                ReferralRewardGrant::where($grantKey)->delete();

                return false;
            }

            if ($walletTransaction instanceof WalletTransaction) {
                ReferralRewardGrant::where($grantKey)->update(['wallet_transaction_id' => $walletTransaction->id]);
            }

            return $walletTransaction;
        });
    }
}
