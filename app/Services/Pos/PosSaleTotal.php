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
    private const GUARD_DIGITS = MONEY_NOISE_DIGITS;

    private function __construct(
        private int $exponent,
        private int $guardedSubtotal,
        private int $guardedTax,
        private int $guardedDiscounts,
        private int $lineCount,
    )
    {
    }

    /**
     * An empty sale, in the currency and the exponent the store charges in.
     */
    public static function inDefaultCurrency(): self
    {
        // The setting is clamped HERE and not inside defaultCurrencyDecimalPoints(), because that helper feeds
        // usdToDefaultCurrency, which every money figure on every screen passes through and whose answer must not
        // change for a setting the admin form allows (reviewer B, F4). The bound belongs to the integer grid: below
        // zero the exponent would multiply a sale instead of dividing it, and above MAX_CURRENCY_DECIMAL_POINTS the
        // guarded integer runs out of 64-bit room sooner than the bound in toGuarded() admits.
        $exponent = max(0, min(MAX_CURRENCY_DECIMAL_POINTS, defaultCurrencyDecimalPoints()));

        return new self(exponent: $exponent, guardedSubtotal: 0, guardedTax: 0, guardedDiscounts: 0, lineCount: 0);
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
            guardedSubtotal: $this->addGuarded($this->guardedSubtotal, $this->toGuarded($lineSubtotal)),
            guardedTax: $this->addGuarded($this->guardedTax, $this->toGuarded($appliedTaxAmount)),
            guardedDiscounts: $this->guardedDiscounts,
            lineCount: $this->lineCount + 1,
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
            guardedDiscounts: $this->addGuarded(
                $this->addGuarded($this->guardedDiscounts, $this->toGuarded($couponDiscount)),
                $this->toGuarded($extraDiscount),
            ),
            lineCount: $this->lineCount,
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
     * How many cart lines the sale could actually be priced from.
     *
     * A cart line whose product is no longer in the catalogue is skipped, by this class's caller and by the sale's own
     * loop alike. Zero priced lines means the sale has nothing to sell, which the seller app's till already refuses
     * and the two panel tills did not: they wrote an order row with no lines and charged the posted figure for it.
     */
    public function lineCount(): int
    {
        return $this->lineCount;
    }

    /**
     * The sale's TAX in whole minor units of the currency it is charged in.
     *
     * `orders.total_tax_amount` used to be a second float accumulation of the same per-line figures the sale's total
     * is built from, so an order charged in whole minor units recorded a tax that was not — 13.563 on a two-decimal
     * store (reviewer A, F4; reviewer B, F3). This is the same integer the total already holds.
     */
    public function taxInMinorUnits(): int
    {
        return $this->toMinorUnits($this->guardedTax);
    }

    /**
     * The sale's tax as a decimal amount in the currency the sale is charged in.
     */
    public function taxAmount(): float
    {
        return $this->taxInMinorUnits() / (10 ** $this->exponent);
    }

    /**
     * The sale's cost in whole minor units of the currency it is charged in. This is the authoritative figure.
     */
    public function minorUnits(): int
    {
        return $this->toMinorUnits($this->guardedSubtotal + $this->guardedTax - $this->guardedDiscounts);
    }

    /**
     * A figure on the guarded integer grid, converted once and floored to whole minor units.
     */
    private function toMinorUnits(int $guarded): int
    {
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
        // floor for positive numbers is a trap for the next reader. Reviewer A was right that the unguarded cast in
        // toGuarded() used to make this branch reachable with a fractional minor unit, by overflowing to a large
        // NEGATIVE integer; the bound added there is what shuts that door, and this stays a floor regardless.
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

        $factor = 10 ** ($this->exponent + self::GUARD_DIGITS);

        // The bound this class's own comment claimed and did not enforce. `is_finite()` is true of 9.3e18, and
        // `(int) round(9.3e18)` in PHP is -9146744073709551616: reviewer A measured a cash sale of 93,000,000,000
        // units writing orders.order_amount = -91467440737.10 and taking the stock for it, which is the very failure
        // the non-positive wallet guard was added to prevent. A line that cannot be counted is refused, not wrapped.
        if (abs($amount) > PHP_INT_MAX / $factor) {
            throw new InvalidArgumentException('A cart line costs more than this sale can be counted in.');
        }

        return (int) round($amount * $factor);
    }

    /**
     * Two figures on the guarded grid, added without leaving the integers.
     *
     * The per-line bound above is not enough on its own, which I found by attacking my own fix for reviewer A's F5:
     * PHP does not WRAP an integer overflow, it turns the sum into a float, and a float handed to this class's
     * int-typed constructor raises a TypeError — which the till would answer with a 500 the cashier never sees, since
     * neither panel script has an `error:` callback. Measured on two lines each just inside the per-line bound. So
     * the bound this class claims is now the bound it keeps for a cart of any number of lines, not only for one.
     */
    private function addGuarded(int $running, int $addition): int
    {
        if ($addition > 0 ? $running > PHP_INT_MAX - $addition : $running < PHP_INT_MIN - $addition) {
            throw new InvalidArgumentException('This cart costs more than the sale can be counted in.');
        }

        return $running + $addition;
    }
}
