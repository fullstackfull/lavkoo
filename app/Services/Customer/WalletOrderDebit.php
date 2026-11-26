<?php

namespace App\Services\Customer;

use App\Services\Storefront\StorefrontCurrency;

/**
 * What a wallet order debits: its stored amount rounded half up, once, at the unit a wallet debit is counted in. The balance check and the
 * debit both use that unit, so the wallet is never checked against, or debited by, a different amount than the order's (E-16).
 *
 * **The unit was TWO places, unconditionally**, which is the riyal's and the dollar's. `gcc.storefronts.currency.decimals` says the Kuwaiti
 * dinar, the Bahraini dinar and the Omani rial have THREE -- ISO 4217 -- so in those three markets two places is a unit ten times coarser
 * than the smallest coin the currency has, and an order of 10.015 was charged as 10.02: the customer paid a figure their own money cannot
 * express and the ledger row was not the order's amount. The places are read from `StorefrontCurrency::decimalsOf()`, the one authority on
 * how many places a currency has and the same one the Odoo postings and the gateway list read, and **never** from
 * `decimal_point_settings`, which is a display choice whose own admin field allows 0.
 *
 * `gcc.wallet.debit_in_the_markets_minor_unit` is the switch and it ships OFF: while it is off this rounds at two places exactly as it
 * always has, for every market, and not one query is made. With it on, a market selling in its own currency rounds at that currency's
 * places and every other request still rounds at two -- which is Saudi Arabia, the United Arab Emirates and Qatar whatever the switch says,
 * because SAR, AED and QAR have two.
 *
 * **What this deliberately does NOT do, because each of the three is the owner's and not this class's** (E-16, tracker):
 *
 *  - the point-of-sale tills (admin, vendor, seller app) debit `currencyConverter(posted amount)` UNROUNDED and do not come through here, so
 *    one order amount is still debited differently depending on the till. Whether the tills follow the checkout's rule is half of D18;
 *  - the four writers of the one balance still apply their own conversions, and the gateway top-up's double conversion loses money at a
 *    `decimal_point_settings` of 0 (measured in `ATopUpCreditsWhatWasPaidTest`). What a stored unit is worth is open financial decision 1;
 *  - the points-to-wallet, refund and referral CREDITS are computed where they are computed. LF-1, LF-2 and RF-10 are pinned, not settled.
 *
 * Naming them here rather than leaving them implicit is the point: a class that says it is "the one unit" while three money paths do not
 * come through it would be the most expensive kind of comment.
 */
final class WalletOrderDebit
{
    /** The switch. Off as it ships: every market's wallet debit rounds at two places, exactly as it always has. */
    public const MARKET_MINOR_UNIT_SWITCH = 'gcc.wallet.debit_in_the_markets_minor_unit';

    /** What a wallet debit has always been rounded at, and what the riyal, the dirham, the dollar and the Qatari riyal have. */
    public const TWO_PLACES = 2;

    private function __construct(
        private readonly float $usdAmount,
        private readonly int $places,
    ) {
    }

    public static function forOrderAmount(float|int|string $orderAmountInUsd): self
    {
        $places = self::places();

        return new self(usdAmount: round((float) $orderAmountInUsd, $places), places: $places);
    }

    /**
     * The places a wallet debit is rounded at for the request being served.
     *
     * Two unless the switch is on AND the storefront being served sells in a currency of its own: a request that serves no storefront -- a
     * queue worker, a panel, a console command, and every request while the storefronts are off -- is not a claim about any market's money,
     * so it rounds where it always did.
     */
    public static function places(): int
    {
        if (!filter_var(config(self::MARKET_MINOR_UNIT_SWITCH, false), FILTER_VALIDATE_BOOLEAN)) {
            return self::TWO_PLACES;
        }

        $currency = app(StorefrontCurrency::class);
        $code = $currency->currencyCodeInForce();

        return $code === null ? self::TWO_PLACES : $currency->decimalsOf($code);
    }

    public function amountInUsd(): float
    {
        return $this->usdAmount;
    }

    /** The balance is read at the same unit as the debit, so the check and the balance written after it agree. */
    public function isCoveredBy(float|int|string|null $walletBalanceInUsd): bool
    {
        return round((float) $walletBalanceInUsd, $this->places) >= $this->usdAmount;
    }
}
