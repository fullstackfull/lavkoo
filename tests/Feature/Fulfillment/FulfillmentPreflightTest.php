<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderSyncState;
use App\Services\Fulfillment\Providers\TorodPoint\TorodPointAdapter;
use App\Services\Fulfillment\Readiness\FulfillmentPreflight;
use App\Services\Fulfillment\Readiness\PreflightCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The read-only readiness report: what it flags on the schema of a restored database, on safety, and on the
 * queue / scheduler wiring - and that it never changes anything.
 */
class FulfillmentPreflightTest extends FulfillmentTestCase
{
    private function report(bool $allowProduction = false, bool $allowLive = false): array
    {
        return app(FulfillmentPreflight::class)->run($allowProduction, $allowLive);
    }

    private function find(array $checks, string $name): PreflightCheck
    {
        foreach ($checks as $check) {
            if ($check->name === $name) {
                return $check;
            }
        }

        $this->fail("no check named '{$name}'");
    }

    public function test_a_complete_core_schema_passes_every_column_check(): void
    {
        $checks = $this->report();

        foreach (['orders', 'order_details', 'products', 'business_settings', 'currencies', 'admins', 'admin_roles', 'categories', 'id types'] as $name) {
            $this->assertSame(PreflightCheck::PASS, $this->find($checks, $name)->status, $name);
        }
    }

    public function test_a_missing_core_column_fails_and_names_it(): void
    {
        Schema::table('orders', fn ($table) => $table->dropColumn('order_note'));

        $check = $this->find($this->report(), 'orders');

        $this->assertSame(PreflightCheck::FAIL, $check->status);
        $this->assertStringContainsString('order_note', $check->detail);
    }

    public function test_a_missing_core_table_fails_with_the_instruction_to_restore_the_dump_first(): void
    {
        Schema::drop('order_details');

        $check = $this->find($this->report(), 'order_details');

        $this->assertSame(PreflightCheck::FAIL, $check->status);
        $this->assertStringContainsString('installation dump', $check->detail);
    }

