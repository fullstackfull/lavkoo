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
 * Grants are made, narrowed and taken away only by the operator on the server. These pin each command, what it refuses and what it audits.
 */
class GrantCommandsTest extends TestCase
{
    use BuildsPlatformAdminWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildPlatformAdminWorld();
        $this->insertAdmin(id: 7, status: 1);
        $this->insertAdmin(id: 8, status: 1);
        $this->insertAdmin(id: 9, status: 0);
        Carbon::setTestNow('2026-11-17 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_grant_without_countries_is_the_platform_owner_and_is_audited(): void
    {
        $this->assertSame(0, $this->artisan('gcc:platform-admin:grant', ['admin_id' => 7])->expectsOutputToContain('every market')->run());

        $grant = DB::table('platform_admin_grants')->where('admin_id', 7)->first();
        $this->assertNotNull($grant);
        $this->assertSame(1, (int) $grant->level);
        $this->assertNull($grant->countries, 'SQL NULL is every market');
        $this->assertNull($grant->revoked_at);
        $this->assertNull($grant->granted_by, 'the command cannot know a person, so it records none');
        $this->assertSame('2026-11-17 10:00:00', $grant->granted_at);

        $audit = $this->auditLines();
        $this->assertCount(1, $audit);
        $this->assertSame('grant.created', $audit[0]->action);
        $this->assertSame(7, (int) $audit[0]->admin_id);
        $this->assertSame($this->sortedByKey(['source' => 'console', 'before' => null, 'after' => ['level' => 1, 'countries' => null]]), $this->contextOf($audit[0]));
    }

    public function test_a_grant_remembers_the_admin_row_it_was_made_for(): void
    {
        DB::table('admins')->where('id', 7)->update(['created_at' => '2026-01-05 09:00:00']);

        $this->artisan('gcc:platform-admin:grant', ['admin_id' => 7])->doesntExpectOutputToContain('another admin row')->assertExitCode(0);

        $this->assertSame(hash('sha256', '7|2026-01-05 09:00:00'), DB::table('platform_admin_grants')->where('admin_id', 7)->value('admin_fingerprint'));
    }

    public function test_granting_again_to_the_same_row_leaves_its_sessions_and_its_grant_time_alone(): void
    {
        $this->artisan('gcc:platform-admin:grant', ['admin_id' => 7])->assertExitCode(0);
        $this->insertToken(adminId: 7, grantId: (int) DB::table('platform_admin_grants')->value('id'), hash: str_repeat('a', 64));
        Carbon::setTestNow('2026-11-18 10:00:00');

        $this->artisan('gcc:platform-admin:grant', ['admin_id' => 7, '--countries' => 'KW'])->doesntExpectOutputToContain('another admin row')->assertExitCode(0);

        $this->assertNull(DB::table('platform_admin_tokens')->value('revoked_at'), 'the same admin keeps the sessions');
        $this->assertSame('2026-11-17 10:00:00', DB::table('platform_admin_grants')->value('granted_at'));
        $this->assertArrayNotHasKey('replaced_admin_row', $this->contextOf($this->auditLines()->last()));
    }

    public function test_granting_again_after_the_admin_row_was_replaced_adopts_the_new_row_and_ends_the_sessions_of_the_old_one(): void
    {
        $this->artisan('gcc:platform-admin:grant', ['admin_id' => 7])->assertExitCode(0);
        $grantId = (int) DB::table('platform_admin_grants')->value('id');
        $this->insertToken(adminId: 7, grantId: $grantId, hash: str_repeat('a', 64));
        Schema::withoutForeignKeyConstraints(fn () => DB::table('admins')->where('id', 7)->delete());
        DB::table('admins')->insert(['id' => 7, 'name' => 'Replacement', 'email' => 'replacement@example.test', 'password' => 'x', 'status' => 1, 'created_at' => '2026-05-01 12:00:00']);
        Carbon::setTestNow('2026-11-18 10:00:00');

        $this->artisan('gcc:platform-admin:grant', ['admin_id' => 7])->expectsOutputToContain('another admin row')->assertExitCode(0);

        $grant = DB::table('platform_admin_grants')->where('admin_id', 7)->first();
        $this->assertSame(1, DB::table('platform_admin_grants')->count(), 'the grant is updated in place');
        $this->assertSame($grantId, (int) $grant->id);
        $this->assertSame(hash('sha256', '7|2026-05-01 12:00:00'), $grant->admin_fingerprint, 'the grant is now the replacement row\'s');
        $this->assertSame('2026-11-18 10:00:00', $grant->granted_at);
        $this->assertSame('2026-11-18 10:00:00', DB::table('platform_admin_tokens')->value('revoked_at'), 'a session of the row that was deleted ends');
        $line = $this->auditLines()->last();
        $this->assertSame('grant.updated', $line->action);
        $this->assertSame(
            $this->sortedByKey(['source' => 'console', 'before' => ['level' => 1, 'countries' => null, 'revoked' => false], 'after' => ['level' => 1, 'countries' => null], 'tokens_revoked' => 1, 'replaced_admin_row' => true]),
            $this->contextOf($line),
        );
    }

    public function test_countries_are_normalised_deduplicated_and_kept_in_the_order_of_the_configuration(): void
    {
        $this->assertSame(0, $this->artisan('gcc:platform-admin:grant', ['admin_id' => 7, '--countries' => ' kw, AE ,kw'])->run());

        $this->assertSame(['AE', 'KW'], json_decode((string) DB::table('platform_admin_grants')->value('countries'), true));
    }

    public function test_a_restricted_grant_can_be_widened_to_every_market_again_only_by_the_command(): void
    {
        $this->artisan('gcc:platform-admin:grant', ['admin_id' => 7, '--countries' => 'KW'])->assertExitCode(0);
        $this->artisan('gcc:platform-admin:grant', ['admin_id' => 7])->assertExitCode(0);

        $this->assertNull(DB::table('platform_admin_grants')->where('admin_id', 7)->value('countries'));
        $this->assertSame(1, DB::table('platform_admin_grants')->count(), 'one row per admin');
        $this->assertSame(['grant.created', 'grant.updated'], $this->auditLines()->pluck('action')->all());
        $this->assertSame(
            $this->sortedByKey(['source' => 'console', 'before' => ['level' => 1, 'countries' => ['KW'], 'revoked' => false], 'after' => ['level' => 1, 'countries' => null]]),
            $this->contextOf($this->auditLines()[1]),
        );
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusals(): array
    {
        return [
            'an admin that does not exist' => [['admin_id' => 99], 'does not exist'],
            'an admin id that is not a number' => [['admin_id' => 'seven'], 'positive whole number'],
            'an inactive admin' => [['admin_id' => 9], 'not active'],
            'an unknown country code' => [['admin_id' => 7, '--countries' => 'KW,XX'], 'Unknown country code: XX'],
            'a list with no country in it' => [['admin_id' => 7, '--countries' => ' , '], 'no country code'],
            'a level that does not exist' => [['admin_id' => 7, '--level' => '2'], 'level 1'],
            'a level that is not a number' => [['admin_id' => 7, '--level' => 'high'], 'level 1'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_grant_is_refused_and_nothing_is_written(array $arguments, string $reason): void
    {
        $this->assertSame(1, Artisan::call('gcc:platform-admin:grant', $arguments));
        $this->assertStringContainsString($reason, Artisan::output());
        $this->assertSame(0, DB::table('platform_admin_grants')->count());
        $this->assertSame(0, DB::table('platform_admin_audit_logs')->count());
    }

    public function test_the_first_admin_gets_no_exemption_from_the_status_check(): void
    {
        $this->insertAdmin(id: 1, status: 0);

        $this->assertSame(1, Artisan::call('gcc:platform-admin:grant', ['admin_id' => 1]));
        $this->assertSame(0, DB::table('platform_admin_grants')->count());
    }

    public function test_a_status_that_is_not_exactly_one_is_not_active(): void
    {
        DB::table('admins')->where('id', 8)->update(['status' => 2]);

        $this->assertSame(1, Artisan::call('gcc:platform-admin:grant', ['admin_id' => 8]));
        $this->assertSame(0, DB::table('platform_admin_grants')->count());
    }

    public function test_a_failed_grant_with_a_bad_country_leaves_an_existing_grant_untouched(): void
    {
        $this->artisan('gcc:platform-admin:grant', ['admin_id' => 7, '--countries' => 'KW'])->assertExitCode(0);

        $this->artisan('gcc:platform-admin:grant', ['admin_id' => 7, '--countries' => 'AE,XX'])->assertExitCode(1);

        $this->assertSame(['KW'], json_decode((string) DB::table('platform_admin_grants')->value('countries'), true));
        $this->assertCount(1, $this->auditLines());
    }

    public function test_granting_again_after_a_revoke_reactivates_the_grant_and_kills_every_old_session(): void
    {
        $grantId = $this->grant(adminId: 7);
        $this->insertToken(adminId: 7, grantId: $grantId, hash: str_repeat('a', 64));
        $this->artisan('gcc:platform-admin:revoke', ['admin_id' => 7])->assertExitCode(0);
        $this->assertNotNull(DB::table('platform_admin_grants')->value('revoked_at'));

        // A session that somehow survived the revoke (a hand edit) must not come back to life with the grant.
        DB::table('platform_admin_tokens')->update(['revoked_at' => null]);

        $this->artisan('gcc:platform-admin:grant', ['admin_id' => 7, '--countries' => 'QA'])->assertExitCode(0);

        $grant = DB::table('platform_admin_grants')->first();
        $this->assertNull($grant->revoked_at);
        $this->assertSame(['QA'], json_decode($grant->countries, true));
        $this->assertSame(1, DB::table('platform_admin_tokens')->whereNotNull('revoked_at')->count());
        $this->assertSame('grant.reactivated', $this->auditLines()->last()->action);
        $this->assertSame(
            $this->sortedByKey(['source' => 'console', 'before' => ['level' => 1, 'countries' => null, 'revoked' => true], 'after' => ['level' => 1, 'countries' => ['QA']], 'tokens_revoked' => 1]),
            $this->contextOf($this->auditLines()->last()),
        );
    }

    public function test_revoking_a_grant_revokes_every_token_of_that_admin_and_only_that_admin(): void
    {
        $seven = $this->grant(adminId: 7);
        $eight = $this->grant(adminId: 8);
        $this->insertToken(adminId: 7, grantId: $seven, hash: str_repeat('a', 64));
        $this->insertToken(adminId: 7, grantId: $seven, hash: str_repeat('b', 64));
        $this->insertToken(adminId: 8, grantId: $eight, hash: str_repeat('c', 64));

        $this->artisan('gcc:platform-admin:revoke', ['admin_id' => 7])->assertExitCode(0);

        $this->assertNotNull(DB::table('platform_admin_grants')->where('admin_id', 7)->value('revoked_at'));
        $this->assertNull(DB::table('platform_admin_grants')->where('admin_id', 8)->value('revoked_at'));
        $this->assertSame(2, DB::table('platform_admin_tokens')->where('admin_id', 7)->whereNotNull('revoked_at')->count());
        $this->assertSame(0, DB::table('platform_admin_tokens')->where('admin_id', 8)->whereNotNull('revoked_at')->count());

        $line = $this->auditLines()->last();
        $this->assertSame('grant.revoked', $line->action);
        $this->assertSame(7, (int) $line->admin_id);
        $this->assertSame($this->sortedByKey(['source' => 'console', 'tokens_revoked' => 2, 'already_revoked' => false]), $this->contextOf($line));
    }

    public function test_revoking_twice_is_harmless_and_keeps_the_first_revocation_time(): void
    {
        $grantId = $this->grant(adminId: 7);
        $this->insertToken(adminId: 7, grantId: $grantId, hash: str_repeat('a', 64));
        $this->artisan('gcc:platform-admin:revoke', ['admin_id' => 7])->assertExitCode(0);
        $firstRevocation = DB::table('platform_admin_grants')->value('revoked_at');

        Carbon::setTestNow('2026-11-18 10:00:00');
        $this->artisan('gcc:platform-admin:revoke', ['admin_id' => 7])->assertExitCode(0);

        $this->assertSame($firstRevocation, DB::table('platform_admin_grants')->value('revoked_at'));
        $this->assertTrue($this->contextOf($this->auditLines()->last())['already_revoked']);
    }

    public function test_revoking_an_admin_who_has_no_grant_is_refused(): void
    {
        $this->assertSame(1, Artisan::call('gcc:platform-admin:revoke', ['admin_id' => 7]));
        $this->assertStringContainsString('no grant', Artisan::output());
        $this->assertSame(0, DB::table('platform_admin_audit_logs')->count());
    }

    public function test_revoking_tokens_leaves_the_grant_and_other_admins_alone_and_keeps_the_time_of_an_earlier_revocation(): void
    {
        $seven = $this->grant(adminId: 7);
        $eight = $this->grant(adminId: 8);
        $this->insertToken(adminId: 7, grantId: $seven, hash: str_repeat('a', 64));
        $this->insertToken(adminId: 7, grantId: $seven, hash: str_repeat('b', 64), revokedAt: '2026-11-01 08:00:00');
        $this->insertToken(adminId: 8, grantId: $eight, hash: str_repeat('c', 64));

        $this->assertSame(0, $this->artisan('gcc:platform-admin:revoke-tokens', ['admin_id' => 7])->expectsOutputToContain('1 session')->run());

        $this->assertNull(DB::table('platform_admin_grants')->where('admin_id', 7)->value('revoked_at'), 'the admin keeps the grant');
        $this->assertSame('2026-11-17 10:00:00', DB::table('platform_admin_tokens')->where('token_hash', str_repeat('a', 64))->value('revoked_at'));
        $this->assertSame('2026-11-01 08:00:00', DB::table('platform_admin_tokens')->where('token_hash', str_repeat('b', 64))->value('revoked_at'));
        $this->assertNull(DB::table('platform_admin_tokens')->where('token_hash', str_repeat('c', 64))->value('revoked_at'));

        $line = $this->auditLines()->last();
        $this->assertSame('tokens.revoked', $line->action);
        $this->assertSame($this->sortedByKey(['source' => 'console', 'tokens_revoked' => 1]), $this->contextOf($line));
    }

    public function test_every_command_tells_the_operator_when_the_migrations_have_not_run(): void
    {
        $this->rollBackPlatformAdminMigrations();
        $this->assertFalse(Schema::hasTable('platform_admin_grants'));

        foreach (['gcc:platform-admin:grant', 'gcc:platform-admin:revoke', 'gcc:platform-admin:revoke-tokens'] as $command) {
            $this->assertSame(1, Artisan::call($command, ['admin_id' => 7]), $command);
            $this->assertStringContainsString('migrations', Artisan::output(), $command);
        }
    }

    public function test_no_command_needs_the_flag(): void
    {
        config(['gcc.platform_admin.enabled' => false]);

        $this->artisan('gcc:platform-admin:grant', ['admin_id' => 7])->assertExitCode(0);
    }

    private function insertAdmin(int $id, int $status): void
    {
        DB::table('admins')->insert(['id' => $id, 'name' => "Admin {$id}", 'email' => "admin{$id}@example.test", 'password' => 'x', 'status' => $status]);
    }

    private function grant(int $adminId): int
    {
        return (int) DB::table('platform_admin_grants')->insertGetId(['admin_id' => $adminId, 'admin_fingerprint' => $this->adminFingerprint(adminId: $adminId), 'level' => 1, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function insertToken(int $adminId, int $grantId, string $hash, ?string $revokedAt = null): void
    {
        DB::table('platform_admin_tokens')->insert([
            'admin_id' => $adminId, 'grant_id' => $grantId, 'token_hash' => $hash, 'password_fingerprint' => str_repeat('c', 64), 'device_name' => 'Test phone',
            'expires_at' => now()->addHour(), 'revoked_at' => $revokedAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function auditLines(): Collection
    {
        return DB::table('platform_admin_audit_logs')->orderBy('id')->get();
    }
}
