<?php

namespace App\Services\Storefront;

use Closure;

/**
 * The storefront one request is served from. The Saudi storefront owns every cache key that exists today, so its keys, and
 * every key while storefronts are switched off, stay exactly as they are.
 */
final readonly class StorefrontContext
{
    /**
     * The market that owns the UNPREFIXED cache key: the market whose entries this platform already had cached before storefronts existed.
     *
     * A literal, deliberately, and not {@see StorefrontResolver::defaultCountry()}. Reading the configured default market here would mean
     * that changing it -- which owner decision A1-03 makes a recorded, changeable decision -- handed the new default market the bare key,
     * and the bare key holds entries built from the Saudi catalogue. The new market would read another market's cached lists.
     *
     * What it IS coupled to is the pre-migration half of {@see StorefrontCatalog}: before the `storefront_country` column exists, the
     * unmarked catalogue rows are served to the CONFIGURED default market, so the market that owns the bare key and the market that owns
     * the unmarked rows have to be the same one. They are, while the default market is Saudi Arabia.
     * `StorefrontCacheKeyOwnershipTest::test_the_unprefixed_key_belongs_to_the_market_the_platform_serves_by_default` fails on any other
     * configuration rather than letting it ship quietly, and moving the default market means moving both halves together.
     */
    private const UNSCOPED_COUNTRY = 'SA';

    public function __construct(
        public string $country,
        public string $currency,
        private bool $storefrontsEnabled,
    ) {
    }

    /** The context the request was bound to, or the default storefront when none was bound: queues, console commands and tests. */
    public static function current(): self
    {
        return app()->bound(self::class) ? app(self::class) : StorefrontResolver::fromConfig()->context();
    }

    /**
     * Runs the callback in this storefront, then puts the request's own storefront back: a queue worker or a recovery runs one job after
     * another in one process, so a storefront left bound would narrow the next one.
     */
    public function serve(Closure $callback): mixed
    {
        $requestContext = app()->bound(self::class) ? app(self::class) : null;
        app()->instance(self::class, $this);

        try {
            return $callback();
        } finally {
            $requestContext === null ? app()->forgetInstance(self::class) : app()->instance(self::class, $requestContext);
        }
    }

    /**
     * The key the Saudi storefront keeps, followed by the key each other configured storefront writes under. Invalidation forgets all
     * of them, so a publish or a category change reaches every market, whether or not the storefronts are switched on.
     *
     * @return list<string>
     */
    public static function keysForEveryStorefront(string $key): array
    {
        $keys = [$key];

        foreach (StorefrontResolver::fromConfig()->configuredCountries() as $country) {
            if ($country !== self::UNSCOPED_COUNTRY) {
                $keys[] = self::scoped($country, $key);
            }
        }

        return $keys;
    }

    /**
     * The keys that the given storefronts write one key under: the Saudi storefront's own key, and the key each other one is scoped to. A publish of one storefront's theme forgets
     * these and no others.
     *
     * @param list<string> $countries
     *
     * @return list<string>
     */
    public static function keysForStorefronts(array $countries, string $key): array
    {
        return array_values(array_unique(array_map(
            static fn (string $country): string => strtoupper($country) === self::UNSCOPED_COUNTRY ? $key : self::scoped(strtoupper($country), $key),
            $countries,
        )));
    }

    public function scopedCacheKey(string $key): string
    {
        if (!$this->storefrontsEnabled || $this->country === self::UNSCOPED_COUNTRY) {
            return $key;
        }

        return self::scoped($this->country, $key);
    }

    private static function scoped(string $country, string $key): string
    {
        return "storefront:{$country}:{$key}";
    }
}
