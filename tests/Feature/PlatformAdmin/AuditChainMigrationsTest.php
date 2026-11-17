<?php

namespace Tests\Feature\PlatformAdmin;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsPlatformAdminWorld;
use Tests\TestCase;

/**
 * The two migrations of the tamper-evident trail only add: two nullable columns to the lines, a table that holds the tail of the chain, and one that holds the
 * checkpoints of a prune. Lines that were written before them stay as they are, and read as older than the chain.
 */
class AuditChainMigrationsTest extends TestCase
{
    use BuildsPlatformAdminWorld;

    private const COLUMNS = '2026_11_30_100100_add_hash_chain_to_platform_admin_audit_logs_table.php';

    private const TABLES = '2026_11_30_100200_create_platform_admin_audit_chain_tables.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStoreTables();
        $this->runPlatformAdminMigrations();
        $this->runPlatformAdminPhaseTwoMigrations(except: [self::COLUMNS, self::TABLES]);
    }

    public function test_the_first_adds_the_two_columns_and_the_rollback_removes_them_and_nothing_else(): void
    {
        $before = Schema::getColumnListing('platform_admin_audit_logs');

        $this->runPlatformAdminPhaseTwoMigrations(only: self::COLUMNS);

        $this->assertEqualsCanonicalizing([...$before, 'prev_hash', 'hash'], Schema::getColumnListing('platform_admin_audit_logs'));

        (require database_path('migrations/' . self::COLUMNS))->down();

        $this->assertEqualsCanonicalizing($before, Schema::getColumnListing('platform_admin_audit_logs'));
    }

    public function test_the_second_creates_the_tail_with_its_one_row_and_the_checkpoints_and_the_rollback_drops_them(): void
    {
        $this->runPlatformAdminPhaseTwoMigrations(only: self::COLUMNS);
        $this->runPlatformAdminPhaseTwoMigrations(only: self::TABLES);

        $this->assertEqualsCanonicalizing(['id', 'last_line_id', 'last_hash'], Schema::getColumnListing('platform_admin_audit_tail'));
        $this->assertEqualsCanonicalizing(['id', 'last_line_id', 'last_hash', 'lines_removed', 'days', 'operator', 'pruned_at'], Schema::getColumnListing('platform_admin_audit_checkpoints'));
        $tail = DB::table('platform_admin_audit_tail')->get();
        $this->assertCount(1, $tail);
        $this->assertSame([1, null, null], [(int) $tail[0]->id, $tail[0]->last_line_id, $tail[0]->last_hash]);
        $this->assertSame(0, DB::table('platform_admin_audit_checkpoints')->count());

        (require database_path('migrations/' . self::TABLES))->down();

        $this->assertFalse(Schema::hasTable('platform_admin_audit_tail'));
        $this->assertFalse(Schema::hasTable('platform_admin_audit_checkpoints'));
        $this->assertTrue(Schema::hasTable('platform_admin_audit_logs'), 'the lines belong to level 1 and stay');
    }

    public function test_the_tail_can_hold_only_the_one_row(): void
    {
        $this->runPlatformAdminPhaseTwoMigrations(only: self::COLUMNS);
        $this->runPlatformAdminPhaseTwoMigrations(only: self::TABLES);

        $this->expectException(QueryException::class);

        DB::table('platform_admin_audit_tail')->insert(['id' => 1, 'last_line_id' => null, 'last_hash' => null]);
    }

    public function test_lines_written_before_the_migrations_stay_as_they_are_and_have_no_hash(): void
    {
        DB::table('platform_admin_audit_logs')->insert(['admin_id' => 7, 'action' => 'grant.created', 'context' => '{"source":"console"}', 'created_at' => '2026-10-01 10:00:00']);

        $this->runBoth();

        $row = DB::table('platform_admin_audit_logs')->first();
        $this->assertSame(['grant.created', ['source' => 'console'], '2026-10-01 10:00:00', null, null], [$row->action, json_decode((string) $row->context, true), $row->created_at, $row->prev_hash, $row->hash]);
        $this->assertNull(DB::table('platform_admin_audit_tail')->value('last_hash'), 'the chain has not begun');
    }

    public function test_the_tail_is_made_from_the_newest_hashed_line_when_the_table_is_made_again_over_a_trail_that_has_them(): void
    {
        $this->runPlatformAdminPhaseTwoMigrations(only: self::COLUMNS);
        DB::table('platform_admin_audit_logs')->insert([
            ['id' => 3, 'action' => 'a', 'created_at' => '2026-11-01 10:00:00', 'prev_hash' => str_repeat('0', 64), 'hash' => str_repeat('1', 64)],
            ['id' => 4, 'action' => 'b', 'created_at' => '2026-11-02 10:00:00', 'prev_hash' => str_repeat('1', 64), 'hash' => str_repeat('2', 64)],
            ['id' => 5, 'action' => 'c', 'created_at' => '2026-11-03 10:00:00', 'prev_hash' => null, 'hash' => null],
        ]);

        $this->runPlatformAdminPhaseTwoMigrations(only: self::TABLES);

        $tail = DB::table('platform_admin_audit_tail')->first();
        $this->assertSame([4, str_repeat('2', 64)], [(int) $tail->last_line_id, $tail->last_hash]);
    }

    public function test_the_migrations_are_safe_to_run_or_roll_back_twice_and_in_a_trail_that_was_partly_migrated(): void
    {
        $this->runBoth();
        $this->runPlatformAdminPhaseTwoMigrations(only: self::COLUMNS);
        $this->runPlatformAdminPhaseTwoMigrations(only: self::TABLES);

        $this->assertSame(1, DB::table('platform_admin_audit_tail')->count());

        (require database_path('migrations/' . self::TABLES))->down();
        (require database_path('migrations/' . self::TABLES))->down();
        (require database_path('migrations/' . self::COLUMNS))->down();
        (require database_path('migrations/' . self::COLUMNS))->down();

        $this->assertFalse(Schema::hasColumn('platform_admin_audit_logs', 'hash'));
    }

    public function test_the_columns_hold_the_hex_of_a_sha256(): void
    {
        $this->runBoth();

        DB::table('platform_admin_audit_logs')->insert(['action' => 'a', 'created_at' => '2026-11-01 10:00:00', 'prev_hash' => str_repeat('a', 64), 'hash' => str_repeat('b', 64)]);

        $row = DB::table('platform_admin_audit_logs')->first();
        $this->assertSame([str_repeat('a', 64), str_repeat('b', 64)], [$row->prev_hash, $row->hash]);
    }

    private function runBoth(): void
    {
        $this->runPlatformAdminPhaseTwoMigrations(only: self::COLUMNS);
        $this->runPlatformAdminPhaseTwoMigrations(only: self::TABLES);
    }
}
