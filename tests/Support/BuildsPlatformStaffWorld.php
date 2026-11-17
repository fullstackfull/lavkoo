<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * What the tests of the platform admin API need to put the staff country scopes (GCC-14c) beside it: the table of countries, built by its real migration, the employees that
 * hold rows in it, and the switch. The employees are made straight in the database, so that what a request is judged against does not depend on the code under test.
 *
 * Employees are admins whose role is not the master admin's (role 1). An admin made by `platformAdmin()` is the master admin unless its role is given.
 */
trait BuildsPlatformStaffWorld
{
    private const EMPLOYEE_ROLE = 2;

    protected function buildStaffCountryTable(): void
    {
        (require database_path('migrations/2026_11_28_100000_create_admin_country_scopes_table.php'))->up();
    }

    protected function switchStaffScopes(bool $on): void
    {
        config(['gcc.staff.country_scopes' => $on]);
    }

    /** @param list<string> $countries stored as they are given: a test may hand over a value no command would accept */
    protected function assignStaffCountries(int $adminId, array $countries): void
    {
        foreach ($countries as $country) {
            DB::table('admin_country_scopes')->insert(['admin_id' => $adminId, 'country' => $country, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function clearStaffCountries(int $adminId): void
    {
        DB::table('admin_country_scopes')->where('admin_id', $adminId)->delete();
    }

    /** What the staff scope of an admin is, in one move: the rows are replaced by these. */
    protected function setStaffCountries(int $adminId, ?array $countries): void
    {
        $this->clearStaffCountries(adminId: $adminId);

        if ($countries !== null) {
            $this->assignStaffCountries(adminId: $adminId, countries: $countries);
        }
    }

    protected function setRole(int $adminId, int $role): void
    {
        DB::table('admins')->where('id', $adminId)->update(['admin_role_id' => $role]);
    }

    /**
     * An admin who holds a grant, and who is an employee (not the master admin), with the staff countries given, or none.
     *
     * @param list<string>|null $grantCountries null is every market
     * @param list<string>|null $staffCountries null is no row at all
     * @param array<string, mixed> $grantOverrides
     *
     * @return array{id: int, email: string, password: string, name: string, grant_id: int}
     */
    protected function employeeWithGrant(?array $grantCountries = null, ?array $staffCountries = null, array $grantOverrides = []): array
    {
        $employee = $this->platformAdmin($grantCountries, ['admin_role_id' => self::EMPLOYEE_ROLE], $grantOverrides);
        $this->setStaffCountries(adminId: $employee['id'], countries: $staffCountries);

        return $employee;
    }

    /** @return list<string> the countries of the platform, in the order of the configuration */
    protected function configuredCountries(): array
    {
        return array_map('strval', array_keys((array) config('gcc.storefronts.countries')));
    }
}
