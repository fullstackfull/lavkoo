<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Admin;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\ReadsRouteMiddleware;
use Tests\TestCase;

/**
 * Nothing under /admin may be reachable without being a signed-in admin.
 *
 * routes/admin/routes.php once carried a bare `admin` prefix group (Zender / Lynomia OTP gateway settings,
 * manual messages, cart marketing, app carousel) with no authentication whatsoever: anyone on the internet
 * could read and change the OTP gateway configuration, press "test" (which posts the stored API secret to
 * the configured base URL, i.e. wherever the attacker set it), send messages through the store's account,
 * and edit the app carousel. This pins the fix and fails if any admin route ever loses its guard again.
 */
class AdminRouteAuthenticationTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use ReadsRouteMiddleware;

    /** Public by design: the admin sign-in screens (guest-only, throttled). */
    private const PUBLIC_BY_DESIGN = [
        'App\Http\Controllers\Admin\Auth\LoginController',
    ];

    /**
     * The actions of that controller a stranger can guess a password at, and so the ones the throttle must cover.
     * `logout` is deliberately not here: it lives on the same controller, takes no credentials, and rate-limiting it
     * would mean rate-limiting signing OUT, which protects nobody.
     */
    private const SIGN_IN_ACTIONS = ['index', 'login'];

    private const FORMERLY_OPEN = [
        ['GET', '/admin/otp/settings'],
        ['PUT', '/admin/zender/settings'],
        ['POST', '/admin/zender/test'],
        ['POST', '/admin/zender/toggle'],
        ['GET', '/admin/integrations/manual-message'],
        ['POST', '/admin/integrations/manual-message/check'],
        ['POST', '/admin/integrations/manual-message/send'],
        ['GET', '/admin/integrations/cart-marketing'],
        ['POST', '/admin/integrations/cart-marketing/send'],
        ['GET', '/admin/app-carousel'],
        ['POST', '/admin/app-carousel/update'],
        ['POST', '/admin/app-carousel/delete-image'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCoreSchema();
        Schema::create('zender_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('base_url')->nullable();
            $table->text('secret')->nullable();
            $table->string('type')->default('sms');
            $table->text('message')->nullable();
            $table->integer('expire')->default(300);
            $table->integer('priority')->default(2);
            foreach (['account', 'mode', 'device', 'gateway'] as $column) {
                $table->string($column)->nullable();
            }
            $table->integer('sim')->nullable();
            $table->timestamps();
        });

        View::getFinder()->prependLocation(base_path('tests/Support/views'));
        // The shell's request middleware needs the installation database; the guard under test does not.
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class, ActivationCheckMiddleware::class,
        ]);
        Http::preventStrayRequests();
    }

    public function test_a_route_that_opts_out_of_the_guard_is_not_counted_as_guarded(): void
    {
        $optedOut = Route::get('admin/zz-scan-probe', static fn () => 'x')->middleware(['admin', 'actch:admin_panel'])->withoutMiddleware('admin');
        $guarded = Route::get('admin/zz-scan-probe-guarded', static fn () => 'x')->middleware(['admin', 'actch:admin_panel']);

        $this->assertNotContains('admin', $this->declaredMiddleware($optedOut), 'the scan must see the opt-out');
        $this->assertContains('actch:admin_panel', $this->declaredMiddleware($optedOut));
        $this->assertContains('admin', $this->declaredMiddleware($guarded));
    }

    public function test_no_admin_route_is_reachable_without_the_admin_guard(): void
    {
        $unguarded = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $controller = ltrim(explode('@', $route->getActionName())[0], '\\');
            $isAdmin = str_starts_with($route->uri(), 'admin/') || $route->uri() === 'admin' || str_contains($controller, '\\Admin\\');
            if (!$isAdmin || in_array($controller, self::PUBLIC_BY_DESIGN, true)) {
                continue;
            }
            if (!in_array('admin', $this->declaredMiddleware($route), true)) {
                $unguarded[] = implode('|', $route->methods()) . ' /' . $route->uri() . ' → ' . $route->getActionName();
            }
        }

        $this->assertSame([], $unguarded, "Admin routes without the `admin` middleware:\n" . implode("\n", $unguarded));
    }

    public function test_every_formerly_open_route_now_carries_the_panel_guard_and_a_module_permission(): void
    {
        foreach (self::FORMERLY_OPEN as [$method, $uri]) {
            $route = Route::getRoutes()->match(\Illuminate\Http\Request::create($uri, $method));
            $middleware = $this->declaredMiddleware($route);

            $this->assertContains('admin', $middleware, "{$method} {$uri}");
            $this->assertContains('actch:admin_panel', $middleware, "{$method} {$uri}");
            $this->assertNotEmpty(array_filter($middleware, static fn (string $name): bool => str_starts_with($name, 'module:')), "{$method} {$uri} needs a module permission");
        }
    }

    public function test_a_stranger_gets_nothing_from_any_formerly_open_route(): void
    {
        DB::table('zender_settings')->insert(['id' => 1, 'enabled' => true, 'base_url' => 'https://otp.lynomia.com', 'secret' => encrypt('super-secret'), 'type' => 'sms', 'message' => 'Your code {{otp}}', 'created_at' => now(), 'updated_at' => now()]);

        foreach (self::FORMERLY_OPEN as [$method, $uri]) {
            $response = $this->call($method, $uri, ['phone' => '0555555555', 'base_url' => 'https://evil.example.com', 'enabled' => 0, 'message' => 'x', 'type' => 'sms', 'expire' => 60]);

            $this->assertSame(404, $response->getStatusCode(), "{$method} {$uri} must not answer a stranger");
        }

        Http::assertNothingSent();
        $row = DB::table('zender_settings')->first();
        $this->assertSame('https://otp.lynomia.com', $row->base_url, 'a stranger cannot repoint the OTP gateway');
        $this->assertSame(1, (int) $row->enabled);
    }

    public function test_the_stored_gateway_secret_is_never_sent_to_a_stranger_chosen_url(): void
    {
        DB::table('zender_settings')->insert(['id' => 1, 'enabled' => true, 'base_url' => 'https://otp.lynomia.com', 'secret' => encrypt('super-secret'), 'type' => 'sms', 'message' => 'Your code {{otp}}', 'created_at' => now(), 'updated_at' => now()]);

        $this->put('/admin/zender/settings', ['base_url' => 'https://evil.example.com', 'type' => 'sms', 'message' => 'x', 'expire' => 60, 'priority' => 2]);
        $this->post('/admin/zender/test', ['phone' => '0555555555']);

        Http::assertNothingSent();
    }

    public function test_an_admin_without_the_module_is_turned_away_and_one_with_it_gets_in(): void
    {
        // Admin 2 holds only `catalog`; admin 3 holds `3rd_party_setup`; admin 1 is the master admin.
        $this->actingAs(Admin::findOrFail(2), 'admin');
        $this->get('/admin/otp/settings')->assertRedirect();
        $this->get('/admin/integrations/manual-message')->assertRedirect();
        $this->get('/admin/app-carousel')->assertRedirect();

        $this->actingAs(Admin::findOrFail(3), 'admin');
        $this->get('/admin/otp/settings')->assertOk()->assertSee('base_url', false);
        $this->get('/admin/app-carousel')->assertRedirect();
    }

    public function test_the_gateway_secret_is_not_rendered_back_to_the_signed_in_admin(): void
    {
        DB::table('zender_settings')->insert(['id' => 1, 'enabled' => true, 'base_url' => 'https://otp.lynomia.com', 'secret' => encrypt('super-secret-value'), 'type' => 'sms', 'message' => 'Your code {{otp}}', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs(Admin::findOrFail(1), 'admin');

        $html = $this->get('/admin/otp/settings')->assertOk()->getContent();

        $this->assertStringNotContainsString('super-secret-value', $html);
    }

    public function test_the_gateway_toggle_that_used_to_crash_now_works_for_an_authorised_admin(): void
    {
        DB::table('zender_settings')->insert(['id' => 1, 'enabled' => true, 'base_url' => 'https://otp.lynomia.com', 'secret' => encrypt('s'), 'type' => 'sms', 'message' => 'm', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs(Admin::findOrFail(1), 'admin');

        $this->post('/admin/zender/toggle', [])->assertRedirect();
        $this->assertSame(0, (int) DB::table('zender_settings')->value('enabled'), 'an unticked switch is not submitted, which means off');

        $this->post('/admin/zender/toggle', ['enabled' => 1])->assertRedirect();
        $this->assertSame(1, (int) DB::table('zender_settings')->value('enabled'));
    }

    // ---- the two guards that stand BESIDE the admin middleware, and were pinned by nothing (G-V2) ----

    public function test_every_admin_sign_in_route_carries_the_brute_force_throttle(): void
    {
        $unthrottled = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (!$this->takesCredentials($route)) {
                continue;
            }
            if (!in_array('throttle:auth', $this->declaredMiddleware($route), true)) {
                $unthrottled[] = implode('|', $route->methods()) . ' /' . $route->uri();
            }
        }

        $this->assertNotSame([], $this->signInRoutes(), 'the scan found no sign-in route at all, so it proves nothing');
        $this->assertSame([], $unthrottled, "Admin sign-in routes without throttle:auth:\n" . implode("\n", $unthrottled));
    }

    public function test_the_scan_sees_a_sign_in_route_that_loses_the_throttle(): void
    {
        $throttled = Route::post('zz-login-probe', [\App\Http\Controllers\Admin\Auth\LoginController::class, 'login'])->middleware(['throttle:auth']);
        $bare = Route::post('zz-login-probe-bare', [\App\Http\Controllers\Admin\Auth\LoginController::class, 'login']);

        $this->assertContains('throttle:auth', $this->declaredMiddleware($throttled));
        $this->assertNotContains('throttle:auth', $this->declaredMiddleware($bare), 'the scan must see a sign-in route with no throttle');
    }

    public function test_the_auth_throttle_is_counted_per_caller_and_is_not_unlimited(): void
    {
        $limiter = app(RateLimiter::class)->limiter('auth');

        $this->assertNotNull($limiter, 'the `auth` rate limiter is not registered, so throttle:auth throttles nothing');

        $limit = $limiter(Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']));

        $this->assertGreaterThan(0, $limit->maxAttempts, 'an unlimited limit is not a brute-force barrier');
        $this->assertSame('203.0.113.9', $limit->key, 'the limit must be counted per caller, not shared by everyone at once');
    }

    public function test_a_signed_in_admin_whose_account_is_switched_off_is_turned_out(): void
    {
        Route::get('admin/zz-status-probe', static fn (): string => 'in')->middleware('admin');
        DB::table('admins')->where('id', 2)->update(['status' => 0]);

        $this->actingAs(Admin::findOrFail(2), 'admin')->get('admin/zz-status-probe')->assertRedirect();

        $this->assertFalse(Auth::guard('admin')->check(), 'a switched-off admin must be logged out, not merely redirected');
    }

    public function test_the_first_admin_cannot_be_locked_out_by_the_status_check(): void
    {
        Route::get('admin/zz-status-probe', static fn (): string => 'in')->middleware('admin');
        DB::table('admins')->where('id', 1)->update(['status' => 0]);

        $this->actingAs(Admin::findOrFail(1), 'admin')->get('admin/zz-status-probe')->assertOk()->assertSee('in');
    }

    /** Whether a route is one a stranger can present a password to: an action of SIGN_IN_ACTIONS on a sign-in controller. */
    private function takesCredentials(\Illuminate\Routing\Route $route): bool
    {
        $parts = explode('@', $route->getActionName());

        return in_array(ltrim($parts[0], '\\'), self::PUBLIC_BY_DESIGN, true) && in_array($parts[1] ?? '', self::SIGN_IN_ACTIONS, true);
    }

    /** @return list<string> the sign-in routes the throttle scan looked at, so a scan that found none fails loudly */
    private function signInRoutes(): array
    {
        $found = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($this->takesCredentials($route)) {
                $found[] = implode('|', $route->methods()) . ' /' . $route->uri();
            }
        }

        return $found;
    }
}
