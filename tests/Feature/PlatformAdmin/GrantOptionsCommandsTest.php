<?php

namespace Tests\Feature\PlatformAdmin;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsPlatformAdminWorld;
use Tests\TestCase;

/**
 * What an operator can say about a grant besides the markets: the parts of the API it includes, the day it ends, and that a grant for every market is meant;
 * and the list that shows every grant.
 */
class GrantOptionsCommandsTest extends TestCase
{
    use BuildsPlatformAdminWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildPlatformAdminWorld();
        foreach ([[7, 1], [8, 1], [9, 0], [10, 1]] as [$id, $status]) {
            DB::table('admins')->insert(['id' => $id, 'name' => "Admin {$id}", 'email' => "admin{$id}@example.test", 'password' => 'x', 'status' => $status, 'created_at' => '2026-01-05 09:00:00']);
        }
        Carbon::setTestNow('2026-11-17 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---- a grant for every market is meant -----------------------------------------------------------------------------------------------------

    public function test_a_grant_for_every_market_needs_the_owner_to_be_confirmed_and_without_it_nothing_is_written(): void
    {
        [$exit, $output] = $this->runCommand(['admin_id' => 7]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--confirm-owner', $output);
        $this->assertStringContainsString('every market', $output);
        $this->assertSame(0, DB::table('platform_admin_grants')->count());
        $this->assertSame(0, DB::table('platform_admin_audit_logs')->count());
    }

    public function test_with_the_owner_confirmed_it_is_a_grant_for_every_market_every_capability_and_no_end(): void
    {
        [$exit, $output] = $this->runCommand(['admin_id' => 7, '--confirm-owner' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('every market, every capability, with no end', $output);
        $grant = DB::table('platform_admin_grants')->where('admin_id', 7)->first();
        $this->assertNull($grant->countries);
        $this->assertNull($grant->capabilities);
        $this->assertNull($grant->expires_at);
    }

    public function test_countries_left_empty_name_no_country_and_so_are_a_grant_for_every_market_that_needs_the_owner_confirmed(): void
    {
        [$exit, $output] = $this->runCommand(['admin_id' => 7, '--countries' => '']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--confirm-owner', $output);
        $this->assertSame(0, DB::table('platform_admin_grants')->count());

        [$exit] = $this->runCommand(['admin_id' => 7, '--countries' => '  ', '--confirm-owner' => true]);

        $this->assertSame(0, $exit);
        $this->assertNull(DB::table('platform_admin_grants')->where('admin_id', 7)->value('countries'));
    }

    public function test_confirming_the_owner_beside_a_list_of_countries_is_refused_because_it_says_the_opposite(): void
    {
        [$exit, $output] = $this->runCommand(['admin_id' => 7, '--countries' => 'KW', '--confirm-owner' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--confirm-owner', $output);
        $this->assertStringContainsString('--countries', $output);
        $this->assertSame(0, DB::table('platform_admin_grants')->count());
    }

    public function test_a_restricted_grant_is_not_widened_by_running_the_command_without_options(): void
    {
        $this->runCommand(['admin_id' => 7, '--countries' => 'KW']);

        [$exit] = $this->runCommand(['admin_id' => 7]);

        $this->assertSame(1, $exit);
        $this->assertSame(['KW'], json_decode((string) DB::table('platform_admin_grants')->value('countries'), true), 'what was there is still there');
    }

    // ---- capabilities -------------------------------------------------------------------------------------------------------------------------

    public function test_capabilities_are_normalised_deduplicated_and_kept_in_their_own_order_and_said_back(): void
    {
        [$exit, $output] = $this->runCommand(['admin_id' => 7, '--countries' => 'KW', '--capabilities' => ' sessions, OVERVIEW ,sessions,alerts']);

        $this->assertSame(0, $exit);
        $this->assertSame(['overview', 'alerts', 'sessions'], json_decode((string) DB::table('platform_admin_grants')->value('capabilities'), true));
        $this->assertStringContainsString('overview, alerts, sessions', $output);
        $this->assertStringNotContainsString('every capability', $output);
    }

    public function test_naming_all_five_stores_the_five_and_naming_none_stores_null(): void
    {
        $this->runCommand(['admin_id' => 7, '--countries' => 'KW', '--capabilities' => 'overview,markets,alerts,audit,sessions']);
        $this->assertSame(['overview', 'markets', 'alerts', 'audit', 'sessions'], json_decode((string) DB::table('platform_admin_grants')->value('capabilities'), true));

        $this->runCommand(['admin_id' => 7, '--countries' => 'KW']);
        $this->assertNull(DB::table('platform_admin_grants')->value('capabilities'));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function badCapabilities(): array
    {
        return [
            'a name that is not one' => ['overview,payments', 'Unknown capability: payments'],
            'two that are not' => ['payments,orders', 'Unknown capabilities: payments, orders'],
            'one that is not, named twice' => ['payments, Payments', 'Unknown capability: payments.'],
            'a list with no name in it' => [' , ', 'no capability'],
            'a name in the wrong place' => ['overview;alerts', 'Unknown capability: overview;alerts'],
        ];
    }

    #[DataProvider('badCapabilities')]
    public function test_capabilities_that_are_not_capabilities_are_refused_and_an_existing_grant_is_untouched(string $given, string $message): void
    {
        $this->runCommand(['admin_id' => 7, '--countries' => 'KW', '--capabilities' => 'alerts']);
        $before = json_encode(DB::table('platform_admin_grants')->get());

        [$exit, $output] = $this->runCommand(['admin_id' => 7, '--countries' => 'AE', '--capabilities' => $given]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString($message, $output);
        $this->assertStringContainsString('overview, markets, alerts, audit, sessions', $output, 'it says which there are');
        $this->assertSame($before, json_encode(DB::table('platform_admin_grants')->get()));
        $this->assertSame(1, DB::table('platform_admin_audit_logs')->count());
    }

    // ---- the day it ends ----------------------------------------------------------------------------------------------------------------------

    public function test_a_grant_is_good_through_the_end_of_the_day_it_names_and_the_command_says_so(): void
    {
        [$exit, $output] = $this->runCommand(['admin_id' => 7, '--countries' => 'KW', '--expires' => '2026-12-31']);

        $this->assertSame(0, $exit);
        $this->assertSame('2026-12-31 23:59:59', DB::table('platform_admin_grants')->value('expires_at'));
        $this->assertStringContainsString('until the end of 2026-12-31', $output);
    }

    public function test_today_is_a_day_a_grant_can_end_on_and_yesterday_is_not(): void
    {
        [$exit] = $this->runCommand(['admin_id' => 7, '--countries' => 'KW', '--expires' => '2026-11-17']);
        $this->assertSame(0, $exit);
        $this->assertSame('2026-11-17 23:59:59', DB::table('platform_admin_grants')->value('expires_at'));

        [$exit, $output] = $this->runCommand(['admin_id' => 8, '--countries' => 'KW', '--expires' => '2026-11-16']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('has passed', $output);
        $this->assertSame(0, DB::table('platform_admin_grants')->where('admin_id', 8)->count());
    }

    /** @return array<string, array{string}> */
    public static function badDates(): array
    {
        return [
            'a month that does not exist' => ['2026-13-01'],
            'a day that does not exist' => ['2026-02-30'],
            'the order of the days' => ['31-12-2026'],
            'no leading zeros' => ['2026-1-5'],
            'a word' => ['tomorrow'],
            'a time as well' => ['2026-12-31 10:00'],
            'a time zone' => ['2026-12-31T00:00:00Z'],
            'slashes' => ['2026/12/31'],
            'nothing but spaces' => ['   '],
            'two digit years' => ['26-12-31'],
        ];
    }

    #[DataProvider('badDates')]
    public function test_anything_but_a_date_written_year_month_day_is_refused_and_nothing_is_written(string $given): void
    {
        [$exit, $output] = $this->runCommand(['admin_id' => 7, '--countries' => 'KW', '--expires' => $given]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('YYYY-MM-DD', $output);
        $this->assertSame(0, DB::table('platform_admin_grants')->count());
        $this->assertSame(0, DB::table('platform_admin_audit_logs')->count());
    }

    public function test_the_day_is_the_day_of_the_zone_the_platform_stamps_its_rows_in(): void
    {
        date_default_timezone_set('Asia/Kuwait');
        Carbon::setTestNow('2026-11-17 23:30:00');
        try {
            [$exit] = $this->runCommand(['admin_id' => 7, '--countries' => 'KW', '--expires' => '2026-11-17']);
            $this->assertSame(0, $exit, 'it is still the 17th where the platform is');
            $this->assertSame('2026-11-17 23:59:59', DB::table('platform_admin_grants')->value('expires_at'));
        } finally {
            date_default_timezone_set(config('app.timezone'));
        }
    }

    // ---- each run sets the whole grant ---------------------------------------------------------------------------------------------------------

    public function test_each_run_sets_the_whole_grant_and_what_it_leaves_out_is_the_default(): void
    {
        $this->runCommand(['admin_id' => 7, '--countries' => 'KW', '--capabilities' => 'alerts', '--expires' => '2026-12-31']);

        $this->runCommand(['admin_id' => 7, '--countries' => 'AE']);

        $grant = DB::table('platform_admin_grants')->where('admin_id', 7)->first();
        $this->assertSame(['AE'], json_decode($grant->countries, true));
        $this->assertNull($grant->capabilities);
        $this->assertNull($grant->expires_at);
        $this->assertSame(1, DB::table('platform_admin_grants')->count());
    }

    public function test_the_audit_line_says_what_the_grant_was_and_what_it_is_and_who_ran_the_command(): void
    {
        $this->runCommand(['admin_id' => 7, '--countries' => 'KW', '--capabilities' => 'alerts', '--expires' => '2026-12-31']);
        $this->runCommand(['admin_id' => 7, '--confirm-owner' => true]);

        $lines = $this->auditLines();
        $expires = Carbon::parse('2026-12-31 23:59:59')->utc()->toIso8601ZuluString();
        $this->assertSame(['grant.created', 'grant.updated'], $lines->pluck('action')->all());
        $this->assertSame(
            $this->sortedByKey(['source' => 'console', 'operator' => $this->operator(), 'before' => null, 'after' => ['level' => 1, 'countries' => ['KW'], 'capabilities' => ['alerts'], 'expires_at' => $expires]]),
            $this->contextOf($lines[0]),
        );
        $this->assertSame(
            $this->sortedByKey([
                'source' => 'console', 'operator' => $this->operator(),
                'before' => ['level' => 1, 'countries' => ['KW'], 'capabilities' => ['alerts'], 'expires_at' => $expires, 'revoked' => false],
                'after' => ['level' => 1, 'countries' => null, 'capabilities' => null, 'expires_at' => null],
            ]),
            $this->contextOf($lines[1]),
        );
    }

    public function test_every_command_that_changes_a_grant_puts_the_operator_on_its_line(): void
    {
        $this->runCommand(['admin_id' => 7, '--confirm-owner' => true]);
        Artisan::call('gcc:platform-admin:revoke-tokens', ['admin_id' => 7]);
        Artisan::call('gcc:platform-admin:revoke', ['admin_id' => 7]);
        $this->runCommand(['admin_id' => 7, '--confirm-owner' => true]);

        $lines = $this->auditLines();
        $this->assertSame(['grant.created', 'tokens.revoked', 'grant.revoked', 'grant.reactivated'], $lines->pluck('action')->all());
        foreach ($lines as $line) {
            $context = json_decode($line->context, true);
            $this->assertSame('console', $context['source'], $line->action);
            $this->assertSame($this->sortedByKey($this->operator()), $this->sortedByKey($context['operator']), $line->action);
        }
    }

    public function test_giving_back_a_grant_that_has_ended_is_a_reactivation_and_ends_the_sessions_that_were_left_from_before(): void
    {
        $this->runCommand(['admin_id' => 7, '--countries' => 'KW', '--expires' => '2026-11-17']);
        $grantId = (int) DB::table('platform_admin_grants')->value('id');
        DB::table('platform_admin_tokens')->insert([
            'admin_id' => 7, 'grant_id' => $grantId, 'token_hash' => str_repeat('a', 64), 'password_fingerprint' => str_repeat('c', 64), 'device_name' => 'Test phone',
            'expires_at' => now()->addDays(3), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('platform_admin_mfa_challenges')->insert([
            'token_hash' => str_repeat('b', 64), 'admin_id' => 7, 'grant_id' => $grantId, 'password_fingerprint' => str_repeat('c', 64), 'device_name' => 'Test phone',
            'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Carbon::setTestNow('2026-11-18 10:00:00');

        [$exit] = $this->runCommand(['admin_id' => 7, '--countries' => 'KW', '--expires' => '2027-01-31']);

        $this->assertSame(0, $exit);
        $this->assertNotNull(DB::table('platform_admin_tokens')->value('revoked_at'), 'a session of the grant that ended does not come back to life with the next one');
        $this->assertNotNull(DB::table('platform_admin_mfa_challenges')->value('consumed_at'));
        $line = $this->auditLines()->last();
        $this->assertSame('grant.reactivated', $line->action);
        $this->assertSame(1, $this->contextOf($line)['tokens_revoked']);
    }

    public function test_a_grant_that_is_given_a_new_end_without_having_ended_keeps_its_sessions(): void
    {
        $this->runCommand(['admin_id' => 7, '--countries' => 'KW', '--expires' => '2026-12-31']);
        DB::table('platform_admin_tokens')->insert([
            'admin_id' => 7, 'grant_id' => (int) DB::table('platform_admin_grants')->value('id'), 'token_hash' => str_repeat('a', 64), 'password_fingerprint' => str_repeat('c', 64), 'device_name' => 'Test phone',
            'expires_at' => now()->addDays(3), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runCommand(['admin_id' => 7, '--countries' => 'KW', '--expires' => '2027-12-31']);

        $this->assertNull(DB::table('platform_admin_tokens')->value('revoked_at'));
        $this->assertSame('grant.updated', $this->auditLines()->last()->action);
    }

    public function test_the_refusals_of_level_one_still_hold_beside_the_new_options(): void
    {
        foreach ([['admin_id' => 99, '--confirm-owner' => true], ['admin_id' => 9, '--confirm-owner' => true], ['admin_id' => 7, '--confirm-owner' => true, '--level' => '2'], ['admin_id' => 7, '--countries' => 'XX']] as $arguments) {
            [$exit] = $this->runCommand($arguments);

            $this->assertSame(1, $exit, json_encode($arguments));
        }
        $this->assertSame(0, DB::table('platform_admin_grants')->count());
    }

    // ---- the list -----------------------------------------------------------------------------------------------------------------------------

    public function test_the_list_shows_every_grant_what_it_includes_and_where_it_stands(): void
    {
        $this->runCommand(['admin_id' => 7, '--confirm-owner' => true]);
        $this->runCommand(['admin_id' => 8, '--countries' => 'KW,AE', '--capabilities' => 'alerts,overview', '--expires' => '2026-12-31']);
        $this->runCommand(['admin_id' => 10, '--countries' => 'QA']);
        Artisan::call('gcc:platform-admin:revoke', ['admin_id' => 10]);
        DB::table('platform_admin_grants')->insert(['admin_id' => 9, 'admin_fingerprint' => $this->adminFingerprint(adminId: 9), 'level' => 1, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        Schema::withoutForeignKeyConstraints(fn () => DB::table('platform_admin_grants')->insert(['admin_id' => 11, 'admin_fingerprint' => hash('sha256', 'gone'), 'level' => 1, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]));
        DB::table('admins')->insert(['id' => 12, 'name' => 'Replaced', 'email' => 'replaced@example.test', 'password' => 'x', 'status' => 1, 'created_at' => '2026-02-02 09:00:00']);
        DB::table('platform_admin_grants')->insert(['admin_id' => 12, 'admin_fingerprint' => hash('sha256', 'another row'), 'level' => 1, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('platform_admin_grants')->where('admin_id', 7)->update(['expires_at' => now()->subDay()]);
        DB::table('admins')->insert(['id' => 13, 'name' => 'Ended', 'email' => 'ended@example.test', 'password' => 'x', 'status' => 1, 'created_at' => '2026-02-02 09:00:00']);
        DB::table('platform_admin_grants')->insert(['admin_id' => 13, 'admin_fingerprint' => $this->adminFingerprint(adminId: 13), 'level' => 1, 'expires_at' => now()->subSecond(), 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $auditBefore = DB::table('platform_admin_audit_logs')->count();

        [$exit, $output] = $this->runCommand([], 'gcc:platform-admin:list');

        $this->assertSame(0, $exit);
        $rows = $this->listRows($output);
        $this->assertSame(['active', 'revoked', 'admin inactive', 'admin missing', 'admin row replaced', 'expired', 'expired'], array_map(fn (int $id): string => $rows[$id]['state'], [8, 10, 9, 11, 12, 13, 7]));
        $this->assertSame(['AE, KW', 'overview, alerts', '2026-12-31 23:59:59'], [$rows[8]['markets'], $rows[8]['capabilities'], $rows[8]['ends']]);
        $this->assertSame(['every market', 'every capability', '2026-11-17 09:59:59'], [$rows[13]['markets'], $rows[13]['capabilities'], $rows[13]['ends']]);
        $this->assertSame('Admin 8', $rows[8]['name']);
        $this->assertSame($auditBefore, DB::table('platform_admin_audit_logs')->count(), 'looking leaves no line');
    }

    public function test_the_list_with_no_grant_says_so_and_works_without_the_flag_and_tells_about_the_migrations(): void
    {
        config(['gcc.platform_admin.enabled' => false]);

        [$exit, $output] = $this->runCommand([], 'gcc:platform-admin:list');
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No grant', $output);

        $this->rollBackPlatformAdminMigrations();
        [$exit, $output] = $this->runCommand([], 'gcc:platform-admin:list');
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('migrations', $output);
    }

    public function test_the_columns_of_the_grants_migration_alone_missing_are_told_to_the_operator_too(): void
    {
        Schema::table('platform_admin_grants', fn ($table) => $table->dropColumn('capabilities'));

        [$exit, $output] = $this->runCommand(['admin_id' => 7, '--confirm-owner' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('platform_admin_grants.capabilities', $output);
    }

    // ---- helpers ------------------------------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function runCommand(array $arguments, string $command = 'gcc:platform-admin:grant'): array
    {
        $exit = Artisan::call($command, $arguments);

        return [$exit, Artisan::output()];
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
            $rows[(int) $cells[0]] = ['name' => $cells[1], 'level' => $cells[2], 'markets' => $cells[3], 'capabilities' => $cells[4], 'ends' => $cells[5], 'state' => $cells[6]];
        }

        return $rows;
    }

    /** @return array{user: string, host: string} */
    private function operator(): array
    {
        return [
            'user' => function_exists('posix_geteuid') && function_exists('posix_getpwuid') ? (string) posix_getpwuid(posix_geteuid())['name'] : (string) (getenv('USER') ?: getenv('USERNAME') ?: 'unknown'),
            'host' => (string) gethostname(),
        ];
    }

    private function auditLines(): Collection
    {
        return DB::table('platform_admin_audit_logs')->orderBy('id')->get();
    }
}
