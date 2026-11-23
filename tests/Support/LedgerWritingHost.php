<?php

namespace Tests\Support;

use App\Services\Storefront\LedgerOrigin;
use App\Traits\CustomerTrait;

/**
 * Gives a test the wallet writer that CustomerTrait provides to the controllers that use it. The container builds the host with the trait's own
 * dependencies, so a write through it runs the code a controller runs.
 */
final class LedgerWritingHost
{
    use CustomerTrait;

    public function write(int|string $customerId, float $amount, string $transactionType, string $reference, ?LedgerOrigin $origin = null): mixed
    {
        return $this->createWalletTransaction(user_id: $customerId, amount: $amount, transaction_type: $transactionType, reference: $reference, origin: $origin);
    }
}
