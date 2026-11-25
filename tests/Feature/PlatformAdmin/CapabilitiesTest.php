<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\PlatformAdminGrant;
use Carbon\Carbon;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The parts of the API a grant includes, enforced by the route: every route of the API against every set of capabilities, read from the route table,
 * so that a route added later has to be classified here before the suite is green.
 */
class CapabilitiesTest extends PlatformAdminTestCase
{
    /** The capability each route needs. Anything not listed here must be in NEVER_GATED, or the test that reads the route table fails. */
    private const CAPABILITY_OF = [
        'platform_admin.overview.view' => 'overview',
        'platform_admin.markets.view' => 'markets',
        'platform_admin.alerts.view' => 'alerts',
        // The devices alerts are sent to are part of the alerts: an operator who may not see them has no use for a phone that receives them.
        'platform_admin.devices.list' => 'alerts',
        'platform_admin.devices.register' => 'alerts',
        'platform_admin.device.forget' => 'alerts',
        'platform_admin.audit_logs.view' => 'audit',
        'platform_admin.health.view' => 'health',
        'platform_admin.reports.view' => 'reports',
        'platform_admin.exports.orders' => 'export',
        'platform_admin.sessions.list' => 'sessions',
        'platform_admin.sessions.end_others' => 'sessions',
        'platform_admin.session.revoke' => 'sessions',
    ];

    /** What every admin who holds a grant may always do: say who they are, sign out, and prove the second factor again; and the two steps of the sign-in, which come before there is a grant to read. */
    private const NEVER_GATED = ['platform_admin.me.view', 'platform_admin.auth.logout', 'platform_admin.auth.step_up', 'platform_admin.auth.login', 'platform_admin.auth.mfa'];

    /** The routes that carry a second guard on top of their capability: a second factor proved minutes ago (L-15). */
    private const STEP_UP_ROUTES = ['platform_admin.exports.orders'];

    public function test_every_route_of_the_api_is_either_gated_by_one_capability_or_never_gated(): void
    {
        $classified = array_merge(array_keys(self::CAPABILITY_OF), self::NEVER_GATED);

        foreach ($this->platformRoutes() as $route) {
            $this->assertContains($route->getName(), $classified, $route->uri() . ' must be classified: which capability does it need, or why none');
        }
        $this->assertSame([], array_values(array_diff($classified, array_map(fn ($route) => $route->getName(), $this->platformRoutes()))), 'a classified route that does not exist');
        $gated = array_values(array_unique(array_values(self::CAPABILITY_OF)));
        sort($gated);
        $known = PlatformAdminGrant::CAPABILITIES;
        sort($known);
        $this->assertSame($known, $gated, 'every capability gates something, and nothing gates a capability that does not exist');
    }

