<?php

namespace Tests\Feature\StaffScope;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsStaffScopeWorld;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * The only way to give an employee countries, list them and take them back is a command on the server. No HTTP route creates or widens a scope.
 *
 * An employee with no country is unrestricted, so a command that would leave an employee with none says so and refuses unless it is told, with --all, that
 * widening is what the operator means.
 */
class StaffCountryCommandsTest extends TestCase
{
    use BuildsStaffScopeWorld;
    use BuildsStorefrontCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontCatalogTables();
        $this->runStorefrontCountryMigration();
        $this->buildStaffAdminTables();
        $this->runStaffScopeMigration();
    }

    /** @return list<string> the countries stored for an admin, in order */
    private function stored(int $adminId): array
    {
        return DB::table('admin_country_scopes')->where('admin_id', $adminId)->orderBy('country')->pluck('country')->all();
    }

    // ---- assign ------------------------------------------------------------------------------------------------------------------

    public function test_assign_gives_an_employee_the_countries_named(): void
    {
        $this->artisan('gcc:staff-country:assign', ['admin_id' => 2, 'countries' => 'SA,KW'])
            ->expectsOutputToContain('Admin 2 (Catalog one) works with KW, SA only.')
            ->assertExitCode(0);

        $this->assertSame(['KW', 'SA'], $this->stored(adminId: 2));
        $this->assertSame([], $this->stored(adminId: 3));
    }

    public function test_assign_is_idempotent_and_adds_to_what_the_employee_already_has(): void
    {
        $this->artisan('gcc:staff-country:assign', ['admin_id' => 2, 'countries' => 'SA'])->assertExitCode(0);
        $this->artisan('gcc:staff-country:assign', ['admin_id' => 2, 'countries' => 'SA'])
            ->expectsOutputToContain('already had')
            ->assertExitCode(0);
        $this->assertSame(['SA'], $this->stored(adminId: 2));

        $this->artisan('gcc:staff-country:assign', ['admin_id' => 2, 'countries' => 'KW,SA'])
            ->expectsOutputToContain('Added KW.')
            ->assertExitCode(0);

        $this->assertSame(['KW', 'SA'], $this->stored(adminId: 2));
        $this->assertSame(2, DB::table('admin_country_scopes')->count());
    }

    public function test_assign_names_what_it_added_in_the_order_of_the_codes_whatever_order_they_were_typed_in(): void
    {
        $this->artisan('gcc:staff-country:assign', ['admin_id' => 2, 'countries' => 'SA,QA,KW'])
            ->expectsOutputToContain('Added KW, QA, SA.')
            ->assertExitCode(0);
    }

    public function test_assign_takes_codes_in_any_case_trims_them_and_collapses_repeats(): void
    {
        $this->artisan('gcc:staff-country:assign', ['admin_id' => 2, 'countries' => ' kw , KW,sa '])->assertExitCode(0);

        $this->assertSame(['KW', 'SA'], $this->stored(adminId: 2));
    }

    public function test_assign_refuses_a_code_that_is_not_configured_and_writes_nothing_not_even_the_good_ones(): void
    {
        $this->artisan('gcc:staff-country:assign', ['admin_id' => 2, 'countries' => 'SA,ZZ'])
            ->expectsOutputToContain('ZZ is not a configured country')
            ->assertExitCode(1);

        $this->assertSame([], $this->stored(adminId: 2));
    }

    public function test_assign_checks_the_codes_against_the_configuration_of_the_moment(): void
    {
        config(['gcc.storefronts.countries' => array_diff_key(config('gcc.storefronts.countries'), ['QA' => true])]);

        $this->artisan('gcc:staff-country:assign', ['admin_id' => 2, 'countries' => 'QA'])->assertExitCode(1);
        $this->artisan('gcc:staff-country:assign', ['admin_id' => 2, 'countries' => 'BH'])->assertExitCode(0);

        $this->assertSame(['BH'], $this->stored(adminId: 2));
    }

    public function test_assign_refuses_an_empty_list_of_countries(): void
    {
        foreach (['', ' ', ',', ' , '] as $countries) {
            $this->artisan('gcc:staff-country:assign', ['admin_id' => 2, 'countries' => $countries])
                ->expectsOutputToContain('Name at least one country')
                ->assertExitCode(1);
        }

        $this->assertSame(0, DB::table('admin_country_scopes')->count());
    }

    public function test_assign_refuses_an_admin_id_that_is_not_a_positive_whole_number_or_names_no_admin(): void
    {
        foreach (['0', '-2', '2.5', 'abc', '1e1', ''] as $adminId) {
            $this->artisan('gcc:staff-country:assign', ['admin_id' => $adminId, 'countries' => 'SA'])
                ->expectsOutputToContain('The admin id must be a positive whole number')
                ->assertExitCode(1);
        }

        $this->artisan('gcc:staff-country:assign', ['admin_id' => 99, 'countries' => 'SA'])
            ->expectsOutputToContain('There is no admin 99')
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('admin_country_scopes')->count());
    }

    public function test_assign_refuses_the_master_admin_because_a_scope_on_it_would_do_nothing(): void
    {
        $this->artisan('gcc:staff-country:assign', ['admin_id' => 1, 'countries' => 'SA'])
            ->expectsOutputToContain('master admin is never restricted')
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('admin_country_scopes')->count());
    }

    public function test_assign_says_when_the_switch_is_off_that_nothing_is_enforced_yet(): void
    {
        $this->artisan('gcc:staff-country:assign', ['admin_id' => 2, 'countries' => 'SA'])
            ->expectsOutputToContain('GCC_STAFF_COUNTRY_SCOPES is off')
            ->assertExitCode(0);
    }

    public function test_assign_does_not_say_it_when_the_switch_is_on(): void
    {
        $this->switchStaffScopesOn();

        $this->artisan('gcc:staff-country:assign', ['admin_id' => 2, 'countries' => 'SA'])
            ->doesntExpectOutputToContain('is off')
            ->assertExitCode(0);
    }

    public function test_every_command_says_so_when_the_migration_has_not_run_and_changes_nothing(): void
    {
        $this->rollBackStaffScopeMigration();

        $this->artisan('gcc:staff-country:assign', ['admin_id' => 2, 'countries' => 'SA'])
            ->expectsOutputToContain('admin_country_scopes does not exist yet')
            ->assertExitCode(1);
        $this->artisan('gcc:staff-country:revoke', ['admin_id' => 2, '--all' => true])->assertExitCode(1);
        $this->artisan('gcc:staff-country:list')->assertExitCode(1);
    }

    // ---- revoke ------------------------------------------------------------------------------------------------------------------

    public function test_revoke_takes_back_the_countries_named_and_keeps_the_others(): void
    {
        $this->giveCountries(adminId: 2, countries: ['KW', 'QA', 'SA']);

        $this->artisan('gcc:staff-country:revoke', ['admin_id' => 2, 'countries' => 'qa,KW'])
            ->expectsOutputToContain('Admin 2 (Catalog one) works with SA only.')
            ->assertExitCode(0);

        $this->assertSame(['SA'], $this->stored(adminId: 2));
    }

    public function test_revoke_is_idempotent_for_a_country_the_employee_does_not_have(): void
    {
        $this->giveCountries(adminId: 2, countries: ['SA', 'KW']);

        $this->artisan('gcc:staff-country:revoke', ['admin_id' => 2, 'countries' => 'QA'])
            ->expectsOutputToContain('did not have QA')
            ->assertExitCode(0);

        $this->assertSame(['KW', 'SA'], $this->stored(adminId: 2));
    }

    public function test_revoke_for_an_employee_who_has_no_country_says_so_and_changes_nothing(): void
    {
        $this->giveCountries(adminId: 3, countries: ['KW']);

        $this->artisan('gcc:staff-country:revoke', ['admin_id' => 2, 'countries' => 'QA'])
            ->expectsOutputToContain('did not have QA')
            ->assertExitCode(0);

        $this->assertSame([], $this->stored(adminId: 2));
        $this->assertSame(['KW'], $this->stored(adminId: 3));
    }

    public function test_revoke_refuses_to_take_back_the_last_country_because_that_would_widen_the_employee(): void
    {
        $this->giveCountries(adminId: 2, countries: ['KW']);

        $this->artisan('gcc:staff-country:revoke', ['admin_id' => 2, 'countries' => 'KW'])
            ->expectsOutputToContain('would leave admin 2 with no country, which makes the employee unrestricted')
            ->assertExitCode(1);

        $this->assertSame(['KW'], $this->stored(adminId: 2));
    }

    public function test_revoke_with_all_takes_back_every_country_and_says_the_employee_is_unrestricted_now(): void
    {
        $this->giveCountries(adminId: 2, countries: ['KW', 'SA']);
        $this->giveCountries(adminId: 3, countries: ['KW']);

        $this->artisan('gcc:staff-country:revoke', ['admin_id' => 2, '--all' => true])
            ->expectsOutputToContain('Admin 2 (Catalog one) has no country any more and is not restricted.')
            ->assertExitCode(0);

        $this->assertSame([], $this->stored(adminId: 2));
        $this->assertSame(['KW'], $this->stored(adminId: 3), 'another employee keeps theirs');
    }

    public function test_revoke_with_all_works_for_rows_an_admin_that_was_deleted_left_behind(): void
    {
        $this->rollBackStaffScopeMigration();
        Schema::rename('admins', 'admins_kept');
        $this->runStaffScopeMigration();
        Schema::rename('admins_kept', 'admins');
        $this->giveCountries(adminId: 77, countries: ['KW']);

        $this->artisan('gcc:staff-country:revoke', ['admin_id' => 77, '--all' => true])->assertExitCode(0);

        $this->assertSame([], $this->stored(adminId: 77));
    }

    public function test_revoke_asks_for_the_countries_or_all_and_refuses_both_at_once(): void
    {
        $this->giveCountries(adminId: 2, countries: ['KW', 'SA']);

        $this->artisan('gcc:staff-country:revoke', ['admin_id' => 2])
            ->expectsOutputToContain('Name the countries to take back, or use --all')
            ->assertExitCode(1);
        $this->artisan('gcc:staff-country:revoke', ['admin_id' => 2, 'countries' => 'KW', '--all' => true])
            ->expectsOutputToContain('Use either')
            ->assertExitCode(1);

        $this->assertSame(['KW', 'SA'], $this->stored(adminId: 2));
    }

    public function test_revoke_refuses_a_code_that_is_not_configured_and_an_admin_id_that_is_not_a_number(): void
    {
        $this->giveCountries(adminId: 2, countries: ['KW', 'SA']);

        $this->artisan('gcc:staff-country:revoke', ['admin_id' => 2, 'countries' => 'ZZ'])->assertExitCode(1);
        $this->artisan('gcc:staff-country:revoke', ['admin_id' => 'two', 'countries' => 'KW'])->assertExitCode(1);

        $this->assertSame(['KW', 'SA'], $this->stored(adminId: 2));
    }

    public function test_revoke_leaves_a_damaged_row_in_place_which_keeps_the_employee_restricted_until_everything_is_taken_back(): void
    {
        $this->giveCountries(adminId: 2, countries: ['KW', 'zz']);

        $this->artisan('gcc:staff-country:revoke', ['admin_id' => 2, 'countries' => 'KW'])->assertExitCode(0);
        $this->assertSame(['zz'], $this->stored(adminId: 2), 'the damaged row stays: the employee is restricted to nothing, never widened');

        $this->artisan('gcc:staff-country:revoke', ['admin_id' => 2, 'countries' => 'SA'])->assertExitCode(0);
        $this->assertSame(['zz'], $this->stored(adminId: 2));

        $this->artisan('gcc:staff-country:revoke', ['admin_id' => 2, '--all' => true])->assertExitCode(0);
        $this->assertSame([], $this->stored(adminId: 2));
    }

    // ---- list --------------------------------------------------------------------------------------------------------------------

    public function test_list_shows_every_restricted_employee_with_the_countries_they_work_with(): void
    {
        $this->giveCountries(adminId: 2, countries: ['KW', 'SA']);
        $this->giveCountries(adminId: 3, countries: ['AE']);

        $output = $this->outputOf('gcc:staff-country:list');

        $this->assertMatchesRegularExpression('/\|\s*2\s*\|\s*Catalog one\s*\|\s*KW, SA\s*\|\s*KW, SA only\s*\|/', $output);
        $this->assertMatchesRegularExpression('/\|\s*3\s*\|\s*Catalog two\s*\|\s*AE\s*\|\s*AE only\s*\|/', $output);
    }

    public function test_list_says_when_nobody_is_restricted(): void
    {
        $this->artisan('gcc:staff-country:list')
            ->expectsOutputToContain('No employee has a country scope')
            ->assertExitCode(0);
    }

    public function test_list_for_one_employee_says_they_are_unrestricted_when_they_have_no_row(): void
    {
        $this->artisan('gcc:staff-country:list', ['admin_id' => 2])
            ->expectsOutputToContain('Admin 2 (Catalog one) has no country scope and is not restricted.')
            ->assertExitCode(0);
    }

    public function test_list_for_one_employee_shows_that_employee_and_not_the_first_row_of_the_table(): void
    {
        $this->giveCountries(adminId: 2, countries: ['KW']);
        $this->giveCountries(adminId: 3, countries: ['AE', 'QA']);

        $output = $this->outputOf('gcc:staff-country:list', ['admin_id' => 3]);

        $this->assertStringContainsString('Admin 3 (Catalog two) works with AE, QA only.', $output);
        $this->assertStringNotContainsString('Catalog one', $output);
    }

    public function test_list_for_the_master_admin_says_it_is_never_restricted_and_shows_rows_it_holds(): void
    {
        $this->giveCountries(adminId: 1, countries: ['KW']);

        $this->assertStringContainsString('Admin 1 (Master): the master admin is never restricted. Stored, and ignored: KW.', $this->outputOf('gcc:staff-country:list', ['admin_id' => 1]));
    }

    public function test_list_flags_a_value_that_grants_nothing_and_says_the_employee_works_with_nothing(): void
    {
        $this->giveCountries(adminId: 2, countries: ['zz']);

        $output = $this->outputOf('gcc:staff-country:list', ['admin_id' => 2]);

        $this->assertStringContainsString('Admin 2 (Catalog one) works with no country: every value stored for it grants nothing.', $output);
        $this->assertStringContainsString('Stored value zz grants nothing', $output);
    }

    public function test_list_says_whether_the_scopes_are_enforced(): void
    {
        $this->giveCountries(adminId: 2, countries: ['KW']);

        $this->artisan('gcc:staff-country:list')->expectsOutputToContain('GCC_STAFF_COUNTRY_SCOPES is off')->assertExitCode(0);

        $this->switchStaffScopesOn();
        $this->artisan('gcc:staff-country:list')->expectsOutputToContain('Scopes are enforced')->assertExitCode(0);
    }

    public function test_list_says_when_the_products_country_column_is_missing(): void
    {
        $this->switchStaffScopesOn();
        $this->removeStorefrontCountryColumn();

        $this->artisan('gcc:staff-country:list')->expectsOutputToContain('products.storefront_country does not exist')->assertExitCode(0);
    }

    public function test_list_refuses_an_admin_id_that_is_not_a_number(): void
    {
        $this->artisan('gcc:staff-country:list', ['admin_id' => 'x'])->assertExitCode(1);
    }

    // ---- nothing reachable over HTTP creates or widens a scope --------------------------------------------------------------------

    public function test_nothing_a_request_can_reach_names_the_table_of_countries_or_the_class_that_writes_it(): void
    {
        $reaching = [];
        foreach ([app_path('Http'), base_path('routes'), base_path('Modules'), resource_path('views')] as $root) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $contents = $file->isFile() && in_array($file->getExtension(), ['php'], true) ? (string) file_get_contents($file->getPathname()) : '';
                if (str_contains($contents, 'admin_country_scopes') || str_contains($contents, 'StaffCountryAssignments')) {
                    $reaching[] = str_replace(base_path() . '/', '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $reaching, 'only the console commands write the table');
    }

    /** @param array<string, mixed> $arguments */
    private function outputOf(string $command, array $arguments = []): string
    {
        Artisan::call($command, $arguments);

        return Artisan::output();
    }
}
