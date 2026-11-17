<?php

namespace Tests\Feature\PlatformAdmin;

use Illuminate\Support\Facades\DB;

/**
 * What a client can send that is not what the API expects must be refused or ignored, never a server error and never kept in a form that
 * breaks the trail: bytes that are not text, and credentials where they do not belong.
 */
class HardeningTest extends PlatformAdminTestCase
{
    private const NOT_TEXT = "\xff\xfe\xfa";

    public function test_a_user_agent_that_is_not_text_is_served_and_kept_as_text(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);

        $this->getJson(self::PREFIX . '/me', $this->bearer($token) + ['User-Agent' => 'Phone ' . self::NOT_TEXT])->assertOk();

        $stored = (string) DB::table('platform_admin_audit_logs')->value('user_agent');
        $this->assertTrue(mb_check_encoding($stored, 'UTF-8'), 'what is stored is text');
        $this->assertStringStartsWith('Phone ', $stored);
    }

    public function test_a_parameter_that_is_not_text_is_refused_as_what_it_is_and_the_trail_keeps_it_as_text(): void
    {
        $token = $this->mintToken($this->platformAdmin());

        $this->getJson(self::PREFIX . '/overview?market=%FF%FE', $this->bearer($token))->assertStatus(422)->assertJsonPath('errors.0.code', 'invalid_market');
        $this->getJson(self::PREFIX . '/overview?from=%FF%FE', $this->bearer($token))->assertStatus(422)->assertJsonPath('errors.0.code', 'invalid_window');
        $this->getJson(self::PREFIX . '/alerts?severity=%FF', $this->bearer($token))->assertStatus(422)->assertJsonPath('errors.0.code', 'invalid_severity');

        $this->assertSame(3, DB::table('platform_admin_audit_logs')->count());
        foreach (DB::table('platform_admin_audit_logs')->pluck('context') as $context) {
            $this->assertIsArray(json_decode($context, true), 'the context is valid JSON');
        }
    }

    public function test_a_sign_in_whose_device_name_or_e_mail_is_not_text_is_the_same_refusal(): void
    {
        $admin = $this->platformAdmin();
        $asForm = ['HTTP_ACCEPT' => 'application/json'];

        $withBadDeviceName = $this->call('POST', self::PREFIX . '/auth/login', ['email' => $admin['email'], 'password' => $admin['password'], 'device_name' => 'Phone ' . self::NOT_TEXT], [], [], $asForm);
        $withBadEmail = $this->call('POST', self::PREFIX . '/auth/login', ['email' => 'a' . self::NOT_TEXT . '@example.test', 'password' => $admin['password'], 'device_name' => 'Phone'], [], [], $asForm);

        foreach ([$withBadDeviceName, $withBadEmail] as $response) {
            $this->assertSame(401, $response->getStatusCode());
            $this->assertSame($this->uniformRefusal(), $response->json());
        }
        $this->assertSame(0, DB::table('platform_admin_tokens')->count());
    }

    public function test_credentials_in_the_address_are_not_credentials(): void
    {
        $admin = $this->platformAdmin();

        $this->postJson(self::PREFIX . '/auth/login?email=' . urlencode($admin['email']) . '&password=' . urlencode($admin['password']) . '&device_name=Phone')
            ->assertStatus(401)->assertExactJson($this->uniformRefusal());
        $this->post(self::PREFIX . '/auth/login?email=' . urlencode($admin['email']) . '&password=' . urlencode($admin['password']) . '&device_name=Phone', [], ['Accept' => 'application/json'])
            ->assertStatus(401);
        $this->assertSame(0, DB::table('platform_admin_tokens')->count());
    }

    public function test_credentials_in_the_body_of_a_form_or_of_json_both_work(): void
    {
        $admin = $this->platformAdmin();

        $this->postJson(self::PREFIX . '/auth/login', ['email' => $admin['email'], 'password' => $admin['password'], 'device_name' => 'Phone'])->assertOk();
        $this->post(self::PREFIX . '/auth/login', ['email' => $admin['email'], 'password' => $admin['password'], 'device_name' => 'Browser'], ['Accept' => 'application/json'])->assertOk();
    }
}
