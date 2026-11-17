<?php

namespace Tests\Feature\PlatformAdmin;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Monolog\LogRecord;
use Tests\Support\CapturesLogRecords;

/**
 * The first step of the sign-in while the second factor is required: a good password does not open a session, it is given a challenge; and every
 * way it can fail is the one 401 it has always been, after the same work.
 */
class MfaSignInTest extends MfaTestCase
{
    use CapturesLogRecords;

    public function test_a_good_password_of_an_enrolled_admin_gets_a_challenge_and_no_token(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->enrolledAdmin();

        $response = $this->login($admin, deviceName: 'Pixel 9');

        $response->assertOk();
        $challenge = $response->json('data.challenge');
        $this->assertMatchesRegularExpression('/^lkpc_[A-Za-z0-9]{48}$/', $challenge);
        $expiry = Carbon::parse('2026-11-17 10:05:00')->utc()->toIso8601ZuluString();
        $response->assertExactJson(['data' => ['mfa_required' => true, 'challenge' => $challenge, 'challenge_expires_at' => $expiry, 'methods' => ['totp', 'recovery_code']]]);
        $this->assertStringNotContainsString('"token"', $response->getContent(), 'no session yet, so no token');
        $this->assertSame(0, DB::table('platform_admin_tokens')->count());
    }

    public function test_the_challenge_is_kept_only_as_a_hash_with_what_it_needs_to_be_completed(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->enrolledAdmin();

        $challenge = $this->challengeFor($admin, deviceName: 'Pixel 9');

        $row = DB::table('platform_admin_mfa_challenges')->first();
        $this->assertSame(1, DB::table('platform_admin_mfa_challenges')->count());
        $this->assertSame(hash('sha256', $challenge), $row->token_hash);
        $this->assertSame([$admin['id'], $admin['grant_id'], 'Pixel 9', 0], [(int) $row->admin_id, (int) $row->grant_id, $row->device_name, (int) $row->attempts]);
        $this->assertSame(hash('sha256', (string) DB::table('admins')->where('id', $admin['id'])->value('password')), $row->password_fingerprint);
        $this->assertSame('2026-11-17 10:05:00', $row->expires_at);
        $this->assertNull($row->consumed_at);
        $this->assertStringNotContainsString($challenge, (string) json_encode(DB::table('platform_admin_mfa_challenges')->get()));
        $this->assertStringNotContainsString(substr($challenge, 5), $this->everythingStored());
    }

    public function test_the_lifetime_of_a_challenge_is_the_configured_one_up_to_its_ceiling(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->enrolledAdmin();

        foreach ([[2, '10:02:00'], [15, '10:15:00'], [90, '10:15:00'], [0, '10:05:00']] as [$configured, $expected]) {
            config(['gcc.platform_admin.mfa.challenge_ttl_minutes' => $configured]);
            DB::table('platform_admin_mfa_challenges')->delete();

            $this->login($admin)->assertOk();

            $this->assertSame("2026-11-17 {$expected}", DB::table('platform_admin_mfa_challenges')->value('expires_at'), "configured {$configured}");
        }
    }

    public function test_the_challenge_is_audited_without_a_token_a_session_or_a_secret(): void
    {
        $admin = $this->enrolledAdmin();
        $challenge = $this->challengeFor($admin, deviceName: 'Pixel 9');

        $lines = DB::table('platform_admin_audit_logs')->orderBy('id')->get();
        $this->assertSame(['auth.mfa_challenge'], $lines->pluck('action')->all(), 'a good password is not yet a sign-in');
        $this->assertSame($admin['id'], (int) $lines[0]->admin_id);
        $this->assertNull($lines[0]->token_id);
        $this->assertSame('127.0.0.1', $lines[0]->ip);
        $this->assertSame(['device_name' => 'Pixel 9'], json_decode($lines[0]->context, true));
        $this->assertStringNotContainsString($challenge, $this->everythingStored());
        $this->assertStringNotContainsString($admin['password'], $this->everythingStored());
    }

