<?php

namespace Tests\Feature\PlatformAdmin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsPlatformStaffWorld;
use Tests\Support\SeedsPlatformMarketData;

/**
 * What the platform admin API costs the staff country scopes (GCC-14c) and what they cost it: nothing while the staff switch is off, nothing for the master admin who is never
 * restricted, one read of the staff table per request for an employee while it is on, and never anything remembered from one request to the next.
 */
class StaffScopeDormancyTest extends PlatformAdminTestCase
{
    use BuildsPlatformStaffWorld;
    use SeedsPlatformMarketData;

    private const READ_PATHS = ['/me', '/markets', '/overview', '/alerts', '/audit-logs', '/sessions'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMarketScenario();
        $this->buildStaffCountryTable();
        config(['gcc.platform_admin.session.max_concurrent' => 20]);
    }

    public function test_while_the_staff_switch_is_off_no_request_asks_anything_about_the_staff_scope(): void
    {
        $this->switchStaffScopes(on: false);
        $employee = $this->employeeWithGrant(grantCountries: null, staffCountries: ['KW']);
        $master = $this->platformAdmin();
        $this->assignStaffCountries(adminId: $master['id'], countries: ['KW']);

        foreach ([$employee, $master] as $admin) {
            $statements = $this->statementsOfEveryRequest(admin: $admin);

            $this->assertNotEmpty($statements);
            $this->assertSame([], $this->askingAboutTheStaffScope($statements), 'neither the staff table, nor its schema, nor the role of the admin');
        }
    }

    public function test_while_the_staff_switch_is_off_the_same_statements_run_whether_the_staff_table_exists_or_not(): void
    {
        $this->switchStaffScopes(on: false);
        $employee = $this->employeeWithGrant(grantCountries: null, staffCountries: ['KW']);

        $this->statementsOfEveryRequest(admin: $employee);
        $with = $this->textOf(statements: $this->statementsOfEveryRequest(admin: $employee));
        Schema::drop('admin_country_scopes');
        $without = $this->textOf(statements: $this->statementsOfEveryRequest(admin: $employee));

        $this->assertNotEmpty($with);
        $this->assertSame($with, $without);
    }

    public function test_while_the_staff_switch_is_off_the_admin_is_read_with_the_columns_it_has_always_been_read_with(): void
    {
        $this->switchStaffScopes(on: false);
        $token = $this->mintToken($this->employeeWithGrant(grantCountries: null, staffCountries: ['KW']));

        $statements = $this->statementsRunBy(fn () => $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertOk());

        $this->assertContains('select "id", "name", "status", "password", "created_at" from "admins" where "admins"."id" = ? limit 1', array_map(static fn (string $sql): string => str_replace('`', '"', $sql), $statements));
    }

    public function test_the_master_admin_costs_no_statement_when_the_switch_is_on_and_never_touches_the_staff_table(): void
    {
        $master = $this->platformAdmin();
        $this->assignStaffCountries(adminId: $master['id'], countries: ['KW']);
        $token = $this->mintToken($master);

        foreach (self::READ_PATHS as $path) {
            $this->switchStaffScopes(on: false);
            $this->getJson(self::PREFIX . $path, $this->bearer($token))->assertOk();
            $off = $this->statementsWithBindings(fn () => $this->getJson(self::PREFIX . $path, $this->bearer($token)));
            $this->switchStaffScopes(on: true);
            $on = $this->statementsWithBindings(fn () => $this->getJson(self::PREFIX . $path, $this->bearer($token)));

            $this->assertSame(count($off), count($on), "{$path}: the role is read in the statement that reads the admin, so there is no statement more");
            $this->assertSame([], $this->askingAboutTheStaffTable($on), "{$path}: the master admin is never restricted, so the table is not asked, nor is its schema");
        }
    }

    public function test_an_employee_reads_the_staff_table_once_for_a_request_and_again_for_the_next(): void
    {
        $this->switchStaffScopes(on: true);
        $employee = $this->employeeWithGrant(grantCountries: null, staffCountries: ['KW']);
        $withoutRow = $this->employeeWithGrant(grantCountries: null, staffCountries: null);

        // ONE resolve per request, and a resolve is a DIFFERENT number of reads for the two employees -- which is the security-relevant
        // branch and is worth asserting rather than flattening. `StaffCountryScope::resolve()` asks the table two questions on purpose
        // (G1-14): first whether this employee holds ANY row, then which of their rows are still in force. An employee with NO row is
        // unrestricted on this platform, so `resolve()` RETURNS EARLY on the first answer and never asks the second -- hence one read.
        // An employee who does hold rows is asked both -- hence two.
        //
        // Merging the two questions into a single "ignore the expired rows" query would make an employee whose every row has EXPIRED look
        // like an employee with no row, and so unrestricted: a privilege escalation on a timer. Both expectations said 1 until the grant
        // trail arrived (3e33a819) and this test predates it; a flat 2 is just as wrong, and this test is what caught that.
        foreach ([[$employee, 2, 'holds rows, so both questions are asked'], [$withoutRow, 1, 'holds none, so resolve returns on the first answer']] as [$admin, $expected, $why]) {
            $token = $this->mintToken($admin);
            foreach (range(1, 3) as $attempt) {
                $statements = $this->statementsWithBindings(fn () => $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertOk());

                $this->assertCount($expected, $this->readsOfTheStaffTable($statements), "request {$attempt}: one resolve, however many readers there are of the principal -- this employee {$why}");
            }
        }
    }

