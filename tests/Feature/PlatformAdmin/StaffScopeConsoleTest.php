<?php

namespace Tests\Feature\PlatformAdmin;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsPlatformAdminWorld;
use Tests\Support\BuildsPlatformStaffWorld;
use Tests\TestCase;

/**
 * What the console says to an operator who holds both features (GCC-20 with GCC-14c): `gcc:platform-admin:list` shows, while the staff country scopes are on and enforced, the countries
 * the staff scope leaves each admin and the markets they come to, and `gcc:platform-admin:grant` says when the staff scope leaves an admin less than the grant gives. Both only read:
 * what is granted is what the operator gave, and the staff scope is the staff commands' to change.
 *
 * Admins: 7 is the master admin, 8 to 11 are employees.
 */
class StaffScopeConsoleTest extends TestCase
{
    use BuildsPlatformAdminWorld;
    use BuildsPlatformStaffWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildPlatformAdminWorld();
        $this->buildStaffCountryTable();
        foreach ([[7, 1], [8, 2], [9, 2], [10, 2], [11, 2]] as [$id, $role]) {
            DB::table('admins')->insert(['id' => $id, 'name' => "Admin {$id}", 'email' => "admin{$id}@example.test", 'password' => 'x', 'status' => 1, 'admin_role_id' => $role, 'created_at' => '2026-01-05 09:00:00']);
        }
        Carbon::setTestNow('2026-11-17 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---- the list ----------------------------------------------------------------------------------------------------------------------

    public function test_the_list_shows_what_the_staff_scope_leaves_of_each_grant_while_it_is_on(): void
    {
        $this->switchStaffScopes(on: true);
        $this->grant(['admin_id' => 7, '--confirm-owner' => true]);
        $this->grant(['admin_id' => 8, '--confirm-owner' => true]);
        $this->grant(['admin_id' => 9, '--countries' => 'KW,AE']);
        $this->grant(['admin_id' => 10, '--countries' => 'KW']);
        $this->grant(['admin_id' => 11, '--confirm-owner' => true]);
        $this->assignStaffCountries(adminId: 7, countries: ['KW']);
        $this->assignStaffCountries(adminId: 8, countries: ['SA', 'KW']);
        $this->assignStaffCountries(adminId: 9, countries: ['KW', 'OM']);
        $this->assignStaffCountries(adminId: 10, countries: ['SA']);

        [$exit, $output] = $this->runCommand([], 'gcc:platform-admin:list');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Staff countries', $output);
        $this->assertStringContainsString('Effective markets', $output);
        $rows = $this->listRows($output);
        $this->assertSame(['every market', '-', 'every market'], [$rows[7]['markets'], $rows[7]['staff'], $rows[7]['effective']], 'the master admin is never restricted, whatever rows it has');
        $this->assertSame(['every market', 'KW, SA', 'SA, KW'], [$rows[8]['markets'], $rows[8]['staff'], $rows[8]['effective']], 'the staff countries narrow the platform owner\'s grant');
        $this->assertSame(['AE, KW', 'KW, OM', 'KW'], [$rows[9]['markets'], $rows[9]['staff'], $rows[9]['effective']], 'the markets both lists name');
        $this->assertSame(['KW', 'SA', 'none'], [$rows[10]['markets'], $rows[10]['staff'], $rows[10]['effective']], 'a staff scope the grant does not meet leaves no market');
        $this->assertSame(['every market', '-', 'every market'], [$rows[11]['markets'], $rows[11]['staff'], $rows[11]['effective']], 'an employee with no row is not restricted');
    }

    public function test_the_list_names_an_employee_restricted_to_nothing_and_an_admin_that_is_gone(): void
    {
        $this->switchStaffScopes(on: true);
        $this->grant(['admin_id' => 8, '--confirm-owner' => true]);
        $this->assignStaffCountries(adminId: 8, countries: ['zz']);
        $this->grant(['admin_id' => 9, '--confirm-owner' => true]);
        Schema::withoutForeignKeyConstraints(fn () => DB::table('admins')->where('id', 9)->delete());

        [$exit, $output] = $this->runCommand([], 'gcc:platform-admin:list');

        $this->assertSame(0, $exit);
        $rows = $this->listRows($output);
        $this->assertSame(['every market', 'none', 'none'], [$rows[8]['markets'], $rows[8]['staff'], $rows[8]['effective']]);
        $this->assertSame(['admin missing', '-', '-'], [$rows[9]['state'], $rows[9]['staff'], $rows[9]['effective']]);
    }

    public function test_while_the_staff_switch_is_off_the_list_is_the_one_it_always_was_and_asks_nothing_about_the_staff_scope(): void
    {
        $this->switchStaffScopes(on: false);
        $this->grant(['admin_id' => 8, '--confirm-owner' => true]);
        $this->assignStaffCountries(adminId: 8, countries: ['KW']);
        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = strtolower($query->sql . json_encode($query->bindings));
        });

