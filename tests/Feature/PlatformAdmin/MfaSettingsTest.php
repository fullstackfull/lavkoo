<?php

namespace Tests\Feature\PlatformAdmin;

use App\Services\PlatformAdmin\PlatformAdminSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The numbers and the switch of the second factor and of the session rules: what an operator can set, what a value that makes no sense means, and the
 * ceilings nothing configured can lift.
 */
class MfaSettingsTest extends TestCase
{
    public function test_the_defaults_are_the_documented_ones(): void
    {
        $settings = new PlatformAdminSettings();

        $this->assertTrue($settings->mfaRequired());
        $this->assertSame(5, $settings->mfaChallengeTtlMinutes());
        $this->assertSame(5, $settings->mfaMaxChallengeAttempts());
        $this->assertSame(10, $settings->mfaLockoutFailures());
        $this->assertSame(15, $settings->mfaLockoutMinutes());
        $this->assertSame(60, $settings->sessionIdleMinutes());
        $this->assertSame(5, $settings->sessionMaxConcurrent());
    }

    public function test_the_configuration_ships_these_defaults_and_reads_each_number_from_its_own_variable(): void
    {
        $this->assertTrue(config('gcc.platform_admin.mfa.required'));
        $this->assertSame(5, config('gcc.platform_admin.mfa.challenge_ttl_minutes'));
        $this->assertSame(5, config('gcc.platform_admin.mfa.max_challenge_attempts'));
        $this->assertSame(10, config('gcc.platform_admin.mfa.lockout_failures'));
        $this->assertSame(15, config('gcc.platform_admin.mfa.lockout_minutes'));
        $this->assertSame(60, config('gcc.platform_admin.session.idle_minutes'));
        $this->assertSame(5, config('gcc.platform_admin.session.max_concurrent'));

        $source = (string) file_get_contents(base_path('config/gcc.php'));
        foreach ([
            'GCC_PLATFORM_ADMIN_MFA_REQUIRED', 'GCC_PLATFORM_ADMIN_MFA_CHALLENGE_TTL_MINUTES', 'GCC_PLATFORM_ADMIN_MFA_MAX_CHALLENGE_ATTEMPTS',
            'GCC_PLATFORM_ADMIN_MFA_LOCKOUT_FAILURES', 'GCC_PLATFORM_ADMIN_MFA_LOCKOUT_MINUTES', 'GCC_PLATFORM_ADMIN_SESSION_IDLE_MINUTES', 'GCC_PLATFORM_ADMIN_SESSION_MAX_CONCURRENT',
        ] as $variable) {
            $this->assertStringContainsString("'{$variable}'", $source);
        }
    }

    public function test_the_example_environment_file_says_the_second_factor_is_required(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^GCC_PLATFORM_ADMIN_MFA_REQUIRED=true$/m', $example);
        $this->assertMatchesRegularExpression('/^GCC_PLATFORM_ADMIN_ENABLED=false$/m', $example, 'and the API itself is still off');
    }

    /** @return array<string, array{0: mixed, 1: bool}> */
    public static function switches(): array
    {
        return [
            'a real true' => [true, true],
            'a real false' => [false, false],
            'the text false' => ['false', false],
            'the text FALSE' => ['FALSE', false],
            'the text false with spaces round it' => ['  false ', false],
            'the text 0' => ['0', false],
            'the number 0' => [0, false],
            'the text off' => ['off', false],
            'the text no' => ['no', false],
            'the text true' => ['true', true],
            'the text 1' => ['1', true],
            'the number 1' => [1, true],
            'the text yes' => ['yes', true],
            'the text on' => ['on', true],
            'a blank value' => ['', true],
            'a value of spaces' => ['   ', true],
            'nothing at all' => [null, true],
            'a typo' => ['flase', true],
            'a word' => ['maybe', true],
            'a list' => [[], true],
            'a list holding false' => [[false], true],
        ];
    }

    #[DataProvider('switches')]
    public function test_only_a_real_false_or_the_text_of_one_switches_the_second_factor_off(mixed $configured, bool $expected): void
    {
        config(['gcc.platform_admin.mfa.required' => $configured]);

        $this->assertSame($expected, (new PlatformAdminSettings())->mfaRequired());
    }

    public function test_a_missing_entry_leaves_it_required(): void
    {
        config(['gcc.platform_admin.mfa' => []]);

        $this->assertTrue((new PlatformAdminSettings())->mfaRequired());
    }

    /** @return array<string, array{0: string, 1: string, 2: int, 3: int}> the method, the key, the default and the ceiling */
    public static function numbers(): array
    {
        return [
            'the lifetime of a challenge' => ['mfaChallengeTtlMinutes', 'mfa.challenge_ttl_minutes', 5, 15],
            'the tries of a challenge' => ['mfaMaxChallengeAttempts', 'mfa.max_challenge_attempts', 5, 10],
            'the failures that lock an admin' => ['mfaLockoutFailures', 'mfa.lockout_failures', 10, 20],
            'the minutes of the lockout' => ['mfaLockoutMinutes', 'mfa.lockout_minutes', 15, 60],
            'the idle minutes of a session' => ['sessionIdleMinutes', 'session.idle_minutes', 60, 1440],
            'the open sessions of an admin' => ['sessionMaxConcurrent', 'session.max_concurrent', 5, 20],
        ];
    }

    #[DataProvider('numbers')]
    public function test_a_number_that_makes_sense_is_used_up_to_its_ceiling(string $method, string $key, int $default, int $ceiling): void
    {
        $settings = new PlatformAdminSettings();

        foreach ([1, 2, $ceiling - 1, $ceiling] as $configured) {
            config(["gcc.platform_admin.{$key}" => $configured]);
            $this->assertSame($configured, $settings->{$method}(), "{$key} = {$configured}");
        }
    }

    #[DataProvider('numbers')]
    public function test_a_number_above_its_ceiling_is_the_ceiling(string $method, string $key, int $default, int $ceiling): void
    {
        $settings = new PlatformAdminSettings();

        foreach ([$ceiling + 1, $ceiling * 100, PHP_INT_MAX] as $configured) {
            config(["gcc.platform_admin.{$key}" => $configured]);
            $this->assertSame($ceiling, $settings->{$method}(), "{$key} = {$configured}");
        }
    }

    #[DataProvider('numbers')]
    public function test_a_number_that_makes_no_sense_is_the_default(string $method, string $key, int $default, int $ceiling): void
    {
        $settings = new PlatformAdminSettings();

        foreach ([0, -1, -100, 'abc', '', null, false, []] as $configured) {
            config(["gcc.platform_admin.{$key}" => $configured]);
            $this->assertSame($default, $settings->{$method}(), "{$key} = " . json_encode($configured));
        }
    }

    #[DataProvider('numbers')]
    public function test_a_number_written_as_text_is_read_as_the_number(string $method, string $key, int $default, int $ceiling): void
    {
        config(["gcc.platform_admin.{$key}" => '2']);

        $this->assertSame(2, (new PlatformAdminSettings())->{$method}());
    }
}
