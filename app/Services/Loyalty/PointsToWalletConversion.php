<?php

namespace App\Services\Loyalty;

use App\Contracts\Repositories\LoyaltyPointTransactionRepositoryInterface;
use App\Models\WalletTransaction;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Moves loyalty points into the wallet. The wallet is credited first and the points debited second, in one transaction, so the
 * conversion happens completely or not at all. The points debit checks the balance while the customer's row is locked, so two
 * conversions that overlap cannot both spend the same points.
 */
final class PointsToWalletConversion
{
    public function __construct(private readonly LoyaltyPointTransactionRepositoryInterface $loyaltyPointTransactionRepo)
    {
    }

    /**
     * @param Closure(): (WalletTransaction|false) $creditWallet credits the wallet through the write path the caller uses
     * @return WalletTransaction|false the wallet entry the conversion created, or false when nothing was changed
     */
    public function convert(int|string $customerId, float|int $points, Closure $creditWallet): WalletTransaction|false
    {
        try {
            return DB::transaction(function () use ($customerId, $points, $creditWallet): WalletTransaction {
                $walletTransaction = $creditWallet();
                if ($walletTransaction === false) {
                    throw new PointsConversionRefused();
                }

                $debited = $this->loyaltyPointTransactionRepo->addLoyaltyPointTransaction(
                    userId: $customerId,
                    reference: $walletTransaction->transaction_id,
                    amount: $points,
                    transactionType: 'point_to_wallet',
                );
                if (!$debited) {
                    throw new PointsConversionRefused();
                }

                return $walletTransaction;
            });
        } catch (PointsConversionRefused) {
            return false;
        }
    }
}
