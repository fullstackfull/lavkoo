<?php

namespace App\Services\Customer;

use App\Services\Storefront\StorefrontCurrency;

/**
 * The unit a movement of a customer's wallet is counted in, and the one place every writer of that wallet asks for it (E-16).
 *
 * There are five writers of a wallet balance -- `CustomerWalletWriter`, `CustomerTrait::createWalletTransaction`,
 * `CustomerManager::create_wallet_transaction`, `OrderManager::createWalletTransaction` and
 * `WalletTransactionRepository::addWalletTransaction` -- and each of them computed its CREDIT where it stood, unrounded. The same reward
 * therefore reached the ledger as a different figure depending on which writer the path went through, and in Kuwait, Bahrain and Oman a
 * credit could carry more places than the currency has coins for: a points conversion, a refund and a referral reward of one amount were
 * three amounts.
 *
 * `placesUnder()` is the arithmetic {@see WalletOrderDebit} has always done for the DEBIT side, moved here so the two sides cannot
 * disagree about what a market's smallest coin is, and reading from `StorefrontCurrency::decimalsOf()` -- the one authority on how many
 * places a currency has -- and **never** from `decimal_point_settings`, which is a display choice whose own admin field allows 0.
 *
 * `credited()` is the credit side, behind `gcc.wallet.credits_in_the_markets_minor_unit`, and it ships OFF: while it is off an amount is
 * handed straight back, the same float the writers have always stored, and not one query is made. With it on, a request served from a
 * storefront that sells in its own currency rounds half up at that currency's places and every other request -- a panel, a queue worker, a
 * console command, and every request while the storefronts are off -- rounds at two, which is Saudi Arabia, the United Arab Emirates and
 * Qatar whatever the switch says.
 *
 * **What this deliberately does NOT touch**, because each is the owner's and not this class's:
 *
 *  - WHAT a point is worth. The web and the API still convert points by two different formulas ({@see \App\Services\Loyalty\WhatAPointIsWorth});
 *    this rounds whatever they answer, so the two formulas stay two formulas. That is LF-1 and LF-2, open financial decision 1;
 *  - the add-fund BONUS, which is added to the balance beside the credit and is already in store currency, and whose own double conversion
 *    is the same open decision;
 *  - the DEBIT of a writer that does not come through `WalletOrderDebit`, the point-of-sale tills among them (half of D18).
 */
final class WalletMinorUnit
{
    /** What a wallet movement has always been rounded at, and what the riyal, the dirham, the dollar and the Qatari riyal have. */
    public const TWO_PLACES = 2;

    /** The switch of the credit side. Off as it ships: every writer stores the credit it always stored. */
    public const CREDITS_SWITCH = 'gcc.wallet.credits_in_the_markets_minor_unit';

    /**
     * The places a wallet movement is counted at for the request being served, under the switch the asking side owns.
     *
     * Two unless that switch is on AND the storefront being served sells in a currency of its own: a request that serves no storefront is
     * not a claim about any market's money, so it rounds where it always did.
     */
    public static function placesUnder(string $switch): int
    {
        if (!filter_var(config($switch, false), FILTER_VALIDATE_BOOLEAN)) {
            return self::TWO_PLACES;
        }

        $currency = app(StorefrontCurrency::class);
        $code = $currency->currencyCodeInForce();

        return $code === null ? self::TWO_PLACES : $currency->decimalsOf($code);
    }

    /**
     * One credit, at the unit the market it is made in counts money in -- or the amount untouched, which is every installation today.
     *
     * The amount is handed back as it came while the switch is off, rather than rounded at two places, because a credit has never been
     * rounded at all: rounding it at two "by default" would move money on every path that reaches a writer, which is the opposite of what
     * a switch that ships off is for.
     */
    public static function credited(float|int|string $amount): float
    {
        if (!filter_var(config(self::CREDITS_SWITCH, false), FILTER_VALIDATE_BOOLEAN)) {
            return (float) $amount;
        }

        return round((float) $amount, self::placesUnder(self::CREDITS_SWITCH));
    }
}
