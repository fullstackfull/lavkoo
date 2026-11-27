<?php

namespace App\Services\Customer;

use App\Services\Loyalty\WhatAPointIsWorth;
use App\Utils\Convert;
use App\Utils\Helpers;

/**
 * What one write to a customer's wallet moves: an amount to credit, an amount to debit and a bonus, all in the currency the
 * balance is stored in, plus what the ledger row should say about them.
 *
 * This is where the shaping lives — "how much", given a transaction type and an amount. CustomerWalletWriter is where the
 * balance is read and written. They are apart on purpose: the lost update and the row that disagreed with the balance were both
 * in the writing, and keeping the arithmetic out of it means one locked writer serves every caller without any of them having to
 * agree on a currency formula they have never agreed on.
 *
 * ## The formulas here are the ones that were already running
 *
 * Four near-identical copies of this write existed (App\Traits\CustomerTrait, App\Utils\OrderManager, App\Utils\CustomerManager
 * and App\Repositories\WalletTransactionRepository) and they did not agree. Every difference that any live caller could reach is
 * kept, and named, rather than quietly resolved — resolving one would move money on a path nobody asked about. The one
 * difference that is NOT kept is the choice between currencyConverter() and Convert::usd() for the final conversion: they are
 * the same formula (system default rate over the USD rate, dividing) and differ only when that rate is zero, where
 * currencyConverter() returns the amount unconverted and Convert::usd() raises DivisionByZeroError. currencyConverter() is used
 * throughout, as three of the four copies already did.
 */
final readonly class WalletPosting
{
    /** The ledger row's `balance` is the balance the account ends with, so a posting may not carry a bonus the balance would not. */
    private function __construct(
        public string $transactionType,
        public float $credit,
        public float $debit,
        public float $bonus,
        public ?string $reference,
        public ?string $paymentMethod,
    ) {
    }

    /**
     * The posting for a transaction type whose amount needs no conversion beyond the system's own.
     *
     * An unrecognised type credits and debits nothing and still writes a row, which is what all four copies did with one.
     *
     * @param array<string, mixed> $paymentData
     */
    public static function of(string $transactionType, float $amount, ?string $reference = null, array $paymentData = []): self
    {
        $credit = 0.0;
        $debit = 0.0;
        $bonus = 0.0;

        if ($transactionType === 'add_fund') {
            $credit = $amount;
            $bonus = (float) Helpers::add_fund_to_wallet_bonus(Convert::usd($amount));
        } elseif (in_array($transactionType, ['add_fund_by_admin', 'order_refund', 'return_order_amount_by_admin'], true)) {
            $credit = $amount;
        } elseif (in_array($transactionType, ['order_place', 'due_payment_for_order'], true)) {
            $debit = $amount;
        }

        return self::converted(
            transactionType: $transactionType,
            credit: $credit,
            debit: $debit,
            bonus: $bonus,
            reference: $reference,
            paymentData: $paymentData,
        );
    }

    /**
     * Points turned into wallet money the way the WEB controller has always turned them: through the session's exchange rate.
     *
     * This and loyaltyPointsConvertedInTheApi() give different answers for the same number of points, because the two copies
     * they come from did. Web went through loyaltyPointToLocalCurrency() and back out through localToDefaultCurrency(); the API
     * divided by the exchange-rate setting and multiplied by Convert::default(1). Under a single currency the web path divides
     * once more by session('currency_exchange_rate') and the API path does not. That disagreement is older than this class and
     * is not settled here: settling it would change what one of the two paths credits.
     *
     * Both formulas now live in {@see WhatAPointIsWorth}, which keeps them and hands each path its own, so that the day the owner
     * answers LF-1 the answer is one setting rather than a hunt through the call sites. Nothing about what either path credits today
     * is changed by that move: the figures are asserted unchanged in PointsToWalletFormulaCharacterizationTest.
     *
     * @param array<string, mixed> $paymentData
     */
    public static function loyaltyPointsConvertedOnTheWeb(float $points, ?string $reference = null, array $paymentData = []): self
    {
        return self::converted(
            transactionType: 'loyalty_point',
            credit: WhatAPointIsWorth::creditFor($points, WhatAPointIsWorth::WEB),
            debit: 0.0,
            bonus: 0.0,
            reference: $reference,
            paymentData: $paymentData,
        );
    }

    /**
     * Points turned into wallet money the way the API controllers have always turned them. See loyaltyPointsConvertedOnTheWeb()
     * for why there are two.
     *
     * @param array<string, mixed> $paymentData
     */
    public static function loyaltyPointsConvertedInTheApi(float $points, ?string $reference = null, array $paymentData = []): self
    {
        return self::converted(
            transactionType: 'loyalty_point',
            credit: WhatAPointIsWorth::creditFor($points, WhatAPointIsWorth::API),
            debit: 0.0,
            bonus: 0.0,
            reference: $reference,
            paymentData: $paymentData,
        );
    }

    /**
     * Puts the credit and debit into the currency the balance is stored in. The bonus is already in it: the bonus categories are
     * read with a converted amount and answer in store currency, so all four copies added it to the balance untouched.
     *
     * @param array<string, mixed> $paymentData
     */
    private static function converted(string $transactionType, float $credit, float $debit, float $bonus, ?string $reference, array $paymentData): self
    {
        return new self(
            transactionType: $transactionType,
            // One unit for the credit, whichever of the five writers is asked (E-16). Off as it ships: the amount is handed straight back.
            credit: WalletMinorUnit::credited(currencyConverter(amount: $credit)),
            debit: (float) currencyConverter(amount: $debit),
            bonus: $bonus,
            reference: $reference,
            paymentMethod: $paymentData['payment_method'] ?? null,
        );
    }
}
