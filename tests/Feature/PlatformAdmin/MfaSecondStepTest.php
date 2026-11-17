<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\PlatformAdminMfaFactor;
use App\Services\PlatformAdmin\MfaAttempt;
use App\Services\PlatformAdmin\MfaVerdict;
use App\Services\PlatformAdmin\PlatformAdminMfaVerifier;
use App\Services\PlatformAdmin\Totp;
use Carbon\Carbon;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CapturesLogRecords;

/**
 * The second step of the sign-in: a challenge and a code, or a challenge and a recovery code, for a session. What opens one, and the one 401 that
 * every other thing is, with the same work done; the tries a challenge allows, the lockout of an admin, the limit of an address, and everything a
 * challenge is bound to.
 */
class MfaSecondStepTest extends MfaTestCase
{
    use CapturesLogRecords;

    // ---- the answer that opens a session ------------------------------------------------------------------------------------------------------

    public function test_the_code_the_app_shows_opens_a_session_and_the_answer_is_the_sign_in_plus_how_it_was_opened(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->enrolledAdmin();
        $challenge = $this->challengeFor($admin, deviceName: 'Pixel 9');

        $response = $this->second(['challenge' => $challenge, 'code' => $this->totpFor($admin['factor'])], server: ['REMOTE_ADDR' => '10.20.30.40'], headers: ['User-Agent' => 'LavkooSeller/1.0']);

        $response->assertOk();
        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertSame(['token', 'token_type', 'expires_at', 'me', 'mfa'], array_keys($response->json('data')));
        $this->assertMatchesRegularExpression('/^lkpa_[A-Za-z0-9]{64}$/', $response->json('data.token'));
        $this->assertSame('Bearer', $response->json('data.token_type'));
        $expiry = Carbon::parse('2026-11-17 10:00:00')->addMinutes(480)->utc()->toIso8601ZuluString();
        $this->assertSame($expiry, $response->json('data.expires_at'));
        $this->assertSame(
            ['admin_id' => $admin['id'], 'name' => $admin['name'], 'level' => 1, 'scope' => 'all', 'markets' => ['SA', 'AE', 'KW', 'QA', 'BH', 'OM'], 'token_expires_at' => $expiry],
            $response->json('data.me'),
            'the same six members the sign-in has always had',
        );
        $this->assertSame(['state' => 'verified', 'method' => 'totp', 'recovery_codes_left' => 10], $response->json('data.mfa'));
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame([], $response->headers->getCookies());

        $row = DB::table('platform_admin_tokens')->first();
        $this->assertSame(1, DB::table('platform_admin_tokens')->count());
        $this->assertSame(hash('sha256', $response->json('data.token')), $row->token_hash);
        $this->assertSame([$admin['id'], $admin['grant_id'], 'Pixel 9', '10.20.30.40', 'LavkooSeller/1.0', 'totp'], [(int) $row->admin_id, (int) $row->grant_id, $row->device_name, $row->ip, $row->user_agent, $row->mfa_method]);
        $this->assertSame(hash('sha256', (string) DB::table('admins')->where('id', $admin['id'])->value('password')), $row->password_fingerprint);
        $this->assertNull($row->last_used_at);
        $this->getJson(self::PREFIX . '/me', $this->bearer($response->json('data.token')))->assertOk()->assertJsonPath('data.admin_id', $admin['id']);
    }

    public function test_it_consumes_the_challenge_marks_the_step_as_used_and_audits_the_sign_in_with_its_method(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->enrolledAdmin();
        $challenge = $this->challengeFor($admin, deviceName: 'Pixel 9');

        $this->second(['challenge' => $challenge, 'code' => $this->totpFor($admin['factor'])])->assertOk();

        $this->assertSame('2026-11-17 10:00:00', DB::table('platform_admin_mfa_challenges')->where('token_hash', hash('sha256', $challenge))->value('consumed_at'));
        $this->assertSame(Totp::stepAt(timestamp: now()->timestamp), (int) DB::table('platform_admin_mfa_factors')->value('last_used_step'));
        $lines = DB::table('platform_admin_audit_logs')->orderBy('id')->get();
        $this->assertSame(['auth.mfa_challenge', 'auth.login'], $lines->pluck('action')->all());
        $this->assertSame($admin['id'], (int) $lines[1]->admin_id);
        $this->assertSame((int) DB::table('platform_admin_tokens')->value('id'), (int) $lines[1]->token_id);
        $this->assertSame($this->sortedByKey(['device_name' => 'Pixel 9', 'mfa' => 'totp']), $this->contextOf($lines[1]));
    }

    public function test_the_code_of_the_step_before_or_after_is_accepted_and_spaces_in_it_are_ignored_and_two_steps_away_is_not(): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        $admin = $this->enrolledAdmin();

        foreach ([1, -1] as $steps) {
            DB::table('platform_admin_mfa_factors')->update(['last_used_step' => Totp::stepAt(timestamp: now()->timestamp) - 5]);
            $code = $this->totpFor($admin['factor'], steps: $steps);
            $this->second(['challenge' => $this->challengeFor($admin), 'code' => substr($code, 0, 3) . ' ' . substr($code, 3)])->assertOk();
        }

