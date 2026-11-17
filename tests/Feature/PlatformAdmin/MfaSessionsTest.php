<?php

namespace Tests\Feature\PlatformAdmin;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * What a session says about the second factor, and what the second factor being required means for a session that did not pass it.
 */
class MfaSessionsTest extends MfaTestCase
{
    public function test_me_says_whether_the_admin_is_enrolled_whether_this_session_passed_the_factor_and_how(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->enrolledAdmin();
        $token = $this->signInWithMfa($admin);

        $response = $this->getJson(self::PREFIX . '/me', $this->bearer($token));

        $response->assertOk();
        $this->assertSame(['admin_id', 'name', 'level', 'scope', 'markets', 'token_expires_at', 'mfa'], array_keys($response->json('data')));
        $this->assertSame(['enrolled' => true, 'verified' => true, 'method' => 'totp'], $response->json('data.mfa'));
    }

    public function test_me_names_a_recovery_code_as_the_method_of_a_session_that_was_opened_with_one(): void
    {
        $admin = $this->enrolledAdmin();
        $response = $this->second(['challenge' => $this->challengeFor($admin), 'recovery_code' => $admin['factor']['recovery_codes'][0]]);

        $this->getJson(self::PREFIX . '/me', $this->bearer($response->json('data.token')))->assertOk()->assertJsonPath('data.mfa', ['enrolled' => true, 'verified' => true, 'method' => 'recovery_code']);
    }

    public function test_a_session_opened_while_the_second_factor_was_not_required_is_not_verified_and_me_says_whether_the_admin_is_enrolled_anyway(): void
    {
        config(['gcc.platform_admin.mfa.required' => false]);
        $enrolled = $this->enrolledAdmin();
        $notEnrolled = $this->platformAdmin();
        $pending = $this->enrolledAdmin(confirmed: false);
        $foreign = $this->platformAdmin();
        $this->enrolFactor(adminId: $foreign['id'], overrides: ['admin_fingerprint' => hash('sha256', 'another row')]);

        $expected = [[$enrolled, true], [$notEnrolled, false], [$pending, false], [$foreign, false]];
        foreach ($expected as [$admin, $isEnrolled]) {
            $token = $this->login($admin)->json('data.token');

            $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertOk()->assertJsonPath('data.mfa', ['enrolled' => $isEnrolled, 'verified' => false, 'method' => null]);
        }
    }

    public function test_the_sessions_list_says_how_each_passed_the_second_factor(): void
    {
        config(['gcc.platform_admin.mfa.required' => false]);
        $admin = $this->enrolledAdmin();
        $none = $this->mintToken($admin, ['device_name' => 'Staging phone']);
        $this->mintToken($admin, ['device_name' => 'App', 'mfa_method' => 'totp']);
        $this->mintToken($admin, ['device_name' => 'Recovery', 'mfa_method' => 'recovery_code']);

        $rows = $this->getJson(self::PREFIX . '/sessions', $this->bearer($none))->assertOk()->json('data');

        $this->assertSame(['id', 'device_name', 'created_at', 'last_used_at', 'expires_at', 'current', 'mfa_method'], array_keys($rows[0]));
        $methods = array_column($rows, 'mfa_method', 'device_name');
        ksort($methods);
        $this->assertSame(['App' => 'totp', 'Recovery' => 'recovery_code', 'Staging phone' => null], $methods);
    }

    public function test_the_sessions_list_of_a_signed_in_admin_names_the_method(): void
    {
        $admin = $this->enrolledAdmin();
        $token = $this->signInWithMfa($admin, deviceName: 'Pixel 9');

        $this->getJson(self::PREFIX . '/sessions', $this->bearer($token))->assertOk()->assertJsonPath('data.0.device_name', 'Pixel 9')->assertJsonPath('data.0.mfa_method', 'totp')->assertJsonPath('data.0.current', true);
    }

    public function test_while_the_second_factor_is_required_a_session_that_did_not_pass_it_is_no_session_on_any_route(): void
    {
        $admin = $this->enrolledAdmin();
        $staging = $this->mintToken($admin);
        $before = $this->ownRows();

        foreach ($this->platformRoutes() as $route) {
            [$method, $uri] = $this->requestTo($route);
            if (in_array($route->getName(), ['platform_admin.auth.login', 'platform_admin.auth.mfa'], true)) {
                continue;
            }

            $this->json($method, $uri, [], $this->bearer($staging))->assertStatus(401)->assertExactJson($this->uniformRefusal());
        }

        $this->assertSame($before, $this->ownRows(), 'a refusal writes nothing, not even the time of use');
    }

    public function test_the_same_session_is_a_session_again_while_the_second_factor_is_not_required_and_the_one_that_passed_it_always_is(): void
    {
        $admin = $this->enrolledAdmin();
        $staging = $this->mintToken($admin);
        $verified = $this->mintToken($admin, ['mfa_method' => 'totp']);

        $this->getJson(self::PREFIX . '/me', $this->bearer($staging))->assertStatus(401);
        $this->getJson(self::PREFIX . '/me', $this->bearer($verified))->assertOk();

        config(['gcc.platform_admin.mfa.required' => false]);
        $this->getJson(self::PREFIX . '/me', $this->bearer($staging))->assertOk();
        $this->getJson(self::PREFIX . '/me', $this->bearer($verified))->assertOk();

        config(['gcc.platform_admin.mfa.required' => true]);
        $this->getJson(self::PREFIX . '/me', $this->bearer($staging))->assertStatus(401);
    }

    public function test_a_method_that_is_none_of_the_methods_is_a_session_that_did_not_pass_the_factor(): void
    {
        $admin = $this->enrolledAdmin();

        foreach (['sms', 'TOTP', '', ' totp'] as $damaged) {
            $token = $this->mintToken($admin, ['mfa_method' => $damaged]);

            $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertStatus(401)->assertExactJson($this->uniformRefusal());
        }
    }

    public function test_a_session_that_passed_the_factor_does_not_survive_a_reset_of_it(): void
    {
        $admin = $this->enrolledAdmin();
        $token = $this->signInWithMfa($admin);
        $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertOk();

        $this->assertSame(0, Artisan::call('gcc:platform-admin:mfa:reset', ['admin_id' => $admin['id']]));

        $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertStatus(401);
    }

    /** @return array<string, string> */
    private function ownRows(): array
    {
        $rows = [];
        foreach (['platform_admin_tokens', 'platform_admin_audit_logs', 'platform_admin_mfa_factors', 'platform_admin_mfa_challenges'] as $table) {
            $rows[$table] = json_encode(DB::table($table)->orderBy('id')->get());
        }

        return $rows;
    }
}
