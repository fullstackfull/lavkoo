<?php

namespace Tests\Feature\PlatformAdmin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Level 1 only watches. After any number of reads, every table of the database is as it was, except the time a session was last used and the
 * lines of the audit trail and its tail, which are the API's own. No statement that changes anything runs on any other table.
 */
class ReadOnlyProofTest extends PlatformAdminTestCase
{
    private const OWN_WRITES = ['platform_admin_tokens', 'platform_admin_audit_logs', 'platform_admin_audit_tail'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTheStore();
    }

    public function test_reads_change_only_the_last_use_of_a_session_and_the_audit_trail(): void
    {
        foreach ([$this->platformAdminWithEveryCapability(), $this->platformAdminWithEveryCapability(countries: ['KW'])] as $admin) {
            $token = $this->mintToken($admin);
            $before = $this->snapshot();
            $statements = $this->statementsRunBy(function () use ($token): void {
                foreach ($this->readRoutes() as $route) {
                    [$method, $uri] = $this->requestTo($route);

                    $this->json($method, $uri, [], $this->bearer($token))->assertOk();
                    $this->json($method, $uri . '?market=all&severity=warning&page=2&per_page=5&from=2026-10-01&to=2026-10-31', [], $this->bearer($token));
                    $this->json($method, $uri . '?market=KW', [], $this->bearer($token));
                }
            });

            $after = $this->snapshot();
            $changed = array_keys(array_filter($after, fn (string $rows, string $table): bool => $rows !== $before[$table], ARRAY_FILTER_USE_BOTH));
            sort($changed);

            $this->assertSame(self::OWN_WRITES, array_values(array_intersect(self::OWN_WRITES, $changed)), 'the API wrote its own rows');
            $this->assertSame([], array_values(array_diff($changed, self::OWN_WRITES)), 'a read changed a table that is not the API\'s own');
            $this->assertSame(['last_used_at'], $this->changedColumns('platform_admin_tokens', $before, $after), 'of a session only the time it was last used moves');
            $this->assertSame(['last_hash', 'last_line_id'], $this->changedColumns('platform_admin_audit_tail', $before, $after), 'of the tail only the line written last moves');
            $this->assertSame([], $this->writesOutsideTheApi($statements), 'no statement that changes anything ran on another table');
        }
    }

    public function test_a_read_that_is_refused_changes_nothing_at_all(): void
    {
        $before = $this->snapshot();

        foreach ($this->readRoutes() as $route) {
            [$method, $uri] = $this->requestTo($route);

            $this->json($method, $uri)->assertStatus(401);
            $this->json($method, $uri . '?market=KW', [], $this->bearer('lkpa_' . str_repeat('a', 64)))->assertStatus(401);
        }

        $this->assertSame($before, $this->snapshot());
    }

    public function test_only_the_signing_in_signing_out_and_ending_of_sessions_write_and_only_to_the_apis_own_tables(): void
    {
        $admin = $this->platformAdmin();
        $before = $this->snapshot();

        $statements = $this->statementsRunBy(function () use ($admin): void {
            $token = $this->json('POST', self::PREFIX . '/auth/login', ['email' => $admin['email'], 'password' => $admin['password'], 'device_name' => 'Pixel'])->json('data.token');
            $other = $this->mintToken($admin);
            $this->json('DELETE', self::PREFIX . '/sessions/' . DB::table('platform_admin_tokens')->where('token_hash', hash('sha256', $other))->value('id'), [], $this->bearer($token));
            $this->json('POST', self::PREFIX . '/auth/logout', [], $this->bearer($token));
        });

        $changed = array_keys(array_filter($this->snapshot(), fn (string $rows, string $table): bool => $rows !== $before[$table], ARRAY_FILTER_USE_BOTH));
        sort($changed);
        $this->assertSame(self::OWN_WRITES, array_values(array_intersect(self::OWN_WRITES, $changed)));
        $this->assertSame([], array_values(array_diff($changed, self::OWN_WRITES)));
        $this->assertSame([], $this->writesOutsideTheApi(array_filter($statements, static fn (string $sql): bool => !str_contains($sql, 'platform_admin_'))));
    }

    /** @return list<\Illuminate\Routing\Route> */
    private function readRoutes(): array
    {
        return array_values(array_filter($this->platformRoutes(), static fn ($route): bool => in_array('GET', $route->methods(), true)));
    }

    private function seedTheStore(): void
    {
        DB::table('sellers')->insert(['id' => 1, 'f_name' => 'Shop', 'email' => 'shop@example.test', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Saudi product', 'added_by' => 'admin', 'status' => 1, 'request_status' => 1, 'current_stock' => 0, 'storefront_country' => 'SA'],
            ['id' => 2, 'name' => 'Kuwaiti product', 'added_by' => 'admin', 'status' => 1, 'request_status' => 1, 'current_stock' => 3, 'storefront_country' => 'KW'],
        ]);
        DB::table('orders')->insert(['id' => 100, 'order_status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('order_details')->insert(['order_id' => 100, 'product_id' => 2, 'qty' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, string> every table that exists, as the text of its rows in a fixed order */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $rows = DB::table($table)->get()->map(fn ($row): string => json_encode($row))->all();
            sort($rows);
            $snapshot[$table] = implode("\n", $rows);
        }
        ksort($snapshot);

        return $snapshot;
    }

    /**
     * @param array<string, string> $before
     * @param array<string, string> $after
     *
     * @return list<string> the columns of the table whose value differs in at least one row
     */
    private function changedColumns(string $table, array $before, array $after): array
    {
        $changed = [];
        foreach (explode("\n", $after[$table]) as $index => $row) {
            $was = json_decode(explode("\n", $before[$table])[$index] ?? '{}', true);
            foreach (json_decode($row, true) as $column => $value) {
                if (($was[$column] ?? null) !== $value) {
                    $changed[$column] = $column;
                }
            }
        }
        sort($changed);

        return array_values($changed);
    }

    /**
     * @param list<string> $statements
     *
     * @return list<string> the statements that change something and do not name one of the API's own tables
     */
    private function writesOutsideTheApi(array $statements): array
    {
        return array_values(array_filter($statements, static fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|replace|alter|drop|create|truncate)\b/i', $sql) === 1 && !str_contains($sql, 'platform_admin_')));
    }
}
