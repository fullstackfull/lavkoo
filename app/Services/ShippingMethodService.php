<?php

namespace App\Services;

use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\StorefrontShippingMethods;

class ShippingMethodService
{
    /**
     * @param object $request
     * @param string $addedBy
     * @return array
     */
    public function addShippingMethodData(object $request, string $addedBy): array
    {
        return [
            'creator_id' => $addedBy == 'seller' ? auth('seller')->id() : auth('admin')->id(),
            'creator_type' => $addedBy,
            'title' => $request['title'],
            'duration' => $request['duration'],
            'cost' => currencyConverter($request['cost']),
            'created_at' => now(),
            'updated_at' => now()
        ] + $this->marketOfANewMethod();
    }

    /**
     * The market a method created now belongs to, as an array to add to what is written, or nothing at all (H1-14, GCC-14c slice 6).
     *
     * Nothing at all is what every request wrote before this and what every unnarrowed request still writes: no market, which makes the
     * method the DEFAULT storefront's. An employee restricted to one country creates in that country, because a row of no market is a row
     * they could not touch again afterwards.
     *
     * An array rather than a value, so a table that has not had the migration run is never given a column it does not have.
     *
     * @return array<string, string>
     */
    private function marketOfANewMethod(): array
    {
        if (!app(StorefrontShippingMethods::class)->tableCarriesAMarket()) {
            return [];
        }

        $country = app(StaffCountryScope::class)->countryOfNewShippingRow();

        return $country === null ? [] : [StorefrontShippingMethods::COLUMN => $country];
    }
}
