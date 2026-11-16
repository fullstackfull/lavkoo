<?php

namespace App\Services\Storefront;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Which products a storefront may show (GCC-02/03). Every product belongs to one country, so its price, stock and availability are that
 * country's own: nothing is shared with, or converted from, another country's copy of the product.
 *
 * It narrows only a request that was bound to a storefront while storefronts are on. Queues, console commands, the admin panel and every
 * request while storefronts are off see the catalog as they always did. A database whose migration has not run serves the default
 * storefront as before and shows no product to any other.
 */
final class StorefrontCatalog
{
    public const COLUMN = 'storefront_country';

    private const TABLE = 'products';

    /** How long a "no" is remembered, so a worker started before the migration notices it without a restart. A "yes" lasts for the process. */
    private const NEGATIVE_ANSWER_SECONDS = 30;

    /** @var array{value: bool, at: int}|null */
    private static ?array $columnAnswer = null;

    public static function restrict(Builder $query): Builder
    {
        $resolver = StorefrontResolver::fromConfig();
        if (!$resolver->isEnabled() || !app()->bound(StorefrontContext::class)) {
            return $query;
        }

        $country = StorefrontContext::current()->country;
        if (self::columnReady()) {
            return $query->where($query->qualifyColumn(self::COLUMN), $country);
        }

        return $country === $resolver->defaultCountry() ? $query : $query->whereRaw('1 = 0');
    }

    public static function flush(): void
    {
        self::$columnAnswer = null;
    }

    private static function columnReady(): bool
    {
        $answer = self::$columnAnswer;
        if ($answer !== null && ($answer['value'] || time() - $answer['at'] < self::NEGATIVE_ANSWER_SECONDS)) {
            return $answer['value'];
        }

        $ready = Schema::hasColumn(self::TABLE, self::COLUMN);
        self::$columnAnswer = ['value' => $ready, 'at' => time()];

        return $ready;
    }
}