    public function test_the_first_step_starts_no_session_sets_no_cookie_and_signs_nobody_in(): void
    {
        $admin = $this->enrolledAdmin();

        $response = $this->login($admin);

        $response->assertOk();
        $this->assertSame([], $response->headers->getCookies());
        $this->assertGuest('admin');
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function test_the_challenge_opens_nothing_on_its_own(): void
    {
        $admin = $this->enrolledAdmin();
        $challenge = $this->challengeFor($admin);

        foreach ($this->platformRoutes() as $route) {
            [$method, $uri] = $this->requestTo($route);
            if (in_array($route->getName(), ['platform_admin.auth.login', 'platform_admin.auth.mfa'], true)) {
                continue;
            }

            $this->json($method, $uri, [], $this->bearer($challenge))->assertStatus(401)->assertExactJson($this->uniformRefusal());
        }
    }

    public function test_an_admin_who_is_not_enrolled_gets_the_one_401_and_the_reason_is_only_in_the_log_and_the_trail(): void
    {
        $log = $this->captureLog();
        $admin = $this->platformAdmin();
        $wrongPassword = $this->login(['email' => $admin['email'], 'password' => 'not the password']);

        $response = $this->login($admin);

        $response->assertStatus(401)->assertExactJson($this->uniformRefusal());
        $this->assertSame($wrongPassword->getContent(), $response->getContent(), 'nothing tells it from a wrong password');
        $this->assertSame($wrongPassword->headers->get('Content-Type'), $response->headers->get('Content-Type'));
        $this->assertSame(0, DB::table('platform_admin_mfa_challenges')->count());
        $this->assertSame(0, DB::table('platform_admin_tokens')->count());

        $lines = DB::table('platform_admin_audit_logs')->orderBy('id')->get();
        $this->assertSame(['wrong_password', 'mfa_not_enrolled'], $lines->map(fn ($line) => json_decode($line->context, true)['reason'])->all());
        $this->assertSame(['auth.login_failed', 'auth.login_failed'], $lines->pluck('action')->all());
        $reasons = array_values(array_filter(array_map(static fn (LogRecord $record): string => $record->context['reason'] ?? '', $log->getRecords())));
        $this->assertSame(['wrong_password', 'mfa_not_enrolled'], $reasons);
    }

    public function test_a_factor_that_is_pending_or_was_made_for_another_row_is_not_a_factor_at_sign_in(): void
    {
        $pending = $this->enrolledAdmin(confirmed: false);
        $foreign = $this->platformAdmin();
        $this->enrolFactor(adminId: $foreign['id'], overrides: ['admin_fingerprint' => hash('sha256', 'another row')]);

        foreach ([$pending, $foreign] as $admin) {
            $this->login($admin)->assertStatus(401)->assertExactJson($this->uniformRefusal());
        }
        $this->assertSame(0, DB::table('platform_admin_mfa_challenges')->count());
    }

    public function test_every_failure_still_answers_the_same_and_does_the_same_work_while_the_second_factor_is_required(): void
    {
        $hasher = $this->countingHasher();
        $enrolled = $this->enrolledAdmin();
        $notEnrolled = $this->platformAdmin();
        $inactive = $this->enrolledAdmin(adminOverrides: ['status' => 0]);
        $revoked = $this->enrolledAdmin(grantOverrides: ['revoked_at' => now()]);
        $withoutGrant = $this->makeAdmin();
        $unknownPassword = Str::random(20);

        $causes = [
            'no such admin' => ['email' => 'nobody@example.test', 'password' => $unknownPassword, 'device_name' => 'Pixel'],
            'wrong password' => ['email' => $enrolled['email'], 'password' => $unknownPassword, 'device_name' => 'Pixel'],
            'not enrolled' => ['email' => $notEnrolled['email'], 'password' => $notEnrolled['password'], 'device_name' => 'Pixel'],
            'inactive admin' => ['email' => $inactive['email'], 'password' => $inactive['password'], 'device_name' => 'Pixel'],
            'revoked grant' => ['email' => $revoked['email'], 'password' => $revoked['password'], 'device_name' => 'Pixel'],
            'no grant' => ['email' => $withoutGrant['email'], 'password' => $withoutGrant['password'], 'device_name' => 'Pixel'],
            'no device name' => ['email' => $enrolled['email'], 'password' => $enrolled['password']],
            'an empty body' => [],
        ];

        $bodies = [];
        $selects = [];
        foreach ($causes as $cause => $payload) {
            $hasher->checks = 0;
            $statements = $this->statementsRunBy(function () use ($payload, &$response): void {
                $response = $this->postJson(self::LOGIN, $payload);
            });

            $response->assertStatus(401)->assertExactJson($this->uniformRefusal());
            $bodies[$cause] = $response->getContent();
            $selects[$cause] = count(array_filter($statements, static fn (string $sql): bool => str_starts_with(strtolower($sql), 'select')));
            $this->assertSame(1, $hasher->checks, "{$cause}: exactly one password check");
        }

        $this->assertCount(1, array_unique($bodies));
        $this->assertCount(1, array_unique($selects), 'one number of reads for every cause: ' . json_encode($selects));
    }

    public function test_the_good_password_of_a_locked_out_admin_is_throttled_and_no_challenge_is_given(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        config(['gcc.platform_admin.mfa.lockout_failures' => 2]);
        $locked = $this->enrolledAdmin();
        $other = $this->enrolledAdmin();
        $challenge = $this->challengeFor($locked);
        $this->second(['challenge' => $challenge, 'code' => '000000'])->assertStatus(401);
        $this->second(['challenge' => $challenge, 'code' => '000001'])->assertStatus(401);
        DB::table('platform_admin_mfa_challenges')->delete();

        $response = $this->login($locked);

        $response->assertStatus(429)->assertExactJson(['errors' => [['code' => 'platform_admin_throttled', 'message' => 'Too many attempts. Try again later.']]]);
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(15 * 60, (int) $response->headers->get('Retry-After'));
        $this->assertSame(0, DB::table('platform_admin_mfa_challenges')->count());
        $this->login($other)->assertOk()->assertJsonPath('data.mfa_required', true);
    }

    public function test_a_locked_out_admin_gets_the_ordinary_refusals_for_a_wrong_password_so_that_nothing_tells_who_is_locked(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        config(['gcc.platform_admin.mfa.lockout_failures' => 1]);
        $locked = $this->enrolledAdmin();
        $this->second(['challenge' => $this->challengeFor($locked), 'code' => '000000'])->assertStatus(401);

        $this->login(['email' => $locked['email'], 'password' => 'not the password'])->assertStatus(401)->assertExactJson($this->uniformRefusal());
        $this->login(['email' => 'nobody@example.test', 'password' => 'not the password'])->assertStatus(401)->assertExactJson($this->uniformRefusal());
    }

    public function test_a_lockout_ends_with_its_window(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        config(['gcc.platform_admin.mfa.lockout_failures' => 1]);
        $admin = $this->enrolledAdmin();
        $this->second(['challenge' => $this->challengeFor($admin), 'code' => '000000'])->assertStatus(401);
        $this->login($admin)->assertStatus(429);

        $this->travelTo('2026-11-17 10:14:59');
        $this->login($admin)->assertStatus(429);

        $this->travelTo('2026-11-17 10:15:01');
        $this->login($admin)->assertOk()->assertJsonPath('data.mfa_required', true);
    }

    public function test_a_good_password_while_the_second_factor_is_required_does_not_start_the_count_of_that_e_mail_again(): void
    {
        config(['gcc.platform_admin.login_attempts_per_minute' => 3]);
        $admin = $this->enrolledAdmin();

        $this->login($admin)->assertOk();
        $this->login($admin)->assertOk();
        $this->login($admin)->assertOk();

        $this->login($admin)->assertStatus(429);
    }

    public function test_a_challenge_per_sign_in_and_the_ones_that_ended_over_a_day_ago_are_removed_at_the_next_one_of_that_admin_only(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->enrolledAdmin();
        $other = $this->enrolledAdmin();
        $this->insertChallenge(admin: $admin, hash: str_repeat('a', 64), expiresAt: now()->subDays(2));
        $this->insertChallenge(admin: $admin, hash: str_repeat('b', 64), expiresAt: now()->subHours(23));
        $this->insertChallenge(admin: $other, hash: str_repeat('c', 64), expiresAt: now()->subDays(40));

        $this->login($admin)->assertOk();

        $this->assertNull(DB::table('platform_admin_mfa_challenges')->where('token_hash', str_repeat('a', 64))->first());
        $this->assertNotNull(DB::table('platform_admin_mfa_challenges')->where('token_hash', str_repeat('b', 64))->first());
        $this->assertNotNull(DB::table('platform_admin_mfa_challenges')->where('token_hash', str_repeat('c', 64))->first(), "another admin's rows are not this sign-in's business");
        $this->assertSame(3, DB::table('platform_admin_mfa_challenges')->count(), 'the one of the day before, the one of the other admin, and the new one');
    }

    public function test_two_sign_ins_make_two_challenges_that_are_both_good(): void
    {
        $admin = $this->enrolledAdmin();

        $first = $this->challengeFor($admin);
        $second = $this->challengeFor($admin);

        $this->assertNotSame($first, $second);
        $this->assertSame(2, DB::table('platform_admin_mfa_challenges')->count());
    }

    // ---- the second factor switched off ------------------------------------------------------------------------------------------------------

    public function test_with_the_second_factor_not_required_the_sign_in_is_the_one_step_of_level_1_for_an_enrolled_admin_too(): void
    {
        config(['gcc.platform_admin.mfa.required' => false]);
        $admin = $this->enrolledAdmin();

        $response = $this->login($admin);

        $response->assertOk();
        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertSame(['token', 'token_type', 'expires_at', 'me'], array_keys($response->json('data')));
        $this->assertSame(['admin_id', 'name', 'level', 'scope', 'markets', 'token_expires_at'], array_keys($response->json('data.me')));
        $this->assertStringNotContainsString('mfa', $response->getContent());
        $this->assertSame(0, DB::table('platform_admin_mfa_challenges')->count());
        $this->assertSame(['auth.login'], DB::table('platform_admin_audit_logs')->pluck('action')->all());
        $this->assertSame(['device_name' => 'Test phone'], json_decode((string) DB::table('platform_admin_audit_logs')->value('context'), true), 'the line is the one of level 1');
        $this->assertNull(DB::table('platform_admin_tokens')->value('mfa_method'));
    }

    public function test_with_the_second_factor_not_required_a_good_password_still_starts_the_count_of_that_e_mail_again(): void
    {
        config(['gcc.platform_admin.mfa.required' => false, 'gcc.platform_admin.login_attempts_per_minute' => 3]);
        $admin = $this->platformAdmin();

        for ($round = 0; $round < 5; $round++) {
            $this->login($admin)->assertOk();
        }
    }

    public function test_the_switch_is_read_at_every_sign_in(): void
    {
        $admin = $this->enrolledAdmin();

        $this->login($admin)->assertJsonPath('data.mfa_required', true);

        config(['gcc.platform_admin.mfa.required' => false]);
        $this->login($admin)->assertJsonStructure(['data' => ['token']]);

        config(['gcc.platform_admin.mfa.required' => 'flase']);
        $this->login($admin)->assertJsonPath('data.mfa_required', true);
    }

    private function everythingStored(): string
    {
        $everything = '';
        foreach (['platform_admin_tokens', 'platform_admin_audit_logs', 'platform_admin_grants', 'platform_admin_mfa_factors', 'platform_admin_mfa_challenges'] as $table) {
            $everything .= json_encode(DB::table($table)->get());
        }

        return $everything;
    }

    /** @param array{id: int, grant_id: int} $admin */
    private function insertChallenge(array $admin, string $hash, Carbon $expiresAt): void
    {
        DB::table('platform_admin_mfa_challenges')->insert([
            'token_hash' => $hash, 'admin_id' => $admin['id'], 'grant_id' => $admin['grant_id'], 'password_fingerprint' => str_repeat('c', 64), 'device_name' => 'Test phone',
            'expires_at' => $expiresAt, 'created_at' => $expiresAt, 'updated_at' => $expiresAt,
        ]);
    }
}
