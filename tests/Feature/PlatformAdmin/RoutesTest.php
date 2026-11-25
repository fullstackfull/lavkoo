<?php

namespace Tests\Feature\PlatformAdmin;

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\AuthenticatePlatformAdmin;
use App\Http\Middleware\EnsurePlatformAdminCapability;
use App\Http\Middleware\EnsureStorefrontMaySell;
use App\Http\Middleware\PlatformAdminGate;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordApiResponseShape;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Http\Middleware\ResolveStorefront;
use App\Http\Middleware\SellerApiAuthMiddleware;
use App\Http\Middleware\VaryByStorefront;
use App\Services\DeveloperPortal\ApiManifest;
use Closure;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

/**
 * How the routes of the API are wired. The wiring is what keeps it apart from every other API: no session, no cookie, no storefront, no
 * telemetry row, no response shape recorded for the developer portal, and never listed to a partner.
 */
class RoutesTest extends PlatformAdminTestCase
{
    /** The only routes of the API that change anything, and what they change: a session or a sign-in, never business data. */
    private const WRITING_ROUTES = [
        'platform_admin.auth.login' => 'POST',
        'platform_admin.auth.mfa' => 'POST',
        // A step-up stamps the moment the session proved the second factor again, on the session's own row, and changes nothing else (L-15).
        'platform_admin.auth.step_up' => 'POST',
        'platform_admin.auth.logout' => 'POST',
        'platform_admin.sessions.end_others' => 'DELETE',
        'platform_admin.session.revoke' => 'DELETE',
        // A device of this session, and never business data: the row says which phone the admin is alerted on (L-16).
        'platform_admin.devices.register' => 'POST',
        'platform_admin.device.forget' => 'DELETE',
    ];

    /** The two steps of the sign-in: the only routes that are not behind a token, because they are how one is got. */
    private const SIGN_IN_ROUTES = ['platform_admin.auth.login', 'platform_admin.auth.mfa'];

    /** The routes that ask for the second factor again, minutes before the request, on top of the token (L-15). */
    private const STEP_UP_ROUTES = ['platform_admin.exports.orders'];

    public function test_the_aliases_point_at_the_three_middleware(): void
    {
        $aliases = app(Router::class)->getMiddleware();

        $this->assertSame(PlatformAdminGate::class, $aliases['platform_admin'] ?? null);
        $this->assertSame(AuthenticatePlatformAdmin::class, $aliases['platform_admin_auth'] ?? null);
        $this->assertSame(EnsurePlatformAdminCapability::class, $aliases['platform_admin_can'] ?? null);
    }

    public function test_every_route_is_under_the_prefix_is_named_and_serialisable(): void
    {
        $names = [];
        foreach ($this->platformRoutes() as $route) {
            $this->assertMatchesRegularExpression('#^api/platform-admin/v1/[a-z\-/{}]+$#', $route->uri());
            $this->assertMatchesRegularExpression('/^platform_admin\.[a-z_]+\.[a-z_]+$/', (string) $route->getName(), $route->uri());
            $this->assertNotInstanceOf(Closure::class, $route->getAction('uses'), "{$route->uri()} must be a controller method so that route:cache can serialise it");
            $names[] = $route->getName();
        }

        $this->assertSame($names, array_values(array_unique($names)), 'route names are unique');
    }

    public function test_every_route_runs_the_gate_first_and_only_the_two_steps_of_the_sign_in_run_without_the_authentication(): void
    {
        foreach ($this->platformRoutes() as $route) {
            $expected = match (true) {
                in_array($route->getName(), self::SIGN_IN_ROUTES, true) => ['platform_admin'],
                // The export is the one route that also asks for a second factor proved minutes ago, because it takes a market's rows OFF
                // the platform. It is named here rather than allowed everywhere, so a route that acquires the step-up is noticed, and a
                // route that LOSES it is noticed too.
                in_array($route->getName(), self::STEP_UP_ROUTES, true) => ['platform_admin', 'platform_admin_auth', 'platform_admin_step_up'],
                default => ['platform_admin', 'platform_admin_auth'],
            };
            $withoutCapability = array_values(array_filter($route->gatherMiddleware(), static fn ($middleware): bool => !(is_string($middleware) && str_starts_with($middleware, 'platform_admin_can:'))));

            $this->assertSame($expected, $withoutCapability, $route->uri());
        }
    }

