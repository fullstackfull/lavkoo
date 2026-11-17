<?php

namespace Tests\Feature\PlatformAdmin;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\Support\BuildsPlatformAdminWorld;
use Tests\Support\CapturesLogRecords;
use Tests\TestCase;

/**
 * The migrations of the second factor are additive: two new tables, hung off the grants table, and one nullable column on the sessions. They
 * succeed whatever `admins` turns out to be on the real database, because nothing of theirs refers to it.
 */
class MfaMigrationsTest extends TestCase
{
    use BuildsPlatformAdminWorld;
    use CapturesLogRecords;

    private const NEW_TABLES = ['platform_admin_mfa_factors', 'platform_admin_mfa_challenges'];

    /** The tables of the tamper-evident trail, which phase 2 adds as well: they hang off nothing, and have a test of their own (AuditChainMigrationsTest). */
    private const CHAIN_TABLES = ['platform_admin_audit_tail', 'platform_admin_audit_checkpoints'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStoreTables();
        $this->runPlatformAdminMigrations();
    }

    public function test_the_migrations_create_the_two_tables_and_the_column_and_the_rollback_removes_them_and_nothing_else(): void
    {
        foreach (self::NEW_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} must not exist before the migration");
        }
        $this->assertFalse(Schema::hasColumn('platform_admin_tokens', 'mfa_method'));

        $this->runPlatformAdminPhaseTwoMigrations();

