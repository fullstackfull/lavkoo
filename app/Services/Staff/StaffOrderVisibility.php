<?php

namespace App\Services\Staff;

use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Which orders and refund requests the countries of an employee reach (GCC-14c, slice 2), written once for the global scopes and for the checks of an id.
 *
 * An order carries no country and no currency: it follows from its lines, and a line follows its product (`products.storefront_country`). A restricted employee sees an order
 * only when it has at least one line and EVERY line's product exists and belongs to one of their countries, so an order that holds two countries is visible only to an
 * employee who holds both, and an order with no line, a deleted product or a product without a country is hidden from them (it fails closed). A refund request follows the
 * country of the order line it is about, and is hidden when that line or its product is gone.
 *
 * Both are correlated EXISTS conditions, which MySQL, MariaDB and SQLite all plan as semi and anti joins: one statement for a whole list, never a query per row. A list that
 * says `NOT EXISTS (a line whose product is not theirs)` rather than `NOT IN` cannot be fooled by a product without a country, because a comparison with nothing is not true.
 */
final class StaffOrderVisibility
{
    /**
     * Keeps the orders that have a line and no foreign line.
     *
     * @param list<string> $countries the countries the employee works with: none keeps no order
     */
    public static function constrainOrders(EloquentBuilder|QueryBuilder $query, string $orderIdColumn, array $countries): void
    {
        $query->whereExists(static fn (QueryBuilder $lines) => $lines->selectRaw('1')->from('order_details', 'visible_lines')->whereColumn('visible_lines.order_id', $orderIdColumn))
            ->whereNotExists(static fn (QueryBuilder $foreign) => $foreign->selectRaw('1')->from('order_details', 'foreign_lines')
                ->whereColumn('foreign_lines.order_id', $orderIdColumn)
                ->whereNotExists(static fn (QueryBuilder $home) => self::productOfCountries(query: $home, productIdColumn: 'foreign_lines.product_id', countries: $countries, alias: 'home_products')));
    }

    /**
     * Keeps the refund requests whose order line exists and whose line's product is one of the employee's.
     *
     * @param list<string> $countries
     */
    public static function constrainRefunds(EloquentBuilder|QueryBuilder $query, string $orderLineIdColumn, array $countries): void
    {
        $query->whereExists(static fn (QueryBuilder $lines) => $lines->selectRaw('1')->from('order_details', 'refund_lines')
            ->whereColumn('refund_lines.id', $orderLineIdColumn)
            ->whereExists(static fn (QueryBuilder $home) => self::productOfCountries(query: $home, productIdColumn: 'refund_lines.product_id', countries: $countries, alias: 'refund_products')));
    }

    /** @param list<string> $countries */
    private static function productOfCountries(QueryBuilder $query, string $productIdColumn, array $countries, string $alias): QueryBuilder
    {
        return $query->selectRaw('1')->from('products', $alias)->whereColumn("{$alias}.id", $productIdColumn)->whereIn("{$alias}." . StorefrontCatalog::COLUMN, $countries);
    }
}
