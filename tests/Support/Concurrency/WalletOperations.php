<?php

namespace Tests\Support\Concurrency;

use App\Repositories\WalletTransactionRepository;
use App\Utils\CustomerManager;
use App\Utils\OrderManager;
use Tests\Support\Wallet\WalletWritingHost;

/**
 * Customer wallet writes, each run in its own process against the same wallet row. Each names the write path it exercises, so one
 * test can race the same kind of write through every path the application uses. The transaction type comes from the arguments.
 */
final class WalletOperations
{
    /** Through the repository, which the admin and vendor flows call. */
    public static function writeThroughRepository(array $args): bool
    {
        return app(WalletTransactionRepository::class)->addWalletTransaction(
            user_id: (string) $args['customer_id'],
            amount: (float) $args['amount'],
            transactionType: (string) $args['transaction_type'],
            reference: (string) $args['reference'],
        ) !== false;
    }

    /** Through CustomerManager, which the REST order, web checkout and referral paths call. */
    public static function writeThroughCustomerManager(array $args): bool
    {
        return CustomerManager::create_wallet_transaction(
            user_id: (string) $args['customer_id'],
            amount: (float) $args['amount'],
            transaction_type: (string) $args['transaction_type'],
            reference: (string) $args['reference'],
        ) !== false;
    }

    /** Through OrderManager, which the referral bonus after a customer's first delivered order calls. */
    public static function writeThroughOrderManager(array $args): bool
    {
        return OrderManager::createWalletTransaction(
            user_id: (string) $args['customer_id'],
            amount: (float) $args['amount'],
            transaction_type: (string) $args['transaction_type'],
            reference: (string) $args['reference'],
        ) !== false;
    }

    /** Through CustomerTrait, which the POS and loyalty controllers call. */
    public static function writeThroughCustomerTrait(array $args): bool
    {
        return app(WalletWritingHost::class)->write(
            customerId: (string) $args['customer_id'],
            amount: (float) $args['amount'],
            transactionType: (string) $args['transaction_type'],
            reference: (string) $args['reference'],
        ) !== false;
    }
}
