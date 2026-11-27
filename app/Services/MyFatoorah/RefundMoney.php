<?php

namespace App\Services\MyFatoorah;

use App\Services\Storefront\StorefrontCurrency;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What a refund is worth, counted in the smallest coin the currency it is paid back in actually has (D2-10, option A).
 *
 * **The two defects this exists for, both measured.** `RefundService` rounded every refund to two decimals and compared the headroom in
 * whole halalas. Three of the six markets price in thousandths -- 1 KWD is 1,000 fils, 1 BHD 1,000 fils, 1 OMR 1,000 baisa, which is ISO
 * 4217 and what `gcc.storefronts.currency.decimals` says -- and MyFatoorah is a Kuwaiti gateway, so both of these are reachable rather
 * than theoretical:
 *
 *  - a refund of 10.234 KWD was sent and stored as 10.23: four fils the customer is owed and does not get;
 *  - a payment of 10.005 KWD read as 10.01 of headroom: five fils MORE than was ever paid, so the platform could refund more than it took.
 *
 * The second is the one that costs money and it is the reason the rounding here is **never upwards**. A cap that rounds up is not a cap.
 * Both the amount asked for and the headroom left are taken DOWN to the currency's smallest coin: a fraction of a fils cannot be paid to
 * anybody, and the only thing rounding it up can do is hand out a coin nobody asked for or lift a ceiling above what was received.
 *
 * **Why this is not {@see Money}, which is the whole of option A.** That class compares amounts for the payment VERIFICATION path, and its
 * own docblock says it works in whole halalas because the riyal has two decimals. Widening it would change what decides whether a payment
 * is accepted, where a comparison that answers wrongly releases an order that was underpaid -- a bigger money change than this one, with
 * its own evidence to gather. So refunds get their own exact handling and verification keeps the behaviour it has today, untouched.
 *
 * **Why it is not {@see \App\Services\OperationalCosts\Support\Money} either**, which would otherwise be the obvious reuse: every rounding
 * in that class is HALF_UP and its storage scale is six places. Rounding a refund cap up is the defect above, and six places is not a
 * number of places any currency has. The exact-decimal arithmetic underneath is the same library both use.
 *
 * How many places a currency has is NOT decided here: it comes from `StorefrontCurrency::decimalsOf()`, the one authority on it, which the
 * provider shipments, the Odoo postings, the wallet debit and the gateway list all read, and which answers two for every currency it is not
 * told about. A refund in SAR, AED or QAR is therefore two places whatever the switch says, because those currencies have two.
 *
 * **The switch ships OFF** (`gcc.refunds.gateway_refunds_in_the_currencys_minor_unit`). While it is off, every figure on this path is
 * produced by `round($amount, 2)` and compared in halalas exactly as it was before this class existed -- that is what `asItAlwaysWas()` is
 * for, and it is the first thing the tests assert. Turning it on is a financial change and is the owner's to make.
 */
final class RefundMoney
{
    /** The switch. Off as it ships: a refund is rounded to two decimals, for every currency, exactly as it always has been. */
    public const EXACT_MINOR_UNIT_SWITCH = 'gcc.refunds.gateway_refunds_in_the_currencys_minor_unit';

    /** What a refund has always been rounded at, and what the riyal, the dirham and the Qatari riyal have. */
    public const TWO_PLACES = 2;

    /**
     * Decimals kept while a binary float is turned into an exact decimal.
     *
     * Ten, which is past what a double can carry for an amount of money, so the conversion neither invents a digit nor rounds one away
     * before the currency's own scale is applied. Rounding twice is how 10.0049 would become 10.005 and then 10.005 of headroom.
     */
    private const FLOAT_DIGITS = 10;

    private function __construct(
        private readonly BigDecimal $amount,
        private readonly int $places,
    ) {
    }

    /** What an operator asked to refund, at the smallest coin the currency has and never a fraction of one more. */
    public static function asked(float|int|string|null $amount, ?string $currencyCode): self
    {
        if (!self::exactHandlingIsOn()) {
            return self::asItAlwaysWas($amount);
        }

        $places = self::places($currencyCode);

        return new self(amount: self::decimal($amount)->toScale($places, RoundingMode::DOWN), places: $places);
    }

    /**
     * How much of a payment may still be refunded: what was actually paid, less every refund already asked for.
     *
     * Rounded DOWN and nowhere else, which is the over-refund guard. A payment of 10.005 leaves 10.005 to refund and never 10.01, and a
     * column that happens to hold a third decimal for a two-place currency leaves 10.00 rather than lifting the ceiling by a halala.
     */
    public static function headroom(
        float|int|string|null $paid,
        float|int|string|null $alreadyAsked,
        ?string $currencyCode,
    ): self {
        if (!self::exactHandlingIsOn()) {
            return self::asItAlwaysWas((float) $paid - (float) $alreadyAsked);
        }

        $places = self::places($currencyCode);

        return new self(
            amount: self::decimal($paid)->minus(self::decimal($alreadyAsked))->toScale($places, RoundingMode::DOWN),
            places: $places,
        );
    }

    /**
     * The decimals a refund in this currency is counted in: the currency's own while the exact handling is on, two otherwise.
     *
     * A refund whose currency is not recorded is two places as well. Guessing a scale for an amount whose currency nobody wrote down is
     * how a riyal figure would be read as a dinar one.
     */
    public static function places(?string $currencyCode): int
    {
        if (!self::exactHandlingIsOn()) {
            return self::TWO_PLACES;
        }

        $code = trim((string) $currencyCode);

        return $code === '' ? self::TWO_PLACES : app(StorefrontCurrency::class)->decimalsOf($code);
    }

    /**
     * Is this more than may still be refunded?
     *
     * Exact, so a refund of exactly the headroom is allowed and one coin more is refused. While the switch is off both sides have already
     * been rounded to two places, so this answers precisely what comparing them in whole halalas answered.
     */
    public function exceeds(self $headroom): bool
    {
        return $this->amount->compareTo($headroom->amount) > 0;
    }

    public function isPositive(): bool
    {
        return $this->amount->isPositive();
    }

    /** The figure the provider is asked for. */
    public function toFloat(): float
    {
        return $this->amount->toFloat();
    }

    /** The exact string the money column receives. */
    public function toStorage(): string
    {
        return (string) $this->amount;
    }

    /** The figure as an operator is shown it, written the way every amount on this path has been written. */
    public function forHumans(): string
    {
        return number_format($this->amount->toFloat(), $this->places);
    }

    private static function exactHandlingIsOn(): bool
    {
        return filter_var(config(self::EXACT_MINOR_UNIT_SWITCH, false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * `round($amount, 2)`, which is what this path did before this class existed and what it still does while the switch is off.
     *
     * PHP's own `round()` is called rather than reproduced, so the figures while the switch is off are identical by construction and not by
     * an argument about which rounding mode matches which.
     */
    private static function asItAlwaysWas(float|int|string|null $amount): self
    {
        return new self(
            amount: BigDecimal::of(number_format(round((float) $amount, self::TWO_PLACES), self::TWO_PLACES, '.', '')),
            places: self::TWO_PLACES,
        );
    }

    private static function decimal(float|int|string|null $amount): BigDecimal
    {
        if ($amount === null || $amount === '') {
            return BigDecimal::zero();
        }

        return BigDecimal::of(is_float($amount)
            ? number_format($amount, self::FLOAT_DIGITS, '.', '')
            : (string) $amount);
    }
}
