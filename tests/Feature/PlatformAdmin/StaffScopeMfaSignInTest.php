<?php

namespace Tests\Feature\PlatformAdmin;

use Tests\Support\BuildsPlatformStaffWorld;

/**
 * The sign-in in two steps (the second factor is required, as the API ships) with the staff country scopes (GCC-14c) on: the session the second step opens is built from the same
 * principal as every other request, so what it answers about the admin is what the next request will say.
 */
class StaffScopeMfaSignInTest extends MfaTestCase
{
    use BuildsPlatformStaffWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStaffCountryTable();
        $this->switchStaffScopes(on: true);
    }

    public function test_the_second_step_answers_with_the_markets_the_staff_scope_leaves_and_so_does_the_next_request(): void
    {
        $employee = $this->enrolledAdmin(countries: null, adminOverrides: ['admin_role_id' => 2]);
        $this->assignStaffCountries(adminId: $employee['id'], countries: ['OM', 'KW']);

        $response = $this->second(['challenge' => $this->challengeFor($employee), 'code' => $this->totpFor($employee['factor'])]);

        $response->assertOk();
        $this->assertSame(['KW', 'OM'], $response->json('data.me.markets'));
        $this->assertSame('restricted', $response->json('data.me.scope'));
        $this->assertSame(['admin_id', 'name', 'level', 'scope', 'markets', 'token_expires_at'], array_keys($response->json('data.me')));
        $me = $this->getJson(self::PREFIX . '/me', $this->bearer((string) $response->json('data.token')));
        $this->assertSame(['KW', 'OM'], $me->json('data.markets'));
        $this->assertTrue($me->json('data.mfa.verified'));
    }

    public function test_a_recovery_code_opens_the_same_session_for_the_same_admin(): void
    {
        $employee = $this->enrolledAdmin(countries: ['KW', 'SA'], adminOverrides: ['admin_role_id' => 2]);
        $this->assignStaffCountries(adminId: $employee['id'], countries: ['KW']);

        $response = $this->second(['challenge' => $this->challengeFor($employee), 'recovery_code' => $employee['factor']['recovery_codes'][0]]);

        $response->assertOk();
        $this->assertSame(['KW'], $response->json('data.me.markets'));
        $this->assertSame('recovery_code', $response->json('data.mfa.method'));
    }

    public function test_the_master_admin_signs_in_to_every_market_whatever_rows_it_has(): void
    {
        $master = $this->enrolledAdmin();
        $this->assignStaffCountries(adminId: $master['id'], countries: ['KW']);

        $response = $this->second(['challenge' => $this->challengeFor($master), 'code' => $this->totpFor($master['factor'])]);

        $response->assertOk();
        $this->assertSame(['SA', 'AE', 'KW', 'QA', 'BH', 'OM'], $response->json('data.me.markets'));
        $this->assertSame('all', $response->json('data.me.scope'));
    }

    public function test_an_admin_the_staff_scope_leaves_no_market_is_not_refused_at_either_step(): void
    {
        $employee = $this->enrolledAdmin(countries: ['KW'], adminOverrides: ['admin_role_id' => 2]);
        $this->assignStaffCountries(adminId: $employee['id'], countries: ['SA']);

        $first = $this->login($employee);
        $first->assertOk()->assertJsonPath('data.mfa_required', true);
        $second = $this->second(['challenge' => $first->json('data.challenge'), 'code' => $this->totpFor($employee['factor'])]);

        $second->assertOk()->assertJsonPath('data.me.markets', [])->assertJsonPath('data.me.scope', 'restricted');
    }
}
