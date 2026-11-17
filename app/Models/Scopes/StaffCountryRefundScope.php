<?php

namespace App\Models\Scopes;

use App\Services\Staff\StaffCountryScope;
use App\Services\Staff\StaffOrderVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * A refund request query of a request that was narrowed to refunds (GCC-14c, slice 2) only reaches the requests about an order line whose product is one of the signed-in
 * employee's countries. A refund follows the line it is about, not the whole order: the refund of a Kuwaiti line of an order that also holds a Saudi one is Kuwait's.
 *
 * The money records of a refund follow the line they are about the same way (slice 3): the model names the subject its request narrows them under.
 *
 * It does nothing for a request that is not narrowed, as the scope of the orders (StaffCountryOrderScope).
 */
final class StaffCountryRefundScope implements Scope
{
    public function __construct(private readonly string $subject = StaffCountryScope::SUBJECT_REFUNDS)
    {
    }

    public function apply(Builder $builder, Model $model): void
    {
        $countries = app(StaffCountryScope::class)->narrowedCountries(subject: $this->subject);

        if ($countries !== null) {
            StaffOrderVisibility::constrainRefunds(query: $builder, orderLineIdColumn: $model->qualifyColumn('order_details_id'), countries: $countries);
        }
    }
}
