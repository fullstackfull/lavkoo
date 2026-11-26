<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\Admin;
use App\Models\PlatformAdminGrant;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsPlatformAdminWorld;
use Tests\Support\CapturesLogRecords;
use Tests\TestCase;

/**
 * The world every HTTP test of the platform admin API runs in: the store tables, the API's own tables built by their real migrations, the
 * flag switched on, and helpers that make admins, grants and tokens straight in the database, so that what a request is judged against does not
 * depend on the code that is under test.
 */
abstract class PlatformAdminTestCase extends TestCase
{
    use BuildsPlatformAdminWorld;
    use CapturesLogRecords;

    protected const PREFIX = '/api/platform-admin/v1';

    /**
     * The routes whose answer is of ONE market, so that a walk of the route table has to name a market to reach them at all: `all` is `422 one_market_only`.
     *
     * Declared here and not once per test file. Two files kept their own copy, and adding the three drill-downs of L-13 made one of them fail with a 422
     * where its subject was what a read WRITES -- a failure that says nothing about the thing the test is for. The next one-market route is declared once.
     */
    protected const ONE_MARKET_ROUTES = [
        'platform_admin.reports.view',
        'platform_admin.exports.orders',
        'platform_admin.orders.view',
        'platform_admin.returns.view',
        'platform_admin.products.view',
        'platform_admin.inventory.view',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildPlatformAdminWorld();

        config([
            'gcc.platform_admin.enabled' => true,
            // The tests of level 1 pin the one-step sign-in and what a session may do, which the second factor does not change: they run in the staging
            // mode of the switch. The second factor has its own tests, which turn it on (see MfaTestCase).
            'gcc.platform_admin.mfa.required' => false,
            // The tests of level 1 make their sessions with whatever times suit them (a session opened two hours ago and never used, say); the idle time is
            // at its ceiling for them, and the rule has its own tests, which set it back to the 60 minutes it ships with (SessionPolicyTest).
            'gcc.platform_admin.session.idle_minutes' => 1440,
            // A matrix of requests from one test address must not trip the limiters that have tests of their own.
            'gcc.platform_admin.requests_per_minute' => 1_000_000,
            'gcc.platform_admin.login_attempts_per_minute' => 1_000_000,
        ]);
    }

    /**
     * Empties every store a rate limit is counted in, so that a test which signs in several times over may read what a route ANSWERS instead of
     * what its throttle says.
     *
     * Cache::flush() alone used to do this and no longer does, deliberately: the security fix that moved the throttle counters into a store of
     * their own exists precisely so that emptying the application's cache cannot release a limit. The tests below sign in at the seller, customer
     * and delivery-man apis several times in one loop to ask what each answers an admin, and their own messages say what they are about -- "it is
     * not a throttle or a failure" -- so they reset the limit between attempts, and this is what resetting it now means. The limiter's store is
     * `array` under both phpunit configurations, so nothing outside the process is touched.
     */
    protected function releaseEveryRateLimit(): void
    {
        Cache::flush();
        Cache::store(config('cache.limiter'))->flush();
    }

