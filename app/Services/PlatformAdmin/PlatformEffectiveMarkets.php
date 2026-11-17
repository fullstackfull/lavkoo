<?php

namespace App\Services\PlatformAdmin;

use App\Models\Admin;
use App\Models\PlatformAdminGrant;
use App\Services\Staff\StaffCountryScope;

/**
 * The one place that decides which markets an admin reaches through the platform admin API (GCC-20 with GCC-14c): the markets their grant names that the staff country scope of
 * the admin panel also leaves them. Everything that reads the markets of a principal reads what this gives, so no answer of the API can be wider than the narrower of the two.
 *
 * The staff scope is asked of StaffCountryScope, which alone knows its switch, its table and how rows become countries, and it is asked on every request, from the rows as they are
 * now: nothing is kept from one request to the next, so a scope given, changed or taken back applies to the next request. While the staff switch is off nothing about it is asked, and
 * the markets are the grant's, as they were. An admin it does not restrict (the master admin, an employee with no row) has the grant's markets too.
 *
 * An admin restricted to countries their grant does not name reaches no market: they can sign in and see nothing, as an admin whose grant names none does. And an admin the
 * staff scope restricts is no platform owner, even when their grant names every market and their scope names every country: the orders that belong to no market are for the admin
 * nothing restricts (the staff scope hides them from every restricted employee).
 */
final class PlatformEffectiveMarkets
{
    public function __construct(private readonly PlatformMarkets $markets, private readonly StaffCountryScope $staffScope)
    {
    }

    public function of(PlatformAdminGrant $grant, Admin $admin): EffectiveMarkets
    {
        $granted = $this->markets->allowedBy(grant: $grant);
        $staffCountries = $this->staffScope->countriesOfAdmin(admin: $admin);

        return new EffectiveMarkets(
            granted: $granted,
            markets: $staffCountries === null ? $granted : array_values(array_intersect($granted, $staffCountries)),
            staffCountries: $staffCountries,
        );
    }

    /**
     * The columns to read an admin with, given the ones the caller reads anyway: the role is added only while the staff switch is on, because only then does the answer
     * depend on it. While it is off the query is the one it always was.
     *
     * @param list<string> $columns
     *
     * @return list<string>
     */
    public function columnsToRead(array $columns): array
    {
        return $this->staffScope->isOn() ? [...$columns, 'admin_role_id'] : $columns;
    }
}
