<?php

namespace Tests\Feature\PlatformAdmin;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Monolog\LogRecord;
use Tests\Support\CapturesLogRecords;
use Tests\Support\EnrolsPlatformAdminFactors;

/**
 * A grant that has ended is no grant: at the sign-in, at the second step and on every request, as a grant that was revoked is, and the first request of
 * a session that meets it ends every session of that admin.
 */
class GrantExpiryTest extends PlatformAdminTestCase
{
    use CapturesLogRecords;
    use EnrolsPlatformAdminFactors;

    public function test_a_sign_in_with_a_grant_that_has_ended_is_the_one_401_and_the_reason_is_only_in_the_log_and_the_trail(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $log = $this->captureLog();
        $expired = $this->platformAdmin(grantOverrides: ['expires_at' => now()->subSecond()]);
        $wrongPassword = $this->postJson(self::PREFIX . '/auth/login', ['email' => $expired['email'], 'password' => 'not the password', 'device_name' => 'Pixel']);

        $response = $this->postJson(self::PREFIX . '/auth/login', ['email' => $expired['email'], 'password' => $expired['password'], 'device_name' => 'Pixel']);

        $response->assertStatus(401)->assertExactJson($this->uniformRefusal());
        $this->assertSame($wrongPassword->getContent(), $response->getContent());
        $this->assertSame(0, DB::table('platform_admin_tokens')->count());
        $lines = DB::table('platform_admin_audit_logs')->orderBy('id')->get();
        $this->assertSame(['wrong_password', 'expired_grant'], $lines->map(fn ($line) => json_decode($line->context, true)['reason'])->all());
        $reasons = array_values(array_filter(array_map(static fn (LogRecord $record): string => $record->context['reason'] ?? '', $log->getRecords())));
        $this->assertSame(['wrong_password', 'expired_grant'], $reasons);
    }

    public function test_the_last_moment_of_a_grant_is_over_and_the_second_before_it_is_not(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $over = $this->platformAdmin(grantOverrides: ['expires_at' => now()]);
        $inside = $this->platformAdmin(grantOverrides: ['expires_at' => now()->addSecond()]);

        $this->postJson(self::PREFIX . '/auth/login', ['email' => $over['email'], 'password' => $over['password'], 'device_name' => 'Pixel'])->assertStatus(401);
        $this->postJson(self::PREFIX . '/auth/login', ['email' => $inside['email'], 'password' => $inside['password'], 'device_name' => 'Pixel'])->assertOk();
    }

    public function test_a_session_opened_before_the_end_works_until_it_and_not_after(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->platformAdmin(grantOverrides: ['expires_at' => '2026-11-17 12:00:00']);
        $token = $this->mintToken($admin);

        $this->travelTo('2026-11-17 11:59:59');
        $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertOk();

        $this->travelTo('2026-11-17 12:00:00');
        $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertStatus(401)->assertExactJson($this->uniformRefusal());
    }

    public function test_the_first_request_after_the_end_ends_every_session_of_that_admin_and_says_so_once(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->platformAdmin(grantOverrides: ['expires_at' => now()->addHour()]);
        $other = $this->platformAdmin();
        $phone = $this->mintToken($admin, ['device_name' => 'Phone']);
        $tablet = $this->mintToken($admin, ['device_name' => 'Tablet']);
        $this->mintToken($admin, ['device_name' => 'Already ended', 'revoked_at' => '2026-11-17 09:00:00']);
        $theirs = $this->mintToken($other);
        $phoneId = (int) DB::table('platform_admin_tokens')->where('device_name', 'Phone')->value('id');

        $this->travelTo('2026-11-17 11:30:00');
        $this->getJson(self::PREFIX . '/me', $this->bearer($phone) + ['User-Agent' => 'LavkooSeller/1.0'])->assertStatus(401);

        $this->assertSame(0, DB::table('platform_admin_tokens')->where('admin_id', $admin['id'])->whereNull('revoked_at')->count(), 'every open session of the admin ended, not only the one that asked');
        $this->assertSame('2026-11-17 09:00:00', DB::table('platform_admin_tokens')->where('device_name', 'Already ended')->value('revoked_at'), 'one that was ended already keeps its time');
        $this->getJson(self::PREFIX . '/me', $this->bearer($theirs))->assertOk();

        $lines = DB::table('platform_admin_audit_logs')->where('action', 'session.grant_expired')->get();
        $this->assertCount(1, $lines);
        $this->assertSame([$admin['id'], $phoneId, '127.0.0.1', 'LavkooSeller/1.0'], [(int) $lines[0]->admin_id, (int) $lines[0]->token_id, $lines[0]->ip, $lines[0]->user_agent]);
        $this->assertSame($this->sortedByKey(['expired_at' => Carbon::parse('2026-11-17 11:00:00')->utc()->toIso8601ZuluString(), 'sessions_ended' => 2]), $this->contextOf($lines[0]));

        $before = [json_encode(DB::table('platform_admin_tokens')->get()), DB::table('platform_admin_audit_logs')->count()];
        $this->getJson(self::PREFIX . '/me', $this->bearer($tablet))->assertStatus(401)->assertExactJson($this->uniformRefusal());
        $this->assertSame($before, [json_encode(DB::table('platform_admin_tokens')->get()), DB::table('platform_admin_audit_logs')->count()], 'the others meet sessions that are ended already and write nothing');
    }

