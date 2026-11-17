<?php

namespace Tests\Feature\StaffScope;

use App\Models\Admin;
use App\Services\Staff\StaffCountryScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsStaffScopeWorld;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * Who a request is narrowed to (GCC-14c): the one service that knows the switch, the table of countries and how they become the signed-in employee's scope.
 *
 * An employee with no row is unrestricted, so turning the switch on changes nothing until someone assigns a country. With rows the employee is restricted to the
 * countries that are still configured, and a stored value that no longer names one grants nothing: it never widens the scope.
 */
class StaffCountryScopeTest extends TestCase
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

    private function bindFor(int $adminId): ?array
    {
        $request = Request::create('/admin/products/list/in_house');
        $this->app->instance('request', $request);

        return $this->scope->bind(request: $request, admin: Admin::query()->findOrFail($adminId));
    }

    // ---- the switch ---------------------------------------------------------------------------------------------------------------

    public function test_the_switch_ships_off(): void
    {
        $this->assertFalse(config('gcc.staff.country_scopes'));
        $this->assertFalse($this->scope->isOn());
    }

    public function test_the_switch_defaults_to_false_in_the_config_file_itself(): void
    {
        $this->assertSame("filter_var(env('GCC_STAFF_COUNTRY_SCOPES', false), FILTER_VALIDATE_BOOLEAN)", $this->defaultOfTheSwitchInTheConfigFile());
    }

    /** @return string the expression the config file gives the switch */
    private function defaultOfTheSwitchInTheConfigFile(): string
    {
        preg_match("/'country_scopes' => (.+),\n/", (string) file_get_contents(config_path('gcc.php')), $matches);

        return $matches[1] ?? '';
    }

    public function test_with_the_switch_off_nothing_is_asked_of_the_database_and_nobody_is_narrowed(): void
    {
        $this->giveCountries(adminId: 2, countries: ['KW']);
        $admin = Admin::query()->findOrFail(2);
        $request = Request::create('/admin/products/list/in_house');
        $this->app->instance('request', $request);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $countries = $this->scope->bind(request: $request, admin: $admin);

        $this->assertNull($countries);
        $this->assertNull($this->scope->boundCountries());
        $this->assertSame([], DB::getQueryLog(), 'no table lookup, no schema probe, nothing');
    }

    // ---- when the switch acts as off ----------------------------------------------------------------------------------------------

    public function test_with_the_table_missing_the_switch_acts_as_off(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 2, countries: ['KW']);
        $this->rollBackStaffScopeMigration();

        $this->assertFalse($this->scope->enabled());
        $this->assertNull($this->bindFor(adminId: 2));
    }

    public function test_with_the_products_country_column_missing_the_switch_acts_as_off(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 2, countries: ['KW']);
        $this->removeStorefrontCountryColumn();

        $this->assertFalse($this->scope->enabled());
        $this->assertNull($this->bindFor(adminId: 2));
    }

    public function test_with_the_switch_on_and_both_in_place_it_is_enabled(): void
    {
        $this->switchStaffScopesOn();

        $this->assertTrue($this->scope->isOn());
        $this->assertTrue($this->scope->enabled());
    }

    // ---- who is restricted --------------------------------------------------------------------------------------------------------

    public function test_an_employee_with_no_row_is_unrestricted_so_turning_the_switch_on_changes_nothing(): void
    {
        $this->switchStaffScopesOn();

        $this->assertNull($this->bindFor(adminId: 2));
        $this->assertNull($this->scope->boundCountries());
        $this->assertFalse($this->scope->restricts());
    }

    public function test_an_employee_with_rows_is_restricted_to_those_countries_and_to_them_only(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 2, countries: ['KW', 'SA']);

        $this->assertSame(['KW', 'SA'], $this->bindFor(adminId: 2));
        $this->assertSame(['KW', 'SA'], $this->scope->boundCountries());
        $this->assertTrue($this->scope->restricts());
        $this->assertTrue($this->scope->allows(country: 'KW'));
        $this->assertFalse($this->scope->allows(country: 'AE'));
    }

    public function test_the_rows_of_another_employee_do_not_count(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 3, countries: ['KW']);

        $this->assertNull($this->bindFor(adminId: 2));
        $this->assertSame(['KW'], $this->bindFor(adminId: 3));
    }

    public function test_the_master_admin_is_never_restricted_whatever_the_table_holds(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 1, countries: ['KW']);

        $this->assertNull($this->bindFor(adminId: 1));
        $this->assertNull($this->scope->boundCountries());
    }

    public function test_an_employee_whose_role_was_never_set_is_an_employee_not_a_master_admin(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 4, countries: ['AE']);

        $this->assertSame(['AE'], $this->bindFor(adminId: 4));
    }

    public function test_a_country_that_is_configured_but_not_open_yet_can_be_granted(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 2, countries: ['AE']);

        $this->assertFalse(config('gcc.storefronts.countries.AE.active'));
        $this->assertSame(['AE'], $this->bindFor(adminId: 2));
    }

    public function test_no_one_is_bound_while_nobody_is_signed_in(): void
    {
        $this->switchStaffScopesOn();
        $request = Request::create('/admin/products/list/in_house');

        $this->assertNull($this->scope->bind(request: $request, admin: null));
    }

    // ---- damaged values restrict, they never widen ---------------------------------------------------------------------------------

    public function test_a_value_that_names_no_configured_country_grants_nothing_and_the_employee_stays_restricted(): void
    {
        $this->switchStaffScopesOn();

        foreach (['ZZ', 'sa', 'Sa', '', ' K', "S\0"] as $damaged) {
            DB::table('admin_country_scopes')->delete();
            DB::table('admin_country_scopes')->insert(['admin_id' => 2, 'country' => $damaged]);

            $this->assertSame([], $this->bindFor(adminId: 2), 'stored as ' . json_encode($damaged));
        }
    }

    public function test_a_damaged_value_beside_a_good_one_leaves_only_the_good_one(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 2, countries: ['SA', 'ZZ', 'ku']);

        $this->assertSame(['SA'], $this->bindFor(adminId: 2));
    }

    public function test_a_country_taken_out_of_the_configuration_grants_nothing_any_more(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 2, countries: ['KW', 'QA']);
        config(['gcc.storefronts.countries' => array_diff_key(config('gcc.storefronts.countries'), ['KW' => true])]);

        $this->assertSame(['QA'], $this->bindFor(adminId: 2));
    }

    public function test_a_configuration_without_countries_restricts_an_employee_with_rows_to_nothing(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 2, countries: ['SA']);
        config(['gcc.storefronts.countries' => null]);

        $this->assertSame([], $this->bindFor(adminId: 2));
    }

    // ---- the binding belongs to one request ----------------------------------------------------------------------------------------

    public function test_what_is_bound_belongs_to_the_request_it_was_bound_to(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 2, countries: ['KW']);

        $this->assertSame(['KW'], $this->bindFor(adminId: 2));
        $this->assertSame(['KW'], $this->scope->boundCountries());

        $this->app->instance('request', Request::create('/admin/products/list/in_house'));

        $this->assertNull($this->scope->boundCountries(), 'the next request starts unbound: nothing is remembered between requests');
    }

    public function test_a_later_request_of_the_same_process_sees_the_rows_as_they_are_then(): void
    {
        $this->switchStaffScopesOn();

        $this->assertNull($this->bindFor(adminId: 2));
        $this->giveCountries(adminId: 2, countries: ['QA']);

        $this->assertSame(['QA'], $this->bindFor(adminId: 2), 'a scope assigned between two requests applies to the second');
    }

    public function test_the_signed_in_employee_of_one_request_never_narrows_the_next_one(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 2, countries: ['KW']);

        $this->bindFor(adminId: 2);
        $request = Request::create('/admin/products/list/in_house');
        $this->app->instance('request', $request);
        $this->scope->bind(request: $request, admin: Admin::query()->findOrFail(1));

        $this->assertNull($this->scope->boundCountries());
    }

    // ---- narrowing a list of countries ---------------------------------------------------------------------------------------------

    public function test_a_list_of_countries_is_left_alone_while_nothing_is_bound(): void
    {
        $this->assertSame(['SA', 'AE', 'KW'], $this->scope->narrow(countries: ['SA', 'AE', 'KW']));
    }

    public function test_a_request_nobody_narrowed_covers_every_product_without_asking_the_database(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertTrue($this->scope->coversEveryProduct(productIds: [1, 99, 123]));

        $this->assertSame([], DB::getQueryLog(), 'an id that does not exist is the action\'s to answer, as it always was');
    }

    public function test_every_country_is_allowed_while_nothing_is_bound(): void
    {
        $this->assertTrue($this->scope->allows(country: 'KW'));
        $this->assertTrue($this->scope->allows(country: 'ZZ'));
        $this->assertFalse($this->scope->restricts());
    }

    public function test_a_list_of_countries_is_cut_to_the_bound_ones_and_keeps_its_order(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 2, countries: ['AE', 'KW']);
        $this->bindFor(adminId: 2);

        $this->assertSame(['AE', 'KW'], $this->scope->narrow(countries: ['SA', 'AE', 'KW', 'QA']));
        $this->assertSame([], $this->scope->narrow(countries: ['SA', 'QA']));
    }

    // ---- the table default ---------------------------------------------------------------------------------------------------------

    public function test_the_default_country_of_a_new_row_is_the_one_the_migration_gives_it(): void
    {
        $id = (int) DB::table('products')->insertGetId(['name' => 'No country given']);

        $this->assertSame(StaffCountryScope::TABLE_DEFAULT_COUNTRY, DB::table('products')->where('id', $id)->value('storefront_country'));
    }

    public function test_the_readiness_probe_looks_at_both_tables_and_the_column_each_time_it_is_asked(): void
    {
        $this->switchStaffScopesOn();
        $this->assertTrue($this->scope->enabled());

        Schema::drop('admin_country_scopes');

        $this->assertFalse($this->scope->enabled(), 'a worker started before a rollback notices it without a restart');
    }
}
