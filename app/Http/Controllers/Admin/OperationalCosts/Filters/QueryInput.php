<?php

namespace App\Http\Controllers\Admin\OperationalCosts\Filters;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Reads the filters of a screen from the query string. Whatever is not what the filter expects (a list where a word was asked for, a date that is not a date)
 * is the same as no filter at all: a hand-made URL can narrow a screen, it can not break one.
 */
final class QueryInput
{
    private const TEXT_LIMIT = 120;

    public static function text(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, self::TEXT_LIMIT) : null;
    }

    public static function integer(Request $request, string $key): ?int
    {
        $value = $request->query($key);

        return is_string($value) && ctype_digit($value) && strlen($value) <= 18 ? (int) $value : null;
    }

    public static function flag(Request $request, string $key): bool
    {
        return $request->query($key) === '1';
    }

    public static function date(Request $request, string $key): ?CarbonImmutable
    {
        $value = $request->query($key);
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1 || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return CarbonImmutable::create((int) $parts[1], (int) $parts[2], (int) $parts[3])->startOfDay();
    }

    /** Was the filter sent at all, even empty? A form that is submitted with an empty field says "no limit" where a first visit says nothing. */
    public static function sent(Request $request, string $key): bool
    {
        return $request->query->has($key);
    }
}
