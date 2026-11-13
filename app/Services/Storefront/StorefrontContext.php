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

    public function scopedCacheKey(string $key): string
    {
        if (!$this->storefrontsEnabled || $this->country === self::UNSCOPED_COUNTRY) {
            return $key;
        }

        return "storefront:{$this->country}:{$key}";
    }
}
