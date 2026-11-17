<?php

namespace Tests\Feature\PlatformAdmin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsPlatformAdminWorld;
use Tests\TestCase;

/**
 * The migration that gives a grant an end and a list of capabilities is additive: two nullable columns, and a grant made before it means what it meant.
 */
class GrantsMigrationTest extends TestCase
{
    use BuildsPlatformAdminWorld;

    private const FILE = '2026_11_30_100000_add_expiry_and_capabilities_to_platform_admin_grants_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStoreTables();
        $this->runPlatformAdminMigrations();
        $this->runPlatformAdminPhaseTwoMigrations(except: [self::FILE]);
    }

    public function test_the_migration_adds_the_two_columns_and_the_rollback_removes_them(): void
    {
        $this->assertFalse(Schema::hasColumn('platform_admin_grants', 'expires_at'));
        $this->assertFalse(Schema::hasColumn('platform_admin_grants', 'capabilities'));

        $this->runPlatformAdminPhaseTwoMigrations(only: self::FILE);

        $this->assertEqualsCanonicalizing(
            ['id', 'admin_id', 'admin_fingerprint', 'level', 'countries', 'capabilities', 'granted_by', 'granted_at', 'revoked_at', 'expires_at', 'created_at', 'updated_at'],
            Schema::getColumnListing('platform_admin_grants'),
        );

        (require database_path('migrations/' . self::FILE))->down();

        $this->assertFalse(Schema::hasColumn('platform_admin_grants', 'expires_at'));
        $this->assertFalse(Schema::hasColumn('platform_admin_grants', 'capabilities'));
        $this->assertTrue(Schema::hasColumn('platform_admin_grants', 'countries'), 'what level 1 made stays');
    }

    public function test_a_grant_made_before_the_migration_has_every_capability_and_no_end(): void
    {
        DB::table('admins')->insert(['id' => 7, 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'x']);
        DB::table('platform_admin_grants')->insert(['admin_id' => 7, 'admin_fingerprint' => $this->adminFingerprint(adminId: 7), 'level' => 1, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->runPlatformAdminPhaseTwoMigrations(only: self::FILE);

        $row = DB::table('platform_admin_grants')->first();
        $this->assertNull($row->capabilities, 'SQL NULL: every capability that exists');
        $this->assertNull($row->expires_at, 'no end');
    }

    public function test_the_migration_is_safe_to_run_or_roll_back_twice(): void
    {
        $this->runPlatformAdminPhaseTwoMigrations(only: self::FILE);
        $this->runPlatformAdminPhaseTwoMigrations(only: self::FILE);
        $this->assertTrue(Schema::hasColumn('platform_admin_grants', 'capabilities'));

        (require database_path('migrations/' . self::FILE))->down();
        (require database_path('migrations/' . self::FILE))->down();

        $this->assertFalse(Schema::hasColumn('platform_admin_grants', 'capabilities'));
    }

    public function test_the_columns_are_nullable_json_and_a_moment(): void
    {
        $this->runPlatformAdminPhaseTwoMigrations(only: self::FILE);
        DB::table('admins')->insert(['id' => 7, 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'x']);

        DB::table('platform_admin_grants')->insert([
            'admin_id' => 7, 'admin_fingerprint' => $this->adminFingerprint(adminId: 7), 'level' => 1, 'capabilities' => json_encode(['alerts']), 'expires_at' => '2027-03-31 23:59:59',
            'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = DB::table('platform_admin_grants')->first();
        $this->assertSame(['alerts'], json_decode($row->capabilities, true));
        $this->assertSame('2027-03-31 23:59:59', $row->expires_at);
    }
}
