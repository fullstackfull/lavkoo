<?php

namespace Tests\Feature\PlatformAdmin;

use Tests\TestCase;

/**
 * The API ships dormant. These pin the defaults an operator gets when nothing is set in the environment.
 */
class ConfigDefaultsTest extends TestCase
{
    public function test_the_api_is_switched_off_by_default(): void
    {
        $this->assertFalse(config('gcc.platform_admin.enabled'));
    }

    public function test_the_documented_defaults(): void
    {
        $this->assertSame(480, config('gcc.platform_admin.token_ttl_minutes'));
        $this->assertSame(5, config('gcc.platform_admin.login_attempts_per_minute'));
        $this->assertSame(120, config('gcc.platform_admin.requests_per_minute'));
        $this->assertSame(10, config('gcc.platform_admin.failed_login_audit_per_hour'));
        $this->assertSame(30, config('gcc.platform_admin.window_days_default'));
        $this->assertSame(92, config('gcc.platform_admin.window_days_max'));
        $this->assertSame(
            ['zero_stock_warning_count' => 10, 'zero_stock_critical_count' => 50, 'pending_age_hours' => 24, 'pending_warning_count' => 5, 'pending_critical_count' => 20],
            config('gcc.platform_admin.alerts'),
        );
    }

    public function test_the_environment_flag_is_read_as_a_boolean_and_the_example_file_ships_it_off(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^GCC_PLATFORM_ADMIN_ENABLED=false$/m', $example);
        $this->assertStringContainsString("filter_var(env('GCC_PLATFORM_ADMIN_ENABLED', false), FILTER_VALIDATE_BOOLEAN)", (string) file_get_contents(base_path('config/gcc.php')));
    }
}
