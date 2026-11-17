<?php

namespace Tests\Feature\PlatformAdmin;

use Illuminate\Testing\TestResponse;
use Tests\Support\EnrolsPlatformAdminFactors;

/**
 * The world of the tests of the second factor: the second factor required, which is how the API ships, and helpers that sign in the way the app does,
 * in two steps. The tests of level 1 do not use it: they run in the staging mode of the switch (see PlatformAdminTestCase).
 */
abstract class MfaTestCase extends PlatformAdminTestCase
{
    use EnrolsPlatformAdminFactors;

    protected const LOGIN = self::PREFIX . '/auth/login';

    protected const MFA = self::PREFIX . '/auth/mfa';

    protected function setUp(): void
    {
        parent::setUp();

        config(['gcc.platform_admin.mfa.required' => true]);
    }

    /**
     * An admin with a grant and a second factor that is confirmed unless said otherwise.
     *
     * @param list<string>|null $countries
     * @param array<string, mixed> $adminOverrides
     * @param array<string, mixed> $grantOverrides
     *
     * @return array{id: int, email: string, password: string, name: string, grant_id: int, factor: array{secret: string, base32: string, recovery_codes: list<string>, id: int}}
     */
    protected function enrolledAdmin(?array $countries = null, array $adminOverrides = [], array $grantOverrides = [], bool $confirmed = true): array
    {
        $admin = $this->platformAdmin($countries, $adminOverrides, $grantOverrides);
        $admin['factor'] = $this->enrolFactor(adminId: $admin['id'], confirmed: $confirmed);

        return $admin;
    }

    /**
     * @param array<string, mixed> $credentials an admin made by platformAdmin(), or the fields to send
     * @param array<string, string> $server
     */
    protected function login(array $credentials, string $deviceName = 'Test phone', array $server = [], array $headers = []): TestResponse
    {
        return $this->withServerVariables($server)->postJson(self::LOGIN, ['email' => $credentials['email'], 'password' => $credentials['password'], 'device_name' => $deviceName], $headers);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $server
     * @param array<string, string> $headers
     */
    protected function second(array $body, array $server = [], array $headers = []): TestResponse
    {
        return $this->withServerVariables($server)->postJson(self::MFA, $body, $headers);
    }

    /** The first step, which must have asked for a code: the challenge it gave. */
    protected function challengeFor(array $admin, string $deviceName = 'Test phone', array $server = []): string
    {
        $response = $this->login($admin, $deviceName, $server);
        $response->assertOk()->assertJsonPath('data.mfa_required', true);

        return (string) $response->json('data.challenge');
    }

    /** Both steps, with the code an authenticator app shows now: the token. */
    protected function signInWithMfa(array $admin, string $deviceName = 'Test phone'): string
    {
        $response = $this->second(['challenge' => $this->challengeFor($admin, $deviceName), 'code' => $this->totpFor($admin['factor'])]);
        $response->assertOk();

        return (string) $response->json('data.token');
    }
}
