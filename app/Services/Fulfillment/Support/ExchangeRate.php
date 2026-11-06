<?php

namespace App\Services\Fulfillment\Support;

/**
 * A rate from the store's currency table.
 *
 * The platform keeps `currencies.exchange_rate` as text (varchar(191): its own migration ex_rate_update.php changed it from a number),
 * so what is read is a string that is only as good as the person who typed it. A rate is accepted when it is a plain positive decimal
 * ("1", "3.75", "0.2666") of a believable size; anything else ("", "abc", "1,5", "1e3", "-2", "0", "0.0000000001") is no rate at all, and the amount that needed it is held
 * instead of being converted with a guess. The same rule judges the column in the compatibility report and every conversion at run time.
 */
final class ExchangeRate
{
    /** Digits, optionally a decimal point and more digits: no sign, no exponent, no thousands separator, no comma as the point. */
    private const PLAIN_DECIMAL = '/^\d{1,15}(?:\.\d{1,20})?$/D';

    /** Fences against an absurd value (a rate that turns every amount into nothing, or into billions); no real currency is outside them. */
    private const SMALLEST = 0.000001;

    private const LARGEST = 1000000000.0;

    public static function parse(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            $rate = (float) $value;

            return self::withinFences($rate);
        }

        if (!is_string($value)) {
            return null;
        }

        $text = trim($value);
        if (preg_match(self::PLAIN_DECIMAL, $text) !== 1) {
            return null;
        }

        return self::withinFences((float) $text);
    }

    private static function withinFences(float $rate): ?float
    {
        return is_finite($rate) && $rate >= self::SMALLEST && $rate <= self::LARGEST ? $rate : null;
    }
}