    public function test_the_sign_in_of_an_employee_reads_the_staff_table_once_and_that_of_the_master_admin_not_at_all(): void
    {
        $this->switchStaffScopes(on: true);
        $employee = $this->employeeWithGrant(grantCountries: null, staffCountries: ['KW']);
        $master = $this->platformAdmin();
        $this->assignStaffCountries(adminId: $master['id'], countries: ['KW']);

        $employeeSignIn = $this->statementsWithBindings(fn () => $this->postJson(self::PREFIX . '/auth/login', ['email' => $employee['email'], 'password' => $employee['password'], 'device_name' => 'Pixel'])->assertOk());
        $masterSignIn = $this->statementsWithBindings(fn () => $this->postJson(self::PREFIX . '/auth/login', ['email' => $master['email'], 'password' => $master['password'], 'device_name' => 'Pixel'])->assertOk());

        // One resolve, which is two reads: see the note in the test above.
        $this->assertCount(2, $this->readsOfTheStaffTable($employeeSignIn));
        $this->assertSame([], $this->askingAboutTheStaffTable($masterSignIn));
    }

    public function test_a_refused_request_never_gets_as_far_as_the_staff_scope(): void
    {
        $this->switchStaffScopes(on: true);
        $this->employeeWithGrant(grantCountries: null, staffCountries: ['KW']);

        $statements = $this->statementsWithBindings(function (): void {
            $this->getJson(self::PREFIX . '/me', $this->bearer('lkpa_' . str_repeat('a', 64)))->assertStatus(401);
            $this->getJson(self::PREFIX . '/me')->assertStatus(401);
        });

        $this->assertSame([], $this->askingAboutTheStaffScope($statements));
    }

    /** @return list<array{0: string, 1: array<int, mixed>}> */
    private function statementsOfEveryRequest(array $admin): array
    {
        $token = $this->mintToken($admin);

        return $this->statementsWithBindings(function () use ($admin, $token): void {
            foreach (self::READ_PATHS as $path) {
                $this->getJson(self::PREFIX . $path, $this->bearer($token));
            }
            $this->postJson(self::PREFIX . '/auth/login', ['email' => $admin['email'], 'password' => $admin['password'], 'device_name' => 'Pixel']);
        });
    }

    /**
     * @param callable(): mixed $action
     *
     * @return list<array{0: string, 1: array<int, mixed>}> the SQL of every statement the action ran and what was bound to it
     */
    private function statementsWithBindings(callable $action): array
    {
        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = [$query->sql, $query->bindings];
        });
        $action();

        return $statements;
    }

    /**
     * @param list<array{0: string, 1: array<int, mixed>}> $statements
     *
     * @return list<string> the statements that name the staff table, whether as SQL or as the name asked of the schema
     */
    private function askingAboutTheStaffTable(array $statements): array
    {
        return array_values(array_map(static fn (array $statement): string => $statement[0], array_filter($statements, static fn (array $statement): bool => str_contains(strtolower($statement[0] . json_encode($statement[1])), 'admin_country_scopes'))));
    }

    /**
     * @param list<array{0: string, 1: array<int, mixed>}> $statements
     *
     * @return list<string> the statements that name the staff table, and the ones that read the role of an admin
     */
    private function askingAboutTheStaffScope(array $statements): array
    {
        return array_values(array_map(static fn (array $statement): string => $statement[0], array_filter($statements, static fn (array $statement): bool => str_contains(strtolower($statement[0] . json_encode($statement[1])), 'admin_country_scopes') || str_contains(strtolower($statement[0]), 'admin_role_id'))));
    }

    /**
     * @param list<array{0: string, 1: array<int, mixed>}> $statements
     *
     * @return list<string> the statements that read rows of the staff table
     */
    private function readsOfTheStaffTable(array $statements): array
    {
        return array_values(array_map(static fn (array $statement): string => $statement[0], array_filter($statements, static fn (array $statement): bool => preg_match('/\bfrom\s+[`"]?admin_country_scopes[`"]?(\s|$)/i', $statement[0]) === 1)));
    }

    /**
     * @param list<array{0: string, 1: array<int, mixed>}> $statements
     *
     * @return list<string>
     */
    private function textOf(array $statements): array
    {
        return array_map(static fn (array $statement): string => $statement[0], $statements);
    }
}
