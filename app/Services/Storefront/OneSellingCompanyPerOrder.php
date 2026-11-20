<?php

namespace App\Services\Storefront;

use App\Exceptions\OrderMixesSellingCompaniesException;
use App\Models\Cart;

/**
 * An order names one country and one selling company (GCC-13, D1-02). It is the last line of defence behind the cart, which already keeps
 * a storefront's rows apart: this rule is read off the rows that are actually about to be ordered, so it holds for the two cases the cart
 * cannot cover — storefronts switched off, when none of the storefront-aware cart logic runs, and a request the resolver never bound, such
 * as the hook of a payment that recorded no storefront, a queue worker or a console command.
 *
 * It judges the rows of the order in front of it, never the customer's whole cart. A legacy cart group that holds two countries is still
 * checked out per storefront: a storefront request reads only its own country's rows, so what reaches this rule is one country at a time.
 * What it refuses is the single order that would carry both.
 *
 * Every writer asks the same question of the same products; only the plumbing differs. The storefront checkout holds cart rows, the two
 * point-of-sale screens hold the products of a session cart, the seller app's point of sale holds the products of a request payload and
 * the channel materializer holds the products of a channel row, so the rule is asked either way round and reads one scope either way.
 *
 * Saudi Arabia never meets it. Every product there carries one country, so the checked rows of any order name one selling scope.
 */
final class OneSellingCompanyPerOrder
{
    /**
     * @param iterable<Cart> $cartRows the checked rows of the order, with their products loaded
     * @throws OrderMixesSellingCompaniesException
     */
    public static function assertRowsBelongToOne(iterable $cartRows): void
    {
        self::assertProductsBelongToOne(products: self::productsOf(cartRows: $cartRows));
    }

    /** @param iterable<Cart> $cartRows the checked rows of the order, with their products loaded */
    public static function spansMoreThanOne(iterable $cartRows): bool
    {
        return self::productsSpanMoreThanOne(products: self::productsOf(cartRows: $cartRows));
    }

    /**
     * For a writer that holds the products themselves rather than cart rows: the point of sale, where the order's lines are a session
     * cart or a request payload and the products have already been loaded to be priced.
     *
     * @param iterable<object|null> $products the products of the lines about to be written
     * @throws OrderMixesSellingCompaniesException
     */
    public static function assertProductsBelongToOne(iterable $products): void
    {
        $scopes = self::sellingScopesOf(products: $products);

        if (count($scopes) > 1) {
            throw new OrderMixesSellingCompaniesException(sellingScopes: $scopes);
        }
    }

    /** @param iterable<object|null> $products the products of the lines about to be written */
    public static function productsSpanMoreThanOne(iterable $products): bool
    {
        return count(self::sellingScopesOf(products: $products)) > 1;
    }

    /**
     * @param iterable<Cart> $cartRows
     * @return iterable<object|null>
     */
    private static function productsOf(iterable $cartRows): iterable
    {
        foreach ($cartRows as $cartRow) {
            yield $cartRow->product;
        }
    }

    /**
     * The distinct selling scopes the products name. A row whose product is gone, and every product before the country migration has run,
     * names none: it says nothing about who sells it, so it can neither agree nor disagree with another.
     *
     * @param iterable<object|null> $products
     * @return list<string>
     */
    private static function sellingScopesOf(iterable $products): array
    {
        $scopes = [];
        foreach ($products as $product) {
            $scope = StorefrontCatalog::sellingScopeOf(product: $product);

            if ($scope !== null && !in_array($scope, $scopes, true)) {
                $scopes[] = $scope;
            }
        }

        return $scopes;
    }
}
