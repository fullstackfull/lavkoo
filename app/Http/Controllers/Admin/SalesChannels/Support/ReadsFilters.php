<?php

namespace App\Http\Controllers\Admin\SalesChannels\Support;

/**
 * The query-string filters of the list screens, read so that a value nobody could have chosen (an array, an unknown code, an impossible date) is no filter at all instead of an
 * error or a different query.
 */
trait ReadsFilters
{
    /** @param list<string> $allowed */
    private function oneOf(string $key, array $allowed): ?string
    {
        $value = requestString($key);

        return in_array($value, $allowed, true) ? $value : null;
    }

    private function date(string $key): ?string
    {
        $value = requestString($key);

        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $value : null;
    }
}
