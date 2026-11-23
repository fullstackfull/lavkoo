<?php

namespace App\Services\Wallet;

use App\Enums\Wallet\MarketBalanceRefusal;

/**
 * What a credit or a debit of a market account did. It is an object, not a boolean, on purpose: `if ($writer->debit(...))` was how an
 * ignored `false` let an order be committed as paid while the debit that paid for it had been refused (E-10, the lesson of the branch
 * gcc/p0-wallet-debit-result-handling, where the wallet writers answer a refusal with `WalletPaymentRefused` and
 * `WalletCreditRefused` so the order rolls back with it). An object is always truthy, so a caller has to read `applied`, and a refusal
 * carries the reason it has to act on.
 *
 * A refusal always means nothing was written: no balance moved, and no ledger row was added.
 */
final readonly class MarketBalanceChange
{
    private function __construct(
        public bool $applied,
        public ?MarketBalanceRefusal $refusal,
        /** What the account holds now; what it held when the change was refused; zero when there is no account to speak of. */
        public float $amount,
        public string $market,
        public ?string $currencyCode,
    ) {
    }

    public static function succeeded(float $amount, string $market, string $currencyCode): self
    {
        return new self(applied: true, refusal: null, amount: $amount, market: $market, currencyCode: $currencyCode);
    }

    public static function refused(MarketBalanceRefusal $refusal, string $market, ?string $currencyCode = null, float $amount = 0.0): self
    {
        return new self(applied: false, refusal: $refusal, amount: $amount, market: $market, currencyCode: $currencyCode);
    }
}
