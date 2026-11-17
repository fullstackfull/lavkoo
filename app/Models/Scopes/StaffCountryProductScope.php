<?php

namespace App\Models\Scopes;

use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * A product query of a request that is narrowed to the countries of the signed-in employee (GCC-14c) only reaches the products of those countries: every list, counter,
 * picker, filter and lookup by id of the product screens goes through this one scope, whether it is written with the repository, the model or a relation. It does nothing
 * for a request that is not narrowed, which is every request while the staff country scopes are off.
 *
 * It is an Eloquent scope on purpose: it wraps what the query already holds in parentheses before it adds its own condition, so a query that ends in an `or` condition,
 * as the product search does, can never let a product of another country through.
 */
final class StaffCountryProductScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $countries = app(StaffCountryScope::class)->boundCountries();

        if ($countries !== null) {
            $builder->whereIn($model->qualifyColumn(StorefrontCatalog::COLUMN), $countries);
        }
    }
}
