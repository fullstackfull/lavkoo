<?php

namespace App\Services\PlatformAdmin;

use Illuminate\Http\Request;

/**
 * The page and the size of a page a list was asked for, held to bounds. A value that is not a positive whole number is the default, a page
 * size above the cap is the cap, and a page number above the last one the API will walk to is that one: a request cannot ask the database to skip
 * a billion rows to be told there is nothing there.
 */
final readonly class PlatformPagination
{
    public const MAX_PAGE = 1000;

    private function __construct(public int $page, public int $perPage)
    {
    }

    public static function fromRequest(Request $request, int $defaultPerPage, int $maxPerPage): self
    {
        return new self(
            page: self::boundedInteger(given: $request->query('page'), default: 1, max: self::MAX_PAGE),
            perPage: self::boundedInteger(given: $request->query('per_page'), default: $defaultPerPage, max: $maxPerPage),
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    private static function boundedInteger(mixed $given, int $default, int $max): int
    {
        $number = is_string($given) && ctype_digit($given) ? (int) $given : 0;

        return $number < 1 ? $default : min($number, $max);
    }
}
