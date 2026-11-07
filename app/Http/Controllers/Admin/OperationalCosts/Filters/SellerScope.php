<?php

namespace App\Http\Controllers\Admin\OperationalCosts\Filters;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Whose books a screen shows. Every cost row carries the seller it belongs to (0 is the platform's own), and rows of different sellers are never added together
 * unless the person asks for all of them: the default is the platform.
 */
final class SellerScope
{
    public const PLATFORM = 0;

    public const ALL = 'all';

    private const OPTION_LIMIT = 100;

    /** @return int|null the seller whose books are shown; null for every seller */
    public static function resolve(Request $request): ?int
    {
        $requested = $request->query('seller');
        if ($requested === self::ALL) {
            return null;
        }

        return is_string($requested) && ctype_digit($requested) ? (int) $requested : self::PLATFORM;
    }

    /**
     * @template TBuilder of EloquentBuilder|QueryBuilder
     * @param TBuilder $query
     * @return TBuilder
     */
    public static function apply(EloquentBuilder|QueryBuilder $query, ?int $seller, string $column = 'seller_id'): EloquentBuilder|QueryBuilder
    {
        return $seller === null ? $query : $query->where($column, $seller);
    }

    /**
     * The sellers a table holds rows of, the platform first.
     *
     * @return list<int>
     */
    public static function options(string $table): array
    {
        $sellers = DB::table($table)->distinct()->orderBy('seller_id')->limit(self::OPTION_LIMIT)->pluck('seller_id')->map(static fn ($seller): int => (int) $seller)->all();

        return array_values(array_unique([self::PLATFORM, ...$sellers]));
    }

    /** The value of the `seller` query parameter that selects this scope. */
    public static function parameter(?int $seller): string
    {
        return $seller === null ? self::ALL : (string) $seller;
    }

    public static function label(int $seller): string
    {
        return $seller === self::PLATFORM ? (string) translate('platform') : translate('seller') . ' #' . $seller;
    }
}
