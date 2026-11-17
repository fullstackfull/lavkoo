<?php

namespace Tests\Feature\PlatformAdmin;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

/**
 * The API ships dormant: switched off it answers like a route that does not exist and touches nothing, and switched on before its
 * migrations it answers 503 instead of a server error.
 */
class FlagAndDormancyTest extends PlatformAdminTestCase
{
    private const NO_SUCH_ROUTE = '/api/platform-admin/v1/zz-no-such-route';

    public function test_with_the_flag_off_every_route_answers_exactly_like_a_route_that_does_not_exist_even_with_a_valid_token(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);
        config(['gcc.platform_admin.enabled' => false]);

        foreach ($this->platformRoutes() as $route) {
            [$method, $uri] = $this->requestTo($route);

            // A missing route asked for as a page, with debug on, is the framework's own debug page, which is not comparable.
            $asked = [$this->bearer($token), ['Accept' => 'application/json']];
            if (!config('app.debug')) {
                $asked[] = ['Accept' => 'text/html'];
            }

            foreach ($asked as $headers) {
                $dormant = $this->call($method, $uri, [], [], [], $this->serverVariables($headers));
                $absent = $this->call($method, self::NO_SUCH_ROUTE, [], [], [], $this->serverVariables($headers));

                $this->assertSame(404, $dormant->getStatusCode(), "{$method} {$uri}");
                $this->assertSame($this->saying($absent, self::NO_SUCH_ROUTE), $this->saying($dormant, $uri), "{$method} {$uri} must say what a missing route says");
                $this->assertSame($this->comparableHeaders($absent), $this->comparableHeaders($dormant), "{$method} {$uri} must have the headers of a missing route");
            }
        }
    }

    public function test_with_the_flag_off_no_request_reads_or_writes_the_database_at_all(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);
        $tokenRow = (array) DB::table('platform_admin_tokens')->first();
        config(['gcc.platform_admin.enabled' => false]);

        $statements = $this->statementsRunBy(function () use ($token): void {
            foreach ($this->platformRoutes() as $route) {
                [$method, $uri] = $this->requestTo($route);
                $this->json($method, $uri, ['email' => 'owner@example.test', 'password' => 'x', 'device_name' => 'Test phone'], $this->bearer($token));
            }
        });

        $this->assertSame([], $statements);
        $this->assertSame($tokenRow, (array) DB::table('platform_admin_tokens')->first(), 'the token was not even touched');
        $this->assertSame(0, DB::table('platform_admin_audit_logs')->count());
    }

    public function test_with_the_flag_off_the_other_services_are_not_called_either(): void
    {
        config(['gcc.platform_admin.enabled' => false]);
        $limiterKeysTouched = [];
        $this->app['events']->listen(CacheHit::class, function ($event) use (&$limiterKeysTouched): void {
            $limiterKeysTouched[] = $event->key;
        });
        $this->app['events']->listen(KeyWritten::class, function ($event) use (&$limiterKeysTouched): void {
            $limiterKeysTouched[] = $event->key;
        });

        foreach ($this->platformRoutes() as $route) {
            [$method, $uri] = $this->requestTo($route);
            $this->json($method, $uri);
        }

        $own = array_filter($limiterKeysTouched, static fn (string $key): bool => str_contains($key, 'platform-admin'));
        $this->assertSame([], array_values($own), 'no limiter, throttle or cache entry of the API is touched while it is off');
    }

    public function test_with_the_flag_on_and_the_migrations_not_run_every_route_answers_503_and_nothing_is_written(): void
    {
        $this->rollBackPlatformAdminMigrations();
        $before = $this->rowCountOfEveryTable();

        foreach ($this->platformRoutes() as $route) {
            [$method, $uri] = $this->requestTo($route);

            $statements = $this->statementsRunBy(function () use ($method, $uri, &$response): void {
                $response = $this->json($method, $uri, ['email' => 'owner@example.test', 'password' => 'x', 'device_name' => 'Test phone'], ['Authorization' => 'Bearer lkpa_' . str_repeat('a', 64)]);
            });

            $response->assertStatus(503)->assertExactJson(['errors' => [['code' => 'platform_admin_unavailable', 'message' => 'The platform admin API is not available yet.']]]);
            $this->assertSame([], array_values(array_filter($statements, static fn (string $sql): bool => !str_starts_with(strtolower($sql), 'select'))), "{$method} {$uri} wrote something");
            $this->assertNoStoreHeaders($response);
        }

        $this->assertSame($before, $this->rowCountOfEveryTable(), 'nothing of the admin or seller side was touched');
    }

    public function test_one_missing_table_is_enough_for_503(): void
    {
        foreach (['platform_admin_audit_logs', 'platform_admin_tokens'] as $table) {
            Schema::drop($table);
            $this->json('GET', self::PREFIX . '/me', [], $this->bearer('lkpa_' . str_repeat('a', 64)))->assertStatus(503);
        }
    }

    public function test_every_answer_of_the_api_is_private_and_never_stored(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);

        $this->json('GET', self::PREFIX . '/me', [], $this->bearer($token))->assertOk()->tap(fn ($r) => $this->assertNoStoreHeaders($r));
        $this->json('GET', self::PREFIX . '/me')->assertStatus(401)->tap(fn ($r) => $this->assertNoStoreHeaders($r));
        $this->json('POST', self::PREFIX . '/auth/login', [])->assertStatus(401)->tap(fn ($r) => $this->assertNoStoreHeaders($r));
        $this->json('DELETE', self::PREFIX . '/sessions/9999', [], $this->bearer($token))->assertStatus(404)->tap(fn ($r) => $this->assertNoStoreHeaders($r));
    }

    public function test_a_dormant_answer_carries_no_trace_of_the_api(): void
    {
        config(['gcc.platform_admin.enabled' => false]);

        $response = $this->json('GET', self::PREFIX . '/me');

        $response->assertStatus(404);
        $this->assertNotContains('authorization', array_map('strtolower', array_map('trim', explode(',', (string) $response->headers->get('Vary')))), 'a Vary of its own would tell it from a missing route');
        $this->assertStringNotContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_flag_is_read_strictly(): void
    {
        foreach (['1', 1, 'true', 'yes', 'on'] as $notABoolean) {
            config(['gcc.platform_admin.enabled' => $notABoolean]);

            $this->json('GET', self::PREFIX . '/me')->assertStatus(404);
        }
    }

    public function test_the_flag_gate_stands_alone_in_front_of_the_authentication_middleware(): void
    {
        Route::middleware('platform_admin_auth')->get('/zz-platform-probe', static fn () => response()->json(['reached' => true]));
        config(['gcc.platform_admin.enabled' => false]);

        $this->getJson('/zz-platform-probe', $this->bearer('lkpa_' . str_repeat('a', 64)))->assertStatus(404);
    }

    private function assertNoStoreHeaders(TestResponse $response): void
    {
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertContains('authorization', array_map('strtolower', array_map('trim', explode(',', (string) $response->headers->get('Vary')))));
    }

    /** @return array<string, string> */
    private function serverVariables(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }

    /** @return array<string, mixed> the headers that say what kind of answer it is, not the ones that differ on every request */
    private function comparableHeaders(TestResponse $response): array
    {
        return array_diff_key($response->headers->all(), array_flip(['date', 'x-correlation-id', 'x-request-id']));
    }

    /** @return array<string, int> the tables of the admin, seller and store side, whose rows the API must leave alone */
    private function rowCountOfEveryTable(): array
    {
        $counts = [];
        foreach (['admins', 'sellers', 'seller_staff', 'seller_api_keys', 'seller_roles', 'products', 'orders', 'order_details', 'business_settings', 'shops'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
