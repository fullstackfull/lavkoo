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
use ReflectionClass;
use Tests\Support\PlatformWriteScanner;

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

    /** The contract the seller app's admin shell is built on. Section 5 of it is the list of endpoints, and it must be the route table's own list. */
    private const CONTRACT_DOCUMENT = 'docs/gcc/GCC-20-PLATFORM-ADMIN-API.md';

    /**
     * The routes that do NOT take a market from the request, and why each one does not (L-11).
     *
     * Every other route reads the data of ONE market, and the market it reads is a `market` parameter the request names -- which is exactly the
     * thing a route must not interpret for itself. `MarketSelector` validates it against the configuration (422 `invalid_market`) and against the
     * viewer's grant (403 `market_not_allowed`), and records the market the request was audited for; a route that read `$request->query('market')`
     * on its own would be a second answer to the same question, and the one that is wrong is the one nobody tested.
     *
     * So this list is a DECLARATION and not a skip list: a new route that reads a market and forgets the selector is in neither half of this test
     * and fails, and a route added here has to come with the sentence saying why it has no market to validate.
     */
    private const WITHOUT_A_MARKET_PARAMETER = [
        // The two steps of the sign-in, the step-up and the sign-out: no data of any market is read, and before the first of them there is no grant
        // to judge a market against at all.
        'platform_admin.auth.login' => 'the sign-in reads no market',
        'platform_admin.auth.mfa' => 'the second step reads no market',
        'platform_admin.auth.step_up' => 'proving the factor again reads no market',
        'platform_admin.auth.logout' => 'ending the session reads no market',
        // The grant itself, which is what the markets are judged AGAINST.
        'platform_admin.me.view' => 'answers the grant, including which markets it allows',
        // A session belongs to an admin and not to a country: there is no market to filter one by, which the card records as deliberate.
        'platform_admin.sessions.list' => 'a session is of an admin and of no market, by design',
        'platform_admin.sessions.end_others' => 'a session is of an admin and of no market, by design',
        'platform_admin.session.revoke' => 'a session is of an admin and of no market, by design',
        // ALL the markets the grant allows, every time: there is no parameter to validate because the answer is not of one market. It filters on
        // the principal (`PlatformAdminPrincipal::canSee`) rather than on a request value.
        'platform_admin.markets.view' => 'answers every market the grant allows, so there is nothing to select',
        // The trail is of the API and not of a market; narrowing it by one would hide the lines that matter most.
        'platform_admin.audit_logs.view' => 'the trail is of the API and not of a market, by design',
        // The SERVER names a device's topics from the grant and the role, so a device cannot ask for a market at all (L-16).
        'platform_admin.devices.list' => 'the server names the topics from the grant, never from a parameter',
        'platform_admin.devices.register' => 'the server names the topics from the grant, never from a parameter',
        'platform_admin.device.forget' => 'the server names the topics from the grant, never from a parameter',
    ];

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

    public function test_every_route_that_reads_market_data_goes_through_the_market_selector(): void
    {
        $without = [];

        foreach ($this->platformRoutes() as $route) {
            $name = (string) $route->getName();
            if (!str_contains($this->controllerCodeOf($route), 'MarketSelector')) {
                $without[$name] = self::WITHOUT_A_MARKET_PARAMETER[$name] ?? 'READS NO MARKET AND VALIDATES NONE';
            }
        }

        // Both halves, as the writing routes are: a route that reads a market and forgets the selector appears here and is not in the list, and a
        // route that stops using the selector cannot quietly leave the walk either.
        $this->assertSame(self::WITHOUT_A_MARKET_PARAMETER, $without);
    }

    public function test_a_route_that_takes_no_market_never_reads_one_out_of_the_request_itself(): void
    {
        foreach ($this->platformRoutes() as $route) {
            $name = (string) $route->getName();
            if (!array_key_exists($name, self::WITHOUT_A_MARKET_PARAMETER)) {
                continue;
            }

            $code = $this->controllerCodeOf($route);

            // The selector is the only thing allowed to turn a request value into a market. A route declared as taking none, that then reads one
            // itself, has neither the validation nor the refusal and is the shape this guard exists to catch.
            foreach (["'market'", '"market"', 'MarketSelection'] as $reach) {
                $this->assertStringNotContainsString(
                    $reach,
                    $code,
                    $name . ' is declared to take no market, so it must not read one out of the request: ' . self::WITHOUT_A_MARKET_PARAMETER[$name],
                );
            }
        }
    }

    /** The source of a route's controller, comments stripped: what it says it refuses is not what it does. */
    private function controllerCodeOf(RoutingRoute $route): string
    {
        $action = (string) $route->getActionName();
        $class = explode('@', $action)[0];
        $this->assertTrue(class_exists($class), $action . ' is not a controller class this test can read');

        $file = (string) (new ReflectionClass($class))->getFileName();

        return PlatformWriteScanner::codeOf((string) file_get_contents($file));
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

    /**
     * The contract document describes EVERY route of the API, read from the route table rather than from a list somebody keeps.
     *
     * This is the guard the document lacked, and the lack is what the document was missing: `GET /health`, `GET /reports`, `GET /exports/orders`, the three
     * `devices` routes and `POST /auth/step-up` were built, tested and shipped, and section 5 went on describing the ten routes that existed when it was
     * written -- for four deliveries, with a note in the middle of it naming three of its own gaps. Nothing read the document against the code, so nothing
     * said so. A route added tomorrow fails this test the day it is added, which is the only way a contract the seller app is built on stays one.
     *
     * The match is the heading's own backticked `METHOD /path`, with anything from the first `?` ignored, so a heading may carry its query string (and
     * several routes may share one heading, as the sessions and the devices do) without the document having to spell a route twice.
     */
    public function test_the_contract_document_describes_every_route_of_the_api(): void
    {
        $document = (string) file_get_contents(base_path(self::CONTRACT_DOCUMENT));
        $section = $this->sectionFive(document: $document);
        $described = [];

        preg_match_all('/^### (.+)$/m', $section, $headings);
        foreach ($headings[1] as $heading) {
            preg_match_all('/`([A-Z]+) (\/[^`?]*)[^`]*`/', $heading, $routes, PREG_SET_ORDER);
            foreach ($routes as $route) {
                $described[] = $route[1] . ' ' . rtrim($route[2], '/');
            }
        }

        $registered = [];
        foreach ($this->platformRoutes() as $route) {
            $registered[] = array_values(array_diff($route->methods(), ['HEAD']))[0] . ' /' . substr($route->uri(), strlen('api/platform-admin/v1/'));
        }

        sort($registered);
        $described = array_values(array_unique($described));
        sort($described);

        $this->assertSame([], array_values(array_diff($registered, $described)), 'every route of the API is described in section 5 of ' . self::CONTRACT_DOCUMENT);
        $this->assertSame([], array_values(array_diff($described, $registered)), 'section 5 of ' . self::CONTRACT_DOCUMENT . ' describes a route that does not exist');
    }

    /** Section 5 of the contract, from its own heading up to the next one, so a `### ` heading of another section cannot be read as an endpoint. */
    private function sectionFive(string $document): string
    {
        $start = strpos($document, "\n## 5. Endpoints\n");
        $this->assertNotFalse($start, self::CONTRACT_DOCUMENT . ' has no section 5');
        $end = strpos($document, "\n## 6. ", $start);
        $this->assertNotFalse($end, self::CONTRACT_DOCUMENT . ' section 5 does not end');

        return substr($document, $start, $end - $start);
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
