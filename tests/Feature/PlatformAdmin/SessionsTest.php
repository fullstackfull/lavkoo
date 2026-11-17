<?php

namespace Tests\Feature\PlatformAdmin;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SessionsTest extends PlatformAdminTestCase
{
    public function test_the_list_holds_this_admins_live_sessions_newest_first_and_marks_the_one_in_use(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->platformAdmin();
        $first = $this->mintToken($admin, ['device_name' => 'Phone', 'created_at' => now()->subHours(2), 'expires_at' => now()->addHours(6)]);
        $second = $this->mintToken($admin, ['device_name' => 'Tablet', 'created_at' => now()->subHour(), 'last_used_at' => now()->subMinutes(5), 'expires_at' => now()->addHours(7)]);

        $response = $this->getJson(self::PREFIX . '/sessions', $this->bearer($second));

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertSame(['Tablet', 'Phone'], array_column($rows, 'device_name'));
        $this->assertSame([true, false], array_column($rows, 'current'));
        $this->assertSame(['id', 'device_name', 'created_at', 'last_used_at', 'expires_at', 'current', 'mfa_method'], array_keys($rows[0]), 'exactly the agreed fields, so no hash, address or user agent can slip in');
        $this->assertSame(Carbon::parse('2026-11-17 09:00:00')->utc()->toIso8601ZuluString(), $rows[0]['created_at']);
        $this->assertSame(Carbon::parse('2026-11-17 10:00:00')->utc()->toIso8601ZuluString(), $rows[0]['last_used_at'], 'the request being answered has just been counted: the last use was over a minute ago');
        $this->assertSame(Carbon::parse('2026-11-17 17:00:00')->utc()->toIso8601ZuluString(), $rows[0]['expires_at']);
        $this->assertNull($rows[1]['last_used_at']);
        $this->assertStringNotContainsString(hash('sha256', $first), $response->getContent());
    }

    public function test_the_list_leaves_out_revoked_expired_and_other_admins_sessions(): void
    {
        $admin = $this->platformAdmin();
        $other = $this->platformAdmin();
        $mine = $this->mintToken($admin, ['device_name' => 'Mine']);
        $this->mintToken($admin, ['device_name' => 'Revoked', 'revoked_at' => now()->subMinute()]);
        $this->mintToken($admin, ['device_name' => 'Expired', 'expires_at' => now()->subMinute()]);
        $this->mintToken($other, ['device_name' => 'Theirs']);

        $this->getJson(self::PREFIX . '/sessions', $this->bearer($mine))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.device_name', 'Mine');
    }

    public function test_an_admin_can_end_another_session_of_their_own_and_it_stops_working_at_once(): void
    {
        $admin = $this->platformAdmin();
        $current = $this->mintToken($admin, ['device_name' => 'Laptop']);
        $lost = $this->mintToken($admin, ['device_name' => 'Lost phone']);
        $lostId = (int) DB::table('platform_admin_tokens')->where('device_name', 'Lost phone')->value('id');
        $this->getJson(self::PREFIX . '/me', $this->bearer($lost))->assertOk();

        $this->deleteJson(self::PREFIX . "/sessions/{$lostId}", [], $this->bearer($current))->assertOk()->assertExactJson(['data' => ['id' => $lostId, 'revoked' => true]]);

        $this->getJson(self::PREFIX . '/me', $this->bearer($lost))->assertStatus(401)->assertExactJson($this->uniformRefusal());
        $this->getJson(self::PREFIX . '/me', $this->bearer($current))->assertOk();
        $this->assertNotNull(DB::table('platform_admin_tokens')->where('id', $lostId)->value('revoked_at'));
        $this->assertNull(DB::table('platform_admin_tokens')->where('device_name', 'Laptop')->value('revoked_at'));
    }

    public function test_an_admin_can_end_the_session_they_are_using(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);
        $id = (int) DB::table('platform_admin_tokens')->value('id');

        $this->deleteJson(self::PREFIX . "/sessions/{$id}", [], $this->bearer($token))->assertOk();

