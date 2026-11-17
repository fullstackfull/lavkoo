<?php

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformAdmin\MfaMethod;
use App\Models\Admin;
use App\Models\PlatformAdminMfaChallenge;
use App\Models\PlatformAdminMfaFactor;
use App\Models\PlatformAdminToken;
use App\Services\PlatformAdmin\Base32;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsPlatformAdminWorld;
use Tests\TestCase;

class MfaModelsTest extends TestCase
{
    use BuildsPlatformAdminWorld;

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildPlatformAdminWorld();
        DB::table('admins')->insert(['id' => 7, 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'x', 'created_at' => '2026-01-05 09:00:00']);
        DB::table('platform_admin_grants')->insert(['id' => 3, 'admin_id' => 7, 'admin_fingerprint' => $this->adminFingerprint(adminId: 7), 'level' => 1, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_secret_is_encrypted_in_the_table_and_read_back_whole(): void
    {
        $this->makeFactor();

        $stored = (string) DB::table('platform_admin_mfa_factors')->value('secret');
        $this->assertStringNotContainsString(self::SECRET, $stored);
        $this->assertNotSame(self::SECRET, $stored);
        $this->assertSame(self::SECRET, PlatformAdminMfaFactor::query()->firstOrFail()->secret);
    }

    public function test_the_secret_is_read_as_the_bytes_the_code_is_made_from_or_not_at_all(): void
    {
        $factor = $this->makeFactor();
        $this->assertSame((string) Base32::decode(text: self::SECRET), $factor->fresh()->secretBytes());

        foreach (['not base32 !', 'MZX', ''] as $damaged) {
            DB::table('platform_admin_mfa_factors')->update(['secret' => Crypt::encryptString($damaged)]);
            $this->assertNull(PlatformAdminMfaFactor::query()->firstOrFail()->secretBytes(), "'{$damaged}' is no secret");
        }

        DB::table('platform_admin_mfa_factors')->update(['secret' => 'a payload that is not one']);
        $this->assertNull(PlatformAdminMfaFactor::query()->firstOrFail()->secretBytes(), 'what the key cannot open is no secret');

        DB::table('platform_admin_mfa_factors')->update(['secret' => Crypt::encryptString(self::SECRET)]);
        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstances();
        $this->assertNull(PlatformAdminMfaFactor::query()->firstOrFail()->secretBytes(), 'what another key sealed is no secret');
    }

    public function test_neither_the_secret_nor_the_codes_nor_the_fingerprint_are_in_any_array_or_json_form_of_a_factor(): void
    {
        $factor = $this->makeFactor(recoveryCodes: [['hash' => str_repeat('a', 64), 'used_at' => null]]);

        foreach ([$factor->toArray(), json_decode($factor->toJson(), true), $factor->fresh()->toArray()] as $serialised) {
            $this->assertSame([], array_values(array_intersect(['secret', 'recovery_codes', 'admin_fingerprint'], array_keys($serialised))));
            $this->assertStringNotContainsString(self::SECRET, json_encode($serialised));
            $this->assertStringNotContainsString(str_repeat('a', 64), json_encode($serialised));
        }
    }

    public function test_a_factor_is_pending_until_it_is_confirmed(): void
    {
        $this->assertFalse($this->makeFactor()->isConfirmed());
        PlatformAdminMfaFactor::query()->update(['confirmed_at' => now()]);

        $this->assertTrue(PlatformAdminMfaFactor::query()->firstOrFail()->isConfirmed());
    }

    public function test_a_factor_is_made_for_one_admin_row_and_a_row_that_only_carries_the_same_id_is_not_that_admin(): void
    {
        $factor = $this->makeFactor();
        $this->assertTrue($factor->isMadeFor(admin: Admin::query()->findOrFail(7)));

        DB::table('admins')->where('id', 7)->update(['created_at' => '2026-02-01 10:00:00']);
        $this->assertFalse($factor->isMadeFor(admin: Admin::query()->findOrFail(7)), 'the same id created at another moment is another row');

        DB::table('admins')->where('id', 7)->update(['created_at' => '2026-01-05 09:00:00', 'name' => 'Renamed', 'email' => 'renamed@example.test', 'password' => 'another hash']);
        $this->assertTrue($factor->isMadeFor(admin: Admin::query()->findOrFail(7)), 'a new name, e-mail or password is the same row');
    }

    public function test_the_codes_that_are_left_are_the_ones_without_a_time_of_use(): void
    {
        $this->assertSame(0, $this->makeFactor(recoveryCodes: null)->recoveryCodesLeft());
        $this->assertSame(0, PlatformAdminMfaFactor::query()->firstOrFail()->recoveryCodesLeft());

        PlatformAdminMfaFactor::query()->delete();
        $factor = $this->makeFactor(recoveryCodes: [
            ['hash' => str_repeat('a', 64), 'used_at' => null],
            ['hash' => str_repeat('b', 64), 'used_at' => '2026-11-17T10:00:00Z'],
            ['hash' => str_repeat('c', 64)],
            ['hash' => str_repeat('d', 64), 'used_at' => null],
        ]);

        $this->assertSame(3, $factor->fresh()->recoveryCodesLeft());
    }

    public function test_a_challenge_is_open_until_it_is_consumed_or_expires(): void
    {
        $challenge = PlatformAdminMfaChallenge::create([
            'token_hash' => str_repeat('a', 64), 'admin_id' => 7, 'grant_id' => 3, 'password_fingerprint' => str_repeat('c', 64), 'device_name' => 'Test phone', 'expires_at' => now()->addMinute(),
        ]);

        $this->assertFalse($challenge->isConsumed());
        $this->assertFalse($challenge->isExpired());
        $this->assertSame(0, $challenge->fresh()->attempts);

        $challenge->expires_at = now()->subSecond();
        $this->assertTrue($challenge->isExpired());

        $challenge->expires_at = now();
        $this->assertTrue($challenge->isExpired(), 'a challenge whose last moment is this one is over, as a session is');

        $challenge->consumed_at = now();
        $this->assertTrue($challenge->isConsumed());
    }

    public function test_a_challenge_never_serialises_its_hash_or_the_fingerprint_of_the_password(): void
    {
        $challenge = PlatformAdminMfaChallenge::create([
            'token_hash' => str_repeat('a', 64), 'admin_id' => 7, 'grant_id' => 3, 'password_fingerprint' => str_repeat('c', 64), 'device_name' => 'Test phone', 'expires_at' => now()->addMinute(),
        ]);

        $this->assertArrayNotHasKey('token_hash', $challenge->toArray());
        $this->assertArrayNotHasKey('password_fingerprint', $challenge->toArray());
    }

    public function test_a_session_says_how_it_passed_the_second_factor_and_a_value_that_is_no_method_is_none(): void
    {
        $token = PlatformAdminToken::create([
            'admin_id' => 7, 'grant_id' => 3, 'token_hash' => str_repeat('a', 64), 'password_fingerprint' => str_repeat('b', 64), 'device_name' => 'Test phone', 'expires_at' => now()->addMinute(),
        ]);
        $this->assertNull($token->fresh()->mfaMethod());

        foreach ([MfaMethod::Totp, MfaMethod::RecoveryCode] as $method) {
            $token->forceFill(['mfa_method' => $method->value])->save();
            $this->assertSame($method, $token->fresh()->mfaMethod());
        }

        foreach (['sms', 'TOTP', '', ' totp'] as $damaged) {
            DB::table('platform_admin_tokens')->update(['mfa_method' => $damaged]);
            $this->assertNull(PlatformAdminToken::query()->firstOrFail()->mfaMethod(), "'{$damaged}' is no method");
        }
    }

    /** @param list<array<string, mixed>>|null $recoveryCodes */
    private function makeFactor(?array $recoveryCodes = null): PlatformAdminMfaFactor
    {
        return PlatformAdminMfaFactor::create([
            'admin_id' => 7, 'grant_id' => 3, 'admin_fingerprint' => $this->adminFingerprint(adminId: 7), 'secret' => self::SECRET, 'recovery_codes' => $recoveryCodes,
        ]);
    }
}
