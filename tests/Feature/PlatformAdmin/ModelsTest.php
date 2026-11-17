<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\Admin;
use App\Models\PlatformAdminAuditLog;
use App\Models\PlatformAdminGrant;
use App\Models\PlatformAdminToken;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsPlatformAdminWorld;
use Tests\TestCase;

class ModelsTest extends TestCase
{
    use BuildsPlatformAdminWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildPlatformAdminWorld();
        DB::table('admins')->insert(['id' => 7, 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'x']);
    }

    /** @return array<string, array{0: ?string, 1: ?list<string>}> */
    public static function storedCountries(): array
    {
        return [
            'SQL NULL is every market' => [null, null],
            'a list of codes is those markets' => ['["KW","AE"]', ['KW', 'AE']],
            'an empty list is no market' => ['[]', []],
            'the JSON text null is no market, not every market' => ['null', []],
            'damaged JSON is no market' => ['["KW"', []],
            'an object is no market' => ['{"market":"KW"}', []],
            'a bare string is no market' => ['"KW"', []],
            'non-string entries are dropped' => ['["KW",7,null]', ['KW']],
        ];
    }

    #[DataProvider('storedCountries')]
    public function test_only_sql_null_means_every_market(?string $stored, ?array $expected): void
    {
        if ($stored !== null) {
            json_decode($stored);
            if (json_last_error() !== JSON_ERROR_NONE && DB::getDriverName() !== 'sqlite') {
                $this->markTestSkipped('A JSON column on this engine refuses damaged text, so it cannot be stored at all.');
            }
        }

        DB::table('platform_admin_grants')->insert(['admin_id' => 7, 'admin_fingerprint' => $this->adminFingerprint(adminId: 7), 'level' => 1, 'countries' => $stored, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame($expected, PlatformAdminGrant::query()->firstOrFail()->countryList());
    }

    public function test_a_grant_is_active_until_it_is_revoked_and_only_at_a_known_level(): void
    {
        $grant = new PlatformAdminGrant(['admin_id' => 7, 'level' => PlatformAdminGrant::LEVEL_MONITOR]);
        $this->assertTrue($grant->isActive());

        $grant->revoked_at = now();
        $this->assertFalse($grant->isActive());

        $this->assertFalse((new PlatformAdminGrant(['admin_id' => 7, 'level' => 0]))->isActive());
    }

    public function test_a_grant_is_made_for_one_admin_row_and_a_row_that_only_carries_the_same_id_is_not_that_admin(): void
    {
        DB::table('admins')->where('id', 7)->update(['created_at' => '2026-01-05 09:00:00']);
        $original = Admin::query()->findOrFail(7);
        $grant = new PlatformAdminGrant(['admin_id' => 7, 'admin_fingerprint' => PlatformAdminGrant::fingerprintOf(admin: $original)]);

        $this->assertSame(hash('sha256', '7|2026-01-05 09:00:00'), PlatformAdminGrant::fingerprintOf(admin: $original), 'the formula is the id and the creation time of the row');
        $this->assertTrue($grant->isMadeFor(admin: $original));

        DB::table('admins')->where('id', 7)->update(['created_at' => '2026-02-01 10:00:00']);
        $this->assertFalse($grant->isMadeFor(admin: Admin::query()->findOrFail(7)), 'the same id, created at another moment: another row');

        DB::table('admins')->where('id', 7)->update(['created_at' => '2026-01-05 09:00:00']);
        DB::table('admins')->insert(['id' => 8, 'name' => 'Other', 'email' => 'other@example.test', 'password' => 'x', 'created_at' => '2026-01-05 09:00:00']);
        $this->assertFalse($grant->isMadeFor(admin: Admin::query()->findOrFail(8)), 'the same creation time, another id: another row');
    }

    public function test_the_fingerprint_does_not_move_when_the_admin_edits_the_row_and_a_row_without_a_creation_time_is_told_apart_by_its_id_alone(): void
    {
        $withoutCreationTime = Admin::query()->findOrFail(7);
        $grant = new PlatformAdminGrant(['admin_id' => 7, 'admin_fingerprint' => PlatformAdminGrant::fingerprintOf(admin: $withoutCreationTime)]);

        $this->assertSame(hash('sha256', '7|'), PlatformAdminGrant::fingerprintOf(admin: $withoutCreationTime));

        DB::table('admins')->where('id', 7)->update(['name' => 'Renamed', 'email' => 'renamed@example.test', 'password' => 'another hash']);
        $this->assertTrue($grant->isMadeFor(admin: Admin::query()->findOrFail(7)), 'a new e-mail, name or password is the same admin row');

        DB::table('admins')->where('id', 7)->update(['created_at' => '2026-03-01 08:00:00']);
        $this->assertFalse($grant->isMadeFor(admin: Admin::query()->findOrFail(7)), 'a row that has a creation time is not the one that had none');
    }

    public function test_a_token_is_active_until_it_expires_or_is_revoked_and_never_serialises_its_hash(): void
    {
        $token = PlatformAdminToken::create([
            'admin_id' => 7, 'grant_id' => $this->grantId(), 'token_hash' => str_repeat('a', 64), 'password_fingerprint' => str_repeat('b', 64),
            'device_name' => 'Test phone', 'expires_at' => now()->addMinute(),
        ]);

        $this->assertTrue($token->isActive());
        $this->assertSame([$token->id], PlatformAdminToken::query()->active()->pluck('id')->all());
        $this->assertArrayNotHasKey('token_hash', $token->toArray());
        $this->assertArrayNotHasKey('password_fingerprint', $token->toArray());

        $token->expires_at = now()->subSecond();
        $this->assertFalse($token->isActive());

        $token->expires_at = now()->addMinute();
        $token->revoked_at = now();
        $this->assertFalse($token->isActive());
        $token->save();
        $this->assertSame([], PlatformAdminToken::query()->active()->pluck('id')->all());
    }

    public function test_an_audit_line_is_written_once_with_a_context_and_no_update_time(): void
    {
        $line = PlatformAdminAuditLog::create(['admin_id' => 7, 'action' => 'overview.view', 'market' => 'KW', 'context' => ['status' => 200]]);

        $this->assertSame(['status' => 200], $line->fresh()->context);
        $this->assertNull(PlatformAdminAuditLog::UPDATED_AT);
        $this->assertNotNull($line->created_at);
    }

    private function grantId(): int
    {
        return (int) DB::table('platform_admin_grants')->insertGetId(['admin_id' => 7, 'admin_fingerprint' => $this->adminFingerprint(adminId: 7), 'level' => 1, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