        [$exit, $output] = $this->runCommand([], 'gcc:platform-admin:list');

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('Staff countries', $output);
        $this->assertStringNotContainsString('Effective markets', $output);
        $this->assertSame(7, count(explode('|', trim($this->firstLineOfTheTable($output), '| '))), 'the seven columns it always had');
        $this->assertSame([], array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'admin_country_scopes') || str_contains($sql, 'admin_role_id'))));
    }

    public function test_with_the_switch_on_but_the_table_missing_the_list_shows_no_staff_column(): void
    {
        $this->switchStaffScopes(on: true);
        $this->grant(['admin_id' => 8, '--confirm-owner' => true]);
        Schema::drop('admin_country_scopes');

        [$exit, $output] = $this->runCommand([], 'gcc:platform-admin:list');

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('Staff countries', $output);
        $this->assertSame('every market', $this->listRows($output)[8]['markets']);
    }

    public function test_the_list_still_writes_nothing(): void
    {
        $this->switchStaffScopes(on: true);
        $this->grant(['admin_id' => 8, '--confirm-owner' => true]);
        $this->assignStaffCountries(adminId: 8, countries: ['KW']);
        $before = [DB::table('platform_admin_audit_logs')->count(), DB::table('admin_country_scopes')->get()->toJson(), DB::table('platform_admin_grants')->get()->toJson()];

        $this->runCommand([], 'gcc:platform-admin:list');

        $this->assertSame($before, [DB::table('platform_admin_audit_logs')->count(), DB::table('admin_country_scopes')->get()->toJson(), DB::table('platform_admin_grants')->get()->toJson()]);
    }

    // ---- the grant ---------------------------------------------------------------------------------------------------------------------

    public function test_a_grant_that_the_staff_scope_narrows_is_given_as_it_was_asked_and_the_operator_is_told(): void
    {
        $this->switchStaffScopes(on: true);
        $this->assignStaffCountries(adminId: 8, countries: ['SA', 'KW']);

        [$exit, $output] = $this->grant(['admin_id' => 8, '--confirm-owner' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Admin 8 may use the platform admin API at level 1 (monitor) for every market', $output);
        $this->assertStringContainsString('Staff country scopes are ON and admin 8 is restricted to KW, SA in the admin panel', $output);
        $this->assertStringContainsString('through the platform admin API they will see SA, KW, not all of every market', $output);
        $this->assertStringContainsString('The grant is as given; nothing was changed', $output);
        $this->assertNull(DB::table('platform_admin_grants')->where('admin_id', 8)->value('countries'), 'the grant is every market, as the operator said');
    }

    public function test_a_grant_the_staff_scope_leaves_no_market_of_says_so(): void
    {
        $this->switchStaffScopes(on: true);
        $this->assignStaffCountries(adminId: 9, countries: ['SA']);

        [, $output] = $this->grant(['admin_id' => 9, '--countries' => 'KW,AE']);

        $this->assertStringContainsString('restricted to SA in the admin panel', $output);
        $this->assertStringContainsString('they will see no market, not all of AE, KW', $output);
    }

    public function test_an_employee_restricted_to_nothing_is_told_so(): void
    {
        $this->switchStaffScopes(on: true);
        $this->assignStaffCountries(adminId: 9, countries: ['zz']);

        [, $output] = $this->grant(['admin_id' => 9, '--confirm-owner' => true]);

        $this->assertStringContainsString('restricted to no country in the admin panel', $output);
        $this->assertStringContainsString('they will see no market, not all of every market', $output);
    }

    /** @return array<string, array{0: int, 1: list<string>|null, 2: string, 3: bool}> */
    public static function casesWithNoNote(): array
    {
        return [
            'the staff scope takes nothing away' => [8, ['KW', 'SA'], '--countries=KW', true],
            'the staff scope names every market the grant does' => [8, ['KW', 'AE'], '--countries=KW,AE', true],
            'the master admin is never restricted' => [7, ['KW'], '--confirm-owner', true],
            'an employee with no row' => [8, null, '--confirm-owner', true],
            'the staff switch is off' => [8, ['KW'], '--confirm-owner', false],
        ];
    }

    /** @param list<string>|null $staffCountries */
    #[DataProvider('casesWithNoNote')]
    public function test_nothing_is_said_when_the_staff_scope_takes_nothing_from_the_grant(int $adminId, ?array $staffCountries, string $option, bool $switchOn): void
    {
        $this->switchStaffScopes(on: $switchOn);
        if ($staffCountries !== null) {
            $this->assignStaffCountries(adminId: $adminId, countries: $staffCountries);
        }
        $arguments = ['admin_id' => $adminId];
        [$name, $value] = array_pad(explode('=', $option, 2), 2, true);
        $arguments[$name] = $value;

        [$exit, $output] = $this->grant($arguments);

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('Staff country scopes', $output);
    }

    public function test_with_the_table_missing_nothing_is_said(): void
    {
        $this->switchStaffScopes(on: true);
        $this->assignStaffCountries(adminId: 8, countries: ['KW']);
        Schema::drop('admin_country_scopes');

        [$exit, $output] = $this->grant(['admin_id' => 8, '--confirm-owner' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('Staff country scopes', $output);
    }

    public function test_the_note_changes_nothing_and_the_audit_line_is_the_one_a_grant_always_wrote(): void
    {
        $this->switchStaffScopes(on: true);
        $this->assignStaffCountries(adminId: 8, countries: ['KW']);
        $staffBefore = DB::table('admin_country_scopes')->get()->toJson();

        $this->grant(['admin_id' => 8, '--confirm-owner' => true]);
        $this->switchStaffScopes(on: false);
        $this->grant(['admin_id' => 10, '--confirm-owner' => true]);

        $this->assertSame($staffBefore, DB::table('admin_country_scopes')->get()->toJson());
        $lines = DB::table('platform_admin_audit_logs')->orderBy('id')->get();
        $this->assertSame(['grant.created', 'grant.created'], $lines->pluck('action')->all());
        $this->assertSame(array_keys($this->contextOf($lines[0])), array_keys($this->contextOf($lines[1])), 'the same members, whether or not the staff scope narrowed the grant');
    }

    // ---- helpers -----------------------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function grant(array $arguments): array
    {
        return $this->runCommand($arguments, 'gcc:platform-admin:grant');
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function runCommand(array $arguments, string $command): array
    {
        $exit = Artisan::call($command, $arguments);

        return [$exit, Artisan::output()];
    }

    private function firstLineOfTheTable(string $output): string
    {
        foreach (explode("\n", $output) as $line) {
            if (str_starts_with($line, '|')) {
                return $line;
            }
        }

        return '';
    }

    /** @return array<int, array<string, string>> the rows of the table the list printed, by admin */
    private function listRows(string $output): array
    {
        $rows = [];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^\|\s*(\d+)\s*\|/', $line) !== 1) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim($line, '| ')));
            $rows[(int) $cells[0]] = ['name' => $cells[1], 'level' => $cells[2], 'markets' => $cells[3], 'capabilities' => $cells[4], 'ends' => $cells[5], 'state' => $cells[6], 'staff' => $cells[7] ?? null, 'effective' => $cells[8] ?? null];
        }

        return $rows;
    }
}
