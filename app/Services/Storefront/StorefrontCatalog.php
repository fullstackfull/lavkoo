<?php

namespace App\Services\Storefront;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Which products a storefront may show (GCC-02/03). Every product belongs to one country, so its price, stock and availability are that
 * country's own: nothing is shared with, or converted from, another country's copy of the product.
 *
 * It narrows only a storefront request while storefronts are on. A storefront request is one the middleware bound to a storefront, or one
 * it has not reached yet (the providers boot before any middleware) that would be bound to the storefront it asks for. The admin and
 * vendor panels, the seller APIs, queues and console commands work across every country, and while storefronts are off nothing is
 * narrowed and nothing about the resolver is read. A database whose migration has not run serves the default storefront as before and
 * shows no product to any other.
 */
final class StorefrontCatalog
{
    public const COLUMN = 'storefront_country';

    private const TABLE = 'products';

    /** What the panels and the seller APIs answer. They work across every country. */
    private const PANEL_PATHS = ['admin', 'admin/*', 'vendor', 'vendor/*', 'seller', 'seller/*', 'api/v2', 'api/v2/*', 'api/v3', 'api/v3/*'];

    /** How long a "no" is remembered, so a worker started before the migration notices it without a restart. A "yes" lasts for the process. */
    private const NEGATIVE_ANSWER_SECONDS = 30;

    /** @var array{value: bool, at: int}|null */
    private static ?array $columnAnswer = null;

    public static function restrict(Builder $query): Builder
    {
        $country = self::country();
        if ($country === null) {
            return $query;
        }

        if (self::columnReady()) {
            return $query->where($query->qualifyColumn(self::COLUMN), $country);
        }

        return self::isDefaultCountry($country) ? $query : $query->whereRaw('1 = 0');
    }

    /** Whether the storefront of the request may sell this product: the cart asks before it takes a product, whatever route reached it. */
    public static function offers(?Model $product): bool
    {
        $country = self::country();
        if ($country === null || $product === null) {
            return true;
        }

        if (self::columnReady()) {
            return $product->getAttribute(self::COLUMN) === $country;
        }

        return self::isDefaultCountry($country);
    }

    /**
     * The key a cache entry built from the catalog is written under. A storefront request writes under its storefront's key, which is the
     * plain key for the Saudi storefront. A request that works across every country (the admin and vendor panels, the seller APIs, a queue,
     * a console command) gets a key of its own, so it never fills the Saudi storefront's entry with other countries' products. While
     * storefronts are off every key is the plain key and nothing about the resolver is read.
     */
    public static function cacheKey(string $key): string
    {
        if (!config('gcc.storefronts.enabled')) {
            return $key;
        }

        return self::context()?->scopedCacheKey($key) ?? self::unboundKey($key);
    }

    /** @return list<string> every key the entry can be under: the plain key, and while storefronts are on, each storefront's and the unbound one. */
    public static function cacheKeysForEveryStorefront(string $key): array
    {
        if (!config('gcc.storefronts.enabled')) {
            return [$key];
        }

        return [...StorefrontContext::keysForEveryStorefront($key), self::unboundKey($key)];
    }

    public static function flush(): void
    {
        self::$columnAnswer = null;
    }

    /** The country this request is narrowed to, or null when nothing is narrowed: storefronts off, a panel, a seller API, a queue or a console command. */
    public static function country(): ?string
    {
        return self::context()?->country;
    }

    private static function context(): ?StorefrontContext
    {
        if (!config('gcc.storefronts.enabled')) {
            return null;
        }

        return app()->bound(StorefrontContext::class) ? StorefrontContext::current() : self::contextOfUnboundRequest();
    }

    private static function contextOfUnboundRequest(): ?StorefrontContext
    {
        $request = request();
        if (app()->runningInConsole() || $request->is(...self::PANEL_PATHS)) {
            return null;
        }

        return StorefrontResolver::fromConfig()->contextForRequest(request: $request);
    }

    private static function unboundKey(string $key): string
    {
        return "storefront:*:{$key}";
    }

    private static function isDefaultCountry(string $country): bool
    {
        return $country === StorefrontResolver::fromConfig()->defaultCountry();
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
