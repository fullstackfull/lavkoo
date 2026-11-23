<?php

namespace Tests\Feature\StaffScope;

use App\Models\Admin;
use App\Services\Staff\StaffCountryScope;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsStaffScopeWorld;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * What the countries of an admin are outside a request of the admin panel (GCC-14c): the platform admin API (GCC-20) asks it for the admin who signs in to it, where nothing is
 * bound to the request and nothing may be remembered. It is the rule of `bind()` without the binding: the switch, the master admin, the rows and the countries that are still configured.
 *
 * Employees of the world: 1 is the master admin (role 1), 2 and 3 are catalog employees (role 2), 4 is an employee whose role was never set.
 */
class StaffCountryOfAdminTest extends TestCase
{
    use BuildsStaffScopeWorld;
    use BuildsStorefrontCatalog;

    private StaffCountryScope $scope;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontCatalogTables();
        $this->runStorefrontCountryMigration();
        $this->buildStaffAdminTables();
        $this->runStaffScopeMigration();
        $this->scope = app(StaffCountryScope::class);
    }

    private function countriesOf(int $adminId): ?array
    {
        return $this->scope->countriesOfAdmin(admin: Admin::query()->findOrFail($adminId));
    }

    public function test_with_the_switch_off_nobody_is_restricted_and_nothing_is_asked_of_the_database(): void
    {
        $this->giveCountries(adminId: 2, countries: ['KW']);
        $admin = Admin::query()->findOrFail(2);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $countries = $this->scope->countriesOfAdmin(admin: $admin);

        $this->assertNull($countries);
        $this->assertSame([], DB::getQueryLog(), 'no table lookup, no schema probe, not even the role');
    }

    public function test_the_master_admin_is_never_restricted_and_asks_nothing_of_the_staff_table_or_its_schema(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 1, countries: ['KW']);
        $master = Admin::query()->findOrFail(1);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $countries = $this->scope->countriesOfAdmin(admin: $master);

        $this->assertNull($countries);
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_an_employee_with_no_row_is_unrestricted(): void
    {
        $this->switchStaffScopesOn();

        $this->assertNull($this->countriesOf(adminId: 2));
    }

    public function test_an_employee_with_rows_is_restricted_to_the_configured_countries_among_them_in_alphabetical_order(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 2, countries: ['SA', 'KW', 'zz']);

        $this->assertSame(['KW', 'SA'], $this->countriesOf(adminId: 2));
        $this->assertNull($this->countriesOf(adminId: 3), 'the rows of another employee do not count');
    }

    public function test_an_employee_whose_values_are_all_damaged_is_restricted_to_nothing(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 2, countries: ['zz', 'kw']);

        $this->assertSame([], $this->countriesOf(adminId: 2));
    }

    public function test_an_employee_whose_role_was_never_set_is_an_employee(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 4, countries: ['KW']);

        $this->assertSame(['KW'], $this->countriesOf(adminId: 4));
    }

    public function test_an_admin_read_without_the_role_has_it_read_and_the_master_admin_is_still_never_restricted(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 1, countries: ['KW']);
        $this->giveCountries(adminId: 2, countries: ['AE']);

        $this->assertSame(['AE'], $this->scope->countriesOfAdmin(admin: Admin::query()->select(['id', 'name'])->findOrFail(2)));
        $this->assertNull($this->scope->countriesOfAdmin(admin: Admin::query()->select(['id', 'name'])->findOrFail(1)));
    }

    public function test_it_acts_as_off_while_the_table_or_the_products_column_is_missing(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 2, countries: ['KW']);
        $this->assertSame(['KW'], $this->countriesOf(adminId: 2));

        $this->removeStorefrontCountryColumn();
        $this->assertNull($this->countriesOf(adminId: 2));

        $this->runStorefrontCountryMigration();
        $this->assertSame(['KW'], $this->countriesOf(adminId: 2));

        $this->rollBackStaffScopeMigration();
        $this->assertNull($this->countriesOf(adminId: 2));
    }

    public function test_a_country_taken_out_of_the_configuration_stops_granting(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 2, countries: ['KW', 'OM']);
        $this->assertSame(['KW', 'OM'], $this->countriesOf(adminId: 2));

        $countries = config('gcc.storefronts.countries');
        unset($countries['OM']);
        config(['gcc.storefronts.countries' => $countries]);

        $this->assertSame(['KW'], $this->countriesOf(adminId: 2));
    }

    public function test_it_reads_the_rows_as_they_are_now_and_binds_and_remembers_nothing(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 2, countries: ['KW']);

        $this->assertSame(['KW'], $this->countriesOf(adminId: 2));
        $this->assertNull($this->scope->boundCountries(), 'nothing is bound to the request');
        $this->assertFalse($this->scope->restricts());

        DB::table('admin_country_scopes')->where('admin_id', 2)->delete();
        $this->giveCountries(adminId: 2, countries: ['AE']);

        $this->assertSame(['AE'], $this->countriesOf(adminId: 2), 'a change applies to the next question');
    }
}
