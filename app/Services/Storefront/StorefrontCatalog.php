<?php

namespace App\Services\Storefront;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
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

    /** The company that sells a product, once products carry one (D1-03). No migration adds it yet, so the company half of a selling scope is dormant. */
    public const ENTITY_COLUMN = 'legal_entity_id';

    private const TABLE = 'products';

    /** What the panels and the seller APIs answer. They work across every country. */
    private const PANEL_PATHS = ['admin', 'admin/*', 'vendor', 'vendor/*', 'seller', 'seller/*', 'api/v2', 'api/v2/*', 'api/v3', 'api/v3/*'];

    /** How long a "no" is remembered, so a worker started before the migration notices it without a restart. A "yes" lasts for the process. */
    private const NEGATIVE_ANSWER_SECONDS = 30;

    /** @var array{value: bool, at: int}|null */
    private static ?array $columnAnswer = null;

    public static function restrict(Builder $query): Builder
    {
        return self::narrowTo(query: $query, column: $query->qualifyColumn(self::COLUMN));
    }

    /** For a plain query that joins the products table, as the checkout guard does: it reads the cart without going through the Product model. */
    public static function restrictJoinedProducts(QueryBuilder $query): QueryBuilder
    {
        return self::narrowTo(query: $query, column: self::TABLE . '.' . self::COLUMN);
    }

    /** For a plain query over a table that holds product ids, such as the search index: it is narrowed before any cap on the number of rows. */
    public static function restrictProductIds(QueryBuilder $query, string $column): QueryBuilder
    {
        if (!self::narrows()) {
            return $query;
        }

        return $query->whereIn($column, fn (QueryBuilder $products) => self::narrowTo(query: $products->select('id')->from(self::TABLE), column: self::COLUMN));
    }

    /**
     * A cart only holds, shows and changes the rows of the storefront it is used from, so a customer who shops two countries has two separate
     * carts. A row whose product no longer exists belongs to no country, so it stays reachable: it can be removed, and a checkout cleans it up.
     */
    public static function restrictCartRows(Builder $cartRows): Builder
    {
        if (!self::narrows()) {
            return $cartRows;
        }

        return $cartRows->where(fn (Builder $rows) => $rows
            ->whereHas('allProducts', fn (Builder $products) => self::restrict(query: $products))
            ->orWhereDoesntHave('allProducts'));
    }

    /**
     * Runs an operation that is cross-storefront by nature, such as moving a guest's cart into an account at login, once in every
     * storefront: the rows of each country go to the cart of that country, and none is left behind because the request was served from
     * another one. Storefronts that are switched off, and a request that is not a storefront request, run it once as it always ran.
     */
    public static function inEveryStorefront(Closure $operation): void
    {
        if (!self::narrows()) {
            $operation();

            return;
        }

        $resolver = StorefrontResolver::fromConfig();
        foreach ($resolver->configuredCountries() as $country) {
            $resolver->contextOfRecordedCountry($country)?->serve(callback: $operation);
        }
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
     * Who sells this product: the country it belongs to, and the company that sells it once products carry one. Two products with the same
     * scope may be invoiced on one order; two with different scopes may not, whichever request is asking and whether or not storefronts are
     * switched on. An order asks this of its rows one last time before it is written (OneSellingCompanyPerOrder).
     *
     * Both halves are read the same way, off the row the caller already loaded, so the company half comes on by itself the day a migration
     * adds the column and costs nothing until then — no schema is read and no query is made, which is what keeps it off the Saudi checkout's
     * query count. A product loaded without these columns therefore says nothing about who sells it, which is also the answer before the
     * country migration has run: the database the platform has today.
     */
    public static function sellingScopeOf(?Model $product): ?string
    {
        if ($product === null) {
            return null;
        }

        $scope = [];
        foreach ([self::COLUMN, self::ENTITY_COLUMN] as $column) {
            $value = $product->getAttribute($column);

            if ($value !== null) {
                $scope[] = $column . '=' . $value;
            }
        }

        return $scope === [] ? null : implode('/', $scope);
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

    /** The country the products of a cart group belong to, for a link that must open the storefront the cart was made in. Null while storefronts are off. */
    public static function countryOfCartGroup(string $cartGroupId): ?string
    {
        if (!config('gcc.storefronts.enabled') || !self::columnReady()) {
            return null;
        }

        return DB::table('carts')
            ->join(self::TABLE, self::TABLE . '.id', '=', 'carts.product_id')
            ->where('carts.cart_group_id', $cartGroupId)
            ->orderBy('carts.id')
            ->value(self::TABLE . '.' . self::COLUMN);
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

    /** Whether a query over products is narrowed at all for this request: not while storefronts are off, for a panel, a seller API or a queue, nor for the default storefront before the migration has run. */
    private static function narrows(): bool
    {
        $country = self::country();

        return $country !== null && (self::columnReady() || !self::isDefaultCountry($country));
    }

    private static function narrowTo(Builder|QueryBuilder $query, string $column): Builder|QueryBuilder
    {
        $country = self::country();
        if ($country === null) {
            return $query;
        }

        if (self::columnReady()) {
            return $query->where($column, $country);
        }

        return self::isDefaultCountry($country) ? $query : $query->whereRaw('1 = 0');
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