        foreach (self::NEW_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} must exist after the migration");
        }
        $this->assertTrue(Schema::hasColumn('platform_admin_tokens', 'mfa_method'));

        $this->rollBackPlatformAdminPhaseTwoMigrations();

        foreach (self::NEW_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} must be gone after the rollback");
        }
        $this->assertFalse(Schema::hasColumn('platform_admin_tokens', 'mfa_method'));
        foreach (['platform_admin_grants', 'platform_admin_tokens', 'platform_admin_audit_logs'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} belongs to level 1 and stays");
        }
    }

    public function test_the_migrations_change_no_existing_table_except_by_the_column_of_the_sessions_the_two_of_the_grants_and_the_two_of_the_audit_lines(): void
    {
        $before = $this->columnsOfEveryTable();

        $this->runPlatformAdminPhaseTwoMigrations();
        $after = array_diff_key($this->columnsOfEveryTable(), array_flip([...self::NEW_TABLES, ...self::CHAIN_TABLES]));

        $expected = $before;
        $expected['platform_admin_tokens'][] = 'mfa_method';
        $expected['platform_admin_grants'] = array_merge($expected['platform_admin_grants'], ['capabilities', 'expires_at']);
        $expected['platform_admin_audit_logs'] = array_merge($expected['platform_admin_audit_logs'], ['prev_hash', 'hash']);
        foreach (['platform_admin_tokens', 'platform_admin_grants', 'platform_admin_audit_logs'] as $table) {
            $this->assertEqualsCanonicalizing($expected[$table], $after[$table], $table);
            unset($expected[$table], $after[$table]);
        }
        $this->assertSame($expected, $after);

        $this->rollBackPlatformAdminPhaseTwoMigrations();
        $this->assertSame($before, $this->columnsOfEveryTable());
    }

    public function test_the_factor_table_has_the_agreed_columns_and_one_factor_per_admin(): void
    {
        $this->runPlatformAdminPhaseTwoMigrations();

        $this->assertEqualsCanonicalizing(
            ['id', 'admin_id', 'grant_id', 'admin_fingerprint', 'secret', 'confirmed_at', 'last_used_step', 'recovery_codes', 'created_at', 'updated_at'],
            Schema::getColumnListing('platform_admin_mfa_factors'),
        );
        $this->assertContains('admin_id', $this->uniqueIndexes('platform_admin_mfa_factors'));
    }

    public function test_the_challenge_table_keeps_only_a_hash_of_the_challenge_and_makes_it_unique(): void
    {
        $this->runPlatformAdminPhaseTwoMigrations();

        $columns = Schema::getColumnListing('platform_admin_mfa_challenges');
        $this->assertEqualsCanonicalizing(
            ['id', 'token_hash', 'admin_id', 'grant_id', 'password_fingerprint', 'device_name', 'attempts', 'expires_at', 'consumed_at', 'created_at', 'updated_at'],
            $columns,
        );
        $this->assertNotContains('challenge', $columns, 'the challenge itself is never stored');
        $this->assertNotContains('token', $columns);
        $this->assertContains('token_hash', $this->uniqueIndexes('platform_admin_mfa_challenges'));
        $this->assertContains('admin_id', $this->indexedColumns('platform_admin_mfa_challenges'));
    }

    public function test_the_session_column_is_nullable_and_a_session_made_without_it_has_none(): void
    {
        $this->runPlatformAdminPhaseTwoMigrations();
        $this->insertAdmin(id: 7);
        $grantId = $this->insertGrant(adminId: 7);

        DB::table('platform_admin_tokens')->insert([
            'admin_id' => 7, 'grant_id' => $grantId, 'token_hash' => str_repeat('a', 64), 'password_fingerprint' => str_repeat('c', 64),
            'device_name' => 'Test phone', 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNull(DB::table('platform_admin_tokens')->value('mfa_method'));
    }

    public function test_the_new_tables_are_tied_to_the_grants_and_not_to_admins(): void
    {
        $this->runPlatformAdminPhaseTwoMigrations();

        foreach (self::NEW_TABLES as $table) {
            $this->assertSame(
                [['columns' => ['grant_id'], 'foreign_table' => 'platform_admin_grants', 'foreign_columns' => ['id'], 'on_delete' => 'cascade']],
                $this->foreignKeysOf($table),
                $table,
            );
        }
    }

    public function test_deleting_a_grant_removes_its_factor_and_its_challenges(): void
    {
        $this->runPlatformAdminPhaseTwoMigrations();
        $this->insertAdmin(id: 7);
        $this->insertAdmin(id: 8);
        $grantId = $this->insertGrant(adminId: 7);
        $otherGrantId = $this->insertGrant(adminId: 8);
        $this->insertFactor(adminId: 7, grantId: $grantId);
        $this->insertFactor(adminId: 8, grantId: $otherGrantId);
        $this->insertChallenge(adminId: 7, grantId: $grantId, hash: str_repeat('a', 64));
        $this->insertChallenge(adminId: 8, grantId: $otherGrantId, hash: str_repeat('b', 64));

        DB::table('platform_admin_grants')->where('id', $grantId)->delete();

        $this->assertSame([8], DB::table('platform_admin_mfa_factors')->pluck('admin_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([8], DB::table('platform_admin_mfa_challenges')->pluck('admin_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_deleting_an_admin_takes_its_factor_with_it_exactly_when_it_takes_the_grant(): void
    {
        $this->runPlatformAdminPhaseTwoMigrations();
        $this->insertAdmin(id: 7);
        $grantId = $this->insertGrant(adminId: 7);
        $this->insertFactor(adminId: 7, grantId: $grantId);

        DB::table('admins')->where('id', 7)->delete();

        $this->assertSame(0, DB::table('platform_admin_grants')->count());
        $this->assertSame(0, DB::table('platform_admin_mfa_factors')->count(), 'the secret of a deleted admin does not stay behind');
    }

    public function test_a_second_factor_for_the_same_admin_and_a_repeated_challenge_hash_are_refused_by_the_database(): void
    {
        $this->runPlatformAdminPhaseTwoMigrations();
        $this->insertAdmin(id: 7);
        $grantId = $this->insertGrant(adminId: 7);
        $this->insertFactor(adminId: 7, grantId: $grantId);
        $this->insertChallenge(adminId: 7, grantId: $grantId, hash: str_repeat('a', 64));

        try {
            $this->insertFactor(adminId: 7, grantId: $grantId);
            $this->fail('one admin must have one factor row');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        try {
            $this->insertChallenge(adminId: 7, grantId: $grantId, hash: str_repeat('a', 64));
            $this->fail('a challenge hash must be unique');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_a_challenge_starts_with_no_attempt_and_is_not_consumed(): void
    {
        $this->runPlatformAdminPhaseTwoMigrations();
        $this->insertAdmin(id: 7);
        $grantId = $this->insertGrant(adminId: 7);
        $this->insertChallenge(adminId: 7, grantId: $grantId, hash: str_repeat('a', 64));

        $row = DB::table('platform_admin_mfa_challenges')->first();
        $this->assertSame(0, (int) $row->attempts);
        $this->assertNull($row->consumed_at);
    }

    public function test_the_migrations_run_again_after_a_rollback(): void
    {
        $this->runPlatformAdminPhaseTwoMigrations();
        $keys = $this->foreignKeysOf('platform_admin_mfa_factors');

        $this->rollBackPlatformAdminPhaseTwoMigrations();
        $this->runPlatformAdminPhaseTwoMigrations();

        $this->assertSame($keys, $this->foreignKeysOf('platform_admin_mfa_factors'));
        $this->assertTrue(Schema::hasColumn('platform_admin_tokens', 'mfa_method'));
    }

    public function test_the_column_migration_is_safe_to_run_or_roll_back_twice_and_before_the_table_exists(): void
    {
        $this->runPlatformAdminPhaseTwoMigrations();
        $this->runPlatformAdminPhaseTwoMigrations(only: '2026_11_29_100200_add_mfa_method_to_platform_admin_tokens_table.php');
        $this->assertTrue(Schema::hasColumn('platform_admin_tokens', 'mfa_method'));

        $this->rollBackPlatformAdminPhaseTwoMigrations();
        $this->rollBackPlatformAdminPhaseTwoMigrations();
        $this->rollBackPlatformAdminMigrations();
        $this->rollBackPlatformAdminPhaseTwoMigrations();

        $this->assertFalse(Schema::hasTable('platform_admin_tokens'));
    }

    public function test_a_pretend_run_prints_the_statements_and_neither_changes_the_database_nor_warns(): void
    {
        $log = $this->captureLog();

        $statements = DB::pretend(fn () => $this->runPlatformAdminPhaseTwoMigrations());

        $this->assertNotEmpty(array_filter($statements, static fn (array $statement): bool => str_contains($statement['query'], 'platform_admin_mfa_factors')));
        $this->assertFalse(Schema::hasTable('platform_admin_mfa_factors'));
        $this->assertFalse(Schema::hasColumn('platform_admin_tokens', 'mfa_method'));
        $this->assertSame([], $this->warningsIn($log));
    }

    public function test_they_succeed_and_say_nothing_when_there_is_no_admins_table_at_all(): void
    {
        $this->rollBackPlatformAdminMigrations();
        Schema::drop('admins');
        $this->runPlatformAdminMigrations();
        $log = $this->captureLog();

        $this->runPlatformAdminPhaseTwoMigrations();

        foreach (self::NEW_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertSame([], $this->warningsIn($log), 'nothing of the second factor refers to admins, so it has nothing to warn about');
    }

    /** @return array<string, list<string>> */
    private function columnsOfEveryTable(): array
    {
        $columns = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            $listed = Schema::getColumnListing($table);
            if ($listed !== []) {
                $columns[$table] = $listed;
            }
        }
        ksort($columns);

        return $columns;
    }

    /** @return list<string> */
    private function uniqueIndexes(string $table): array
    {
        return collect(Schema::getIndexes($table))->filter(fn (array $index) => $index['unique'])->map(fn (array $index) => implode(',', $index['columns']))->values()->all();
    }

    /** @return list<string> */
    private function indexedColumns(string $table): array
    {
        return collect(Schema::getIndexes($table))->map(fn (array $index) => implode(',', $index['columns']))->values()->all();
    }

    /** @return list<array{columns: list<string>, foreign_table: string, foreign_columns: list<string>, on_delete: string}> */
    private function foreignKeysOf(string $table): array
    {
        return array_map(static fn (array $key): array => [
            'columns' => $key['columns'],
            'foreign_table' => $key['foreign_table'],
            'foreign_columns' => $key['foreign_columns'],
            'on_delete' => strtolower($key['on_delete']),
        ], Schema::getForeignKeys($table));
    }

    /** @return list<string> */
    private function warningsIn(TestHandler $log): array
    {
        return array_values(array_map(
            static fn (LogRecord $record): string => $record->message,
            array_filter($log->getRecords(), static fn (LogRecord $record): bool => $record->level === Level::Warning),
        ));
    }

    private function insertAdmin(int $id): void
    {
        DB::table('admins')->insert(['id' => $id, 'name' => "Admin {$id}", 'email' => "admin{$id}@example.test", 'password' => 'x']);
    }

    private function insertGrant(int $adminId): int
    {
        return (int) DB::table('platform_admin_grants')->insertGetId(['admin_id' => $adminId, 'admin_fingerprint' => $this->adminFingerprint(adminId: $adminId), 'level' => 1, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function insertFactor(int $adminId, int $grantId): void
    {
        DB::table('platform_admin_mfa_factors')->insert([
            'admin_id' => $adminId, 'grant_id' => $grantId, 'admin_fingerprint' => str_repeat('f', 64), 'secret' => 'encrypted', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertChallenge(int $adminId, int $grantId, string $hash): void
    {
        DB::table('platform_admin_mfa_challenges')->insert([
            'token_hash' => $hash, 'admin_id' => $adminId, 'grant_id' => $grantId, 'password_fingerprint' => str_repeat('c', 64), 'device_name' => 'Test phone',
            'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
