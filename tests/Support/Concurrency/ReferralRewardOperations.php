<?php

namespace Tests\Support\Concurrency;

use App\Services\Referral\ReferralRewardService;
use App\Utils\CustomerManager;
use App\Utils\OrderManager;

/**
 * What a worker process does in the referral reward concurrency tests: one referral writer, exactly as its delivery path calls
 * it, through ReferralRewardService::creditOnce(). The wallet ledger has no unique key, so a second credit is a second row.
 */
final class ReferralRewardOperations
{
    /**
     * @param array{referee: int, beneficiary: int, order: int, writer: string, reward_setting: float, hold_microseconds?: int} $args
     * @return array{credited: bool}
     */
    public static function creditOnce(array $args): array
    {
        $result = app(ReferralRewardService::class)->creditOnce(
            refereeId: (int) $args['referee'],
            beneficiaryId: (int) $args['beneficiary'],
            orderId: (int) $args['order'],
            credit: function () use ($args) {
                $walletTransaction = self::credit(writer: (string) $args['writer'], beneficiaryId: (int) $args['beneficiary'], rewardSetting: (float) $args['reward_setting']);
                // Holds the transaction open so the other processes reach the grant row while this one is still writing.
                usleep((int) ($args['hold_microseconds'] ?? 0));

                return $walletTransaction;
            },
        );

        return ['credited' => $result !== false];
    }

    private static function credit(string $writer, int $beneficiaryId, float $rewardSetting): mixed
    {
        return match ($writer) {
            'shared' => OrderManager::createWalletTransaction(user_id: $beneficiaryId, amount: usdToDefaultCurrency(amount: $rewardSetting), transaction_type: 'add_fund_by_admin', reference: 'earned_by_referral'),
            'customer_manager' => CustomerManager::create_wallet_transaction($beneficiaryId, $rewardSetting, 'add_fund_by_admin', 'earned_by_referral'),
        };
    }
}