    public function test_no_route_runs_the_middleware_of_another_api_the_session_or_the_storefront(): void
    {
        $router = app(Router::class);
        $forbidden = [
            RecordHttpTelemetry::class, RecordAnalytics::class, RecordApiResponseShape::class,
            ResolveStorefront::class, VaryByStorefront::class, EnsureStorefrontMaySell::class,
            SellerApiAuthMiddleware::class, AdminMiddleware::class, StartSession::class,
        ];

        foreach ($this->platformRoutes() as $route) {
            $resolved = $router->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware());
            $this->assertSame([], array_values(array_intersect($forbidden, $resolved)), $route->uri() . ' runs ' . implode(', ', array_intersect($forbidden, $resolved)));
            $this->assertSame([], array_values(array_filter($resolved, static fn ($middleware): bool => is_string($middleware) && (str_contains($middleware, 'Authenticate:') || str_contains($middleware, 'VerifyCsrfToken') || str_contains($middleware, 'EncryptCookies')))));
            $this->assertSame([PlatformAdminGate::class], array_slice($resolved, 0, 1), 'the gate is the first thing a request meets');
        }
    }

    public function test_a_route_registered_for_the_api_group_would_be_caught(): void
    {
        $probe = Route::middleware('api')->get('/api/platform-admin/v1/zz-probe', static fn () => 'x')->name('platform_admin.zz.probe');
        $resolved = app(Router::class)->resolveMiddleware($probe->gatherMiddleware());

        $this->assertContains(RecordHttpTelemetry::class, $resolved, 'the probe proves the check above can see the api group');
    }

    public function test_only_the_sign_in_its_second_step_the_sign_out_and_the_ending_of_sessions_are_not_reads(): void
    {
        $writing = [];
        foreach ($this->platformRoutes() as $route) {
            $methods = array_values(array_diff($route->methods(), ['HEAD']));
            if ($methods !== ['GET']) {
                $writing[(string) $route->getName()] = implode('|', $methods);
            }
        }

        $this->assertSame(self::WRITING_ROUTES, $writing);
    }

    public function test_the_portal_lists_every_route_as_an_internal_admin_endpoint_with_a_bearer_token_and_never_to_a_partner(): void
    {
        $manifest = app(ApiManifest::class);
        $manifest->forget();
        $routes = $this->platformRoutes();
        $listed = array_values(array_filter($manifest->endpoints(), static fn (array $endpoint): bool => str_starts_with($endpoint['path'], '/api/platform-admin/v1')));

        $this->assertCount(count($routes), $listed, 'every route is in the manifest');
        foreach ($listed as $endpoint) {
            $this->assertSame('admin', $endpoint['audience'], $endpoint['path']);
            $this->assertSame('internal', $endpoint['visibility'], $endpoint['path']);
            $this->assertTrue($endpoint['documented'], $endpoint['path'] . ' carries an ApiDoc');
            $this->assertSame(!in_array($endpoint['name'], self::SIGN_IN_ROUTES, true), $endpoint['auth']['required'], $endpoint['path']);
            if ($endpoint['auth']['required']) {
                $this->assertSame('bearer', $endpoint['auth']['scheme'], $endpoint['path']);
            }
        }

        foreach (['partner', 'public'] as $audienceThatIsOutside) {
            $outside = array_filter($manifest->endpoints(['visibility' => $audienceThatIsOutside]), static fn (array $endpoint): bool => str_starts_with($endpoint['path'], '/api/platform-admin'));
            $this->assertSame([], array_values($outside), "no route of the API is visible to {$audienceThatIsOutside} callers");
        }
    }

    public function test_the_routes_of_the_other_apis_are_unchanged_by_these_routes(): void
    {
        $others = array_filter(Route::getRoutes()->getRoutes(), static fn (RoutingRoute $route): bool => !str_starts_with($route->uri(), 'api/platform-admin/v1'));
        $names = array_map(static fn (RoutingRoute $route): string => $route->uri(), $others);

        $this->assertContains('api/v1/storefronts', $names);
        $this->assertContains('api/v3/seller/auth/login', $names);
        $this->assertNotContains('api/platform-admin', $names);
    }
}
