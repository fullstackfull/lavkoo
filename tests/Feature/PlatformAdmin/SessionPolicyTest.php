<?php

namespace Tests\Feature\PlatformAdmin;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\EnrolsPlatformAdminFactors;

/**
 * What a session may do and how long it lasts besides its absolute life: it is over when it has been idle too long, an admin has at most so many open
 * at once (the oldest is ended to make room), and an admin can end all but the one in their hand.
 */
class SessionPolicyTest extends PlatformAdminTestCase
{
    use EnrolsPlatformAdminFactors;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gcc.platform_admin.session.idle_minutes' => 60, 'gcc.platform_admin.session.max_concurrent' => 5]);
    }

    // ---- idle ---------------------------------------------------------------------------------------------------------------------------------

    public function test_a_session_unused_for_the_idle_time_is_over_at_its_next_request_and_that_request_ends_it_and_says_so_in_the_trail(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin, ['last_used_at' => now()->subMinutes(61)]);
        $id = (int) DB::table('platform_admin_tokens')->value('id');

        $response = $this->getJson(self::PREFIX . '/me', $this->bearer($token) + ['User-Agent' => 'LavkooSeller/1.0']);

        $response->assertStatus(401)->assertExactJson($this->uniformRefusal());
        $this->assertSame('2026-11-17 10:00:00', DB::table('platform_admin_tokens')->where('id', $id)->value('revoked_at'), 'the session is ended, not merely refused');
        $lines = DB::table('platform_admin_audit_logs')->get();
        $this->assertSame(['session.idle_ended'], $lines->pluck('action')->all());
        $this->assertSame([$admin['id'], $id, '127.0.0.1', 'LavkooSeller/1.0'], [(int) $lines[0]->admin_id, (int) $lines[0]->token_id, $lines[0]->ip, $lines[0]->user_agent]);
        $this->assertSame(['idle_minutes' => 60], json_decode($lines[0]->context, true));
    }

    public function test_the_next_request_with_it_is_the_plain_refusal_of_an_ended_session_and_writes_nothing(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin, ['last_used_at' => now()->subMinutes(61)]);
        $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertStatus(401);
        $before = [json_encode(DB::table('platform_admin_tokens')->get()), DB::table('platform_admin_audit_logs')->count()];

        $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertStatus(401)->assertExactJson($this->uniformRefusal());

        $this->assertSame($before, [json_encode(DB::table('platform_admin_tokens')->get()), DB::table('platform_admin_audit_logs')->count()], 'it is ended once, and said once');
    }

    public function test_the_last_moment_of_the_idle_time_is_idle_and_the_second_before_is_not(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->platformAdmin();
        $justInside = $this->mintToken($admin, ['last_used_at' => now()->subMinutes(60)->addSecond()]);
        $justOutside = $this->mintToken($admin, ['last_used_at' => now()->subMinutes(60)]);

        $this->getJson(self::PREFIX . '/me', $this->bearer($justInside))->assertOk();
        $this->getJson(self::PREFIX . '/me', $this->bearer($justOutside))->assertStatus(401);
    }

    public function test_a_session_that_was_never_used_is_idle_from_the_moment_it_was_opened(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->platformAdmin();
        $stale = $this->mintToken($admin, ['created_at' => now()->subMinutes(61)]);
        $fresh = $this->mintToken($admin, ['created_at' => now()->subMinutes(59)]);

        $this->getJson(self::PREFIX . '/me', $this->bearer($stale))->assertStatus(401);
        $this->getJson(self::PREFIX . '/me', $this->bearer($fresh))->assertOk();
    }

    public function test_a_session_that_is_used_often_enough_lives_until_its_absolute_end(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);

        foreach (range(1, 8) as $round) {
            $this->travelTo(now()->addMinutes(59));
            $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertOk();
        }

        $this->travelTo(now()->addMinutes(59));
        $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertStatus(401);
        $this->assertSame(0, DB::table('platform_admin_audit_logs')->where('action', 'session.idle_ended')->count(), 'it ended at its absolute end, which is not idleness and writes nothing');
    }

    public function test_the_idle_time_is_the_configured_one_up_to_its_ceiling(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->platformAdmin();

        foreach ([[5, 6, 401], [5, 4, 200], [1440, 1439, 200], [99999, 1441, 401], [0, 61, 401], [0, 59, 200]] as [$configured, $idleMinutes, $status]) {
            config(['gcc.platform_admin.session.idle_minutes' => $configured]);
            $token = $this->mintToken($admin, ['last_used_at' => now()->subMinutes($idleMinutes), 'expires_at' => now()->addDays(3)]);

            $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertStatus($status);
        }
    }

    public function test_a_session_with_no_time_at_all_is_idle_because_nothing_says_it_was_ever_used(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);
        DB::table('platform_admin_tokens')->update(['created_at' => null, 'last_used_at' => null]);

        $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertStatus(401);
    }

    public function test_the_list_leaves_out_a_session_at_the_very_moment_its_idle_time_is_up_and_keeps_the_one_a_second_short_of_it(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->platformAdmin();
        $mine = $this->mintToken($admin, ['device_name' => 'Mine']);
        $this->mintToken($admin, ['device_name' => 'Just inside', 'last_used_at' => now()->subMinutes(60)->addSecond()]);
        $this->mintToken($admin, ['device_name' => 'Just outside', 'last_used_at' => now()->subMinutes(60)]);

        $listed = $this->getJson(self::PREFIX . '/sessions', $this->bearer($mine))->assertOk()->json('data.*.device_name');

        sort($listed);
        $this->assertSame(['Just inside', 'Mine'], $listed);
    }

    public function test_an_idle_session_is_not_listed_cannot_be_ended_by_id_and_is_not_counted_as_open(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->platformAdmin();
        $mine = $this->mintToken($admin, ['device_name' => 'Mine']);
        $this->mintToken($admin, ['device_name' => 'Idle', 'last_used_at' => now()->subMinutes(90)]);
        $idleId = (int) DB::table('platform_admin_tokens')->where('device_name', 'Idle')->value('id');

        $this->getJson(self::PREFIX . '/sessions', $this->bearer($mine))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.device_name', 'Mine');
        $this->deleteJson(self::PREFIX . "/sessions/{$idleId}", [], $this->bearer($mine))->assertStatus(404);
    }

    public function test_an_idle_session_ends_with_its_own_line_whatever_else_is_the_matter_with_it(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin, ['last_used_at' => now()->subMinutes(90)]);
        DB::table('admins')->where('id', $admin['id'])->update(['status' => 0]);

        $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertStatus(401);

        $this->assertNotNull(DB::table('platform_admin_tokens')->value('revoked_at'));
    }

    // ---- how many are open --------------------------------------------------------------------------------------------------------------------

    public function test_a_sixth_sign_in_ends_the_oldest_session_and_says_so(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->platformAdmin();
        $tokens = [];
        foreach (range(1, 5) as $index) {
            $tokens[$index] = $this->signIn($admin, device: "Phone {$index}");
        }

        $sixth = $this->signIn($admin, device: 'Phone 6');

        $this->getJson(self::PREFIX . '/me', $this->bearer($tokens[1]))->assertStatus(401);
        foreach ([2, 3, 4, 5] as $index) {
            $this->getJson(self::PREFIX . '/me', $this->bearer($tokens[$index]))->assertOk();
        }
        $this->getJson(self::PREFIX . '/me', $this->bearer($sixth))->assertOk();
        $this->assertSame(5, DB::table('platform_admin_tokens')->where('admin_id', $admin['id'])->whereNull('revoked_at')->count());

        $firstId = (int) DB::table('platform_admin_tokens')->where('device_name', 'Phone 1')->value('id');
        $sixthId = (int) DB::table('platform_admin_tokens')->where('device_name', 'Phone 6')->value('id');
        $line = DB::table('platform_admin_audit_logs')->where('action', 'session.evicted')->first();
        $this->assertNotNull($line);
        $this->assertSame([$admin['id'], $firstId], [(int) $line->admin_id, (int) $line->token_id]);
        $this->assertSame($this->sortedByKey(['by_token_id' => $sixthId, 'limit' => 5]), $this->contextOf($line));
        $this->assertSame(1, DB::table('platform_admin_audit_logs')->where('action', 'session.evicted')->count());
    }

    public function test_the_number_of_sessions_is_the_configured_one_and_one_means_only_the_newest(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        config(['gcc.platform_admin.session.max_concurrent' => 2]);
        $admin = $this->platformAdmin();
        $first = $this->signIn($admin);
        $second = $this->signIn($admin);
        $third = $this->signIn($admin);

        $this->getJson(self::PREFIX . '/me', $this->bearer($first))->assertStatus(401);
        $this->getJson(self::PREFIX . '/me', $this->bearer($second))->assertOk();
        $this->getJson(self::PREFIX . '/me', $this->bearer($third))->assertOk();

        config(['gcc.platform_admin.session.max_concurrent' => 1]);
        $fourth = $this->signIn($admin);

        $this->getJson(self::PREFIX . '/me', $this->bearer($second))->assertStatus(401);
        $this->getJson(self::PREFIX . '/me', $this->bearer($third))->assertStatus(401);
        $this->getJson(self::PREFIX . '/me', $this->bearer($fourth))->assertOk();
        $this->assertSame(1, DB::table('platform_admin_tokens')->whereNull('revoked_at')->count());
        $lines = DB::table('platform_admin_audit_logs')->where('action', 'session.evicted')->orderBy('id')->get();
        $this->assertSame(3, $lines->count(), 'one line for every session that was ended, naming it');
        $ids = DB::table('platform_admin_tokens')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertSame([$ids[0], $ids[1], $ids[2]], $lines->pluck('token_id')->map(fn ($id) => (int) $id)->all(), 'oldest first');
    }

    public function test_a_sign_in_holds_the_row_of_the_grant_while_it_counts_the_open_sessions_so_that_two_of_them_cannot_both_see_room(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite has no row locks: the statement that takes one can be seen on MySQL and MariaDB only.');
        }

        $admin = $this->platformAdmin();

        $statements = $this->statementsRunBy(fn () => $this->signIn($admin));

        $holdingTheGrant = array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'platform_admin_grants') && str_ends_with(rtrim($sql), 'for update'));
        $this->assertCount(1, $holdingTheGrant);
    }

    public function test_sessions_that_are_over_do_not_take_a_place_and_so_nobody_is_ended_for_them(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        config(['gcc.platform_admin.session.max_concurrent' => 3]);
        $admin = $this->platformAdmin();
        $live = [$this->mintToken($admin), $this->mintToken($admin)];
        $this->mintToken($admin, ['revoked_at' => now()->subMinute()]);
        $this->mintToken($admin, ['expires_at' => now()->subMinute()]);
        $this->mintToken($admin, ['last_used_at' => now()->subMinutes(120)]);

        $new = $this->signIn($admin);

        foreach ([...$live, $new] as $token) {
            $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertOk();
        }
        $this->assertSame(0, DB::table('platform_admin_audit_logs')->where('action', 'session.evicted')->count());
    }

    public function test_only_the_sessions_of_the_admin_who_signs_in_are_counted_and_ended(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        config(['gcc.platform_admin.session.max_concurrent' => 1]);
        $admin = $this->platformAdmin();
        $other = $this->platformAdmin();
        $others = $this->mintToken($other);

        $this->signIn($admin);
        $this->signIn($admin);

        $this->getJson(self::PREFIX . '/me', $this->bearer($others))->assertOk();
    }

    public function test_a_sign_in_that_is_refused_ends_nobody(): void
    {
        config(['gcc.platform_admin.session.max_concurrent' => 1]);
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);

        $this->postJson(self::PREFIX . '/auth/login', ['email' => $admin['email'], 'password' => 'not the password', 'device_name' => 'Pixel'])->assertStatus(401);

        $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertOk();
    }

    public function test_the_second_step_ends_the_oldest_too_and_the_first_step_ends_nobody(): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        config(['gcc.platform_admin.session.max_concurrent' => 1, 'gcc.platform_admin.mfa.required' => true]);
        $admin = $this->platformAdmin();
        $factor = $this->enrolFactor(adminId: $admin['id']);
        $old = $this->mintToken($admin, ['mfa_method' => 'totp']);

        $challenge = (string) $this->postJson(self::PREFIX . '/auth/login', ['email' => $admin['email'], 'password' => $admin['password'], 'device_name' => 'New phone'])->json('data.challenge');
        $this->getJson(self::PREFIX . '/me', $this->bearer($old))->assertOk();

        $new = (string) $this->postJson(self::PREFIX . '/auth/mfa', ['challenge' => $challenge, 'code' => $this->totpFor($factor)])->assertOk()->json('data.token');

        $this->getJson(self::PREFIX . '/me', $this->bearer($old))->assertStatus(401);
        $this->getJson(self::PREFIX . '/me', $this->bearer($new))->assertOk();
    }

    // ---- ending the others --------------------------------------------------------------------------------------------------------------------

    public function test_ending_the_others_ends_every_open_session_of_this_admin_but_this_one_and_says_how_many(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->platformAdmin();
        $other = $this->platformAdmin();
        $current = $this->mintToken($admin, ['device_name' => 'Current']);
        $first = $this->mintToken($admin, ['device_name' => 'First']);
        $second = $this->mintToken($admin, ['device_name' => 'Second']);
        $this->mintToken($admin, ['device_name' => 'Ended', 'revoked_at' => now()->subHour()]);
        $theirs = $this->mintToken($other);

        $response = $this->deleteJson(self::PREFIX . '/sessions', [], $this->bearer($current));

        $response->assertOk()->assertExactJson(['data' => ['ended' => 2]]);
        $this->getJson(self::PREFIX . '/me', $this->bearer($current))->assertOk();
        $this->getJson(self::PREFIX . '/me', $this->bearer($first))->assertStatus(401);
        $this->getJson(self::PREFIX . '/me', $this->bearer($second))->assertStatus(401);
        $this->getJson(self::PREFIX . '/me', $this->bearer($theirs))->assertOk();
        $this->assertSame(['Current'], DB::table('platform_admin_tokens')->where('admin_id', $admin['id'])->whereNull('revoked_at')->pluck('device_name')->all());
        $this->assertSame(
            (string) now()->subHour(),
            (string) DB::table('platform_admin_tokens')->where('device_name', 'Ended')->value('revoked_at'),
            'a session that was ended already keeps the time it was ended',
        );
    }

    public function test_ending_the_others_when_there_are_none_ends_nothing(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);

        $this->deleteJson(self::PREFIX . '/sessions', [], $this->bearer($token))->assertOk()->assertExactJson(['data' => ['ended' => 0]]);
        $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertOk();
    }

    public function test_ending_the_others_counts_only_sessions_that_were_open_and_leaves_the_ones_that_were_over_alone(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->platformAdmin();
        $current = $this->mintToken($admin);
        $this->mintToken($admin, ['device_name' => 'Open']);
        $this->mintToken($admin, ['device_name' => 'Expired', 'expires_at' => now()->subMinute()]);
        $this->mintToken($admin, ['device_name' => 'Idle', 'last_used_at' => now()->subMinutes(90)]);

        $this->deleteJson(self::PREFIX . '/sessions', [], $this->bearer($current))->assertOk()->assertExactJson(['data' => ['ended' => 1]]);

        $this->assertNull(DB::table('platform_admin_tokens')->where('device_name', 'Expired')->value('revoked_at'));
        $this->assertNull(DB::table('platform_admin_tokens')->where('device_name', 'Idle')->value('revoked_at'));
    }

    public function test_ending_the_others_is_audited_with_how_many_it_ended(): void
    {
        $admin = $this->platformAdmin();
        $current = $this->mintToken($admin);
        $this->mintToken($admin);
        $this->mintToken($admin);
        $currentId = (int) DB::table('platform_admin_tokens')->where('admin_id', $admin['id'])->min('id');

        $this->deleteJson(self::PREFIX . '/sessions', [], $this->bearer($current))->assertOk();

        $line = DB::table('platform_admin_audit_logs')->where('action', 'sessions.end_others')->first();
        $this->assertNotNull($line);
        $this->assertSame([$admin['id'], $currentId], [(int) $line->admin_id, (int) $line->token_id]);
        $this->assertSame($this->sortedByKey(['status' => 200, 'ended' => 2]), $this->contextOf($line));
    }

    public function test_the_route_for_one_session_is_still_the_route_for_one_session(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);

        $this->deleteJson(self::PREFIX . '/sessions/abc', [], $this->bearer($token))->assertStatus(404);
        $this->deleteJson(self::PREFIX . '/sessions/999999', [], $this->bearer($token))->assertStatus(404)->assertJsonPath('errors.0.code', 'session_not_found');
    }

    // ---- what ends the challenges too ---------------------------------------------------------------------------------------------------------

    public function test_revoking_the_grant_or_the_sessions_or_giving_the_grant_back_ends_the_pending_challenges_of_that_admin_only(): void
    {
        $admin = $this->platformAdmin();
        $other = $this->platformAdmin();

        foreach (['gcc:platform-admin:revoke-tokens', 'gcc:platform-admin:revoke', 'gcc:platform-admin:grant'] as $command) {
            DB::table('platform_admin_mfa_challenges')->delete();
            $this->insertChallenge($admin, str_repeat('a', 64));
            $this->insertChallenge($other, str_repeat('b', 64));

            $this->assertSame(0, Artisan::call($command, ['admin_id' => $admin['id']]), $command);

            $this->assertNotNull(DB::table('platform_admin_mfa_challenges')->where('token_hash', str_repeat('a', 64))->value('consumed_at'), $command);
            $this->assertNull(DB::table('platform_admin_mfa_challenges')->where('token_hash', str_repeat('b', 64))->value('consumed_at'), $command);
        }
    }

    // ---- helpers ------------------------------------------------------------------------------------------------------------------------------

    /** Signs in the way a phone does, in one step, and returns the token. */
    private function signIn(array $admin, string $device = 'Test phone'): string
    {
        $this->travelTo(now()->addSecond());

        return (string) $this->postJson(self::PREFIX . '/auth/login', ['email' => $admin['email'], 'password' => $admin['password'], 'device_name' => $device])->assertOk()->json('data.token');
    }

    private function insertChallenge(array $admin, string $hash): void
    {
        DB::table('platform_admin_mfa_challenges')->insert([
            'token_hash' => $hash, 'admin_id' => $admin['id'], 'grant_id' => $admin['grant_id'], 'password_fingerprint' => str_repeat('c', 64), 'device_name' => 'Test phone',
            'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
