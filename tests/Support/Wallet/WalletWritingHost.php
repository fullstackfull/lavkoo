<?php

namespace Tests\Support\Wallet;

use App\Traits\CustomerTrait;

/**
 * Gives a test the wallet writer that CustomerTrait provides to the controllers that use it. The container builds the host with
 * the trait's own dependencies, so a write through it runs the code a controller runs.
 */
final class WalletWritingHost
{
    use CustomerTrait;

    public function write(int|string $customerId, float $amount, string $transactionType, string $reference): mixed
    {
        return $this->createWalletTransaction($customerId, $amount, $transactionType, $reference);
    }
}
