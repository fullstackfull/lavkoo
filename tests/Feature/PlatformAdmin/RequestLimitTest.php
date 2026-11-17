<?php

namespace Tests\Feature\PlatformAdmin;

use Illuminate\Support\Facades\DB;

/**
 * The reads a token costs are bounded: one address may make only so many requests a minute to any route, counted before a token is looked up.
 */
class RequestLimitTest extends PlatformAdminTestCase
{
    public function test_the_request_after_the_limit_is_throttled_before_any_token_is_looked_up(): void
    {
        config(['gcc.platform_admin.requests_per_minute' => 3]);
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);

        for ($request = 1; $request <= 3; $request++) {
            $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertOk();
        }
        $statements = $this->statementsRunBy(fn () => $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertStatus(429)->assertExactJson(['errors' => [['code' => 'platform_admin_throttled', 'message' => 'Too many attempts. Try again later.']]]));

        $this->assertSame([], $this->withoutSchemaChecks($statements), 'a throttled request reads and writes nothing');
        $this->assertSame(3, DB::table('platform_admin_audit_logs')->count());
    }

    public function test_the_limit_is_per_address_and_ends_after_a_minute(): void
    {
        config(['gcc.platform_admin.requests_per_minute' => 1]);
        $this->travelTo('2026-11-17 10:00:00');
        $token = $this->mintToken($this->platformAdmin());

        $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertOk();
        $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertStatus(429)->assertHeader('Retry-After');
        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])->getJson(self::PREFIX . '/me', $this->bearer($token))->assertOk();

        $this->travelTo('2026-11-17 10:01:01');
        $this->withServerVariables([])->getJson(self::PREFIX . '/me', $this->bearer($token))->assertOk();
    }

    public function test_a_throttled_answer_is_private_too(): void
    {
        config(['gcc.platform_admin.requests_per_minute' => 1]);
        $this->getJson(self::PREFIX . '/me');

        $response = $this->getJson(self::PREFIX . '/me');

        $response->assertStatus(429);
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }
}
