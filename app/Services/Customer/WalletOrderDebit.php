<?php

namespace App\Services\Customer;

/**
 * What a wallet order debits: its USD amount rounded half up to whole cents, once. The balance check and the debit both use these cents, so
 * the wallet is never checked against, or debited by, a different amount than the order's, whatever the store currency is.
 */
final class WalletOrderDebit
{
    private function __construct(
        private readonly float $usdAmount,
    ) {
    }

    public static function forOrderAmount(float|int|string $orderAmountInUsd): self
    {
        return new self(usdAmount: round((float) $orderAmountInUsd, 2));
    }

    public function amountInUsd(): float
    {
        return $this->usdAmount;
    }

    /** The balance is read to whole cents with the same rounding as the debit, so the check and the balance written after it agree. */
    public function isCoveredBy(float|int|string|null $walletBalanceInUsd): bool
    {
        return round((float) $walletBalanceInUsd, 2) >= $this->usdAmount;
    }
}
