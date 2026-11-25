<?php

namespace App\Models\Scopes;

use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\StorefrontMarketRows;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * A query over a table whose rows carry a market of their OWN, narrowed to the markets of the signed-in employee (GCC-14c).
 *
 * The one rule about which market such a row belongs to is `StorefrontMarketRows`': a row that names no market is the DEFAULT storefront's,
 * because every row that existed before the column did was made for the only storefront there had ever been. So an employee who holds the
 * default market reads their own rows and the unmarked ones, an employee who does not reads only rows naming one of their markets, and
 * before the column exists every row is the default storefront's -- the same answer, not a special case. Nothing of that is restated here.
 *
 * It is the rule `StaffCountryShippingScope` was written for, under a name that is not about shipping, because the first table outside the
 * shipping screens to carry its own market is the WAREHOUSE (`inventory_locations.storefront_country`, G1-05) and the subject there is the
 * shelf and not what is on it. Until that column existed the only country a stock screen could be asked about was the PRODUCT's, which
 * answers for the units on a shelf and never for the shelf: a restricted employee was shown every market's warehouse, with its code, its
 * name and its figures. The shipping scope now extends this one so that there is a single implementation of the rule.
 *
 * It does nothing for a request that is not narrowed, which is every request while the staff country scopes are off, every request of a
 * master admin or an unrestricted employee, and every request of the storefront, the apps and the queue -- nothing binds a country there,
 * so a shop's own listing is untouched by this and stays `StorefrontMarketRows::narrow()`'s business.
 *
 * An Eloquent scope on purpose, for the reason the product scope is one: Laravel wraps what the query already holds in parentheses before it
 * applies a scope, so a query that ends in an `or` condition can never let another market's row through.
 */
class StaffCountryMarketRowScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $countries = app(StaffCountryScope::class)->boundCountries();

        if ($countries !== null) {
            app(StorefrontMarketRows::class)->narrowToCountries($builder, $model->getTable(), $countries);
        }
    }
}
