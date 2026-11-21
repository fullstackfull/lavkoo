<?php

namespace App\Services\Payments;

use App\Models\PaymentGatewayAccount;

/** Asks a gateway, server to server, what became of one of its transactions. One implementation per gateway. */
interface GatewayTransactionInquiry
{
    /** Whether this gateway can be asked at all with what the account holds. */
    public function canAsk(?PaymentGatewayAccount $account): bool;

    public function transaction(?PaymentGatewayAccount $account, string $reference): GatewayTransactionState;
}