    public function test_a_database_that_has_not_been_migrated_yet_is_not_an_error(): void
    {
        foreach ($this->ownTables() as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('products', function ($table): void {
            $table->dropIndex('products_fulfillment_provider_idx');
            $table->dropColumn('fulfillment_provider');
        });

        $checks = $this->report();

        $this->assertSame(PreflightCheck::PASS, $this->find($checks, 'fulfillment tables')->status);
        $this->assertStringContainsString('not migrated yet', $this->find($checks, 'fulfillment tables')->detail);
        $this->assertStringContainsString('not added yet', $this->find($checks, 'products.fulfillment_provider')->detail);
        $this->assertStringContainsString('nullable and indexed', $this->find($checks, 'products.fulfillment_provider')->detail, 'the preview says what the migration will add, because --pretend cannot show it');
    }

    public function test_a_server_without_the_platforms_audit_table_is_warned_that_operator_actions_are_not_recorded(): void
    {
        $check = $this->find($this->report(), 'audit_logs');

        $this->assertSame(PreflightCheck::WARN, $check->status, 'a warning, not a failure: the go-live override is also kept on the provider settings');
        $this->assertStringContainsString('NOT recorded', $check->detail);
    }

    public function test_a_server_with_the_audit_table_passes_that_check(): void
    {
        Schema::create('audit_logs', static function ($table): void {
            $table->id();
        });

        $this->assertSame(PreflightCheck::PASS, $this->find($this->report(), 'audit_logs')->status);
    }

    public function test_a_database_migrated_by_an_earlier_revision_of_the_feature_is_told_what_it_lacks(): void
    {
        $this->assertSame(PreflightCheck::PASS, $this->find($this->report(), 'fulfillment columns')->status);

        Schema::table('provider_fulfillments', fn ($table) => $table->dropColumn('cancel_blocked_reason'));

        $check = $this->find($this->report(), 'fulfillment columns');
        $this->assertSame(PreflightCheck::FAIL, $check->status);
        $this->assertStringContainsString('provider_fulfillments.cancel_blocked_reason', $check->detail);
        $this->assertStringContainsString('roll that one migration back', $check->detail);
    }

    public function test_a_database_with_none_of_the_feature_tables_is_reported_not_crashed_on(): void
    {
        foreach ([
            'product_provider_changes', 'provider_city_aliases', 'provider_cities', 'provider_price_changes', 'provider_catalog_items',
            'provider_product_mappings', 'provider_sync_states', 'provider_api_logs', 'provider_fulfillment_events',
            'provider_fulfillment_items', 'provider_fulfillments', 'integration_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        $checks = $this->report();

        $this->assertSame(PreflightCheck::PASS, $this->find($checks, 'torod_point dispatch')->status, 'nothing can be live before there is a settings table');
        $this->assertSame(PreflightCheck::PASS, $this->find($checks, 'fulfillment tables')->status);
        $this->assertSame([], array_filter($checks, static fn (PreflightCheck $check): bool => str_contains($check->name, 'credentials')));
    }

    public function test_the_client_address_check_spots_a_proxy_that_is_not_trusted(): void
    {
        $find = fn (Request $request): PreflightCheck => $this->find(app(FulfillmentPreflight::class)->runtime($request), 'client address');

        $direct = Request::create('/admin/fulfillment-providers', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']);
        $this->assertSame(PreflightCheck::PASS, $find($direct)->status);

        $behindUntrustedProxy = Request::create('/admin/fulfillment-providers', 'GET', server: ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.20']);
        $check = $find($behindUntrustedProxy);
        $this->assertSame(PreflightCheck::WARN, $check->status);
        $this->assertStringContainsString('no proxy is trusted', $check->detail);

        Request::setTrustedProxies(['10.0.0.5'], Request::HEADER_X_FORWARDED_FOR);
        try {
            $behindTrustedProxy = Request::create('/admin/fulfillment-providers', 'GET', server: ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.20']);
            $this->assertSame(PreflightCheck::PASS, $find($behindTrustedProxy)->status);
            $this->assertStringContainsString('198.51.100.20', $find($behindTrustedProxy)->detail);
        } finally {
            Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        }
    }

    public function test_the_proxies_named_in_the_environment_are_the_ones_the_wired_middleware_trusts(): void
    {
        $behindProxy = static fn (): Request => Request::create('/x', 'GET', server: ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.20']);
        $clientSeenBy = static function (Request $request): string {
            (new \Illuminate\Http\Middleware\TrustProxies())->handle($request, static fn (Request $passed) => response('ok'));

            return (string) $request->ip();
        };

        try {
            config(['trustedproxy.proxies' => null]);
            $this->assertSame('10.0.0.5', $clientSeenBy($behindProxy()), 'nothing is trusted by default');

            config(['trustedproxy.proxies' => '10.0.0.4, 10.0.0.5']);
            $this->assertSame('198.51.100.20', $clientSeenBy($behindProxy()));

            config(['trustedproxy.proxies' => '10.0.0.99']);
            $this->assertSame('10.0.0.5', $clientSeenBy($behindProxy()), 'a proxy that is not listed is not believed');
        } finally {
            Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        }
    }

    public function test_the_application_wires_the_framework_proxy_middleware_not_the_unused_class_in_app(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringContainsString('use Illuminate\Http\Middleware\TrustProxies;', $bootstrap);
        $this->assertStringNotContainsString('App\Http\Middleware\TrustProxies', $bootstrap);
        $this->assertStringContainsString("env('TRUSTED_PROXIES')", (string) file_get_contents(base_path('config/trustedproxy.php')));
    }

    public function test_the_command_line_run_says_the_client_address_is_checked_elsewhere(): void
    {
        $check = $this->find($this->report(), 'client address');

        $this->assertSame(PreflightCheck::PASS, $check->status);
        $this->assertStringContainsString('Integration Center', $check->detail);
    }

    public function test_a_shared_settings_table_of_a_different_shape_is_reported(): void
    {
        $this->assertSame(PreflightCheck::PASS, $this->find($this->report(), 'integration_settings')->status);

        Schema::table('integration_settings', fn ($table) => $table->dropColumn('credentials'));

        $check = $this->find($this->report(), 'integration_settings');
        $this->assertSame(PreflightCheck::FAIL, $check->status);
        $this->assertStringContainsString('credentials', $check->detail);
    }

    public function test_a_half_applied_migration_fails(): void
    {
        Schema::dropIfExists('provider_cities');
        Schema::dropIfExists('provider_city_aliases');

        $check = $this->find($this->report(), 'fulfillment tables');

        $this->assertSame(PreflightCheck::FAIL, $check->status);
        $this->assertStringContainsString('provider_cities', $check->detail);
    }

    public function test_production_refuses_unless_the_read_only_flag_is_given(): void
    {
        $this->app['env'] = 'production';

        $this->assertSame(PreflightCheck::FAIL, $this->find($this->report(), 'database')->status);
        $this->assertSame(PreflightCheck::PASS, $this->find($this->report(allowProduction: true), 'database')->status);
    }

    public function test_live_dispatch_fails_the_safety_check_unless_the_operator_allows_it(): void
    {
        config(['fulfillment.enabled' => true]);
        $this->enableProvider(ProviderCode::TorodPoint, ['dispatch_enabled' => true, 'dry_run' => false]);
        $this->enableProvider(ProviderCode::M5azn, ['dispatch_enabled' => true, 'dry_run' => true]);

        $checks = $this->report();

        $this->assertSame(PreflightCheck::FAIL, $this->find($checks, 'torod_point dispatch')->status);
        $this->assertSame(PreflightCheck::PASS, $this->find($checks, 'm5azn dispatch')->status, 'dry run is safe');
        $this->assertSame(PreflightCheck::PASS, $this->find($this->report(allowLive: true), 'torod_point dispatch')->status);
    }

    public function test_the_environment_kill_switch_makes_live_dispatch_irrelevant(): void
    {
        config(['fulfillment.enabled' => false]);
        $this->enableProvider(ProviderCode::TorodPoint, ['dispatch_enabled' => true, 'dry_run' => false]);

        $this->assertSame(PreflightCheck::PASS, $this->find($this->report(), 'torod_point dispatch')->status);
    }

    public function test_enabled_external_writes_fail_the_safety_check_unless_the_operator_allows_them(): void
    {
        config(['fulfillment.external_writes_enabled' => true]);
        $this->assertSame(PreflightCheck::FAIL, $this->find($this->report(), 'external writes')->status);
        $this->assertSame(PreflightCheck::WARN, $this->find($this->report(allowLive: true), 'external writes')->status);

        config(['fulfillment.enabled' => false]);
        $this->assertSame(PreflightCheck::FAIL, $this->find($this->report(), 'external writes')->status, 'a latent write permission is still a failure');

        config(['fulfillment.external_writes_enabled' => false]);
        $this->assertSame(PreflightCheck::PASS, $this->find($this->report(), 'external writes')->status);
    }

    public function test_the_kill_switch_check_names_the_mode(): void
    {
        config(['fulfillment.enabled' => false, 'fulfillment.external_writes_enabled' => false]);
        $off = $this->find($this->report(), 'kill switch');
        config(['fulfillment.enabled' => true, 'fulfillment.external_writes_enabled' => false]);
        $readOnly = $this->find($this->report(), 'kill switch');
        config(['fulfillment.enabled' => true, 'fulfillment.external_writes_enabled' => true]);
        $live = $this->find($this->report(), 'kill switch');

        $this->assertSame(PreflightCheck::PASS, $off->status);
        $this->assertSame(PreflightCheck::WARN, $readOnly->status);
        $this->assertStringContainsString('read-only', $readOnly->detail);
        $this->assertSame(PreflightCheck::WARN, $live->status);
        $this->assertStringContainsString('EXTERNAL_WRITES_ENABLED', $live->detail);
    }

    public function test_the_example_app_key_is_flagged_and_is_a_failure_in_production(): void
    {
        preg_match('/^APP_KEY=(.+)$/m', (string) file_get_contents(base_path('.env.example')), $matches);
        $this->assertNotEmpty($matches[1] ?? null, '.env.example carries an APP_KEY');
        config(['app.key' => trim($matches[1])]);

        $this->assertSame(PreflightCheck::WARN, $this->find($this->report(), 'app key')->status);

        $this->app['env'] = 'production';
        $this->assertSame(PreflightCheck::FAIL, $this->find($this->report(allowProduction: true), 'app key')->status);

        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        $this->assertSame(PreflightCheck::PASS, $this->find($this->report(allowProduction: true), 'app key')->status);
    }

    public function test_an_app_key_that_cannot_be_compared_is_not_reported_as_fine(): void
    {
        $preflight = $this->app->make(FulfillmentPreflight::class, ['exampleEnvironmentFile' => base_path('no-such-example.env')]);
        $this->app['env'] = 'production';

        $check = collect($preflight->safety(allowProduction: true))->first(static fn (PreflightCheck $check): bool => $check->name === 'app key');

        $this->assertSame(PreflightCheck::WARN, $check->status, 'a server without .env.example is the normal case: the check cannot say "differs"');
        $this->assertStringContainsString('could not be compared', $check->detail);
        $this->assertStringNotContainsString((string) config('app.key'), $check->detail . $check->name);
    }

    public function test_the_scheduler_heartbeat_distinguishes_never_fresh_late_and_stopped(): void
    {
        config(['fulfillment.enabled' => true]);
        $beat = fn () => $this->find($this->report(), 'scheduler heartbeat');

        $this->assertSame(PreflightCheck::WARN, $beat()->status, 'never ran');

        $state = ProviderSyncState::create(['provider' => 'system', 'capability' => 'sweep', 'last_attempt_at' => now()->subSeconds(40), 'last_success_at' => now()->subSeconds(40)]);
        $this->assertSame(PreflightCheck::PASS, $beat()->status);

        $state->forceFill(['last_attempt_at' => now()->subMinutes(8)])->save();
        $this->assertSame(PreflightCheck::WARN, $beat()->status);

        $state->forceFill(['last_attempt_at' => now()->subMinutes(40)])->save();
        $this->assertSame(PreflightCheck::FAIL, $beat()->status, 'the scheduler has stopped: orders are not being dispatched');
    }

    public function test_the_heartbeat_is_not_expected_while_the_kill_switch_is_off(): void
    {
        config(['fulfillment.enabled' => false]);

        $this->assertSame(PreflightCheck::PASS, $this->find($this->report(), 'scheduler heartbeat')->status);
    }

    public function test_the_queue_checks_warn_about_retry_after_the_sync_driver_and_a_per_process_cache(): void
    {
        config(['fulfillment.queue.connection' => 'database', 'queue.connections.database.retry_after' => 90, 'cache.default' => 'array']);
        $checks = $this->report();
        $this->assertSame(PreflightCheck::WARN, $this->find($checks, 'queue retry_after')->status);
        $this->assertSame(PreflightCheck::WARN, $this->find($checks, 'cache')->status);

        config(['queue.connections.database.retry_after' => 180, 'cache.default' => 'file']);
        $checks = $this->report();
        $this->assertSame(PreflightCheck::PASS, $this->find($checks, 'queue retry_after')->status);
        $this->assertSame(PreflightCheck::PASS, $this->find($checks, 'cache')->status);

        config(['fulfillment.queue.connection' => 'sync']);
        $this->assertSame(PreflightCheck::WARN, $this->find($this->report(), 'queue')->status);
    }

    public function test_webhooks_accepted_without_a_secret_is_a_failure(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['webhooks_enabled' => true]);

        $this->assertSame(PreflightCheck::FAIL, $this->find($this->report(), 'torod webhook secret')->status);

        $this->enableProvider(ProviderCode::TorodPoint, ['webhooks_enabled' => true], ['webhook_secret' => 'a-secret-value']);
        $this->assertSame(PreflightCheck::PASS, $this->find($this->report(), 'torod webhook secret')->status);
    }

    public function test_enabled_providers_without_credentials_are_reported(): void
    {
        config(['fulfillment.providers.torod_point.adapter' => TorodPointAdapter::class]);
        $this->enableProvider(ProviderCode::TorodPoint, ['company_id' => '714']);

        $check = $this->find($this->report(), 'torod_point credentials');

        $this->assertSame(PreflightCheck::WARN, $check->status);
        $this->assertStringContainsString('fulfillment_username', $check->detail);
    }

    public function test_the_command_prints_a_report_exits_non_zero_on_a_failure_and_changes_nothing(): void
    {
        $before = $this->countEverything();
        Schema::table('orders', fn ($table) => $table->dropColumn('order_note'));

        $this->artisan('fulfillment:preflight')->assertFailed();
        Artisan::call('fulfillment:preflight', ['--json' => true]);
        preg_match('/^(\[.*\])\s*$/ms', Artisan::output(), $matches);
        $json = json_decode($matches[1] ?? '[]', true);

        $this->assertNotEmpty($json);
        $this->assertContains('fail', array_column($json, 'status'));
        Schema::table('orders', fn ($table) => $table->text('order_note')->nullable());
        $this->assertSame($before, $this->countEverything(), 'the report wrote nothing');
    }

    public function test_the_command_succeeds_on_a_healthy_environment(): void
    {
        config(['fulfillment.queue.connection' => 'sync', 'fulfillment.external_writes_enabled' => false]);

        $this->artisan('fulfillment:preflight')->assertSuccessful();
    }

    /** @return list<string> */
    private function ownTables(): array
    {
        return ['provider_fulfillments', 'provider_fulfillment_items', 'provider_fulfillment_events', 'provider_api_logs', 'provider_sync_states', 'provider_product_mappings', 'provider_catalog_items', 'provider_price_changes', 'provider_cities', 'provider_city_aliases', 'product_provider_changes'];
    }

    /** @return array<string, int> */
    private function countEverything(): array
    {
        $counts = [];
        foreach ([...$this->ownTables(), 'orders', 'order_details', 'products', 'integration_settings'] as $table) {
            $counts[$table] = Schema::hasTable($table) ? DB::table($table)->count() : -1;
        }

        return $counts;
    }
}