        $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertStatus(401);
    }

    public function test_another_admins_session_id_answers_404_exactly_like_one_that_does_not_exist_and_stays_alive(): void
    {
        $admin = $this->platformAdmin();
        $other = $this->platformAdmin();
        $mine = $this->mintToken($admin);
        $theirs = $this->mintToken($other);
        $theirId = (int) DB::table('platform_admin_tokens')->where('admin_id', $other['id'])->value('id');
        $expected = ['errors' => [['code' => 'session_not_found', 'message' => 'No such session.']]];

        $this->deleteJson(self::PREFIX . "/sessions/{$theirId}", [], $this->bearer($mine))->assertStatus(404)->assertExactJson($expected);
        $this->deleteJson(self::PREFIX . '/sessions/999999', [], $this->bearer($mine))->assertStatus(404)->assertExactJson($expected);

        $this->assertNull(DB::table('platform_admin_tokens')->where('id', $theirId)->value('revoked_at'));
        $this->getJson(self::PREFIX . '/me', $this->bearer($theirs))->assertOk();
    }

    public function test_a_session_that_has_already_ended_is_not_found(): void
    {
        $admin = $this->platformAdmin();
        $mine = $this->mintToken($admin);
        $this->mintToken($admin, ['revoked_at' => now()->subMinute()]);
        $this->mintToken($admin, ['expires_at' => now()->subMinute()]);

        foreach (DB::table('platform_admin_tokens')->where('id', '>', 1)->pluck('id') as $id) {
            $this->deleteJson(self::PREFIX . "/sessions/{$id}", [], $this->bearer($mine))->assertStatus(404);
        }
    }

    public function test_an_id_that_is_not_a_number_is_a_missing_route(): void
    {
        $admin = $this->platformAdmin();

        $this->deleteJson(self::PREFIX . '/sessions/abc', [], $this->bearer($this->mintToken($admin)))->assertStatus(404);
    }

    public function test_signing_out_ends_this_session_only_and_the_next_request_is_refused(): void
    {
        $admin = $this->platformAdmin();
        $current = $this->mintToken($admin, ['device_name' => 'Laptop']);
        $other = $this->mintToken($admin, ['device_name' => 'Phone']);

        $this->postJson(self::PREFIX . '/auth/logout', [], $this->bearer($current))->assertOk()->assertExactJson(['data' => ['revoked' => true]]);

        $this->getJson(self::PREFIX . '/me', $this->bearer($current))->assertStatus(401);
        $this->getJson(self::PREFIX . '/me', $this->bearer($other))->assertOk();
        $this->assertNotNull(DB::table('platform_admin_tokens')->where('device_name', 'Laptop')->value('revoked_at'));
        $this->assertNull(DB::table('platform_admin_tokens')->where('device_name', 'Phone')->value('revoked_at'));
    }

    public function test_the_time_a_session_was_last_used_moves_at_most_once_a_minute(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);
        $this->assertNull(DB::table('platform_admin_tokens')->value('last_used_at'));

        $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertOk();
        $this->assertSame('2026-11-17 10:00:00', DB::table('platform_admin_tokens')->value('last_used_at'));

        $this->travelTo('2026-11-17 10:00:59');
        $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertOk();
        $this->assertSame('2026-11-17 10:00:00', DB::table('platform_admin_tokens')->value('last_used_at'), 'inside the minute nothing is written');

        $this->travelTo('2026-11-17 10:01:01');
        $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertOk();
        $this->assertSame('2026-11-17 10:01:01', DB::table('platform_admin_tokens')->value('last_used_at'));
    }

    public function test_a_refused_request_does_not_count_as_use(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);
        DB::table('platform_admin_grants')->update(['revoked_at' => now()]);

        $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertStatus(401);

        $this->assertNull(DB::table('platform_admin_tokens')->value('last_used_at'));
    }

    public function test_me_says_who_is_asking_what_they_may_see_and_until_when(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $owner = $this->platformAdmin(adminOverrides: ['name' => 'Platform Owner']);
        $viewer = $this->platformAdmin(countries: ['KW', 'AE'], adminOverrides: ['name' => 'Kuwait Viewer']);
        $ownerToken = $this->mintToken($owner, ['expires_at' => now()->addHours(3)]);
        $viewerToken = $this->mintToken($viewer, ['expires_at' => now()->addHours(4)]);
        $expiry = static fn (int $hours): string => Carbon::parse('2026-11-17 10:00:00')->addHours($hours)->utc()->toIso8601ZuluString();

        $noFactor = ['enrolled' => false, 'verified' => false, 'method' => null];
        $this->getJson(self::PREFIX . '/me', $this->bearer($ownerToken))->assertOk()->assertExactJson(['data' => [
            'admin_id' => $owner['id'], 'name' => 'Platform Owner', 'level' => 1, 'scope' => 'all', 'markets' => ['SA', 'AE', 'KW', 'QA', 'BH', 'OM'], 'token_expires_at' => $expiry(3), 'mfa' => $noFactor,
        ]]);
        $this->getJson(self::PREFIX . '/me', $this->bearer($viewerToken))->assertOk()->assertExactJson(['data' => [
            'admin_id' => $viewer['id'], 'name' => 'Kuwait Viewer', 'level' => 1, 'scope' => 'restricted', 'markets' => ['AE', 'KW'], 'token_expires_at' => $expiry(4), 'mfa' => $noFactor,
        ]]);
    }

    public function test_the_scope_is_read_on_every_request_so_narrowing_a_grant_takes_effect_at_once(): void
    {
        $viewer = $this->platformAdmin(countries: ['KW', 'AE']);
        $token = $this->mintToken($viewer);
        $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertJsonPath('data.markets', ['AE', 'KW']);

        DB::table('platform_admin_grants')->where('id', $viewer['grant_id'])->update(['countries' => json_encode(['KW'])]);

        $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertJsonPath('data.markets', ['KW'])->assertJsonPath('data.scope', 'restricted');
    }

    public function test_a_grant_whose_countries_cannot_be_read_grants_no_market_at_all(): void
    {
        $viewer = $this->platformAdmin();
        $token = $this->mintToken($viewer);

        foreach (['[]', 'null', '{"a":"KW"}', '"KW"', '["XX"]'] as $stored) {
            DB::table('platform_admin_grants')->where('id', $viewer['grant_id'])->update(['countries' => $stored]);

            $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertOk()->assertJsonPath('data.scope', 'restricted')->assertJsonPath('data.markets', []);
        }
    }

    public function test_every_request_of_a_signed_in_admin_is_audited_even_when_it_is_refused(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->platformAdmin();
        $mine = $this->mintToken($admin);
        $tokenId = (int) DB::table('platform_admin_tokens')->value('id');

        $this->getJson(self::PREFIX . '/me', $this->bearer($mine) + ['User-Agent' => str_repeat('a', 400)])->assertOk();
        $this->getJson(self::PREFIX . '/sessions', $this->bearer($mine))->assertOk();
        $this->deleteJson(self::PREFIX . '/sessions/999999', [], $this->bearer($mine))->assertStatus(404);

        $lines = DB::table('platform_admin_audit_logs')->orderBy('id')->get();
        $this->assertSame(['me.view', 'sessions.list', 'session.revoke'], $lines->pluck('action')->all());
        $this->assertSame([$admin['id'], $admin['id'], $admin['id']], $lines->pluck('admin_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([$tokenId, $tokenId, $tokenId], $lines->pluck('token_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['127.0.0.1', '127.0.0.1', '127.0.0.1'], $lines->pluck('ip')->all());
        $this->assertSame(255, strlen($lines[0]->user_agent));
        $this->assertSame([null, null, null], $lines->pluck('market')->all());
        $this->assertSame(
            [['status' => 200], ['status' => 200], $this->sortedByKey(['status' => 404, 'params' => ['id' => '999999']])],
            $lines->map(fn ($line) => $this->contextOf($line))->all(),
        );
    }

    public function test_a_sign_out_is_audited_as_a_sign_out(): void
    {
        $admin = $this->platformAdmin();

        $this->postJson(self::PREFIX . '/auth/logout', [], $this->bearer($this->mintToken($admin)))->assertOk();

        $this->assertSame(['auth.logout'], DB::table('platform_admin_audit_logs')->pluck('action')->all());
    }

    public function test_nothing_a_request_sent_reaches_the_trail_except_the_few_parameters_it_is_known_to_take(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);

        $this->getJson(self::PREFIX . '/me?market=KW&secret=' . $token . '&password=hunter2&from[]=x', $this->bearer($token))->assertOk();

        $stored = (string) DB::table('platform_admin_audit_logs')->value('context');
        $this->assertStringNotContainsString($token, $stored);
        $this->assertStringNotContainsString('hunter2', $stored);
        $this->assertSame($this->sortedByKey(['status' => 200, 'params' => ['market' => 'KW']]), $this->contextOf(DB::table('platform_admin_audit_logs')->first()), 'a parameter of the API is kept (arrays are not), anything else is not');
    }
}
