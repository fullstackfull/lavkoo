<?php

namespace Tests\Feature\PlatformAdmin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Proving the second factor again inside a session that already holds one (L-15).
 *
 * A session may last eight hours; an action that takes something out of the platform asks for the factor again within minutes, so that an unlocked
 * phone on a desk is not the same thing as the operator. What follows pins the proof, its expiry, that it belongs to one session, that a code cannot
 * be used twice and that the middleware which reads it actually refuses.
 */
class StepUpTest extends MfaTestCase
{
    private const STEP_UP = self::PREFIX . '/auth/step-up';

    /** A route that exists only in this test, so that the middleware is proved by a request and not by reading the route file. */
    private const PROBE = '/api/platform-admin/v1/zz-step-up-probe';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['platform_admin', 'platform_admin_auth', 'platform_admin_step_up'])
            ->get(self::PROBE, static fn (): array => ['data' => ['reached' => true]])
            ->name('platform_admin.zz.probe');
    }

    public function test_the_right_code_stamps_the_session_and_says_how_long_the_proof_is_fresh(): void
    {
        $admin = $this->enrolledAdmin();
        $token = $this->signInWithMfa($admin);

        $response = $this->postJson(self::STEP_UP, ['code' => $this->totpFor($admin['factor'], steps: 1)], $this->bearer($token));

        $response->assertOk()->assertJsonPath('data.stepped_up', true);
        $this->assertSame(now()->addMinutes(5)->utc()->toIso8601ZuluString(), $response->json('data.fresh_until'));
        $this->assertNotNull(DB::table('platform_admin_tokens')->where('token_hash', hash('sha256', $token))->value('stepped_up_at'));
    }

    public function test_the_export_route_is_refused_until_the_session_has_stepped_up_and_then_let_through(): void
    {
        $admin = $this->enrolledAdmin();
        $token = $this->signInWithMfa($admin);

        $refused = $this->getJson(self::PROBE, $this->bearer($token));
        $refused->assertStatus(403)->assertJsonPath('errors.0.code', 'step_up_required')->assertJsonPath('errors.0.within_minutes', 5);

        $this->postJson(self::STEP_UP, ['code' => $this->totpFor($admin['factor'], steps: 1)], $this->bearer($token))->assertOk();

        $this->getJson(self::PROBE, $this->bearer($token))->assertOk()->assertJsonPath('data.reached', true);
    }

    public function test_the_proof_goes_stale_when_its_minutes_are_up(): void
    {
        $admin = $this->enrolledAdmin();
        $token = $this->signInWithMfa($admin);
        $this->postJson(self::STEP_UP, ['code' => $this->totpFor($admin['factor'], steps: 1)], $this->bearer($token))->assertOk();

        $this->travel(4)->minutes();
        $this->getJson(self::PROBE, $this->bearer($token))->assertOk();

        $this->travel(2)->minutes();
        $this->getJson(self::PROBE, $this->bearer($token))->assertStatus(403)->assertJsonPath('errors.0.code', 'step_up_required');
    }

    public function test_a_step_up_belongs_to_the_session_that_made_it_and_to_no_other_of_the_same_admin(): void
    {
        $admin = $this->enrolledAdmin();
        $phone = $this->signInWithMfa($admin, 'Phone');
        // A minute between each: a code that opened one session cannot open another, and the window an authenticator code is accepted in is a
        // step either side of now, so the next code has to be waited for rather than computed ahead.
        $this->travel(60)->seconds();
        $tablet = $this->signInWithMfa($admin, 'Tablet');
        $this->travel(60)->seconds();

        $this->postJson(self::STEP_UP, ['code' => $this->totpFor($admin['factor'])], $this->bearer($phone))->assertOk();

        $this->getJson(self::PROBE, $this->bearer($phone))->assertOk();
        $this->getJson(self::PROBE, $this->bearer($tablet))->assertStatus(403, 'the other device proved nothing');
    }

    public function test_a_code_cannot_be_used_twice_and_the_second_try_is_the_one_refusal(): void
    {
        $admin = $this->enrolledAdmin();
        $token = $this->signInWithMfa($admin);
        $code = $this->totpFor($admin['factor'], steps: 1);

        $this->postJson(self::STEP_UP, ['code' => $code], $this->bearer($token))->assertOk();
        $replayed = $this->postJson(self::STEP_UP, ['code' => $code], $this->bearer($token));

        $replayed->assertStatus(401)->assertExactJson($this->uniformRefusal());
    }

    public function test_a_wrong_code_is_the_one_refusal_and_stamps_nothing(): void
    {
        $admin = $this->enrolledAdmin();
        $token = $this->signInWithMfa($admin);

        $this->postJson(self::STEP_UP, ['code' => '000000'], $this->bearer($token))->assertStatus(401)->assertExactJson($this->uniformRefusal());

        $this->assertNull(DB::table('platform_admin_tokens')->where('token_hash', hash('sha256', $token))->value('stepped_up_at'));
        $this->getJson(self::PROBE, $this->bearer($token))->assertStatus(403);
    }

    public function test_a_recovery_code_proves_it_too_and_is_then_used_up(): void
    {
        $admin = $this->enrolledAdmin();
        $token = $this->signInWithMfa($admin);
        $recovery = $admin['factor']['recovery_codes'][1];

        $this->postJson(self::STEP_UP, ['recovery_code' => $recovery], $this->bearer($token))->assertOk()->assertJsonPath('data.stepped_up', true);
        $this->getJson(self::PROBE, $this->bearer($token))->assertOk();

        $this->travel(10)->minutes();
        $this->postJson(self::STEP_UP, ['recovery_code' => $recovery], $this->bearer($token))->assertStatus(401);
    }

    public function test_no_token_reaches_the_step_up_at_all(): void
    {
        $this->postJson(self::STEP_UP, ['code' => '123456'], ['Authorization' => 'Bearer ' . Str::random(64), 'Accept' => 'application/json'])
            ->assertStatus(401)
            ->assertExactJson($this->uniformRefusal());
    }

    public function test_with_the_second_factor_switched_off_there_is_nothing_to_prove_and_the_route_is_open(): void
    {
        config(['gcc.platform_admin.mfa.required' => false]);
        $token = $this->mintToken($this->platformAdmin());

        $this->getJson(self::PROBE, $this->bearer($token))->assertOk()->assertJsonPath('data.reached', true);
        $this->postJson(self::STEP_UP, [], $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('data.stepped_up', true)
            ->assertJsonPath('data.reason', 'second_factor_not_required');
    }

    public function test_a_step_up_is_written_to_the_audit_trail_with_how_it_was_proved(): void
    {
        $admin = $this->enrolledAdmin();
        $token = $this->signInWithMfa($admin);

        $this->postJson(self::STEP_UP, ['code' => $this->totpFor($admin['factor'], steps: 1)], $this->bearer($token))->assertOk();

        $lines = DB::table('platform_admin_audit_logs')->where('action', 'auth.step_up')->orderBy('id')->get();

        $this->assertCount(1, $lines, 'one line for one step-up, and not the same event written twice');
        $this->assertSame($admin['id'], (int) $lines[0]->admin_id);
        // contextOf(), not a bare decode: a json column's keys come back in the engine's own order (see ReportsAndExportsTest).
        $this->assertSame($this->sortedByKey(['status' => 200, 'method' => 'totp', 'fresh_minutes' => 5]), $this->contextOf($lines[0]));
    }

    public function test_the_configured_minutes_are_capped_so_a_step_up_cannot_be_made_to_last(): void
    {
        config(['gcc.platform_admin.step_up_minutes' => 600]);
        $admin = $this->enrolledAdmin();
        $token = $this->signInWithMfa($admin);
        $this->postJson(self::STEP_UP, ['code' => $this->totpFor($admin['factor'], steps: 1)], $this->bearer($token))->assertOk();

        $this->travel(16)->minutes();

        $this->getJson(self::PROBE, $this->bearer($token))->assertStatus(403, 'fifteen minutes is the most a step-up lasts, whatever is configured');
    }
}
