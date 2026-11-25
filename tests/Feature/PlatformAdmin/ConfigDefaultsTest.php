<?php

namespace Tests\Feature\PlatformAdmin;

use App\Services\PlatformAdmin\PlatformAlertService;
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
            [
                'zero_stock_warning_count' => 10,
                'zero_stock_critical_count' => 50,
                // Stock BELOW zero is not an ordinary state of a shop but a sale that should have been refused, so ONE product is already
                // information and this pair is far lower than the zero-stock one above it.
                'oversold_warning_count' => 5,
                'oversold_critical_count' => 20,
                'pending_age_hours' => 24,
                'pending_warning_count' => 5,
                'pending_critical_count' => 20,
                // One pair per health signal (L-09). The owner's ruling on any threshold changes a line of configuration, which is the
                // whole reason each signal has its own key rather than sharing one number.
                'health' => [
                    'payments_stuck_warning_count' => 5,
                    'payments_stuck_critical_count' => 20,
                    'payments_unreconciled_warning_count' => 5,
                    'payments_unreconciled_critical_count' => 20,
                    'channels_failing_warning_count' => 5,
                    'channels_failing_critical_count' => 20,
                    'carriers_failing_warning_count' => 5,
                    'carriers_failing_critical_count' => 20,
                    'books_need_a_person_warning_count' => 5,
                    'books_need_a_person_critical_count' => 20,
                    'shop_issues_open_warning_count' => 5,
                    'shop_issues_open_critical_count' => 20,
                    'approvals_pending_warning_count' => 5,
                    'approvals_pending_critical_count' => 20,
                ],
            ],
            config('gcc.platform_admin.alerts'),
        );
    }

    /**
     * Every health signal that raises an alert has its own pair of counts in the SHIPPED configuration.
     *
     * Here rather than in AlertsTest, which replaces the whole alerts block with thresholds of its own: this is a question about what the
     * platform ships, and asking it where a test has overridden the block would answer about the override.
     */
    public function test_every_health_signal_has_its_own_pair_of_shipped_counts(): void
    {
        $configured = array_keys((array) config('gcc.platform_admin.alerts.health'));

        foreach (app(PlatformAlertService::class)->healthSignalCodes() as $signal) {
            $this->assertContains($signal . '_warning_count', $configured, $signal . ' needs a warning count');
            $this->assertContains($signal . '_critical_count', $configured, $signal . ' needs a critical count');
        }
    }

    public function test_the_environment_flag_is_read_as_a_boolean_and_the_example_file_ships_it_off(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^GCC_PLATFORM_ADMIN_ENABLED=false$/m', $example);
        $this->assertStringContainsString("filter_var(env('GCC_PLATFORM_ADMIN_ENABLED', false), FILTER_VALIDATE_BOOLEAN)", (string) file_get_contents(base_path('config/gcc.php')));
    }
}