    public function test_a_request_that_finds_the_sessions_ended_already_by_another_says_nothing(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->platformAdmin(grantOverrides: ['expires_at' => now()->addHour()]);
        $token = $this->mintToken($admin);
        $this->travelTo('2026-11-17 11:30:00');
        $raced = false;
        DB::beforeExecuting(static function (string $query) use (&$raced): void {
            if (!$raced && str_starts_with($query, 'update') && str_contains($query, 'platform_admin_tokens') && str_contains($query, 'revoked_at')) {
                $raced = true;
                DB::table('platform_admin_tokens')->update(['revoked_at' => '2026-11-17 11:29:59']);
            }
        });

        $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertStatus(401);

        $this->assertTrue($raced, 'the sessions were ended by another request between the look and the ending');
        $this->assertSame(0, DB::table('platform_admin_audit_logs')->where('action', 'session.grant_expired')->count(), 'it ended none, so it says nothing');
    }

    public function test_a_grant_that_is_revoked_and_has_also_ended_is_only_revoked_and_writes_no_expiry_line(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->platformAdmin(grantOverrides: ['expires_at' => now()->subHour(), 'revoked_at' => now()->subDay()]);
        $token = $this->mintToken($admin);

        $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertStatus(401);

        $this->assertSame(0, DB::table('platform_admin_audit_logs')->where('action', 'session.grant_expired')->count(), 'the operator ended it, not time, and a revoked grant ends nothing by itself (the revoke command did)');
        $this->assertNull(DB::table('platform_admin_tokens')->value('revoked_at'));
    }

    public function test_the_second_step_refuses_a_grant_that_has_ended_with_its_own_reason_and_ends_no_session(): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        config(['gcc.platform_admin.mfa.required' => true]);
        $log = $this->captureLog();
        $admin = $this->platformAdmin(grantOverrides: ['expires_at' => now()->addMinutes(2)]);
        $factor = $this->enrolFactor(adminId: $admin['id']);
        $challenge = (string) $this->postJson(self::PREFIX . '/auth/login', ['email' => $admin['email'], 'password' => $admin['password'], 'device_name' => 'Pixel'])->json('data.challenge');

        $this->travelTo('2026-11-17 10:03:00');
        $this->postJson(self::PREFIX . '/auth/mfa', ['challenge' => $challenge, 'code' => $this->totpFor($factor)])->assertStatus(401)->assertExactJson($this->uniformRefusal());

        $this->assertSame(0, DB::table('platform_admin_tokens')->count());
        $reasons = array_values(array_filter(array_map(static fn (LogRecord $record): string => $record->context['reason'] ?? '', $log->getRecords())));
        $this->assertSame(['expired_grant'], $reasons);
    }

    public function test_an_admin_with_a_grant_that_has_ended_cannot_be_enrolled_for_the_second_factor(): void
    {
        $admin = $this->platformAdmin(grantOverrides: ['expires_at' => now()->subSecond()]);

        $this->assertSame(1, Artisan::call('gcc:platform-admin:mfa:enroll', ['admin_id' => $admin['id']]));
        $this->assertStringContainsString('no active grant', Artisan::output());
        $this->assertSame(0, DB::table('platform_admin_mfa_factors')->count());
    }

    public function test_the_end_does_not_depend_on_the_flag_of_the_second_factor_or_on_the_platform_owner_being_one(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $owner = $this->platformAdmin(grantOverrides: ['expires_at' => now()->subSecond()]);
        $viewer = $this->platformAdmin(countries: ['KW'], grantOverrides: ['expires_at' => now()->subSecond()]);

        foreach ([$owner, $viewer] as $admin) {
            $this->getJson(self::PREFIX . '/me', $this->bearer($this->mintToken($admin)))->assertStatus(401);
        }
    }
}
