<?php

namespace App\Services\Staff;

use App\Models\Admin;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The countries the signed-in employee works with (GCC-14c, slice 1: the product screens of the admin panel).
 *
 * It is the only place that knows the switch (`gcc.staff.country_scopes`), the table of countries and how they become an employee's scope. The switch ships off and
 * acts as off until the table and `products.storefront_country` exist: nothing is read then, and every answer is the one the panel has always given.
 *
 * An employee with no row is unrestricted, so turning the switch on changes nothing until a country is assigned. With rows the employee is restricted to the
 * countries that are still configured; a stored value that names none grants nothing, and an employee whose values all grant nothing is restricted to nothing,
 * never widened. The master admin is never restricted.
 *
 * What a request is narrowed to lives on that request alone (never in the container, a static or the cache), so the next request of the same process, a queue
 * worker and a console command start unbound.
 */
final class StaffCountryScope
{
    public const SWITCH = 'gcc.staff.country_scopes';

    public const TABLE = 'admin_country_scopes';

    /** The country of a product no path gave one to: the default of the column. A bulk import without a country and a new product while countries are not tracked take it. */
    public const TABLE_DEFAULT_COUNTRY = 'SA';

    private const MASTER_ADMIN_ROLE_ID = 1;

    private const REQUEST_ATTRIBUTE = 'staff_country_scope';

    /** Whether the switch is on. Nothing else is asked while it is off. */
    public function isOn(): bool
    {
        return filter_var(config(self::SWITCH, false), FILTER_VALIDATE_BOOLEAN);
    }

    /** Whether scopes are enforced: the switch is on and both the table of countries and the column of the products exist. */
    public function enabled(): bool
    {
        return $this->isOn() && Schema::hasTable(self::TABLE) && Schema::hasColumn('products', StorefrontCatalog::COLUMN);
    }

    /**
     * Resolves the employee of a request and binds the result to that request.
     *
     * @return list<string>|null the countries the request is narrowed to, or null when it is not narrowed
     */
    public function bind(Request $request, ?Admin $admin): ?array
    {
        $countries = $admin !== null && $this->enabled() ? $this->resolve(adminId: (int) $admin->getKey(), roleId: $admin->admin_role_id) : null;
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $countries);

        return $countries;
    }

    /**
     * What the rows of an employee make of their scope, whatever the switch says: null for the master admin and for an employee with no row, otherwise the stored
     * countries that are configured, which may be none.
     *
     * @return list<string>|null
     */
    public function resolve(int $adminId, ?int $roleId): ?array
    {
        if ($roleId === self::MASTER_ADMIN_ROLE_ID) {
            return null;
        }

        $stored = $this->storedCountriesOf(adminId: $adminId);
        if ($stored === []) {
            return null;
        }

        $granted = array_values(array_unique(array_intersect($stored, $this->configuredCountries())));
        sort($granted);

        return $granted;
    }

    /**
     * Every value stored for an employee, damaged ones included.
     *
     * @return list<string>
     */
    public function storedCountriesOf(int $adminId): array
    {
        return DB::table(self::TABLE)->where('admin_id', $adminId)->orderBy('country')->pluck('country')->map(static fn ($country): string => (string) $country)->all();
    }

    /**
     * Every country the platform knows, open or not: a country's catalogue is prepared before its storefront opens.
     *
     * @return list<string>
     */
    public function configuredCountries(): array
    {
        return array_values(array_map('strval', array_keys((array) config('gcc.storefronts.countries'))));
    }

    /** @return list<string>|null what the current request is narrowed to: null when it is not narrowed, an empty list when it may see nothing */
    public function boundCountries(): ?array
    {
        $bound = request()->attributes->get(self::REQUEST_ATTRIBUTE);

        return is_array($bound) ? $bound : null;
    }

    public function restricts(): bool
    {
        return $this->boundCountries() !== null;
    }

    public function allows(string $country): bool
    {
        $bound = $this->boundCountries();

        return $bound === null || in_array($country, $bound, true);
    }

    /**
     * Keeps the countries the current request may work with, in the order they were given. A request that is not narrowed keeps them all.
     *
     * @param list<string> $countries
     * @return list<string>
     */
    public function narrow(array $countries): array
    {
        $bound = $this->boundCountries();

        return $bound === null ? $countries : array_values(array_intersect($countries, $bound));
    }

    /**
     * The country the rows of a bulk import are saved in for the current request, or null when the request is not narrowed. The sheet has no country column, so every row
     * takes one country: with countries tracked, the only country of the employee; while they are not, the default of the table, which has to be one of theirs. An employee
     * with several countries, or none, has no such country and may not import.
     */
    public function countryOfImportedRows(bool $countriesAreTracked): ?string
    {
        $bound = $this->boundCountries();

        return match (true) {
            $bound === null => null,
            $countriesAreTracked => count($bound) === 1 ? $bound[0] : null,
            default => in_array(self::TABLE_DEFAULT_COUNTRY, $bound, true) ? self::TABLE_DEFAULT_COUNTRY : null,
        };
    }

    /** @return string|null the language key of why a narrowed request may not import a sheet, or null when it may or the request is not narrowed */
    public function whyItMayNotImport(bool $countriesAreTracked): ?string
    {
        $bound = $this->boundCountries();
        if ($bound === null || $this->countryOfImportedRows(countriesAreTracked: $countriesAreTracked) !== null) {
            return null;
        }

        return $countriesAreTracked && count($bound) > 1
            ? 'the_import_sheet_has_no_country_and_your_account_works_with_more_than_one'
            : 'the_products_of_this_sheet_would_be_saved_in_a_country_your_account_does_not_work_with';
    }

    /**
     * Whether every one of these products exists and belongs to a country the current request may work with. A request that is not narrowed may work with every
     * product, existing or not: the screens answer for an unknown one as they always did.
     *
     * @param list<int> $productIds
     */
    public function coversEveryProduct(array $productIds): bool
    {
        $bound = $this->boundCountries();
        $ids = array_values(array_unique($productIds));
        if ($bound === null || $ids === []) {
            return true;
        }

        return DB::table('products')->whereIn('id', $ids)->whereIn(StorefrontCatalog::COLUMN, $bound)->count() === count($ids);
    }
}