    /**
     * An admin with a password made at run time.
     *
     * @param array<string, mixed> $overrides columns of the admins table
     *
     * @return array{id: int, email: string, password: string, name: string}
     */
    protected function makeAdmin(array $overrides = []): array
    {
        $password = Str::random(24);
        $row = array_merge([
            'name' => 'Admin ' . Str::random(5),
            'email' => Str::lower(Str::random(10)) . '@example.test',
            'password' => Hash::make($password),
            'status' => 1,
            'admin_role_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);

        $id = isset($row['id']) ? (int) $row['id'] : null;
        if ($id === null) {
            $id = (int) DB::table('admins')->insertGetId($row);
        } else {
            DB::table('admins')->insert($row);
        }

        return ['id' => $id, 'email' => $row['email'], 'password' => $password, 'name' => $row['name']];
    }

    /**
     * @param list<string>|null $countries null is every market
     * @param array<string, mixed> $overrides
     *
     * @return int the grant id
     */
    protected function grantTo(array|int $admin, ?array $countries = null, array $overrides = []): int
    {
        $adminId = is_array($admin) ? $admin['id'] : $admin;

        return (int) DB::table('platform_admin_grants')->insertGetId(array_merge([
            'admin_id' => $adminId,
            'admin_fingerprint' => $this->adminFingerprint(adminId: $adminId),
            'level' => 1,
            'countries' => $countries === null ? null : json_encode($countries),
            'granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * An admin whose grant NAMES every capability, for a test that walks every route.
     *
     * `platformAdmin()` names none, which is every capability except those added after the first grant was made
     * (`PlatformAdminGrant::NOT_GIVEN_BY_DEFAULT`), so a walk of every route with such a token meets a 403 on the newest parts. A test about
     * routes rather than about capabilities wants this one.
     *
     * @return array<string, mixed>
     */
    protected function platformAdminWithEveryCapability(?array $countries = null): array
    {
        return $this->platformAdmin($countries, grantOverrides: ['capabilities' => json_encode(PlatformAdminGrant::CAPABILITIES)]);
    }

    /** An admin with a grant: every market unless countries are given. */
    protected function platformAdmin(?array $countries = null, array $adminOverrides = [], array $grantOverrides = []): array
    {
        $admin = $this->makeAdmin($adminOverrides);
        $admin['grant_id'] = $this->grantTo($admin, $countries, $grantOverrides);

        return $admin;
    }

    /**
     * A token for the admin, written straight into the table the way the API itself would have written it. Returns the plain token.
     *
     * @param array<string, mixed> $overrides columns of platform_admin_tokens
     */
    protected function mintToken(array $admin, array $overrides = []): string
    {
        $plain = 'lkpa_' . Str::random(64);

        DB::table('platform_admin_tokens')->insert(array_merge([
            'admin_id' => $admin['id'],
            'grant_id' => $admin['grant_id'],
            'token_hash' => hash('sha256', $plain),
            'password_fingerprint' => hash('sha256', (string) DB::table('admins')->where('id', $admin['id'])->value('password')),
            'device_name' => 'Test phone',
            'expires_at' => now()->addHours(8),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $plain;
    }

    /** @return array<string, string> */
    protected function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
    }

    protected function adminModel(array $admin): Admin
    {
        return Admin::query()->findOrFail($admin['id']);
    }

    /** @return array<string, mixed> the body every refusal to authenticate has, whatever the cause */
    protected function uniformRefusal(): array
    {
        return ['errors' => [['code' => 'platform_admin_auth', 'message' => 'Authentication failed.']]];
    }

    /** @return list<RoutingRoute> every route of the API, whatever commit added it: the tests that walk them cover a route added later */
    protected function platformRoutes(): array
    {
        $routes = array_values(array_filter(Route::getRoutes()->getRoutes(), static fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/platform-admin/v1')));
        $this->assertNotEmpty($routes, 'no route of the platform admin API is registered, so a test that walks them would prove nothing');

        return $routes;
    }

    /** @return array{0: string, 1: string} the method and the address of a request to the route, its parameters filled with an id */
    protected function requestTo(RoutingRoute $route): array
    {
        return [$route->methods()[0], '/' . preg_replace('/\{[^}]+\}/', '1', $route->uri())];
    }

    /**
     * Asks every route of the platform API with the given headers, and says that each is refused with the one 401 and that the refusals wrote nothing.
     *
     * @param array<string, string> $headers
     * @param array<string, string> $query what is put in the query string of every request
     */
    protected function assertEveryRouteRefuses(array $headers, string $why, array $query = []): void
    {
        $before = $this->ownTables();

        foreach ($this->platformRoutes() as $route) {
            [$method, $uri] = $this->requestTo($route);

            $response = $this->json($method, $query === [] ? $uri : $uri . '?' . http_build_query($query), [], $headers);

            $this->assertSame(401, $response->getStatusCode(), "{$why}: {$method} {$uri} must be refused");
            $response->assertExactJson($this->uniformRefusal());
        }

        $this->assertSame($before, $this->ownTables(), "{$why}: a refused request wrote something");
    }

    /** The rows of the tables the API owns and of the admins, as text, so that two states can be compared. */
    protected function ownTables(): string
    {
        $rows = [];
        foreach (['platform_admin_grants', 'platform_admin_tokens', 'platform_admin_audit_logs', 'platform_admin_audit_tail', 'platform_admin_mfa_factors', 'platform_admin_mfa_challenges'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get();
        }
        $rows['admins'] = DB::table('admins')->orderBy('id')->get();

        return json_encode($rows);
    }

    /**
     * @param callable(): void $action
     *
     * @return list<string> the SQL of every statement the action ran
     */
    protected function statementsRunBy(callable $action): array
    {
        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });
        $action();

        return $statements;
    }

    /**
     * The statements that touch data: the ones that only ask the database what its schema looks like (the gate checks that the tables and columns of the
     * API exist before it does anything else) are not a read of anything the API keeps.
     *
     * @param list<string> $statements
     *
     * @return list<string>
     */
    protected function withoutSchemaChecks(array $statements): array
    {
        return array_values(array_filter($statements, static fn (string $sql): bool => !str_contains($sql, 'sqlite_master') && !str_contains($sql, 'information_schema') && !str_contains($sql, 'pragma_table_xinfo')));
    }

    /**
     * The audit line of a refusal is written after the answer, and only for an admin who has a grant, so what the trail reads and writes for it is left out
     * when the work done for every refusal is compared.
     *
     * @param list<string> $statements
     *
     * @return list<string>
     */
    protected function withoutAuditTrail(array $statements): array
    {
        return array_values(array_filter($statements, static fn (string $sql): bool => !str_contains($sql, 'platform_admin_audit_')));
    }

    /**
     * What an answer says, so that two answers can be compared. With debug on, an error answer also carries the file, the line and the trace
     * of where it was thrown, which differ from one request to the next; there the status and the message are compared, and otherwise the whole body.
     * The path the request was for is taken out, because a missing route says which one it was.
     */
    protected function saying(TestResponse $response, ?string $path = null): string
    {
        $content = $path === null ? $response->getContent() : str_replace(ltrim($path, '/'), '<path>', $response->getContent());

        if (config('app.debug') && $response->getStatusCode() >= 400) {
            $said = $response->json('message');
            $message = is_array($said) ? json_encode($said) : $said;

            if ($path !== null) {
                $message = str_replace(ltrim($path, '/'), '<path>', (string) $message);
            }

            return $response->getStatusCode() . ' ' . ($message ?? $response->headers->get('Content-Type'));
        }

        return $response->getStatusCode() . ' ' . $content;
    }

    /** Counts the password checks the application makes, and remembers the hash each was made against. */
    protected function countingHasher(): object
    {
        $hasher = new class($this->app->make(Hasher::class)) implements Hasher {
            public int $checks = 0;

            /** @var list<string> the hashes it was asked to check against */
            public array $hashes = [];

            public function __construct(private readonly Hasher $inner)
            {
            }

            public function info($hashedValue): array
            {
                return $this->inner->info($hashedValue);
            }

            public function make($value, array $options = []): string
            {
                return $this->inner->make($value, $options);
            }

            public function check($value, $hashedValue, array $options = []): bool
            {
                $this->checks++;
                $this->hashes[] = (string) $hashedValue;

                return $this->inner->check($value, $hashedValue, $options);
            }

            public function needsRehash($hashedValue, array $options = []): bool
            {
                return $this->inner->needsRehash($hashedValue, $options);
            }
        };
        $this->app->instance(Hasher::class, $hasher);

        return $hasher;
    }
}
