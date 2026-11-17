<?php

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformAdmin\MfaMethod;
use App\Models\PlatformAdminMfaFactor;
use App\Services\PlatformAdmin\Base32;
use App\Services\PlatformAdmin\MfaAttempt;
use App\Services\PlatformAdmin\MfaVerdict;
use App\Services\PlatformAdmin\PlatformAdminMfaVerifier;
use App\Services\PlatformAdmin\RecoveryCodes;
use App\Services\PlatformAdmin\Totp;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The check of a code against a factor, on its own: what it decides, what it uses up, and that a refusal that is not a guess does not say it was one.
 */
class MfaVerifierTest extends MfaTestCase
{
    /** A secret found by search whose code at step 100 (3 000 to 3 029 seconds after the epoch) is six zeros, which is what an attempt that is not for a code is checked as. */
    private const SECRET_WHOSE_CODE_AT_STEP_100_IS_ZEROS = '685807f5a8d598a14261b6c5a678147465934387';

    public function test_the_right_code_is_accepted_and_its_step_is_used_up(): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        $admin = $this->enrolledAdmin();

        $verdict = $this->verify(admin: $admin, body: ['code' => $this->totpFor($admin['factor'])]);

        $this->assertTrue($verdict->isAccepted());
        $this->assertSame(MfaMethod::Totp, $verdict->method);
        $this->assertSame(10, $verdict->recoveryCodesLeft);
        $this->assertNull($verdict->reason);
        $this->assertSame(Totp::stepAt(timestamp: now()->timestamp), $this->factorRow($admin)->last_used_step);
    }

    public function test_only_a_step_later_than_the_last_one_used_is_accepted(): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        $admin = $this->enrolledAdmin();

        // The last step used (relative to the current step), the step of the code sent (relative too), and whether it is refused as a replay.
        foreach ([[-1, 0, false], [0, 0, true], [0, -1, true], [1, 1, true], [0, 1, false], [-5, -1, false], [null, -1, false]] as [$lastUsed, $sent, $replay]) {
            $current = Totp::stepAt(timestamp: now()->timestamp);
            DB::table('platform_admin_mfa_factors')->update(['last_used_step' => $lastUsed === null ? null : $current + $lastUsed]);

            $verdict = $this->verify(admin: $admin, body: ['code' => $this->totpFor($admin['factor'], steps: $sent)]);

            $this->assertSame($replay ? MfaVerdict::REPLAYED_CODE : null, $verdict->reason, "last used {$lastUsed}, code of step {$sent}");
        }
    }

    public function test_a_wrong_code_and_a_replayed_one_are_guesses_and_change_nothing(): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        $admin = $this->enrolledAdmin();
        DB::table('platform_admin_mfa_factors')->update(['last_used_step' => Totp::stepAt(timestamp: now()->timestamp)]);
        $before = json_encode($this->factorRow($admin));

        $replayed = $this->verify(admin: $admin, body: ['code' => $this->totpFor($admin['factor'])]);
        $wrong = $this->verify(admin: $admin, body: ['code' => $this->otherThan($this->totpFor($admin['factor']), $admin)]);

        $this->assertSame([MfaVerdict::REPLAYED_CODE, true], [$replayed->reason, $replayed->wasAGuess()]);
        $this->assertSame([MfaVerdict::WRONG_CODE, true], [$wrong->reason, $wrong->wasAGuess()]);
        $this->assertFalse($wrong->isAccepted());
        $this->assertSame($before, json_encode($this->factorRow($admin)));
    }

    public function test_nothing_to_check_against_is_refused_as_an_unusable_factor_and_is_not_a_guess(): void
    {
        $verifier = new PlatformAdminMfaVerifier();

        foreach ([['code' => '123456'], ['recovery_code' => 'abcde-23456']] as $body) {
            $verdict = $verifier->useCodeOf(attempt: $this->attemptOf($body), factor: null);

            $this->assertSame([MfaVerdict::UNUSABLE_FACTOR, false, false], [$verdict->reason, $verdict->wasAGuess(), $verdict->isAccepted()], json_encode($body));
        }
    }

    public function test_a_secret_the_key_cannot_read_is_an_unusable_factor_for_a_code_and_changes_nothing(): void
    {
        $admin = $this->enrolledAdmin();
        DB::table('platform_admin_mfa_factors')->update(['secret' => Crypt::encryptString('MFRGGZDFMZTWQ2LK')]);
        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstances();

        $verdict = $this->verify(admin: $admin, body: ['code' => '123456']);

        $this->assertSame([MfaVerdict::UNUSABLE_FACTOR, false], [$verdict->reason, $verdict->wasAGuess()]);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function malformedAttempts(): array
    {
        return [
            'both' => [['code' => '123456', 'recovery_code' => 'abcde-23456']],
            'neither' => [[]],
            'a code that is not six digits' => [['code' => '12345']],
            'a recovery code that is not one' => [['recovery_code' => 'abcde']],
            'a code that is not text' => [['code' => 123456]],
            'a code that is a list' => [['code' => ['123456']]],
        ];
    }

    #[DataProvider('malformedAttempts')]
    public function test_a_malformed_attempt_is_refused_as_malformed_and_is_not_a_guess_even_with_a_good_factor(array $body): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        $admin = $this->enrolledAdmin();
        $before = json_encode($this->factorRow($admin));

        $verdict = $this->verify(admin: $admin, body: $body);

        $this->assertSame([MfaVerdict::MALFORMED_INPUT, false], [$verdict->reason, $verdict->wasAGuess()]);
        $this->assertSame($before, json_encode($this->factorRow($admin)));
    }

    public function test_a_malformed_attempt_is_never_accepted_as_the_placeholder_it_is_checked_as_even_when_that_is_the_right_code_of_the_moment(): void
    {
        $admin = $this->enrolledAdmin();
        DB::table('platform_admin_mfa_factors')->update(['secret' => Crypt::encryptString(Base32::encode(bytes: (string) hex2bin(self::SECRET_WHOSE_CODE_AT_STEP_100_IS_ZEROS)))]);
        $this->travelTo(Carbon::createFromTimestampUTC(100 * Totp::PERIOD_SECONDS + 15));

        $control = $this->verify(admin: $admin, body: ['code' => '000000']);
        DB::table('platform_admin_mfa_factors')->update(['last_used_step' => null]);

        $this->assertTrue($control->isAccepted(), 'the setup is right: at this moment 000000 is the code of this admin');
        foreach ([['code' => '000000', 'recovery_code' => 'abcde-23456'], [], ['code' => '00000'], ['recovery_code' => 'abcde']] as $body) {
            $verdict = $this->verify(admin: $admin, body: $body);

            $this->assertSame(MfaVerdict::MALFORMED_INPUT, $verdict->reason, json_encode($body));
        }
        $this->assertNull($this->factorRow($admin)->last_used_step);
    }

    public function test_a_recovery_code_is_accepted_once_marked_used_and_counted(): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        $admin = $this->enrolledAdmin();
        $code = $admin['factor']['recovery_codes'][4];

        $verdict = $this->verify(admin: $admin, body: ['recovery_code' => strtoupper(str_replace('-', '', $code))]);

        $this->assertTrue($verdict->isAccepted());
        $this->assertSame([MfaMethod::RecoveryCode, 9], [$verdict->method, $verdict->recoveryCodesLeft]);
        $stored = json_decode((string) $this->factorRow($admin)->recovery_codes, true);
        $this->assertSame([RecoveryCodes::hash(normalized: (string) RecoveryCodes::normalized(typed: $code)), now()->utc()->toIso8601ZuluString()], [$stored[4]['hash'], $stored[4]['used_at']]);
        $this->assertSame(9, count(array_filter($stored, static fn (array $entry): bool => $entry['used_at'] === null)));
        $this->assertNull($this->factorRow($admin)->last_used_step);

        $again = $this->verify(admin: $admin, body: ['recovery_code' => $code]);
        $this->assertSame([MfaVerdict::REPLAYED_CODE, true, false], [$again->reason, $again->wasAGuess(), $again->isAccepted()]);
        $this->assertSame($stored, json_decode((string) $this->factorRow($admin)->recovery_codes, true), 'a used code is not touched again');
    }

    public function test_any_of_the_ten_recovery_codes_is_found_and_only_that_one_is_used(): void
    {
        $admin = $this->enrolledAdmin();

        foreach ([0, 9] as $index) {
            DB::table('platform_admin_mfa_factors')->update(['recovery_codes' => json_encode(array_map(
                static fn (string $code): array => ['hash' => RecoveryCodes::hash(normalized: (string) RecoveryCodes::normalized(typed: $code)), 'used_at' => null],
                $admin['factor']['recovery_codes'],
            ))]);

            $verdict = $this->verify(admin: $admin, body: ['recovery_code' => $admin['factor']['recovery_codes'][$index]]);

            $this->assertTrue($verdict->isAccepted(), "code {$index}");
            $used = array_keys(array_filter(json_decode((string) $this->factorRow($admin)->recovery_codes, true), static fn (array $entry): bool => $entry['used_at'] !== null));
            $this->assertSame([$index], $used);
        }
    }

    public function test_a_wrong_recovery_code_is_a_guess_and_a_code_of_another_factor_is_wrong(): void
    {
        $admin = $this->enrolledAdmin();
        $other = $this->enrolledAdmin();

        foreach (['abcde-23456', $other['factor']['recovery_codes'][0]] as $code) {
            $verdict = $this->verify(admin: $admin, body: ['recovery_code' => $code]);

            $this->assertSame([MfaVerdict::WRONG_CODE, true], [$verdict->reason, $verdict->wasAGuess()]);
        }
    }

    public function test_a_factor_with_no_recovery_codes_left_to_find_refuses_a_recovery_code_as_wrong(): void
    {
        $admin = $this->enrolledAdmin();
        DB::table('platform_admin_mfa_factors')->update(['recovery_codes' => null]);

        $verdict = $this->verify(admin: $admin, body: ['recovery_code' => $admin['factor']['recovery_codes'][0]]);

        $this->assertSame(MfaVerdict::WRONG_CODE, $verdict->reason);
    }

    /** @param array<string, mixed> $body */
    private function verify(array $admin, array $body): MfaVerdict
    {
        return (new PlatformAdminMfaVerifier())->useCodeOf(attempt: $this->attemptOf($body), factor: PlatformAdminMfaFactor::query()->where('admin_id', $admin['id'])->firstOrFail());
    }

    /** @param array<string, mixed> $body */
    private function attemptOf(array $body): MfaAttempt
    {
        return MfaAttempt::fromRequest(Request::create('/', 'POST', ['challenge' => 'lkpc_' . str_repeat('a', 48)] + $body));
    }

    private function factorRow(array $admin): object
    {
        return DB::table('platform_admin_mfa_factors')->where('admin_id', $admin['id'])->first();
    }

    /** A code that is not the one given, nor any an app could show for the admin around now. */
    private function otherThan(string $code, array $admin): string
    {
        $shown = array_map(fn (int $steps): string => $this->totpFor($admin['factor'], steps: $steps), [-2, -1, 0, 1, 2]);

        foreach (range(0, 999) as $candidate) {
            $other = str_pad((string) ($candidate * 997 % 1000000), 6, '0', STR_PAD_LEFT);
            if ($other !== $code && !in_array($other, $shown, true)) {
                return $other;
            }
        }

        $this->fail('every candidate was a right code');
    }
}
