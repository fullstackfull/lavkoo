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
        $scopes = self::sellingScopesOf(cartRows: $cartRows);

        if (count($scopes) > 1) {
            throw new OrderMixesSellingCompaniesException(sellingScopes: $scopes);
        }
    }

    /** @param iterable<Cart> $cartRows the checked rows of the order, with their products loaded */
    public static function spansMoreThanOne(iterable $cartRows): bool
    {
        return count(self::sellingScopesOf(cartRows: $cartRows)) > 1;
    }

    /**
     * The distinct selling scopes the rows name. A row whose product is gone, and every row before the country migration has run, names
     * none: it says nothing about who sells it, so it can neither agree nor disagree with another row.
     *
     * @param iterable<Cart> $cartRows
     * @return list<string>
     */
    private static function sellingScopesOf(iterable $cartRows): array
    {
        $scopes = [];
        foreach ($cartRows as $cartRow) {
            $scope = StorefrontCatalog::sellingScopeOf(product: $cartRow->product);

            if ($scope !== null && !in_array($scope, $scopes, true)) {
                $scopes[] = $scope;
            }
        }

        return $scopes;
    }
}
