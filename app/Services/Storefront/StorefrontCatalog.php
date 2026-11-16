<?php

namespace App\Services\Storefront;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
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

    /** A cart only holds, shows and changes the rows of the storefront it is used from, so a customer who shops two countries has two separate carts. */
    public static function restrictCartRows(Builder $cartRows): Builder
    {
        if (!self::narrows()) {
            return $cartRows;
        }

        return $cartRows->whereHas('allProducts', fn (Builder $products) => self::restrict(query: $products));
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

    public static function flush(): void
    {
        self::$columnAnswer = null;
    }

    private static function country(): ?string
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
