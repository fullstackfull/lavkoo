<?php

namespace Tests\Support;

use App\Services\PlatformAdmin\Base32;
use App\Services\PlatformAdmin\RecoveryCodes;
use App\Services\PlatformAdmin\Totp;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Second factors made straight in the database, the way the console would have made them, so that what a request is judged against does not depend on
 * the code under test; and the codes such a factor shows. The stored shapes are spelled out here: the secret is Base32 text encrypted with the
 * application key, the recovery codes are a JSON list of `hash` and `used_at`.
 *
 * Needs the tables of the platform admin API (BuildsPlatformAdminWorld) and the grant of the admin to exist already.
 */
trait EnrolsPlatformAdminFactors
{
    /**
     * @param array<string, mixed> $overrides columns of platform_admin_mfa_factors
     *
     * @return array{secret: string, base32: string, recovery_codes: list<string>, id: int} the raw secret, the text of it an app is given, the codes as shown, and the row
     */
    protected function enrolFactor(int $adminId, bool $confirmed = true, array $overrides = []): array
    {
        $secret = Totp::newSecret();
        $base32 = Base32::encode(bytes: $secret);
        $codes = RecoveryCodes::generate();

        $id = (int) DB::table('platform_admin_mfa_factors')->insertGetId(array_merge([
            'admin_id' => $adminId,
            'grant_id' => (int) DB::table('platform_admin_grants')->where('admin_id', $adminId)->value('id'),
            'admin_fingerprint' => $this->adminFingerprint(adminId: $adminId),
            'secret' => Crypt::encryptString($base32),
            'confirmed_at' => $confirmed ? now() : null,
            'last_used_step' => null,
            'recovery_codes' => json_encode(array_map(static fn (string $code): array => ['hash' => RecoveryCodes::hash(normalized: (string) RecoveryCodes::normalized(typed: $code)), 'used_at' => null], $codes)),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return ['secret' => $secret, 'base32' => $base32, 'recovery_codes' => $codes, 'id' => $id];
    }

    /**
     * The code an authenticator app would show.
     *
     * @param array{secret: string} $factor
     */
    protected function totpFor(array $factor, ?int $timestamp = null, int $steps = 0): string
    {
        return Totp::codeAt(secret: $factor['secret'], step: Totp::stepAt(timestamp: $timestamp ?? now()->timestamp) + $steps);
    }
}
