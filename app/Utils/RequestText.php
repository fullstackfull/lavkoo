<?php

namespace App\Utils;

/**
 * A value a person typed, as the text it was typed as.
 *
 * A request may carry anything where a form field was expected: a list, an object, true, null, a JSON number. Casting that with (string)
 * is a 500 for a list, and the raw value in `where token = ?` is `IS NULL` for null and, under MySQL, every non-numeric token for the
 * number 0. A number is its digits; anything that is not one value is nothing.
 */
final class RequestText
{
    public static function of(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string)$value : '';
    }
}
