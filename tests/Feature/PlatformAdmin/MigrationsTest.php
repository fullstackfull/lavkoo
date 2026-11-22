<?php

namespace Tests\Feature\PlatformAdmin;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsPlatformAdminWorld;
use Tests\Support\CapturesLogRecords;
use Tests\TestCase;

/**
 * The three tables of the platform admin API are created by their real migration files and removed by their rollbacks, and they change
 * no table that exists today. Whatever `admins` turns out to be on the real database, the migrations succeed: the grants table is tied to it by
 * a foreign key when the database can take one, and is left without one, with a warning, when it cannot.
 */
class MigrationsTest extends TestCase
{
    use BuildsPlatformAdminWorld;
    use CapturesLogRecords;

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
            ['id', 'admin_id', 'admin_fingerprint', 'level', 'countries', 'granted_by', 'granted_at', 'revoked_at', 'created_at', 'updated_at'],
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

    public function test_with_a_bigint_unsigned_admins_id_the_grants_table_is_tied_to_admins_and_nothing_is_warned(): void
    {
        $log = $this->captureLog();

        $this->runPlatformAdminMigrations();

        $this->assertSame([['columns' => ['admin_id'], 'foreign_table' => 'admins', 'foreign_columns' => ['id'], 'on_delete' => 'cascade']], $this->foreignKeysOf('platform_admin_grants'));
        $this->assertSame([['columns' => ['grant_id'], 'foreign_table' => 'platform_admin_grants', 'foreign_columns' => ['id'], 'on_delete' => 'cascade']], $this->foreignKeysOf('platform_admin_tokens'));
        $this->assertSame([], $this->warningsIn($log), 'a database that can take the key has nothing to be warned about');
    }

    public function test_the_key_is_the_one_the_database_itself_reports(): void
    {
        $this->requireMySqlOrMariaDb();

        $this->runPlatformAdminMigrations();

        $rows = DB::select("select constraint_name as name, referenced_table_name as referenced, delete_rule as on_delete from information_schema.referential_constraints where constraint_schema = database() and table_name = 'platform_admin_grants'");
        $this->assertSame([['platform_admin_grants_admin_fk', 'admins', 'CASCADE']], array_map(static fn (object $row): array => [$row->name, $row->referenced, $row->on_delete], $rows));
    }

    /** @return array<string, array{0: string, 1: string}> how admins looks, and what the warning says about it */
    public static function adminsThatCannotBeReferenced(): array
    {
        return [
            'there is no admins table' => ['missing', 'there is no admins table'],
            'admins.id is INT UNSIGNED' => ['int unsigned', 'not BIGINT UNSIGNED'],
            'admins.id is a signed BIGINT' => ['signed bigint', 'not BIGINT UNSIGNED'],
            'admins is a MyISAM table' => ['myisam', 'only InnoDB'],
            'admins is partitioned, which the database refuses to reference' => ['partitioned', 'refused'],
        ];
    }

    #[DataProvider('adminsThatCannotBeReferenced')]
    public function test_when_admins_cannot_be_referenced_the_migrations_still_succeed_without_the_key_and_warn_once(string $shape, string $reason): void
    {
        $this->rebuildAdminsAs($shape);
        $log = $this->captureLog();

        $this->runPlatformAdminMigrations();

        $this->assertSame([], $this->foreignKeysOf('platform_admin_grants'), 'no key to admins');
        $this->assertCount(1, $this->foreignKeysOf('platform_admin_tokens'), 'the tokens are still tied to the grants');
        $warnings = $this->warningsIn($log);
        $this->assertCount(1, $warnings, 'one warning, not one per attempt');
        $this->assertStringContainsString('platform_admin_grants', $warnings[0]);
        $this->assertStringContainsString($reason, $warnings[0]);
        $this->assertStringContainsString('gcc:platform-admin:revoke', $warnings[0], 'it tells the operator what to do before deleting an admin');
        $grantId = $this->insertGrant(adminId: 7, fingerprint: str_repeat('f', 64));
        $this->insertToken(adminId: 7, grantId: $grantId, hash: str_repeat('d', 64));
        $this->assertSame(1, DB::table('platform_admin_grants')->count(), 'the table works');
    }

