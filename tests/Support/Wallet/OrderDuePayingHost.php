<?php

namespace Tests\Support\Wallet;

use App\Models\Order;
use App\Models\User;
use App\Traits\OrderEditManager;

/** Gives a test the due payment that OrderEditManager provides to the REST and web controllers that use it. */
final class OrderDuePayingHost
{
    use OrderEditManager;

    /** @return array{status: bool, message: string} */
    public function payFromWallet(int $orderId, int $customerId): array
    {
        return $this->payEditOrderDueByCustomerWallet(
            order: Order::with(['latestEditHistory'])->findOrFail($orderId),
            customer: User::findOrFail($customerId),
        );
    }
}
