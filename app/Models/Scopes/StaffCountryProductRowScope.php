<?php

namespace App\Models\Scopes;

use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A row that belongs to a product -- a product in a flash deal, the deal of the day, a most-demanded entry, a clearance offer -- narrowed to
 * the countries of the signed-in employee (GCC-14c, slice 4).
 *
 * The promotions themselves have no market of their own yet (GCC-17), so a deal row, a banner row and a clearance setup are not narrowed by
 * anything: what CAN be told apart by country on these screens is the products, and every one of these tables points at exactly one. So the
 * narrowing is the product's, read through `product_id`, and it closes three things at once that the product scope alone does not:
 *
 *  - a LIST of a promotion's products ("which products are in this deal") holds only the employee's;
 *  - a COUNT of them, which the deal list prints beside each deal, counts only the employee's, instead of telling a Kuwait employee that a
 *    deal they may not see into holds twenty products;
 *  - and a write that names its row by `product_id` rather than by its own id -- which is how the clearance screens are written -- reaches
 *    no row of another market, the delete of every admin clearance offer at once included.
 *
 * A row whose `product_id` is null, or names a product that is gone, belongs to no market and so to no employee: it FAILS CLOSED and a
 * restricted employee does not see it. `stock_clearance_products.product_id` is the one nullable column of the four.
 *
 * It does nothing for a request that is not narrowed, which is every request while the staff country scopes are off, every request of a
 * master admin or an unrestricted employee, and every request of the storefront and the apps -- nothing binds a country there, so a shop's
 * own deal listing is untouched by this.
 *
 * An Eloquent scope on purpose, for the reason the product scope is one: Laravel wraps what the query already holds in parentheses before it
 * applies a scope, so a query that ends in an `or` condition can never let another market's row through.
 */
final class StaffCountryProductRowScope implements Scope
{
    public function __construct(private readonly string $column = 'product_id')
    {
    }

    public function apply(Builder $builder, Model $model): void
    {
        $countries = app(StaffCountryScope::class)->boundCountries();

        if ($countries === null) {
            return;
        }

        $productIdColumn = $model->qualifyColumn($this->column);

        $builder->whereExists(static fn (QueryBuilder $products): QueryBuilder => $products->selectRaw('1')
            ->from('products', 'row_products')
            ->whereColumn('row_products.id', $productIdColumn)
            ->whereIn('row_products.' . StorefrontCatalog::COLUMN, $countries));
    }
}
