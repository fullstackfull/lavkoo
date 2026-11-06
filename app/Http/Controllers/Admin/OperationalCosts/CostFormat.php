<?php

namespace App\Http\Controllers\Admin\OperationalCosts;

use App\Services\OperationalCosts\Support\Money;

/**
 * How an amount, a percentage and a rule figure are shown on the cost screens. Money stays Money all the way here; it becomes text only at the very end,
 * and an amount that does not exist is a dash, never a zero.
 */
final class CostFormat
{
    public const DASH = '—';

    public static function amount(Money|string|null $amount, ?string $currency = null): string
    {
        if ($amount === null || $amount === '') {
            return self::DASH;
        }
        $text = Money::of($amount)->toDisplay();

        return $currency === null || $currency === '' ? $text : $text . ' ' . $currency;
    }

    public static function percent(Money|string|null $percent): string
    {
        return $percent === null || $percent === '' ? self::DASH : Money::of($percent)->toDisplay() . '%';
    }

    /** A figure typed into a rule (a weight, a limit, a percentage) without the padding zeros of storage: 2.500000 is shown as 2.5. */
    public static function figure(Money|string|null $value): string
    {
        if ($value === null || $value === '') {
            return self::DASH;
        }
        $text = Money::of($value)->toStorage();

        return rtrim(rtrim($text, '0'), '.');
    }
}