        foreach ([2, -2] as $steps) {
            DB::table('platform_admin_mfa_factors')->update(['last_used_step' => Totp::stepAt(timestamp: now()->timestamp) - 5]);
            $this->second(['challenge' => $this->challengeFor($admin), 'code' => $this->totpFor($admin['factor'], steps: $steps)])->assertStatus(401);
        }
    }

    public function test_the_second_step_needs_no_bearer_token_and_ignores_one(): void
    {
        $admin = $this->enrolledAdmin();
        $stranger = $this->enrolledAdmin();
        $strangerToken = $this->mintToken($stranger, ['mfa_method' => 'totp']);

        $response = $this->second(['challenge' => $this->challengeFor($admin), 'code' => $this->totpFor($admin['factor'])], headers: $this->bearer($strangerToken));

        $response->assertOk()->assertJsonPath('data.me.admin_id', $admin['id']);
    }

    // ---- a recovery code ----------------------------------------------------------------------------------------------------------------------

    public function test_a_recovery_code_opens_a_session_once_and_says_how_many_are_left(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->enrolledAdmin();
        $code = $admin['factor']['recovery_codes'][3];

        $response = $this->second(['challenge' => $this->challengeFor($admin), 'recovery_code' => $code]);

        $response->assertOk();
        $this->assertSame(['state' => 'verified', 'method' => 'recovery_code', 'recovery_codes_left' => 9], $response->json('data.mfa'));
        $this->assertSame('recovery_code', DB::table('platform_admin_tokens')->value('mfa_method'));
        $stored = json_decode((string) DB::table('platform_admin_mfa_factors')->value('recovery_codes'), true);
        $this->assertCount(10, $stored);
        $used = array_values(array_filter($stored, static fn (array $entry): bool => $entry['used_at'] !== null));
        $this->assertCount(1, $used);
        $this->assertSame(Carbon::parse('2026-11-17 10:00:00')->utc()->toIso8601ZuluString(), $used[0]['used_at']);
        $this->assertNull(DB::table('platform_admin_mfa_factors')->value('last_used_step'), 'a recovery code does not touch the step of the app');

        $this->second(['challenge' => $this->challengeFor($admin), 'recovery_code' => $code])->assertStatus(401)->assertExactJson($this->uniformRefusal());
        $this->assertSame(1, DB::table('platform_admin_tokens')->count(), 'a recovery code works once');
    }

    public function test_a_recovery_code_may_be_typed_in_capitals_without_the_hyphen_or_with_spaces_and_another_code_still_works(): void
    {
        $admin = $this->enrolledAdmin();
        [$first, $second, $third] = $admin['factor']['recovery_codes'];

        $this->second(['challenge' => $this->challengeFor($admin), 'recovery_code' => strtoupper($first)])->assertOk()->assertJsonPath('data.mfa.recovery_codes_left', 9);
        $this->second(['challenge' => $this->challengeFor($admin), 'recovery_code' => str_replace('-', '', $second)])->assertOk()->assertJsonPath('data.mfa.recovery_codes_left', 8);
        $this->second(['challenge' => $this->challengeFor($admin), 'recovery_code' => ' ' . substr($third, 0, 5) . ' ' . substr($third, 6) . ' '])->assertOk()->assertJsonPath('data.mfa.recovery_codes_left', 7);
    }

    public function test_using_a_recovery_code_is_audited_with_how_many_are_left_and_never_the_code(): void
    {
        $admin = $this->enrolledAdmin();
        $code = $admin['factor']['recovery_codes'][0];

        $this->second(['challenge' => $this->challengeFor($admin), 'recovery_code' => $code])->assertOk();

        $lines = DB::table('platform_admin_audit_logs')->orderBy('id')->get();
        $this->assertSame(['auth.mfa_challenge', 'auth.login', 'mfa.recovery_code_used'], $lines->pluck('action')->all());
        $this->assertSame($this->sortedByKey(['device_name' => 'Test phone', 'mfa' => 'recovery_code']), $this->contextOf($lines[1]));
        $this->assertSame(['recovery_codes_left' => 9], json_decode($lines[2]->context, true));
        $this->assertSame((int) DB::table('platform_admin_tokens')->value('id'), (int) $lines[2]->token_id);
        $this->assertStringNotContainsString(str_replace('-', '', $code), json_encode($lines));
        $this->assertStringNotContainsString($code, json_encode($lines));
    }

    public function test_a_wrong_recovery_code_counts_and_the_codes_that_are_left_are_the_codes_that_were_not_used(): void
    {
        $admin = $this->enrolledAdmin();
        $challenge = $this->challengeFor($admin);

        $this->second(['challenge' => $challenge, 'recovery_code' => 'abcde-23456'])->assertStatus(401);

        $this->assertSame(1, (int) DB::table('platform_admin_mfa_challenges')->value('attempts'));
        $this->assertSame(10, count(array_filter(json_decode((string) DB::table('platform_admin_mfa_factors')->value('recovery_codes'), true), static fn (array $entry): bool => $entry['used_at'] === null)));
    }

    // ---- replay -------------------------------------------------------------------------------------------------------------------------------

    public function test_a_code_that_was_used_cannot_be_used_again_but_the_next_one_can(): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        $admin = $this->enrolledAdmin();
        $code = $this->totpFor($admin['factor']);
        $this->second(['challenge' => $this->challengeFor($admin), 'code' => $code])->assertOk();

        $challenge = $this->challengeFor($admin);
        $this->second(['challenge' => $challenge, 'code' => $code])->assertStatus(401)->assertExactJson($this->uniformRefusal());
        $this->assertSame(1, (int) DB::table('platform_admin_mfa_challenges')->where('token_hash', hash('sha256', $challenge))->value('attempts'), 'a replay counts as a try');

        $this->second(['challenge' => $challenge, 'code' => $this->totpFor($admin['factor'], steps: 1)])->assertOk();
    }

    public function test_a_code_of_an_older_step_than_the_last_one_accepted_is_refused_and_so_is_the_same_step(): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        $admin = $this->enrolledAdmin();
        $current = Totp::stepAt(timestamp: now()->timestamp);
        DB::table('platform_admin_mfa_factors')->update(['last_used_step' => $current + 1]);

        foreach ([-1, 0, 1] as $steps) {
            $this->second(['challenge' => $this->challengeFor($admin), 'code' => $this->totpFor($admin['factor'], steps: $steps)])->assertStatus(401);
        }

        DB::table('platform_admin_mfa_factors')->update(['last_used_step' => $current]);
        $this->second(['challenge' => $this->challengeFor($admin), 'code' => $this->totpFor($admin['factor'], steps: 1)])->assertOk();
        $this->assertSame($current + 1, (int) DB::table('platform_admin_mfa_factors')->value('last_used_step'));
    }

    public function test_the_step_is_that_of_the_admin_and_not_of_the_challenge_or_the_address(): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        $admin = $this->enrolledAdmin();
        $code = $this->totpFor($admin['factor']);
        $this->second(['challenge' => $this->challengeFor($admin, server: ['REMOTE_ADDR' => '10.0.0.1']), 'code' => $code], server: ['REMOTE_ADDR' => '10.0.0.1'])->assertOk();

        $this->second(['challenge' => $this->challengeFor($admin, server: ['REMOTE_ADDR' => '10.0.0.2']), 'code' => $code], server: ['REMOTE_ADDR' => '10.0.0.2'])->assertStatus(401);
    }

    // ---- what is not accepted: one answer, one piece of work ----------------------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function causes(): array
    {
        return [
            'a challenge that does not exist' => ['unknown'],
            'a challenge that is not the shape of one' => ['malformed challenge'],
            'no challenge at all' => ['no challenge'],
            'a challenge that has expired' => ['expired'],
            'a challenge that was consumed' => ['consumed'],
            'a challenge whose tries are used up' => ['exhausted'],
            'a wrong code' => ['wrong code'],
            'a code of a step that was used' => ['replayed code'],
            'a wrong recovery code' => ['wrong recovery code'],
            'a recovery code that was used' => ['used recovery code'],
            'a code that is not six digits' => ['malformed code'],
            'a code and a recovery code together' => ['both'],
            'neither a code nor a recovery code' => ['neither'],
            'an admin who was deactivated' => ['inactive'],
            'a grant that was revoked' => ['revoked grant'],
            'a grant made for another admin row' => ['another row'],
            'a password that changed since the challenge' => ['password changed'],
            'a factor that was reset' => ['factor reset'],
            'a factor that the application key cannot read' => ['unreadable secret'],
            'a challenge of another admin with this admin\'s code' => ['another admins challenge'],
        ];
    }

    #[DataProvider('causes')]
    public function test_every_cause_is_the_same_401_with_the_same_headers_and_opens_nothing_and_uses_nothing_up(string $cause): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        [$body] = $this->bodyFor(cause: $cause);
        $factorsBefore = json_encode(DB::table('platform_admin_mfa_factors')->orderBy('id')->get());

        $response = $this->second($body);

        $response->assertStatus(401)->assertExactJson($this->uniformRefusal());
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame([], $response->headers->getCookies());
        $this->assertSame(0, DB::table('platform_admin_tokens')->count());
        $this->assertSame($factorsBefore, json_encode(DB::table('platform_admin_mfa_factors')->orderBy('id')->get()), 'no step and no recovery code is used up by a refusal');
    }

    #[DataProvider('causes')]
    public function test_every_cause_costs_exactly_one_check_of_a_code_and_a_dummy_one_when_there_is_nothing_to_check_it_against(string $cause): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        [$body] = $this->bodyFor(cause: $cause);
        $spy = $this->spyOnTheVerifier();
        $spy->calls = [];

        $this->second($body)->assertStatus(401);

        $this->assertCount(1, $spy->calls, 'one check of a code, whatever the cause');
        $withAFactor = ['wrong code', 'replayed code', 'wrong recovery code', 'used recovery code', 'malformed code', 'both', 'neither', 'unreadable secret', 'another admins challenge'];
        $this->assertSame(in_array($cause, $withAFactor, true), $spy->calls[0], $cause . ($spy->calls[0] ? ': against the factor' : ': against nothing, so it costs what a real one does'));
    }

    public function test_a_success_is_one_check_against_the_factor(): void
    {
        $admin = $this->enrolledAdmin();
        $spy = $this->spyOnTheVerifier();

        $this->second(['challenge' => $this->challengeFor($admin), 'code' => $this->totpFor($admin['factor'])])->assertOk();

        $this->assertSame([true], $spy->calls);
    }

    public function test_every_cause_is_logged_with_its_reason_and_a_hash_of_the_challenge_and_never_the_challenge_or_the_code(): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        $log = $this->captureLog();
        $admin = $this->enrolledAdmin();
        $challenge = $this->challengeFor($admin);
        $wrong = $this->wrongCode($admin);
        $this->second(['challenge' => $challenge, 'code' => $wrong])->assertStatus(401);
        $this->second(['challenge' => 'lkpc_' . Str::random(48), 'code' => $wrong])->assertStatus(401);
        $this->second(['challenge' => $challenge])->assertStatus(401);

        $records = array_values(array_filter($log->getRecords(), static fn (LogRecord $record): bool => $record->message === 'Platform admin second factor refused.'));
        $this->assertSame(['wrong_code', 'unknown_challenge', 'malformed_input'], array_map(static fn (LogRecord $record): string => $record->context['reason'], $records));
        $this->assertSame(substr(hash('sha256', $challenge), 0, 12), $records[0]->context['challenge_hash']);
        $this->assertSame('127.0.0.1', $records[0]->context['ip']);
        $written = json_encode(array_map(static fn (LogRecord $record): array => [$record->message, $record->context], $log->getRecords()));
        foreach ([$challenge, substr($challenge, 5), $wrong, $admin['factor']['base32'], $admin['password']] as $secret) {
            $this->assertStringNotContainsString($secret, $written);
        }
    }

    // ---- the tries of a challenge -------------------------------------------------------------------------------------------------------------

    public function test_a_challenge_allows_five_tries_and_is_then_consumed_so_that_the_right_code_no_longer_opens_it(): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        $admin = $this->enrolledAdmin();
        $challenge = $this->challengeFor($admin);

        for ($try = 1; $try <= 5; $try++) {
            $this->second(['challenge' => $challenge, 'code' => $this->wrongCode($admin)])->assertStatus(401)->assertExactJson($this->uniformRefusal());
            $this->assertSame($try, (int) $this->challengeRow($challenge)->attempts);
        }

        $this->assertNotNull($this->challengeRow($challenge)->consumed_at, 'the last try consumed it');
        $this->second(['challenge' => $challenge, 'code' => $this->totpFor($admin['factor'])])->assertStatus(401)->assertExactJson($this->uniformRefusal());
        $this->assertSame(5, (int) $this->challengeRow($challenge)->attempts, 'a challenge that is used up counts no more');
        $this->assertSame(0, DB::table('platform_admin_tokens')->count());
    }

    public function test_the_right_code_at_the_last_try_still_opens_a_session(): void
    {
        $admin = $this->enrolledAdmin();
        $challenge = $this->challengeFor($admin);
        for ($try = 1; $try <= 4; $try++) {
            $this->second(['challenge' => $challenge, 'code' => $this->wrongCode($admin)])->assertStatus(401);
        }

        $this->second(['challenge' => $challenge, 'code' => $this->totpFor($admin['factor'])])->assertOk();

        $this->assertSame(5, (int) $this->challengeRow($challenge)->attempts);
        $this->assertNotNull($this->challengeRow($challenge)->consumed_at);
    }

    public function test_the_number_of_tries_is_the_configured_one_up_to_its_ceiling(): void
    {
        foreach ([[2, 2], [10, 10], [99, 10]] as [$configured, $allowed]) {
            config(['gcc.platform_admin.mfa.max_challenge_attempts' => $configured, 'gcc.platform_admin.mfa.lockout_failures' => 20]);
            $admin = $this->enrolledAdmin();
            $challenge = $this->challengeFor($admin);

            for ($try = 1; $try <= $allowed + 1; $try++) {
                $this->second(['challenge' => $challenge, 'code' => $this->wrongCode($admin)])->assertStatus(401);
            }

            $this->assertSame($allowed, (int) $this->challengeRow($challenge)->attempts, "configured {$configured}");
        }
    }

    public function test_an_expired_challenge_is_refused_without_counting_and_the_last_moment_is_expired(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->enrolledAdmin();
        $challenge = $this->challengeFor($admin);

        $this->travelTo('2026-11-17 10:04:59');
        $this->second(['challenge' => $challenge, 'code' => $this->wrongCode($admin)])->assertStatus(401);
        $this->assertSame(1, (int) $this->challengeRow($challenge)->attempts, 'one second before its last moment it is a challenge like any other');

        $this->travelTo('2026-11-17 10:05:00');
        $this->second(['challenge' => $challenge, 'code' => $this->totpFor($admin['factor'])])->assertStatus(401);
        $this->assertSame(1, (int) $this->challengeRow($challenge)->attempts, 'an expired challenge counts nothing');
        $this->assertSame(0, DB::table('platform_admin_tokens')->count());
    }

    public function test_a_consumed_challenge_cannot_be_used_again_and_nothing_is_opened_twice(): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        $admin = $this->enrolledAdmin();
        $challenge = $this->challengeFor($admin);
        $this->second(['challenge' => $challenge, 'code' => $this->totpFor($admin['factor'])])->assertOk();

        $this->second(['challenge' => $challenge, 'code' => $this->totpFor($admin['factor'], steps: 1)])->assertStatus(401);

        $this->assertSame(1, DB::table('platform_admin_tokens')->count());
        $this->assertSame(1, (int) $this->challengeRow($challenge)->attempts, 'a consumed challenge counts nothing more');
    }

    public function test_the_code_of_another_admin_does_not_open_this_admins_challenge(): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        $admin = $this->enrolledAdmin();
        $other = $this->enrolledAdmin();

        $this->second(['challenge' => $this->challengeFor($admin), 'code' => $this->totpFor($other['factor'])])->assertStatus(401);
        $this->second(['challenge' => $this->challengeFor($admin), 'recovery_code' => $other['factor']['recovery_codes'][0]])->assertStatus(401);

        $this->assertSame(0, DB::table('platform_admin_tokens')->count());
    }

    // ---- the lockout of an admin --------------------------------------------------------------------------------------------------------------

    public function test_wrong_codes_across_challenges_lock_the_admin_and_a_good_challenge_then_answers_429_without_counting_or_checking(): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        config(['gcc.platform_admin.mfa.lockout_failures' => 3]);
        $admin = $this->enrolledAdmin();
        $first = $this->challengeFor($admin);
        $second = $this->challengeFor($admin);
        $this->second(['challenge' => $first, 'code' => $this->wrongCode($admin)])->assertStatus(401);
        $this->second(['challenge' => $first, 'code' => $this->wrongCode($admin)])->assertStatus(401);
        $this->second(['challenge' => $second, 'recovery_code' => 'abcde-23456'])->assertStatus(401);
        $spy = $this->spyOnTheVerifier();

        $response = $this->second(['challenge' => $second, 'code' => $this->totpFor($admin['factor'])]);

        $response->assertStatus(429)->assertExactJson(['errors' => [['code' => 'platform_admin_throttled', 'message' => 'Too many attempts. Try again later.']]]);
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(900, (int) $response->headers->get('Retry-After'));
        $this->assertSame([], $spy->calls, 'a locked admin\'s code is not even checked');
        $this->assertSame(1, (int) $this->challengeRow($second)->attempts, 'and not counted');
        $this->assertSame(0, DB::table('platform_admin_tokens')->count());
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function test_a_locked_admin_is_not_visible_to_anybody_without_a_good_challenge_unknown_and_used_up_challenges_are_still_the_401(): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        config(['gcc.platform_admin.mfa.lockout_failures' => 1]);
        $admin = $this->enrolledAdmin();
        $good = $this->challengeFor($admin);
        $consumed = $this->challengeFor($admin);
        $this->second(['challenge' => $consumed, 'code' => $this->totpFor($admin['factor'])])->assertOk();
        $this->second(['challenge' => $good, 'code' => $this->wrongCode($admin)])->assertStatus(401);

        $this->second(['challenge' => 'lkpc_' . Str::random(48), 'code' => '000000'])->assertStatus(401)->assertExactJson($this->uniformRefusal());
        $this->second(['challenge' => $consumed, 'code' => '000000'])->assertStatus(401)->assertExactJson($this->uniformRefusal());
        $this->second(['challenge' => 'x'])->assertStatus(401)->assertExactJson($this->uniformRefusal());
        $this->second(['challenge' => $good, 'code' => '000000'])->assertStatus(429);
    }

    public function test_what_is_not_a_guess_does_not_count_against_the_admin(): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        config(['gcc.platform_admin.mfa.lockout_failures' => 3]);
        $admin = $this->enrolledAdmin();
        $malformed = $this->challengeFor($admin);
        $deactivated = $this->challengeFor($admin);
        DB::table('admins')->where('id', $admin['id'])->update(['status' => 0]);

        for ($try = 1; $try <= 3; $try++) {
            $this->second(['challenge' => $malformed, 'code' => '12ab56'])->assertStatus(401);
            $this->second(['challenge' => $deactivated, 'code' => $this->totpFor($admin['factor'])])->assertStatus(401);
        }
        DB::table('admins')->where('id', $admin['id'])->update(['status' => 1]);

        $this->second(['challenge' => $this->challengeFor($admin), 'code' => $this->totpFor($admin['factor'])])->assertOk();
    }

    public function test_a_replayed_code_and_a_wrong_recovery_code_count_against_the_admin_like_a_wrong_code(): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        config(['gcc.platform_admin.mfa.lockout_failures' => 3]);
        $admin = $this->enrolledAdmin();
        $code = $this->totpFor($admin['factor']);
        $this->second(['challenge' => $this->challengeFor($admin), 'code' => $code])->assertOk();
        $challenge = $this->challengeFor($admin);

        $this->second(['challenge' => $challenge, 'code' => $code])->assertStatus(401);
        $this->second(['challenge' => $challenge, 'recovery_code' => 'abcde-23456'])->assertStatus(401);
        $this->second(['challenge' => $challenge, 'code' => $this->wrongCode($admin)])->assertStatus(401);

        $this->second(['challenge' => $challenge, 'code' => $this->totpFor($admin['factor'], steps: 1)])->assertStatus(429);
    }

    public function test_the_lockout_is_the_configured_one_and_the_window_ends(): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        config(['gcc.platform_admin.mfa.lockout_failures' => 2, 'gcc.platform_admin.mfa.lockout_minutes' => 5]);
        $admin = $this->enrolledAdmin();
        $challenge = $this->challengeFor($admin);
        $this->second(['challenge' => $challenge, 'code' => $this->wrongCode($admin)])->assertStatus(401);
        $this->second(['challenge' => $challenge, 'code' => $this->wrongCode($admin)])->assertStatus(401);
        $this->second(['challenge' => $challenge, 'code' => $this->totpFor($admin['factor'])])->assertStatus(429);

        $this->travelTo('2026-11-17 10:05:30');
        $fresh = $this->challengeFor($admin);
        $this->second(['challenge' => $fresh, 'code' => $this->totpFor($admin['factor'])])->assertOk();
    }

    public function test_the_lockout_is_audited_once_when_it_begins(): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        config(['gcc.platform_admin.mfa.lockout_failures' => 2]);
        $admin = $this->enrolledAdmin();
        $challenge = $this->challengeFor($admin);

        $this->second(['challenge' => $challenge, 'code' => $this->wrongCode($admin)])->assertStatus(401);
        $this->assertSame(0, DB::table('platform_admin_audit_logs')->where('action', 'auth.mfa_locked')->count());
        $this->second(['challenge' => $challenge, 'code' => $this->wrongCode($admin)])->assertStatus(401);
        $this->second(['challenge' => $challenge, 'code' => $this->wrongCode($admin)])->assertStatus(429);
        $this->second(['challenge' => $challenge, 'code' => $this->wrongCode($admin)])->assertStatus(429);

        $lines = DB::table('platform_admin_audit_logs')->where('action', 'auth.mfa_locked')->get();
        $this->assertCount(1, $lines);
        $this->assertSame($admin['id'], (int) $lines[0]->admin_id);
        $this->assertSame($this->sortedByKey(['failures' => 2, 'minutes' => 15]), $this->contextOf($lines[0]));
    }

    // ---- the limit of an address --------------------------------------------------------------------------------------------------------------

    public function test_an_address_may_try_three_times_the_sign_in_number_a_minute_whatever_the_challenge_and_then_is_throttled(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        config(['gcc.platform_admin.login_attempts_per_minute' => 2]);

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->second(['challenge' => 'lkpc_' . Str::random(48), 'code' => '000000'])->assertStatus(401);
        }
        $response = $this->second(['challenge' => 'lkpc_' . Str::random(48), 'code' => '000000']);

        $response->assertStatus(429)->assertExactJson(['errors' => [['code' => 'platform_admin_throttled', 'message' => 'Too many attempts. Try again later.']]]);
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->second(['challenge' => 'lkpc_' . Str::random(48), 'code' => '000000'], server: ['REMOTE_ADDR' => '10.20.30.40'])->assertStatus(401);

        $this->travelTo('2026-11-17 10:01:01');
        $this->second(['challenge' => 'lkpc_' . Str::random(48), 'code' => '000000'])->assertStatus(401);
    }

    public function test_a_throttled_second_step_reads_nothing_checks_nothing_and_is_private(): void
    {
        config(['gcc.platform_admin.login_attempts_per_minute' => 1]);
        $admin = $this->enrolledAdmin();
        $challenge = $this->challengeFor($admin);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->second(['challenge' => 'lkpc_' . Str::random(48), 'code' => '000000']);
        }
        $spy = $this->spyOnTheVerifier();

        $statements = $this->statementsRunBy(function () use ($challenge, $admin, &$response): void {
            $response = $this->second(['challenge' => $challenge, 'code' => $this->totpFor($admin['factor'])]);
        });

        $response->assertStatus(429);
        $this->assertSame([], $this->withoutSchemaChecks($statements));
        $this->assertSame([], $spy->calls);
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function test_the_second_step_and_the_first_have_limits_of_their_own(): void
    {
        config(['gcc.platform_admin.login_attempts_per_minute' => 1]);
        $admin = $this->enrolledAdmin();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->second(['challenge' => 'lkpc_' . Str::random(48), 'code' => '000000'])->assertStatus(401);
        }
        $this->second(['challenge' => 'lkpc_' . Str::random(48), 'code' => '000000'])->assertStatus(429);

        $this->login($admin)->assertOk();
    }

    // ---- what a challenge is bound to ---------------------------------------------------------------------------------------------------------

    public function test_a_password_changed_after_the_challenge_ends_it(): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        $admin = $this->enrolledAdmin();
        $challenge = $this->challengeFor($admin);
        DB::table('admins')->where('id', $admin['id'])->update(['password' => Hash::make(Str::random(20))]);

        $this->second(['challenge' => $challenge, 'code' => $this->totpFor($admin['factor'])])->assertStatus(401);

        $this->assertSame(0, DB::table('platform_admin_tokens')->count());
        $this->assertNull(DB::table('platform_admin_mfa_factors')->value('last_used_step'), 'the code was not used up by a sign-in that could not be completed');
    }

    public function test_a_grant_revoked_an_admin_deactivated_or_a_grant_made_for_another_row_ends_it(): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        $revoked = $this->enrolledAdmin();
        $inactive = $this->enrolledAdmin();
        $other = $this->enrolledAdmin();
        $challenges = [$revoked['id'] => $this->challengeFor($revoked), $inactive['id'] => $this->challengeFor($inactive), $other['id'] => $this->challengeFor($other)];
        DB::table('platform_admin_grants')->where('id', $revoked['grant_id'])->update(['revoked_at' => now()]);
        DB::table('admins')->where('id', $inactive['id'])->update(['status' => 0]);
        DB::table('platform_admin_grants')->where('id', $other['grant_id'])->update(['admin_fingerprint' => hash('sha256', 'another row')]);

        foreach ([$revoked, $inactive, $other] as $admin) {
            $this->second(['challenge' => $challenges[$admin['id']], 'code' => $this->totpFor($admin['factor'])])->assertStatus(401);
        }
        $this->assertSame(0, DB::table('platform_admin_tokens')->count());
    }

    public function test_a_factor_that_was_reset_or_replaced_after_the_challenge_ends_it(): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        $reset = $this->enrolledAdmin();
        $replaced = $this->enrolledAdmin();
        $resetChallenge = $this->challengeFor($reset);
        $replacedChallenge = $this->challengeFor($replaced);

        $this->assertSame(0, Artisan::call('gcc:platform-admin:mfa:reset', ['admin_id' => $reset['id']]));
        $this->assertSame(0, Artisan::call('gcc:platform-admin:mfa:enroll', ['admin_id' => $replaced['id'], '--replace' => true]));

        $this->second(['challenge' => $resetChallenge, 'code' => $this->totpFor($reset['factor'])])->assertStatus(401);
        $this->second(['challenge' => $replacedChallenge, 'code' => $this->totpFor($replaced['factor'])])->assertStatus(401);
        $this->assertSame(0, DB::table('platform_admin_tokens')->count());
    }

    public function test_a_factor_that_is_pending_or_was_made_for_another_row_does_not_complete_a_sign_in(): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        $pending = $this->enrolledAdmin();
        $foreign = $this->enrolledAdmin();
        $pendingChallenge = $this->challengeFor($pending);
        $foreignChallenge = $this->challengeFor($foreign);
        DB::table('platform_admin_mfa_factors')->where('admin_id', $pending['id'])->update(['confirmed_at' => null]);
        DB::table('platform_admin_mfa_factors')->where('admin_id', $foreign['id'])->update(['admin_fingerprint' => hash('sha256', 'another row')]);

        $this->second(['challenge' => $pendingChallenge, 'code' => $this->totpFor($pending['factor'])])->assertStatus(401);
        $this->second(['challenge' => $foreignChallenge, 'code' => $this->totpFor($foreign['factor'])])->assertStatus(401);
        $this->assertSame(0, DB::table('platform_admin_tokens')->count());
    }

    public function test_the_second_step_does_not_depend_on_the_switch_being_on_when_the_challenge_is_completed(): void
    {
        $admin = $this->enrolledAdmin();
        $challenge = $this->challengeFor($admin);
        config(['gcc.platform_admin.mfa.required' => false]);

        $this->second(['challenge' => $challenge, 'code' => $this->totpFor($admin['factor'])])->assertOk()->assertJsonPath('data.mfa.method', 'totp');
    }

    // ---- what is kept, and what is audited ----------------------------------------------------------------------------------------------------

    public function test_a_refusal_for_an_admin_with_a_grant_is_audited_with_its_reason_a_few_times_an_hour_and_one_without_a_grant_is_only_logged(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        config(['gcc.platform_admin.failed_login_audit_per_hour' => 3, 'gcc.platform_admin.mfa.lockout_failures' => 20, 'gcc.platform_admin.mfa.max_challenge_attempts' => 10]);
        $admin = $this->enrolledAdmin();
        $challenge = $this->challengeFor($admin);

        for ($attempt = 1; $attempt <= 8; $attempt++) {
            $this->second(['challenge' => $challenge, 'code' => $this->wrongCode($admin)])->assertStatus(401);
        }
        $this->second(['challenge' => 'lkpc_' . Str::random(48), 'code' => '000000'])->assertStatus(401);

        $lines = DB::table('platform_admin_audit_logs')->where('action', 'auth.mfa_failed')->get();
        $this->assertCount(3, $lines, 'three lines an hour for a grant, however many tries fail, and none for a challenge nobody was given');
        $this->assertSame([$admin['id']], array_values(array_unique($lines->pluck('admin_id')->map(fn ($id) => (int) $id)->all())));
        $this->assertSame(['wrong_code', 'wrong_code', 'wrong_code'], $lines->map(fn ($line) => json_decode($line->context, true)['reason'])->all());
        $this->assertSame('127.0.0.1', $lines[0]->ip);

        $this->travelTo('2026-11-17 11:00:01');
        $this->second(['challenge' => $challenge, 'code' => $this->wrongCode($admin)])->assertStatus(401);
        $this->assertSame(4, DB::table('platform_admin_audit_logs')->where('action', 'auth.mfa_failed')->count());
    }

    public function test_the_hourly_count_of_refused_second_steps_is_not_the_one_of_refused_passwords(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        config(['gcc.platform_admin.failed_login_audit_per_hour' => 2]);
        $admin = $this->enrolledAdmin();
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->login(['email' => $admin['email'], 'password' => 'wrong'])->assertStatus(401);
        }
        $challenge = $this->challengeFor($admin);
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->second(['challenge' => $challenge, 'code' => $this->wrongCode($admin)])->assertStatus(401);
        }

        $this->assertSame(2, DB::table('platform_admin_audit_logs')->where('action', 'auth.login_failed')->count());
        $this->assertSame(2, DB::table('platform_admin_audit_logs')->where('action', 'auth.mfa_failed')->count());
    }

    public function test_the_reasons_of_the_refusals_are_the_ones_of_the_contract(): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        config(['gcc.platform_admin.failed_login_audit_per_hour' => 1000, 'gcc.platform_admin.mfa.lockout_failures' => 20]);
        $reasons = [];
        foreach (['expired' => 'expired_challenge', 'consumed' => 'consumed_challenge', 'exhausted' => 'attempts_exhausted', 'wrong code' => 'wrong_code', 'replayed code' => 'replayed_code', 'malformed code' => 'malformed_input',
            'inactive' => 'inactive_admin', 'revoked grant' => 'revoked_grant', 'another row' => 'grant_of_another_admin_row', 'password changed' => 'password_changed', 'factor reset' => 'unusable_factor', 'unreadable secret' => 'unusable_factor'] as $cause => $reason) {
            DB::table('platform_admin_audit_logs')->delete();
            [$body] = $this->bodyFor(cause: $cause);

            $this->second($body)->assertStatus(401);

            $line = DB::table('platform_admin_audit_logs')->where('action', 'auth.mfa_failed')->latest('id')->first();
            $reasons[$cause] = $line === null ? null : json_decode($line->context, true)['reason'];
            $this->assertSame($reason, $reasons[$cause], $cause);
        }
    }

    public function test_the_refusal_is_audited_after_the_answer_has_been_given(): void
    {
        $admin = $this->enrolledAdmin();
        $challenge = $this->challengeFor($admin);
        DB::table('platform_admin_audit_logs')->delete();
        $kernel = $this->app->make(HttpKernel::class);
        $request = Request::create(self::MFA, 'POST', ['challenge' => $challenge, 'code' => $this->wrongCode($admin)], [], [], ['HTTP_ACCEPT' => 'application/json']);

        $response = $kernel->handle($request);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(0, DB::table('platform_admin_audit_logs')->count(), 'nothing is written while the answer is made, so a refusal costs the caller the same whoever they claim to be');

        $kernel->terminate($request, $response);

        $this->assertSame(1, DB::table('platform_admin_audit_logs')->where('action', 'auth.mfa_failed')->count());
    }

    public function test_unauthenticated_traffic_to_the_second_step_cannot_make_any_table_grow(): void
    {
        $before = $this->ownRowCounts();

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->second(['challenge' => 'lkpc_' . Str::random(48), 'code' => '123456'])->assertStatus(401);
            $this->second([])->assertStatus(401);
            $this->second(['challenge' => 'x', 'recovery_code' => 'y'])->assertStatus(401);
        }

        $this->assertSame($before, $this->ownRowCounts());
    }

    public function test_nothing_of_a_whole_sign_in_leaves_a_challenge_a_code_a_recovery_code_a_token_or_the_secret_in_a_table_or_the_log(): void
    {
        $log = $this->captureLog();
        $admin = $this->enrolledAdmin();
        $challenge = $this->challengeFor($admin);
        $code = $this->totpFor($admin['factor']);
        $this->second(['challenge' => $challenge, 'code' => $this->wrongCode($admin)])->assertStatus(401);
        $token = (string) $this->second(['challenge' => $challenge, 'code' => $code])->json('data.token');
        $recovery = $admin['factor']['recovery_codes'][2];
        $this->second(['challenge' => $this->challengeFor($admin), 'recovery_code' => $recovery])->assertOk();
        $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertOk();

        $everything = '';
        foreach (['platform_admin_tokens', 'platform_admin_audit_logs', 'platform_admin_grants', 'platform_admin_mfa_challenges'] as $table) {
            $everything .= json_encode(DB::table($table)->get());
        }
        $everything .= json_encode(array_map(static fn (LogRecord $record): array => [$record->message, $record->context], $log->getRecords()));
        foreach ([$challenge, substr($challenge, 5), $code, $recovery, str_replace('-', '', $recovery), $token, substr($token, 5), $admin['factor']['base32'], bin2hex($admin['factor']['secret']), $admin['password']] as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
        }

        $known = ['device_name', 'mfa', 'reason', 'recovery_codes_left', 'status', 'params'];
        foreach (DB::table('platform_admin_audit_logs')->pluck('context') as $context) {
            $this->assertSame([], array_values(array_diff(array_keys((array) json_decode((string) $context, true)), $known)), 'a line of the trail holds only the members the contract names: ' . $context);
        }
    }

    public function test_the_secret_of_a_factor_is_never_in_the_serialised_model_the_trail_or_any_answer_of_the_api(): void
    {
        $admin = $this->enrolledAdmin();
        $token = $this->signInWithMfa($admin);

        $answers = '';
        foreach ($this->platformRoutes() as $route) {
            if (!in_array('GET', $route->methods(), true)) {
                continue;
            }
            [$method, $uri] = $this->requestTo($route);
            $answers .= $this->json($method, $uri, [], $this->bearer($token))->getContent();
        }

        foreach ([$admin['factor']['base32'], bin2hex($admin['factor']['secret']), ...$admin['factor']['recovery_codes']] as $secret) {
            $this->assertStringNotContainsString($secret, $answers);
        }
        $this->assertStringNotContainsString($admin['factor']['base32'], json_encode(PlatformAdminMfaFactor::query()->get()->toArray()));
    }

    // ---- helpers ------------------------------------------------------------------------------------------------------------------------------

    /**
     * The request that is refused for a cause, and the admin it is for. Each sets up its own world, so that a test can ask for any of them.
     *
     * @return array{0: array<string, string>, 1: array<string, mixed>}
     */
    private function bodyFor(string $cause): array
    {
        $admin = $this->enrolledAdmin();
        $challenge = $this->challengeFor($admin);
        $right = fn (): string => $this->totpFor($admin['factor']);

        switch ($cause) {
            case 'unknown':
                return [['challenge' => 'lkpc_' . Str::random(48), 'code' => $right()], $admin];
            case 'malformed challenge':
                return [['challenge' => 'not a challenge', 'code' => $right()], $admin];
            case 'no challenge':
                return [['code' => $right()], $admin];
            case 'expired':
                DB::table('platform_admin_mfa_challenges')->update(['expires_at' => now()->subSecond()]);

                return [['challenge' => $challenge, 'code' => $right()], $admin];
            case 'consumed':
                DB::table('platform_admin_mfa_challenges')->update(['consumed_at' => now()]);

                return [['challenge' => $challenge, 'code' => $right()], $admin];
            case 'exhausted':
                DB::table('platform_admin_mfa_challenges')->update(['attempts' => 5]);

                return [['challenge' => $challenge, 'code' => $right()], $admin];
            case 'wrong code':
                return [['challenge' => $challenge, 'code' => $this->wrongCode($admin)], $admin];
            case 'replayed code':
                DB::table('platform_admin_mfa_factors')->update(['last_used_step' => Totp::stepAt(timestamp: now()->timestamp) + 1]);

                return [['challenge' => $challenge, 'code' => $right()], $admin];
            case 'wrong recovery code':
                return [['challenge' => $challenge, 'recovery_code' => 'abcde-23456'], $admin];
            case 'used recovery code':
                $this->second(['challenge' => $this->challengeFor($admin), 'recovery_code' => $admin['factor']['recovery_codes'][0]])->assertOk();
                DB::table('platform_admin_tokens')->delete();

                return [['challenge' => $challenge, 'recovery_code' => $admin['factor']['recovery_codes'][0]], $admin];
            case 'malformed code':
                return [['challenge' => $challenge, 'code' => '12ab56'], $admin];
            case 'both':
                return [['challenge' => $challenge, 'code' => $right(), 'recovery_code' => $admin['factor']['recovery_codes'][1]], $admin];
            case 'neither':
                return [['challenge' => $challenge], $admin];
            case 'inactive':
                DB::table('admins')->where('id', $admin['id'])->update(['status' => 0]);

                return [['challenge' => $challenge, 'code' => $right()], $admin];
            case 'revoked grant':
                DB::table('platform_admin_grants')->where('id', $admin['grant_id'])->update(['revoked_at' => now()]);

                return [['challenge' => $challenge, 'code' => $right()], $admin];
            case 'another row':
                DB::table('platform_admin_grants')->where('id', $admin['grant_id'])->update(['admin_fingerprint' => hash('sha256', 'another row')]);

                return [['challenge' => $challenge, 'code' => $right()], $admin];
            case 'password changed':
                DB::table('admins')->where('id', $admin['id'])->update(['password' => Hash::make(Str::random(20))]);

                return [['challenge' => $challenge, 'code' => $right()], $admin];
            case 'factor reset':
                DB::table('platform_admin_mfa_factors')->where('admin_id', $admin['id'])->delete();

                return [['challenge' => $challenge, 'code' => $right()], $admin];
            case 'unreadable secret':
                DB::table('platform_admin_mfa_factors')->update(['secret' => 'not a payload this key can open']);

                return [['challenge' => $challenge, 'code' => $right()], $admin];
            case 'another admins challenge':
                $other = $this->enrolledAdmin();

                return [['challenge' => $this->challengeFor($other), 'code' => $right()], $admin];
        }

        $this->fail("no such cause: {$cause}");
    }

    /** A code that is none of the ones an app could show for the admin around now. */
    private function wrongCode(array $admin): string
    {
        $valid = array_map(fn (int $steps): string => $this->totpFor($admin['factor'], steps: $steps), [-2, -1, 0, 1, 2]);

        foreach (range(0, 999) as $candidate) {
            $code = str_pad((string) ($candidate * 997 % 1000000), 6, '0', STR_PAD_LEFT);
            if (!in_array($code, $valid, true)) {
                return $code;
            }
        }

        $this->fail('every candidate was a right code');
    }

    private function challengeRow(string $challenge): object
    {
        return DB::table('platform_admin_mfa_challenges')->where('token_hash', hash('sha256', $challenge))->firstOrFail();
    }

    /** Wraps the check of a code and writes down whether it was given a factor to check against. */
    private function spyOnTheVerifier(): object
    {
        $spy = new class extends PlatformAdminMfaVerifier {
            /** @var list<bool> */
            public array $calls = [];

            public function useCodeOf(MfaAttempt $attempt, ?PlatformAdminMfaFactor $factor): MfaVerdict
            {
                $this->calls[] = $factor !== null;

                return parent::useCodeOf(attempt: $attempt, factor: $factor);
            }
        };
        $this->app->instance(PlatformAdminMfaVerifier::class, $spy);
        foreach ($this->platformRoutes() as $route) {
            $route->flushController();
        }

        return $spy;
    }

    /** @return array<string, int> */
    private function ownRowCounts(): array
    {
        return [
            'tokens' => DB::table('platform_admin_tokens')->count(),
            'grants' => DB::table('platform_admin_grants')->count(),
            'audit' => DB::table('platform_admin_audit_logs')->count(),
            'challenges' => DB::table('platform_admin_mfa_challenges')->count(),
            'factors' => DB::table('platform_admin_mfa_factors')->count(),
        ];
    }
}
