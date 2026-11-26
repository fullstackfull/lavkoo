<?php

namespace App\Services\Catalog;

use App\Rules\NewProductCountryInStaffScope;
use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\ProductCountry;

/**
 * The country a bulk import of products saves its rows in (B-08, GCC-14c).
 *
 * The sheet has no country column, so until now every row took ONE country that nobody chose: the employee's only country when countries
 * are tracked, and the default of the table otherwise. Two consequences, both of which the import screen now answers by letting the
 * operator NAME the country:
 *
 * - **an employee who works with several countries could not import at all** -- the sheet was refused whole, because there was no way to
 *   say which of their countries the rows belonged to;
 * - **an unrestricted employee could not target a country either.** Every import landed in the default storefront's catalogue, so
 *   preparing another market's catalogue from a spreadsheet was not possible from this screen at all.
 *
 * This composes the two services that already answer the halves of it rather than teaching either a new question:
 * `ProductCountry` for what a configured country is and `StaffCountryScope` for whose it is. `StaffCountryScope` is deliberately not
 * touched: it asks `admin_country_scopes` two separate questions on purpose, and that reading is pinned by an architecture test.
 *
 * **A code nobody sells in is REFUSED, not corrected.** `ProductCountry::newProductAttributes()` falls back to the default storefront for
 * an unrecognised choice, which is right for the add form -- one row, in front of the person who typed it. An import is hundreds of rows,
 * and landing them all in a market nobody chose is worse than refusing the sheet, so the fallback is not reused here.
 *
 * **Nothing changes when nothing is chosen**, which is every caller until a screen sends the field: both answers fall straight through to
 * the readings the import has always used.
 */
final class BulkImportCountry
{
    /** The field the import form sends its choice in -- the same name the add-product form uses, so one concept has one spelling. */
    public const FIELD = 'storefront_country';

    public const NOT_A_MARKET = 'the_country_you_chose_for_this_import_is_not_a_country_this_shop_sells_in';

    public function __construct(private readonly StaffCountryScope $staffCountries)
    {
    }

    /** Why this import may not run, as a language key, or null when it may. */
    public function refusal(mixed $chosen): ?string
    {
        $named = $this->named($chosen);

        if ($named === null || !ProductCountry::isTracked()) {
            return $this->staffCountries->whyItMayNotImport(countriesAreTracked: $this->countriesAreTracked());
        }

        return match (true) {
            in_array($named, ProductCountry::options(), true) => null,
            $this->staffCountries->allows(country: $named) => self::NOT_A_MARKET,
            default => NewProductCountryInStaffScope::MESSAGE,
        };
    }

    /** The country every row of this import is saved in, or null to leave the column exactly as the import left it before. */
    public function forRows(mixed $chosen): ?string
    {
        $named = $this->named($chosen);

        if ($named !== null && ProductCountry::isTracked() && in_array($named, ProductCountry::options(), true)) {
            return $named;
        }

        return $this->staffCountries->countryOfImportedRows(countriesAreTracked: $this->countriesAreTracked());
    }

    /**
     * The code the operator chose, as the catalogue spells one, or null when they chose nothing.
     *
     * Trimmed and upper-cased because a select may post a lower-case value and a country code is compared byte for byte everywhere else in
     * this platform; anything that is not two letters is a choice, and a wrong one, so it is handed on to be refused rather than read as
     * "nothing chosen".
     */
    private function named(mixed $chosen): ?string
    {
        if (!is_string($chosen) || trim($chosen) === '') {
            return null;
        }

        return strtoupper(trim($chosen));
    }

    /** The same reading the import has always taken: a narrowed employee, and a column that holds a country. */
    private function countriesAreTracked(): bool
    {
        return $this->staffCountries->restricts() && ProductCountry::isTracked();
    }
}
