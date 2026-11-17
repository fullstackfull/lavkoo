<?php

namespace Tests\Feature\PlatformAdmin;

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
        DB::table('platform_admin_grants')->insert(['admin_id' => 7, 'level' => 1, 'countries' => $stored, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

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
        return (int) DB::table('platform_admin_grants')->insertGetId(['admin_id' => 7, 'level' => 1, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