    #[DataProvider('adminsThatCannotBeReferenced')]
    public function test_the_migrations_run_again_after_a_rollback_when_admins_cannot_be_referenced(string $shape, string $reason): void
    {
        $this->rebuildAdminsAs($shape);
        $log = $this->captureLog();

        $this->runPlatformAdminMigrations();
        $this->rollBackPlatformAdminMigrations();
        $this->assertFalse(Schema::hasTable('platform_admin_grants'));
        $this->runPlatformAdminMigrations();

        $this->assertTrue(Schema::hasTable('platform_admin_grants'));
        $this->assertSame([], $this->foreignKeysOf('platform_admin_grants'));
        $this->assertCount(2, $this->warningsIn($log), 'each run of the migration says it once');
    }

    public function test_a_pretend_run_prints_the_statements_and_neither_changes_the_database_nor_warns(): void
    {
        $log = $this->captureLog();

        $statements = DB::pretend(fn () => $this->runPlatformAdminMigrations());

        $this->assertNotEmpty(array_filter($statements, static fn (array $statement): bool => str_contains($statement['query'], 'platform_admin_grants')));
        $this->assertFalse(Schema::hasTable('platform_admin_grants'));
        $this->assertSame([], $this->warningsIn($log), 'nothing was created, so there is nothing to say about it');
    }

    public function test_the_migrations_run_again_after_a_rollback_and_the_key_comes_back(): void
    {
        $this->runPlatformAdminMigrations();
        $keys = $this->foreignKeysOf('platform_admin_grants');
        $this->assertNotSame([], $keys);

        $this->rollBackPlatformAdminMigrations();
        foreach (self::OWN_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} must be gone after the rollback");
        }
        $this->runPlatformAdminMigrations();

        $this->assertSame($keys, $this->foreignKeysOf('platform_admin_grants'));
        $this->assertContains('admin_id', $this->uniqueIndexes('platform_admin_grants'), 'the unique index survives adding the key');
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
            $listed = Schema::getColumnListing($table);
            if ($listed !== []) {
                $columns[$table] = $listed;
            }
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

    /** @return list<string> the messages of the warnings the log has received */
    private function warningsIn(TestHandler $log): array
    {
        return array_values(array_map(
            static fn (LogRecord $record): string => $record->message,
            array_filter($log->getRecords(), static fn (LogRecord $record): bool => $record->level === Level::Warning),
        ));
    }

    private function requireMySqlOrMariaDb(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('SQLite has no INT or BIGINT to tell apart, no storage engines, no partitions and no information_schema: the MySQL and MariaDB runs cover this.');
        }
    }

    private function rebuildAdminsAs(string $shape): void
    {
        if ($shape !== 'missing') {
            $this->requireMySqlOrMariaDb();
        }

        Schema::drop('admins');

        if ($shape === 'missing') {
            return;
        }

        Schema::create('admins', function (Blueprint $table) use ($shape) {
            match ($shape) {
                'int unsigned' => $table->unsignedInteger('id', true),
                'signed bigint' => $table->bigInteger('id', true),
                default => $table->id(),
            };
            if ($shape === 'myisam') {
                $table->engine = 'MyISAM';
            }
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
        });

        if ($shape === 'partitioned') {
            DB::statement('alter table admins partition by hash(id) partitions 2');
        }
    }

    private function insertAdmin(int $id): void
    {
        DB::table('admins')->insert(['id' => $id, 'name' => "Admin {$id}", 'email' => "admin{$id}@example.test", 'password' => 'x']);
    }

    private function insertGrant(int $adminId, ?string $fingerprint = null): int
    {
        return (int) DB::table('platform_admin_grants')->insertGetId(['admin_id' => $adminId, 'admin_fingerprint' => $fingerprint ?? $this->adminFingerprint(adminId: $adminId), 'level' => 1, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function insertToken(int $adminId, int $grantId, string $hash): void
    {
        DB::table('platform_admin_tokens')->insert([
            'admin_id' => $adminId, 'grant_id' => $grantId, 'token_hash' => $hash, 'password_fingerprint' => str_repeat('c', 64),
            'device_name' => 'Test phone', 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
