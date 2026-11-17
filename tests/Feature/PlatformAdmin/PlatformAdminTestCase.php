<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\Admin;
use Illuminate\Routing\Route as RoutingRoute;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildPlatformAdminWorld();
        config([
            'gcc.platform_admin.enabled' => true,
            // The tests of level 1 pin the one-step sign-in and what a session may do, which the second factor does not change: they run in the staging
            // mode of the switch. The second factor has its own tests, which turn it on (see MfaTestCase).
            'gcc.platform_admin.mfa.required' => false,
            // A matrix of requests from one test address must not trip the limiters that have tests of their own.
            'gcc.platform_admin.requests_per_minute' => 1_000_000,
            'gcc.platform_admin.login_attempts_per_minute' => 1_000_000,
        ]);
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
     * What an answer says, so that two answers can be compared. With debug on, an error answer also carries the file, the line and the trace
     * of where it was thrown, which differ from one request to the next; there the status and the message are compared, and otherwise the whole body.
     * The path the request was for is taken out, because a missing route says which one it was.
     */
    protected function saying(TestResponse $response, ?string $path = null): string
    {
        $content = $path === null ? $response->getContent() : str_replace(ltrim($path, '/'), '<path>', $response->getContent());

        if (config('app.debug') && $response->getStatusCode() >= 400) {
            $message = $path === null ? $response->json('message') : str_replace(ltrim($path, '/'), '<path>', (string) $response->json('message'));

            return $response->getStatusCode() . ' ' . ($message ?? $response->headers->get('Content-Type'));
        }

        return $response->getStatusCode() . ' ' . $content;
    }
}
