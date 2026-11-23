<?php

namespace Tests\Support\Concurrency;

use App\Contracts\Repositories\LoyaltyPointTransactionRepositoryInterface;
use App\Services\Loyalty\PointsToWalletConversion;
use App\Utils\CustomerManager;

/**
 * Loyalty point writes, each run in its own process against the same customer row. Each names the write path it exercises, so one
 * test can race the same kind of write through every path the application uses.
 */
final class LoyaltyOperations
{
    /** An order's points credit through the repository, which the admin and vendor order flows call. */
    public static function creditThroughRepository(array $args): bool
    {
        return app(LoyaltyPointTransactionRepositoryInterface::class)->addLoyaltyPointTransaction(
            userId: (string) $args['customer_id'],
            reference: (string) $args['reference'],
            amount: (float) $args['amount'],
            transactionType: 'order_place',
        );
    }

    /** An order's points credit through CustomerManager, which the seller order flows call. */
    public static function creditThroughCustomerManager(array $args): bool
    {
        return CustomerManager::create_loyalty_point_transaction(
            user_id: (string) $args['customer_id'],
            reference: (string) $args['reference'],
            amount: (float) $args['amount'],
            transaction_type: 'order_place',
        );
    }

    /** A conversion of points into the wallet, through the service the REST and web conversions both call. */
    public static function convertToWallet(array $args): bool
    {
        return app(PointsToWalletConversion::class)->convert(
            customerId: (string) $args['customer_id'],
            points: (float) $args['points'],
            creditWallet: fn () => CustomerManager::create_wallet_transaction(
                user_id: (string) $args['customer_id'],
                amount: (float) $args['points'],
                transaction_type: 'loyalty_point',
                reference: (string) $args['reference'],
            ),
        ) !== false;
    }
}
