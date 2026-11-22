<?php

namespace App\Services\Pos;

use InvalidArgumentException;

/**
 * What one point-of-sale sale costs, added up in whole minor units.
 *
 * The tills used to charge the figure the browser posted in `amount`, while the server held the cart and could work
 * the figure out for itself. Two things were wrong with that, and this class is the second half of the answer (the
 * first half is the controllers asking for this instead of reading the request).
 *
 * THE ADDITION. The panel's own arithmetic is `$subtotal += $line` and `$totalTax += $tax` over binary floats, and
 * then one `floor()` at the currency's decimal places. Binary floats cannot hold 4.35, so seven of them add up to
 * 30.449999999999996 and the floor turns that into 30.44 — the shop loses a halala on a sale its own lines say costs
 * 30.45. Here every figure is rounded ONCE into an integer that counts GUARD_DIGITS below a minor unit, and from
 * there the sale is added up, discounted and floored entirely in integers. The rounding into that integer is the
 * technique App\Services\MyFatoorah\Money already documents: round away the binary noise before the cast, at a grain
 * far coarser than the noise and far finer than a minor unit.
 *
 * THE FLOOR. Flooring, not rounding, is deliberate: `usdToDefaultCurrency` floors, so every figure this platform
 * shows or posts is floored, and rounding here would move the money on sales where nothing was ever wrong. A sale
 * whose exact cost is 272.849 still costs 272.84. The only figures that move are the ones float addition had already
 * carried over a minor unit.
 *
 * MINOR UNITS ARE NOT ALWAYS CENTS. The currencies table holds a name, a symbol, a code and an exchange rate, and no
 * decimal places; the platform's one notion of a currency's exponent is the `decimal_point_settings` business
 * setting, which `defaultCurrencyDecimalPoints()` reads. Set to 3, a sale in a three-decimal Gulf currency (KWD, BHD,
 * OMR) is added up and floored in whole fils, not in whole cents.
 *
 * The figures handed in are the store's base-currency figures, as CartService produces them. The conversion to the
 * currency the sale is charged in happens once, at the end, with the rate `usdToDefaultCurrency` itself uses.
 */
final readonly class PosSaleTotal
{
    /**
     * Digits kept below a minor unit while the sale is added up.
     *
     * Six, the scale App\Services\OperationalCosts\Support\Money stores money at. A line's tax or its share of a
     * coupon is a division and has more precision than a minor unit, so the running total has to keep some; six
     * digits is far more than any one figure contributes and still leaves a 64-bit integer room for a total of
     * 9,000,000,000,000 minor units.
     */
    private const GUARD_DIGITS = 6;

    private function __construct(
        private int $exponent,
        private int $guardedSubtotal,
        private int $guardedTax,
        private int $guardedDiscounts,
    )
    {
    }

    /**
     * An empty sale, in the currency and the exponent the store charges in.
     */
    public static function inDefaultCurrency(): self
    {
        return new self(exponent: defaultCurrencyDecimalPoints(), guardedSubtotal: 0, guardedTax: 0, guardedDiscounts: 0);
    }

    /**
     * One cart line: what the line costs before tax, and the tax applied to it.
     *
     * The two are kept apart because a percentage extra discount is a share of what the LINES cost, not of what they
     * cost plus the VAT — which is what `CartService::getTotalCalculation` is handed `lineSubtotalInBaseCurrency()`
     * for.
     *
     * @param float|int|string|null $lineSubtotal CartService's `productSubtotal` — (price - discount) * quantity
     * @param float|int|string|null $appliedTaxAmount CartService's `appliedTaxAmount` for the same line
     */
    public function plusLine(float|int|string|null $lineSubtotal, float|int|string|null $appliedTaxAmount): self
    {
        return new self(
            exponent: $this->exponent,
            guardedSubtotal: $this->guardedSubtotal + $this->toGuarded($lineSubtotal),
            guardedTax: $this->guardedTax + $this->toGuarded($appliedTaxAmount),
            guardedDiscounts: $this->guardedDiscounts,
        );
    }

    /**
     * The discounts that belong to the cart rather than to a line, as `CartService::getTotalCalculation` resolves
     * them: a coupon, and an extra discount already turned from a percentage into an amount.
     */
    public function lessCartDiscounts(float|int|string|null $couponDiscount, float|int|string|null $extraDiscount): self
    {
        return new self(
            exponent: $this->exponent,
            guardedSubtotal: $this->guardedSubtotal,
            guardedTax: $this->guardedTax,
            guardedDiscounts: $this->guardedDiscounts + $this->toGuarded($couponDiscount) + $this->toGuarded($extraDiscount),
        );
    }

    /**
     * What the lines come to BEFORE tax and before the cart's own discounts, in the store's base currency.
     *
     * This is the figure `CartService::getTotalCalculation` needs to turn a percentage extra discount into an amount,
     * and it is the integer sum, not the float one.
     */
    public function lineSubtotalInBaseCurrency(): float
    {
        return $this->guardedSubtotal / (10 ** ($this->exponent + self::GUARD_DIGITS));
    }

    /**
     * The sale's cost in whole minor units of the currency it is charged in. This is the authoritative figure.
     */
    public function minorUnits(): int
    {
        $guarded = $this->guardedSubtotal + $this->guardedTax - $this->guardedDiscounts;
        $rate = defaultCurrencyRate();
        if ($rate !== 1.0) {
            // One multiplication, rounded straight back onto the guarded integer grid, so the floor below stays
            // integer arithmetic even when the store charges in a converted currency. A double holds an integer
            // exactly to 2^53, which at two decimal places is a sale of some ninety million: past that the
            // conversion, not the addition, is what loses precision.
            $guarded = (int) round($guarded * $rate);
        }

        $unit = 10 ** self::GUARD_DIGITS;

        // intdiv() truncates towards zero; a sale should never come to less than nothing, but a floor that is only a
        // floor for positive numbers is a trap for the next reader.
        return $guarded >= 0 ? intdiv($guarded, $unit) : -intdiv(-$guarded + $unit - 1, $unit);
    }

    /**
     * The same figure as a decimal amount in the currency the sale is charged in — what goes on the order and what
     * the wallet is asked for.
     */
    public function amount(): float
    {
        return $this->minorUnits() / (10 ** $this->exponent);
    }

    /**
     * A figure from the cart, rounded once onto the guarded integer grid.
     */
    private function toGuarded(float|int|string|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        $amount = (float) $amount;
        if (!is_finite($amount)) {
            throw new InvalidArgumentException('A cart line cannot cost a figure that is not a number.');
        }

        return (int) round($amount * (10 ** ($this->exponent + self::GUARD_DIGITS)));
    }
}
