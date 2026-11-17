<?php

namespace App\Services\Storefront;

use App\Rules\NewProductCountryInStaffScope;
use App\Services\Staff\StaffCountryScope;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * The country a product belongs to, as the admin tools set and filter it (GCC-14).
 *
 * The country is chosen once, when the admin adds the product, and never changes afterwards: carts, wishlists, orders, reviews and stock
 * history all point at the row, so a product is copied to sell it in another country, not moved. Sellers do not choose a country until
 * sellers have markets, so a seller product takes the default of the table.
 *
 * None of it exists while storefronts are off or before the products migration has run: every method answers "nothing" and every tool
 * behaves as it did.
 */
final class ProductCountry
{
    /** Whether the tools show, validate and save a product's country. */
    public static function isTracked(): bool
    {
        return StorefrontCatalog::tracksProductCountry();
    }

    /**
     * Every configured country, open or not: a country's catalogue is prepared before its storefront opens. A request narrowed to the countries of the signed-in
     * employee (GCC-14c) is offered those only: the form, the filter and the validation all read this list.
     *
     * @return list<string>
     */
    public static function options(): array
    {
        return app(StaffCountryScope::class)->narrow(countries: self::configured());
    }

    /** @return list<string> every configured country, whoever asks: what a request means by a country does not depend on who sends it */
    private static function configured(): array
    {
        return StorefrontResolver::fromConfig()->configuredCountries();
    }

    /** The country a product belongs to when nobody chose one: the default storefront's. */
    public static function defaultCountry(): string
    {
        return StorefrontResolver::fromConfig()->defaultCountry();
    }

    /** How the tools name a country: its code and the currency its storefront sells in. A code that is no longer configured is shown as it is stored. */
    public static function label(string $country): string
    {
        if (!in_array($country, self::options(), true)) {
            return $country;
        }

        return $country . ' · ' . StorefrontResolver::fromConfig()->currencyOf($country);
    }

    /**
     * The validation of the country field of a new product. A request narrowed to the countries of the signed-in employee (GCC-14c) also asks that the country the
     * product will be saved in is one of them, even while countries are not tracked: the row then takes the default of the table.
     *
     * @return array<string, list<mixed>>
     */
    public static function newProductRules(): array
    {
        if (app(StaffCountryScope::class)->restricts()) {
            return [StorefrontCatalog::COLUMN => self::isTracked() ? ['bail', 'nullable', new NewProductCountryInStaffScope(), Rule::in(self::options())] : ['bail', new NewProductCountryInStaffScope()]];
        }

        if (!self::isTracked()) {
            return [];
        }

        return [StorefrontCatalog::COLUMN => ['nullable', Rule::in(self::options())]];
    }

    /**
     * What a new product is saved with: the country the admin chose, and the default storefront's when the choice is missing or is not a
     * configured country.
     *
     * @return array<string, string>
     */
    public static function newProductAttributes(mixed $requestedCountry): array
    {
        if (!self::isTracked()) {
            return [];
        }

        $country = in_array($requestedCountry, self::configured(), true) ? $requestedCountry : self::defaultCountry();

        return [StorefrontCatalog::COLUMN => $country];
    }

    /**
     * The countries a list is narrowed to. Anything that is not a configured country is dropped, and a filter with nothing left is no
     * filter at all, so a mistyped address shows the whole list instead of an empty one.
     *
     * @return list<string>
     */
    public static function filterOf(mixed $requestedCountries): array
    {
        if (!self::isTracked()) {
            return [];
        }

        return array_values(array_unique(array_intersect(array_filter(Arr::wrap($requestedCountries), 'is_string'), self::options())));
    }
}
