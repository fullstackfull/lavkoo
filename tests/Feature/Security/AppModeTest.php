<?php

namespace Tests\Feature\Security;

use App\Services\Web\CustomerAuthService;
use Tests\TestCase;

/**
 * The verification and reset codes are random only when the application is in live mode (`config('app.mode') == 'live'`), and the mode is the
 * APP_MODE line of the environment file. A configuration that never defined `app.mode` kept every code at 123456 (6 digits) or 1234 (4 digits)
 * whatever APP_MODE said, so a reset or a sign-in by code needed nothing but the phone number it was asked for.
 */
class AppModeTest extends TestCase
{
    public function test_the_app_mode_is_defined_and_follows_the_environment_file(): void
    {
        $this->assertNotNull(config('app.mode'), 'config/app.php defines no app.mode, so no code is ever random');
        $this->assertSame(env('APP_MODE', 'live'), config('app.mode'));
    }

    public function test_the_codes_of_a_server_that_runs_in_live_mode_are_not_a_constant(): void
    {
        config(['app.mode' => 'live']);
        $codes = array_map(static fn (): string => (new CustomerAuthService())->getCustomerVerificationToken(), range(1, 20));

        $this->assertGreaterThan(1, count(array_unique($codes)), 'twenty codes in live mode were the same');
        $this->assertNotSame(['123456'], array_values(array_unique($codes)));
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        }
    }

    public function test_any_other_mode_keeps_the_constant_code_for_tests_and_demos(): void
    {
        config(['app.mode' => 'demo']);

        $this->assertSame('123456', (new CustomerAuthService())->getCustomerVerificationToken());
    }
}