    public function test_every_route_declares_exactly_the_middleware_of_its_capability(): void
    {
        foreach ($this->platformRoutes() as $route) {
            $expected = self::CAPABILITY_OF[$route->getName()] ?? null;
            $declared = array_values(array_filter($route->gatherMiddleware(), static fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'platform_admin_can')));

            $this->assertSame($expected === null ? [] : ["platform_admin_can:{$expected}"], $declared, $route->uri());
        }
    }

    public function test_the_middleware_comes_after_the_authentication_and_before_the_controller(): void
    {
        foreach ($this->platformRoutes() as $route) {
            if (!isset(self::CAPABILITY_OF[$route->getName()])) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $expected = ['platform_admin', 'platform_admin_auth', 'platform_admin_can:' . self::CAPABILITY_OF[$route->getName()]];

            if (in_array($route->getName(), self::STEP_UP_ROUTES, true)) {
                $expected[] = 'platform_admin_step_up';
            }

            $this->assertSame($expected, $middleware, $route->uri());
        }
    }

    public function test_with_one_capability_only_the_routes_of_that_capability_answer_and_every_other_is_403_with_the_capability_it_needs(): void
    {
        $this->mintToken($this->platformAdmin());
        foreach (PlatformAdminGrant::CAPABILITIES as $granted) {
            $admin = $this->platformAdmin(grantOverrides: ['capabilities' => json_encode([$granted])]);
            $token = $this->mintToken($admin);

            foreach ($this->routesToWalk() as $route) {
                [$method, $uri] = $this->requestTo($route);
                $needs = self::CAPABILITY_OF[$route->getName()] ?? null;

                $response = $this->json($method, $uri, [], $this->bearer($token));

                if ($needs !== null && $needs !== $granted) {
                    $response->assertStatus(403)->assertExactJson(['errors' => [['code' => 'capability_not_allowed', 'message' => 'This account may not use that part of the API.', 'capability' => $needs]]]);
                } else {
                    $this->assertNotSame(403, $response->getStatusCode(), "{$granted}: {$method} {$uri}");
                    $this->assertNotSame(401, $response->getStatusCode(), "{$granted}: {$method} {$uri}");
                }
            }
        }
    }

    public function test_a_grant_that_names_every_capability_is_refused_nothing(): void
    {
        $this->mintToken($this->platformAdmin());
        $token = $this->mintToken($this->platformAdmin(grantOverrides: ['capabilities' => json_encode(PlatformAdminGrant::CAPABILITIES)]));

        foreach ($this->routesToWalk() as $route) {
            [$method, $uri] = $this->requestTo($route);

            $status = $this->json($method, $uri, [], $this->bearer($token))->getStatusCode();

            $this->assertNotContains($status, [401, 403], "{$method} {$uri}");
        }
    }

    /**
     * A grant that names NO capability has every one of them except those added after the first grant was made
     * (`PlatformAdminGrant::NOT_GIVEN_BY_DEFAULT`).
     *
     * This used to be "refused nothing", and it was true while the list was fixed. It stopped being the rule the moment the list grew: a
     * capability added to the code would otherwise have been given to every such grant retroactively, the moment it shipped. So the rule is
     * written out here, both ways round -- the capabilities a naming-none grant does get, and the ones it does not -- because a silent widening
     * of what an existing operator may read is exactly the kind of change that has to fail a test.
     */
    public function test_a_grant_that_names_no_capability_is_refused_exactly_the_ones_added_later(): void
    {
        $this->mintToken($this->platformAdmin());
        $token = $this->mintToken($this->platformAdmin());
        $refused = [];

        foreach ($this->routesToWalk() as $route) {
            [$method, $uri] = $this->requestTo($route);
            $needs = self::CAPABILITY_OF[$route->getName()] ?? null;

            $status = $this->json($method, $uri, [], $this->bearer($token))->getStatusCode();

            $this->assertNotSame(401, $status, "{$method} {$uri}");

            if ($status === 403) {
                $refused[] = $needs;
            }
        }

        $this->assertSame(
            PlatformAdminGrant::NOT_GIVEN_BY_DEFAULT,
            array_values(array_unique(array_filter($refused))),
            'a grant naming no capability is refused exactly the capabilities that are not given by default',
        );
        $this->assertNotSame([], PlatformAdminGrant::NOT_GIVEN_BY_DEFAULT, 'if that list is ever emptied, this test must be rewritten rather than left to pass trivially');
    }

    /** @return array<string, array{string}> */
    public static function grantsThatIncludeNothing(): array
    {
        return [
            'an empty list' => ['[]'],
            'the JSON text null' => ['null'],
            'an object' => ['{"overview":true}'],
            'names that are not capabilities' => ['["payments","orders"]'],
            'a name in another case' => ['["Overview"]'],
        ];
    }

    #[DataProvider('grantsThatIncludeNothing')]
    public function test_a_grant_whose_capabilities_include_nothing_that_exists_opens_only_what_is_never_gated(string $stored): void
    {
        $this->mintToken($this->platformAdmin());
        $admin = $this->platformAdmin(grantOverrides: ['capabilities' => $stored]);
        $token = $this->mintToken($admin);

        foreach ($this->routesToWalk() as $route) {
            [$method, $uri] = $this->requestTo($route);
            $needs = self::CAPABILITY_OF[$route->getName()] ?? null;

            $status = $this->json($method, $uri, [], $this->bearer($token))->getStatusCode();

            $this->assertSame($needs !== null, $status === 403, "{$method} {$uri} answered {$status}");
            $this->assertNotSame(401, $status);
        }
    }

    public function test_signing_out_is_never_gated(): void
    {
        $admin = $this->platformAdmin(grantOverrides: ['capabilities' => '[]']);
        $token = $this->mintToken($admin);

        $this->postJson(self::PREFIX . '/auth/logout', [], $this->bearer($token))->assertOk()->assertExactJson(['data' => ['revoked' => true]]);
    }

    public function test_the_capability_is_checked_before_anything_the_request_asked_for_is_read_or_judged(): void
    {
        DB::table('products')->insert(['id' => 1, 'name' => 'A product', 'added_by' => 'admin', 'status' => 1, 'request_status' => 1, 'current_stock' => 0, 'storefront_country' => 'SA']);
        $admin = $this->platformAdmin(grantOverrides: ['capabilities' => json_encode(['markets'])]);
        $token = $this->mintToken($admin);

        $statements = $this->statementsRunBy(function () use ($token, &$response): void {
            $response = $this->getJson(self::PREFIX . '/overview?market=XX&from=garbage', $this->bearer($token));
        });

        $response->assertStatus(403)->assertJsonPath('errors.0.capability', 'overview');
        $this->assertSame([], array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, '"orders"') || str_contains($sql, '`orders`') || str_contains($sql, 'products') || str_contains($sql, 'refund_requests'))), 'nothing of the store was read');
    }

    public function test_a_refused_part_is_audited_like_any_other_request_with_the_status_and_reads_nothing_it_was_not_asked(): void
    {
        $admin = $this->platformAdmin(grantOverrides: ['capabilities' => json_encode(['markets'])]);
        $token = $this->mintToken($admin);

        $this->getJson(self::PREFIX . '/alerts?severity=warning', $this->bearer($token))->assertStatus(403);

        $line = DB::table('platform_admin_audit_logs')->first();
        $this->assertSame(['alerts.view', $admin['id']], [$line->action, (int) $line->admin_id]);
        $this->assertSame($this->sortedByKey(['status' => 403, 'params' => ['severity' => 'warning']]), $this->contextOf($line));
    }

    public function test_the_403_is_private_and_is_not_an_authentication_failure(): void
    {
        $admin = $this->platformAdmin(grantOverrides: ['capabilities' => json_encode(['markets'])]);
        $token = $this->mintToken($admin);

        $response = $this->getJson(self::PREFIX . '/overview', $this->bearer($token));

        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertNotSame($this->uniformRefusal(), $response->json());
        $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertOk();
    }

    public function test_a_request_without_a_token_is_still_the_401_whatever_the_route_needs(): void
    {
        foreach (self::CAPABILITY_OF as $name => $capability) {
            $route = collect($this->platformRoutes())->first(fn ($candidate): bool => $candidate->getName() === $name);
            [$method, $uri] = $this->requestTo($route);

            $this->json($method, $uri)->assertStatus(401)->assertExactJson($this->uniformRefusal());
        }
    }

    public function test_me_says_what_the_grant_includes_in_the_order_of_the_capabilities_and_when_it_ends(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $everything = $this->platformAdmin();
        $some = $this->platformAdmin(grantOverrides: ['capabilities' => json_encode(['sessions', 'overview']), 'expires_at' => '2027-03-31 23:59:59']);
        $none = $this->platformAdmin(grantOverrides: ['capabilities' => '[]']);

        $answers = [];
        foreach (['everything' => $everything, 'some' => $some, 'none' => $none] as $name => $admin) {
            $answers[$name] = $this->getJson(self::PREFIX . '/me', $this->bearer($this->mintToken($admin)))->assertOk()->json('data');
        }

        $this->assertSame(['overview', 'markets', 'alerts', 'audit', 'sessions'], $answers['everything']['capabilities']);
        $this->assertNull($answers['everything']['grant_expires_at']);
        $this->assertSame(['overview', 'sessions'], $answers['some']['capabilities']);
        $this->assertSame(Carbon::parse('2027-03-31 23:59:59')->utc()->toIso8601ZuluString(), $answers['some']['grant_expires_at']);
        $this->assertSame([], $answers['none']['capabilities']);
        $this->assertSame(['admin_id', 'name', 'level', 'scope', 'markets', 'token_expires_at', 'capabilities', 'grant_expires_at', 'mfa', 'contract'], array_keys($answers['some']));
    }

    public function test_the_capabilities_are_read_on_every_request_so_narrowing_a_grant_takes_effect_at_once(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);
        $this->getJson(self::PREFIX . '/alerts', $this->bearer($token))->assertOk();

        DB::table('platform_admin_grants')->where('id', $admin['grant_id'])->update(['capabilities' => json_encode(['overview'])]);

        $this->getJson(self::PREFIX . '/alerts', $this->bearer($token))->assertStatus(403);
        $this->getJson(self::PREFIX . '/overview', $this->bearer($token))->assertOk();
    }

    public function test_a_capability_does_not_widen_the_markets_nor_the_markets_the_capabilities(): void
    {
        $viewer = $this->platformAdmin(countries: ['KW'], grantOverrides: ['capabilities' => json_encode(['overview'])]);
        $token = $this->mintToken($viewer);

        $this->getJson(self::PREFIX . '/overview?market=SA', $this->bearer($token))->assertStatus(403)->assertJsonPath('errors.0.code', 'market_not_allowed');
        $this->getJson(self::PREFIX . '/overview?market=KW', $this->bearer($token))->assertOk();
        $this->getJson(self::PREFIX . '/markets', $this->bearer($token))->assertStatus(403)->assertJsonPath('errors.0.code', 'capability_not_allowed');
    }

    /** @return list<Route> the routes a signed-in admin can walk in a row: the sign-in and the second step need no token, and signing out ends the walk */
    private function routesToWalk(): array
    {
        return array_values(array_filter($this->platformRoutes(), static fn ($route): bool => !in_array($route->getName(), ['platform_admin.auth.login', 'platform_admin.auth.mfa', 'platform_admin.auth.logout'], true)));
    }
}
