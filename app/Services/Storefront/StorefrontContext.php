<?php

namespace App\Services\Storefront;

/**
 * The storefront one request is served from. The Saudi storefront owns every cache key that exists today, so its keys, and
 * every key while storefronts are switched off, stay exactly as they are.
 */
final readonly class StorefrontContext
{
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
