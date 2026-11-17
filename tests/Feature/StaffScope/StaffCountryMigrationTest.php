<?php

namespace Tests\Feature\StaffScope;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsStaffScopeWorld;
use Tests\TestCase;

/**
 * The table of staff countries is created by its real migration file and removed by its rollback, and it changes no table that exists today. Whatever `admins`
 * turns out to be on the real database the migration succeeds: the table is tied to the admin by a foreign key when the database can take one, and is left
 * without one, with a warning, when it cannot, because a deploy must not fail for a feature that ships switched off.
 */
class StaffCountryMigrationTest extends TestCase
{
    use BuildsStaffScopeWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStaffAdminTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('admin_country_scopes');

        parent::tearDown();
    }

    public function test_the_migration_creates_the_table_and_the_rollback_removes_it(): void
    {
        $this->assertFalse(Schema::hasTable('admin_country_scopes'));

        $this->runStaffScopeMigration();
        $this->assertTrue(Schema::hasTable('admin_country_scopes'));

        $this->rollBackStaffScopeMigration();
        $this->assertFalse(Schema::hasTable('admin_country_scopes'));
    }

    public function test_the_migration_changes_no_existing_table(): void
    {
        $before = $this->columnsOfEveryTable();

        $this->runStaffScopeMigration();
        $withOwnTableRemoved = array_diff_key($this->columnsOfEveryTable(), ['admin_country_scopes' => true]);

        $this->assertSame($before, $withOwnTableRemoved);

        $this->rollBackStaffScopeMigration();
        $this->assertSame($before, $this->columnsOfEveryTable());
    }

    public function test_the_table_has_the_agreed_columns_and_one_row_per_admin_and_country(): void
    {
        $this->runStaffScopeMigration();

        $this->assertEqualsCanonicalizing(['id', 'admin_id', 'country', 'created_at', 'updated_at'], Schema::getColumnListing('admin_country_scopes'));
        $this->assertContains('admin_id,country', $this->uniqueIndexes('admin_country_scopes'));
    }

    public function test_the_same_country_twice_for_one_admin_is_refused_by_the_database_and_two_admins_may_share_a_country(): void
    {
        $this->runStaffScopeMigration();
        $this->giveCountries(adminId: 2, countries: ['KW']);

        $this->giveCountries(adminId: 3, countries: ['KW']);
        $this->giveCountries(adminId: 2, countries: ['SA']);

        try {
            $this->giveCountries(adminId: 2, countries: ['KW']);
            $this->fail('one admin must hold a country once');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(3, DB::table('admin_country_scopes')->count());
    }

    public function test_the_country_is_two_characters_wide_like_the_one_of_a_product(): void
    {
        $this->runStaffScopeMigration();

        $column = collect(Schema::getColumns('admin_country_scopes'))->firstWhere('name', 'country');

        $this->assertMatchesRegularExpression('/^(char\(2\)|varchar|text)/i', $column['type'], 'a code is stored as text, never as a number');
        $this->assertFalse($column['nullable'], 'a row without a country would be a row that grants nothing and hides that it is there');
    }

    public function test_the_country_is_compared_byte_for_byte_so_a_lower_case_code_is_not_the_upper_case_one(): void
    {
        $this->runStaffScopeMigration();
        $this->giveCountries(adminId: 2, countries: ['SA', 'sa']);

        $this->assertSame(2, DB::table('admin_country_scopes')->where('admin_id', 2)->count(), 'sa and SA are two values, as in the products column');
        $this->assertSame(1, DB::table('admin_country_scopes')->where('country', 'SA')->count());
    }

    public function test_on_mysql_the_country_is_two_ascii_bytes_compared_byte_for_byte(): void
    {
        $this->requireMySqlOrMariaDb();
        $this->runStaffScopeMigration();

        $column = DB::selectOne("select character_maximum_length as length, collation_name as collation_name from information_schema.columns where table_schema = database() and table_name = 'admin_country_scopes' and column_name = 'country'");

        $this->assertSame([2, 'ascii_bin'], [(int) $column->length, $column->collation_name]);
    }

    public function test_with_a_bigint_unsigned_admins_id_the_table_is_tied_to_admins_and_nothing_is_warned(): void
    {
        $log = $this->captureLog();

        $this->runStaffScopeMigration();

        $this->assertSame([['columns' => ['admin_id'], 'foreign_table' => 'admins', 'foreign_columns' => ['id'], 'on_delete' => 'cascade']], $this->foreignKeysOf('admin_country_scopes'));
        $this->assertSame([], $this->warningsIn($log), 'a database that can take the key has nothing to be warned about');
    }

    public function test_deleting_an_admin_removes_its_countries_so_that_a_reused_id_inherits_nothing(): void
    {
        $this->runStaffScopeMigration();
        $this->giveCountries(adminId: 3, countries: ['KW']);
        $this->giveCountries(adminId: 2, countries: ['SA']);

        DB::table('admins')->where('id', 3)->delete();

        $this->assertSame(['SA'], DB::table('admin_country_scopes')->pluck('country')->all());
    }

    public function test_a_row_for_an_admin_that_does_not_exist_is_refused_when_the_key_is_there(): void
    {
        $this->runStaffScopeMigration();

        try {
            $this->giveCountries(adminId: 99, countries: ['SA']);
            $this->fail('a country for an admin that does not exist must be refused');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_the_key_is_the_one_the_database_itself_reports(): void
    {
        $this->requireMySqlOrMariaDb();

        $this->runStaffScopeMigration();

        $rows = DB::select("select constraint_name as name, referenced_table_name as referenced, delete_rule as on_delete from information_schema.referential_constraints where constraint_schema = database() and table_name = 'admin_country_scopes'");
        $this->assertSame([['admin_country_scopes_admin_fk', 'admins', 'CASCADE']], array_map(static fn (object $row): array => [$row->name, $row->referenced, $row->on_delete], $rows));
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
    public function test_when_admins_cannot_be_referenced_the_migration_still_succeeds_without_the_key_and_warns_once(string $shape, string $reason): void
    {
        $this->rebuildAdminsAs($shape);
        $log = $this->captureLog();

        $this->runStaffScopeMigration();

        $this->assertTrue(Schema::hasTable('admin_country_scopes'));
        $this->assertSame([], $this->foreignKeysOf('admin_country_scopes'), 'no key to admins');
        $warnings = $this->warningsIn($log);
        $this->assertCount(1, $warnings, 'one warning, not one per attempt');
        $this->assertStringContainsString('admin_country_scopes', $warnings[0]);
        $this->assertStringContainsString($reason, $warnings[0]);
        $this->assertStringContainsString('gcc:staff-country:revoke', $warnings[0], 'it tells the operator what to do before deleting an admin');
        $this->giveCountries(adminId: 7, countries: ['KW']);
        $this->assertSame(1, DB::table('admin_country_scopes')->count(), 'the table works');
    }

    #[DataProvider('adminsThatCannotBeReferenced')]
    public function test_the_migration_runs_again_after_a_rollback_when_admins_cannot_be_referenced(string $shape): void
    {
        $this->rebuildAdminsAs($shape);
        $log = $this->captureLog();

        $this->runStaffScopeMigration();
        $this->rollBackStaffScopeMigration();
        $this->assertFalse(Schema::hasTable('admin_country_scopes'));
        $this->runStaffScopeMigration();

        $this->assertTrue(Schema::hasTable('admin_country_scopes'));
        $this->assertSame([], $this->foreignKeysOf('admin_country_scopes'));
        $this->assertCount(2, $this->warningsIn($log), 'each run of the migration says it once');
    }

    public function test_a_pretend_run_prints_the_statements_and_neither_changes_the_database_nor_warns(): void
    {
        $log = $this->captureLog();

        $statements = DB::pretend(fn () => $this->runStaffScopeMigration());

        $this->assertNotEmpty(array_filter($statements, static fn (array $statement): bool => str_contains($statement['query'], 'admin_country_scopes')));
        $this->assertFalse(Schema::hasTable('admin_country_scopes'));
        $this->assertSame([], $this->warningsIn($log), 'nothing was created, so there is nothing to say about it');
    }

    public function test_the_migration_runs_again_after_a_rollback_and_the_key_comes_back(): void
    {
        $this->runStaffScopeMigration();
        $keys = $this->foreignKeysOf('admin_country_scopes');
        $this->assertNotSame([], $keys);

        $this->rollBackStaffScopeMigration();
        $this->assertFalse(Schema::hasTable('admin_country_scopes'));
        $this->runStaffScopeMigration();

        $this->assertSame($keys, $this->foreignKeysOf('admin_country_scopes'));
        $this->assertContains('admin_id,country', $this->uniqueIndexes('admin_country_scopes'), 'the unique index survives adding the key');
    }

    public function test_running_the_migration_on_a_table_that_exists_does_not_fail_a_deploy(): void
    {
        $this->runStaffScopeMigration();
        $this->giveCountries(adminId: 2, countries: ['KW']);

        $this->runStaffScopeMigration();

        $this->assertSame(['KW'], DB::table('admin_country_scopes')->pluck('country')->all(), 'a second run leaves the rows it finds');
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

    private function captureLog(): TestHandler
    {
        config(['logging.default' => 'capture', 'logging.channels.capture' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
        app('log')->forgetChannel('capture');

        return app('log')->channel('capture')->getLogger()->getHandlers()[0];
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
            $table->unsignedBigInteger('admin_role_id')->nullable();
            $table->timestamps();
        });

        if ($shape === 'partitioned') {
            DB::statement('alter table admins partition by hash(id) partitions 2');
        }
    }
}
