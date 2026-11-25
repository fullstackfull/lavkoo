<?php

namespace App\Models\Scopes;

use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\StorefrontMarketRows;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * A shipping query of a request that is narrowed to the countries of the signed-in employee only reaches the rows of those countries
 * (GCC-14c slice 6; H1-14).
 *
 * The refusals came first: an employee restricted to Kuwait could not CHANGE the Saudi store's delivery price, but still saw it on the list,
 * which is a screen that shows what it will not let you touch. This closes that, and it closes it for every list, counter, picker and filter
 * of those screens at once, whether written with the repository, the model or a relation.
 *
 * The rule of which market a row belongs to is `StorefrontMarketRows` and is not restated here: a row that names no market is the DEFAULT
 * storefront's, so an employee who holds the default market sees the unmarked rows and an employee who holds another does not. Before the
 * tables carry a market every row is the default storefront's, which is the only market this platform has ever had rows for.
 *
 * It does nothing for a request that is not narrowed, which is every request while the staff country scopes are off and every request of the
 * storefront side -- nothing binds a country there, so a shop's own listing is untouched by this and stays `StorefrontMarketRows::narrow()`'s
 * business.
 *
 * An Eloquent scope on purpose, for the reason the product scope is one: it wraps what the query already holds in parentheses before adding
 * its own condition, so a query that ends in an `or` can never let another market's row through.
 */
final class StaffCountryShippingScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $countries = app(StaffCountryScope::class)->boundCountries();

        if ($countries !== null) {
            app(StorefrontMarketRows::class)->narrowToCountries($builder, $model->getTable(), $countries);
        }
    }
}
