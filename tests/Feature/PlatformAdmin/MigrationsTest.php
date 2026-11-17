<?php

namespace Tests\Feature\PlatformAdmin;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsPlatformAdminWorld;
use Tests\TestCase;

/**
 * The three tables of the platform admin API are created by their real migration files and removed by their rollbacks, and they change
 * no table that exists today.
 */
class MigrationsTest extends TestCase
{
    use BuildsPlatformAdminWorld;

    private const OWN_TABLES = ['platform_admin_grants', 'platform_admin_tokens', 'platform_admin_audit_logs'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStoreTables();
    }

    public function test_the_migrations_create_the_three_tables_and_the_rollback_removes_them(): void
    {
        foreach (self::OWN_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} must not exist before the migration");
        }

        $this->runPlatformAdminMigrations();

        foreach (self::OWN_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} must exist after the migration");
        }

        $this->rollBackPlatformAdminMigrations();

        foreach (self::OWN_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} must be gone after the rollback");
        }
    }

    public function test_the_migrations_change_no_existing_table(): void
    {
        $before = $this->columnsOfEveryTable();

        $this->runPlatformAdminMigrations();
        $withOwnTablesRemoved = array_diff_key($this->columnsOfEveryTable(), array_flip(self::OWN_TABLES));

        $this->assertSame($before, $withOwnTablesRemoved);

        $this->rollBackPlatformAdminMigrations();
        $this->assertSame($before, $this->columnsOfEveryTable());
    }

    public function test_the_grants_table_has_the_agreed_columns_and_one_grant_per_admin(): void
    {
        $this->runPlatformAdminMigrations();

        $this->assertEqualsCanonicalizing(
            ['id', 'admin_id', 'level', 'countries', 'granted_by', 'granted_at', 'revoked_at', 'created_at', 'updated_at'],
            Schema::getColumnListing('platform_admin_grants'),
        );
        $this->assertContains('admin_id', $this->uniqueIndexes('platform_admin_grants'));
    }

    public function test_the_tokens_table_keeps_only_a_hash_and_makes_it_unique(): void
    {
        $this->runPlatformAdminMigrations();

        $columns = Schema::getColumnListing('platform_admin_tokens');
        $this->assertEqualsCanonicalizing(
            ['id', 'admin_id', 'grant_id', 'token_hash', 'password_fingerprint', 'device_name', 'ip', 'user_agent', 'last_used_at', 'expires_at', 'revoked_at', 'created_at', 'updated_at'],
            $columns,
        );
        $this->assertNotContains('token', $columns, 'the plain token is never stored');
        $this->assertContains('token_hash', $this->uniqueIndexes('platform_admin_tokens'));
        $this->assertContains('admin_id', $this->indexedColumns('platform_admin_tokens'));
    }

    public function test_the_audit_table_is_append_only_and_indexed_by_admin(): void
    {
        $this->runPlatformAdminMigrations();

        $this->assertEqualsCanonicalizing(
            ['id', 'admin_id', 'action', 'market', 'token_id', 'ip', 'user_agent', 'context', 'created_at'],
            Schema::getColumnListing('platform_admin_audit_logs'),
        );
        $this->assertContains('admin_id', $this->indexedColumns('platform_admin_audit_logs'));
    }

    public function test_a_second_grant_for_the_same_admin_and_a_repeated_token_hash_are_refused_by_the_database(): void
    {
        $this->runPlatformAdminMigrations();
        $this->insertAdmin(id: 7);
        $grantId = $this->insertGrant(adminId: 7);

        try {
            $this->insertGrant(adminId: 7);
            $this->fail('one admin must have one grant row');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->insertToken(adminId: 7, grantId: $grantId, hash: str_repeat('a', 64));
        try {
            $this->insertToken(adminId: 7, grantId: $grantId, hash: str_repeat('a', 64));
            $this->fail('a token hash must be unique');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_a_grant_needs_an_existing_admin_and_deleting_the_admin_removes_its_grant_and_tokens_but_not_its_audit_trail(): void
    {
        $this->runPlatformAdminMigrations();

        try {
            $this->insertGrant(adminId: 99);
            $this->fail('a grant for an admin that does not exist must be refused');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->insertAdmin(id: 7);
        $grantId = $this->insertGrant(adminId: 7);
        $this->insertToken(adminId: 7, grantId: $grantId, hash: str_repeat('b', 64));
        DB::table('platform_admin_audit_logs')->insert(['admin_id' => 7, 'action' => 'grant.created', 'created_at' => now()]);

        DB::table('admins')->where('id', 7)->delete();

        $this->assertSame(0, DB::table('platform_admin_grants')->count(), 'a deleted admin keeps no grant, so a reused id cannot inherit one');
        $this->assertSame(0, DB::table('platform_admin_tokens')->count());
        $this->assertSame(1, DB::table('platform_admin_audit_logs')->count(), 'the trail outlives the admin');
    }

    /** @return array<string, list<string>> */
    private function columnsOfEveryTable(): array
    {
        $columns = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            $columns[$table] = Schema::getColumnListing($table);
        }
        ksort($columns);

        return $columns;
    }

    /** @return list<string> the columns of every unique index, joined with a comma when an index spans several */
    private function uniqueIndexes(string $table): array
    {
        return collect(Schema::getIndexes($table))->filter(fn (array $index) => $index['unique'])->map(fn (array $index) => implode(',', $index['columns']))->values()->all();
    }

    /** @return list<string> */
    private function indexedColumns(string $table): array
    {
        return collect(Schema::getIndexes($table))->map(fn (array $index) => implode(',', $index['columns']))->values()->all();
    }

    private function insertAdmin(int $id): void
    {
        DB::table('admins')->insert(['id' => $id, 'name' => "Admin {$id}", 'email' => "admin{$id}@example.test", 'password' => 'x']);
    }

    private function insertGrant(int $adminId): int
    {
        return (int) DB::table('platform_admin_grants')->insertGetId(['admin_id' => $adminId, 'level' => 1, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function insertToken(int $adminId, int $grantId, string $hash): void
    {
        DB::table('platform_admin_tokens')->insert([
            'admin_id' => $adminId, 'grant_id' => $grantId, 'token_hash' => $hash, 'password_fingerprint' => str_repeat('c', 64),
            'device_name' => 'Test phone', 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
