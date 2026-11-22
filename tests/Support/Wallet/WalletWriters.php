<?php

namespace Tests\Support\Wallet;

use App\Repositories\WalletTransactionRepository;
use App\Utils\CustomerManager;
use App\Utils\OrderManager;

/** Calls each wallet write path the same way, so one test can run the same case through every path. */
final class WalletWriters
{
    /** Every path that writes a wallet balance. OrderManager's writer has no due_payment_for_order branch, so tests of that type use CustomerManager. */
    public const PATHS = ['repository', 'customer_manager', 'order_manager', 'customer_trait'];

    public static function write(string $path, string $customerId, string $transactionType, string $reference, float $amount): mixed
    {
        return match ($path) {
            'repository' => app(WalletTransactionRepository::class)->addWalletTransaction(
                user_id: $customerId,
                amount: $amount,
                transactionType: $transactionType,
                reference: $reference,
            ),
            'customer_manager' => CustomerManager::create_wallet_transaction(
                user_id: $customerId,
                amount: $amount,
                transaction_type: $transactionType,
                reference: $reference,
            ),
            'order_manager' => OrderManager::createWalletTransaction(
                user_id: $customerId,
                amount: $amount,
                transaction_type: $transactionType,
                reference: $reference,
            ),
            'customer_trait' => app(WalletWritingHost::class)->write(
                customerId: $customerId,
                amount: $amount,
                transactionType: $transactionType,
                reference: $reference,
            ),
        };
    }
}
