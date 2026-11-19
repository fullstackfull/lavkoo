<?php

namespace Tests\Feature\Security;

use App\Events\MaintenanceModeNotificationEvent;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Admin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\TestCase;

/**
 * A shop that was put in maintenance stays there until an admin opens it again.
 *
 * The settings screen stores the maintenance mode in the settings and puts a copy in the cache, and the check that every page
 * of the shop runs read the copy and nothing else. A cache that is emptied took the copy with it, so the shop opened again by
 * itself while its settings, and the screen the admin looks at, still said it was closed. A cache is emptied by a deployment,
 * by the "clear cache" of the panel, and by the request that changes the language, which needs no account.
 *
 * The maintenance mode is switched on and off here through the real settings action, so the tests follow whatever that action
 * writes. What a visitor meets is asked of two addresses that stand behind the real maintenance check.
 */
class MaintenanceModeOutlivesACacheFlushTest extends TestCase
{
    use BuildsFulfillmentSchema;

    private const MASTER = 1;

    private const THROUGH = 'the page behind the maintenance check';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCoreSchema();
        DB::table('business_settings')->insert([
            ['type' => 'business_mode', 'value' => 'multi'],
            ['type' => 'language', 'value' => json_encode([['id' => 1, 'name' => 'English', 'code' => 'en', 'direction' => 'ltr', 'status' => 1, 'default' => true]])],
        ]);
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class, ActivationCheckMiddleware::class,
        ]);
        Event::fake([MaintenanceModeNotificationEvent::class]);
        Http::preventStrayRequests();
        Route::middleware('maintenance_mode')->get('/vendor/behind-the-check', fn (): string => self::THROUGH);
        Route::middleware('maintenance_mode')->get('/behind-the-check', fn (): string => self::THROUGH);
    }

    public function test_a_shop_in_maintenance_is_still_in_maintenance_after_the_cache_was_emptied(): void
    {
        $this->theAdminSetsMaintenance(on: true);
        $this->assertClosed('/vendor/behind-the-check');
        $this->assertClosed('/behind-the-check');

        Cache::flush();

        $this->assertClosed('/vendor/behind-the-check');
        $this->assertClosed('/behind-the-check');
    }

    /** The request that changes the language needs no account and ends in emptying the whole cache. */
    public function test_a_visitor_who_changes_the_language_does_not_open_a_shop_that_is_in_maintenance(): void
    {
        $this->theAdminSetsMaintenance(on: true);
        $this->app['auth']->guard('admin')->logout();

        $this->post('/change-language', ['language_code' => 'en'])->assertOk();

        $this->assertClosed('/vendor/behind-the-check');
        $this->assertClosed('/behind-the-check');
    }

    public function test_a_shop_the_admin_opened_again_stays_open_after_the_cache_was_emptied(): void
    {
        $this->theAdminSetsMaintenance(on: true);
        $this->theAdminSetsMaintenance(on: false);
        $this->assertOpen('/vendor/behind-the-check');

        Cache::flush();

        $this->assertOpen('/vendor/behind-the-check');
        $this->assertOpen('/behind-the-check');
    }

    public function test_a_shop_that_was_never_in_maintenance_is_open_with_an_empty_cache(): void
    {
        Cache::flush();

        $this->assertOpen('/vendor/behind-the-check');
        $this->assertOpen('/behind-the-check');
    }

    public function test_a_maintenance_window_that_is_over_does_not_close_the_shop_again_after_the_cache_was_emptied(): void
    {
        $this->theAdminSetsMaintenance(on: true, form: ['maintenance_duration' => 'customize', 'start_date' => now()->subDays(3)->toDateTimeString(), 'end_date' => now()->subDay()->toDateTimeString()]);
        $this->assertOpen('/behind-the-check');

        Cache::flush();

        $this->assertOpen('/behind-the-check');
    }

    public function test_a_maintenance_window_that_is_running_is_kept_after_the_cache_was_emptied(): void
    {
        $this->theAdminSetsMaintenance(on: true, form: ['maintenance_duration' => 'customize', 'start_date' => now()->subDay()->toDateTimeString(), 'end_date' => now()->addDay()->toDateTimeString()]);

        Cache::flush();

        $this->assertClosed('/behind-the-check');
    }

    public function test_maintenance_of_the_vendor_panel_alone_leaves_the_website_open_before_and_after_the_cache_was_emptied(): void
    {
        $this->theAdminSetsMaintenance(on: true, form: ['user_website' => 0]);
        $this->assertClosed('/vendor/behind-the-check');
        $this->assertOpen('/behind-the-check');

        Cache::flush();

        $this->assertClosed('/vendor/behind-the-check');
        $this->assertOpen('/behind-the-check');
    }

    /** @param array<string, mixed> $form */
    private function theAdminSetsMaintenance(bool $on, array $form = []): void
    {
        $this->actingAs(Admin::findOrFail(self::MASTER), 'admin')
            ->post('/admin/business-settings/maintenance-mode', $form + ['maintenance_mode' => $on ? 1 : 0, 'user_website' => 1, 'vendor_panel' => 1, 'maintenance_duration' => 'until_change'], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertJsonPath('status', 'success');
        $this->app['auth']->guard('admin')->logout();
    }

    private function assertClosed(string $address): void
    {
        $response = $this->get($address);

        $this->assertTrue($response->isRedirect(), "{$address} answered {$response->getStatusCode()}: the shop is open although its settings say it is in maintenance");
        $this->assertStringContainsString('maintenance-mode', (string) $response->headers->get('Location'));
    }

    private function assertOpen(string $address): void
    {
        $this->get($address)->assertOk()->assertSee(self::THROUGH);
    }
}
