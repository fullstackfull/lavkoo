<?php

namespace App\Enums\Wallet;

/**
 * Why a credit or a debit of a market account did not happen. A refusal always means nothing was written: no balance moved and no ledger
 * row was added. A caller that pays for an order has to turn one into the refusal its own transaction rolls back on (E-10), never ignore it.
 */
enum MarketBalanceRefusal: string
{
    /** The key is off, or its migration has not run: the one balance on the `users` row is still the authority and its own writers own it. */
    case Dormant = 'dormant';

    /** The market is not one of `gcc.storefronts.countries`, so there is no account and no currency to hold it in. */
    case UnknownMarket = 'unknown_market';

    /** The account was opened in another currency than the market is configured to trade in now: money may not change currency by itself. */
    case CurrencyMismatch = 'currency_mismatch';

    /** What currency this market's money is in cannot be told, so there is no label to open an account under. See `StorefrontCurrency`. */
    case CurrencyUnknown = 'currency_unknown';

    /** Nothing to move: an amount that is not a positive number. */
    case NotAnAmount = 'not_an_amount';

    /** The debit would take the account below zero. The floor is applied with the row locked, so two overlapping debits cannot both pass. */
    case Overdraft = 'overdraft';

    public function because(): string
    {
        return match ($this) {
            self::Dormant => 'market accounts are dormant: the legacy balance writers are the authority',
            self::UnknownMarket => 'the market is not configured',
            self::CurrencyMismatch => 'the account is kept in another currency than the market trades in',
            self::CurrencyUnknown => 'the currency this market sells in cannot be told',
            self::NotAnAmount => 'the amount is not a positive number',
            self::Overdraft => 'the debit would take the account below zero',
        };
    }
}
