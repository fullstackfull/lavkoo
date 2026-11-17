<?php

namespace Tests\Feature\PlatformAdmin;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The code can be deployed, and the flag switched on, before the migrations of the second factor have run: until they have, every route says so
 * (503) instead of failing with a server error halfway through a sign-in.
 */
class MfaReadinessTest extends PlatformAdminTestCase
{
    private const UNAVAILABLE = ['errors' => [['code' => 'platform_admin_unavailable', 'message' => 'The platform admin API is not available yet.']]];

    public function test_with_every_migration_run_the_api_serves(): void
    {
        $token = $this->mintToken($this->platformAdmin());

        $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertOk();
    }

    /** @return array<string, array{string}> */
    public static function things(): array
    {
        return [
            'the table of the factors' => ['platform_admin_mfa_factors'],
            'the table of the challenges' => ['platform_admin_mfa_challenges'],
            'the column of the sessions' => ['platform_admin_tokens.mfa_method'],
        ];
    }

    #[DataProvider('things')]
    public function test_one_missing_table_or_column_of_the_second_factor_is_enough_for_503(string $missing): void
    {
        $token = $this->mintToken($this->platformAdmin());
        $this->dropIt($missing);

        foreach ($this->platformRoutes() as $route) {
            [$method, $uri] = $this->requestTo($route);

            $statements = $this->statementsRunBy(function () use ($method, $uri, $token, &$response): void {
                $response = $this->json($method, $uri, ['email' => 'owner@example.test', 'password' => 'x', 'device_name' => 'Test phone'], $this->bearer($token));
            });

            $response->assertStatus(503)->assertExactJson(self::UNAVAILABLE);
            $this->assertSame([], array_values(array_filter($statements, static fn (string $sql): bool => !str_starts_with(strtolower($sql), 'select'))), "{$method} {$uri} wrote something");
        }
    }

    public function test_a_missing_thing_of_the_second_factor_is_an_answer_of_the_gate_before_any_token_is_looked_up(): void
    {
        $this->dropIt('platform_admin_mfa_challenges');

        $statements = $this->statementsRunBy(fn () => $this->getJson(self::PREFIX . '/me', $this->bearer('lkpa_' . str_repeat('a', 64)))->assertStatus(503));

        $reads = array_filter($this->withoutSchemaChecks($statements), static fn (string $sql): bool => str_contains($sql, 'platform_admin_tokens'));
        $this->assertSame([], array_values($reads));
    }

    private function dropIt(string $thing): void
    {
        if (str_contains($thing, '.')) {
            [$table, $column] = explode('.', $thing);
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($column));

            return;
        }

        Schema::drop($thing);
    }
}
