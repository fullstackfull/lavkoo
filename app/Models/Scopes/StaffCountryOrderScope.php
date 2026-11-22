<?php

namespace App\Models\Scopes;

use App\Services\Staff\StaffCountryScope;
use App\Services\Staff\StaffOrderVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * An order query of a request that was narrowed to orders (GCC-14c, slice 2) only reaches the orders whose every line is a product of the signed-in employee's countries.
 * Orders carry no country, so this is the one place that says what one is to an employee (App\Services\Staff\StaffOrderVisibility): a list, a counter, an export, the
 * neighbours of an order, a relation, an update and a lookup by id all go through it.
 *
 * The rows that hang off an order (its lines, its money records) follow the same rule through the order they belong to (slice 3): the model names the subject its request
 * narrows them under and the column that holds the order id.
 *
 * It does nothing for a request that is not narrowed, which is every request while the staff country scopes are off, every request of the master admin and of an employee
 * with no country, and every request of the storefront, the vendor panel and the APIs. A bound request is narrowed only where a screen asked for it (the middleware of the
 * order screens reads the rule of the action): the actions that change an order run on the real data.
 */
final class StaffCountryOrderScope implements Scope
{
    public function __construct(private readonly string $subject = StaffCountryScope::SUBJECT_ORDERS, private readonly string $orderIdColumn = 'id')
    {
    }

    public function apply(Builder $builder, Model $model): void
    {
        $countries = app(StaffCountryScope::class)->narrowedCountries(subject: $this->subject);

        if ($countries !== null) {
            StaffOrderVisibility::constrainOrders(query: $builder, orderIdColumn: $model->qualifyColumn($this->orderIdColumn), countries: $countries);
        }
    }
}
