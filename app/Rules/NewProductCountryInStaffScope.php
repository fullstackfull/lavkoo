<?php

namespace App\Rules;

use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\ProductCountry;
use App\Services\Storefront\StorefrontCatalog;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The country a new product is saved in must be one the signed-in employee works with (GCC-14c). It asks the code that builds the row, so what is checked is what is
 * stored: the employee's choice when countries are tracked and the choice is a country they may use, the default storefront's when it is missing or is not one, and
 * the default of the table while countries are not tracked, whatever the request says. It runs when the field is missing too, because a missing choice is still a country.
 *
 * The message is a language key: the add request translates every message it gives back.
 */
final class NewProductCountryInStaffScope implements ValidationRule
{
    public const MESSAGE = 'the_country_of_this_product_is_not_one_of_your_countries';

    public bool $implicit = true;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $country = ProductCountry::newProductAttributes(requestedCountry: $value)[StorefrontCatalog::COLUMN] ?? StaffCountryScope::TABLE_DEFAULT_COUNTRY;

        if (!app(StaffCountryScope::class)->allows(country: $country)) {
            $fail(self::MESSAGE);
        }
    }
}
