<?php

namespace Tests\Feature\PlatformAdmin;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

class AuditLogsTest extends PlatformAdminTestCase
{
    private const URL = self::PREFIX . '/audit-logs';

    public function test_the_platform_owner_sees_every_line_newest_first(): void
    {
        $this->travelTo('2026-11-17 12:00:00');
        $owner = $this->platformAdmin();
        $viewer = $this->platformAdmin(countries: ['KW']);
        $this->line(adminId: $viewer['id'], action: 'overview.view', market: 'KW', at: '2026-11-17 09:00:00');
        $this->line(adminId: $owner['id'], action: 'markets.view', market: null, at: '2026-11-17 10:00:00');
        $this->line(adminId: null, action: 'grant.created', market: null, at: '2026-11-17 11:00:00');

        $response = $this->logs(token: $this->mintToken($owner));

        $response->assertOk();
        $this->assertSame(['grant.created', 'markets.view', 'overview.view'], array_column($response->json('data'), 'action'));
        $this->assertSame([null, $owner['id'], $viewer['id']], array_column($response->json('data'), 'admin_id'));
    }

    public function test_a_line_has_exactly_the_agreed_fields(): void
    {
        $this->travelTo('2026-11-17 12:00:00');
        $owner = $this->platformAdmin();
        $this->line(adminId: $owner['id'], action: 'overview.view', market: 'KW', at: '2026-11-17 09:00:00', context: ['status' => 200, 'params' => ['market' => 'KW']], tokenId: 7);

        $line = $this->logs(token: $this->mintToken($owner))->json('data.0');

        $this->assertSame(['id', 'admin_id', 'action', 'market', 'token_id', 'ip', 'user_agent', 'context', 'created_at'], array_keys($line));
        $this->assertSame(['overview.view', 'KW', 7, '203.0.113.7', 'TestAgent/1.0'], [$line['action'], $line['market'], $line['token_id'], $line['ip'], $line['user_agent']]);
        $this->assertSame($this->sortedByKey(['status' => 200, 'params' => ['market' => 'KW']]), $this->sortedByKey($line['context']));
        $this->assertSame(Carbon::parse('2026-11-17 09:00:00')->utc()->toIso8601ZuluString(), $line['created_at']);
    }

    public function test_a_restricted_viewer_sees_only_their_own_lines_whatever_the_market(): void
    {
        $owner = $this->platformAdmin();
        $viewer = $this->platformAdmin(countries: ['KW']);
        $other = $this->platformAdmin(countries: ['AE']);
        $this->line(adminId: $viewer['id'], action: 'overview.view', market: 'KW');
        $this->line(adminId: $other['id'], action: 'overview.view', market: 'AE');
        $this->line(adminId: $owner['id'], action: 'markets.view', market: null);
        $this->line(adminId: null, action: 'grant.created', market: null);
        $this->line(adminId: $viewer['id'], action: 'alerts.view', market: 'KW');

        $response = $this->logs(token: $this->mintToken($viewer));

        $this->assertSame(['alerts.view', 'overview.view'], array_column($response->json('data'), 'action'));
        $this->assertSame([$viewer['id']], array_unique(array_column($response->json('data'), 'admin_id')));
        $this->assertSame('restricted', $response->json('meta.scope'));
    }

    public function test_a_restricted_viewer_cannot_ask_for_somebody_elses_lines(): void
    {
        $owner = $this->platformAdmin();
        $viewer = $this->platformAdmin(countries: ['KW']);
        $this->line(adminId: $owner['id'], action: 'markets.view', market: null);
        $this->line(adminId: $viewer['id'], action: 'me.view', market: null);
        $token = $this->mintToken($viewer);

        foreach (['?admin_id=' . $owner['id'], '?admin_id[]=' . $owner['id'], '?market=SA', '?scope=all', '?all=1'] as $query) {
            $this->assertSame([$viewer['id']], array_unique(array_column($this->logs($query, $token)->json('data'), 'admin_id')), $query);
        }
    }

    public function test_the_lines_of_the_requests_that_were_just_made_are_there(): void
    {
        $viewer = $this->platformAdmin(countries: ['KW']);
        $token = $this->mintToken($viewer);
        $this->getJson(self::PREFIX . '/me', $this->bearer($token));
        $this->getJson(self::PREFIX . '/overview?market=SA', $this->bearer($token))->assertStatus(403);

        $lines = $this->logs(token: $token)->json('data');

        $this->assertSame(['overview.view', 'me.view'], array_column($lines, 'action'));
        $this->assertSame(['SA', null], array_column($lines, 'market'));
        $this->assertSame([403, 200], array_map(fn (array $line): int => $line['context']['status'], $lines));
    }

    public function test_the_list_is_paginated_newest_first_and_the_size_of_a_page_is_capped(): void
    {
        $owner = $this->platformAdmin();
        $token = $this->mintToken($owner);
        foreach (range(1, 130) as $number) {
            $this->line(adminId: $owner['id'], action: 'overview.view', market: null, tokenId: $number);
        }

        $first = $this->logs('?per_page=50&page=1', $token);
        $second = $this->logs('?per_page=50&page=2', $token);
        $third = $this->logs('?per_page=50&page=3', $token);

        $this->assertSame([50, 50, 30], [count($first->json('data')), count($second->json('data')), count($third->json('data'))]);
        $this->assertSame([true, true, false], [$first->json('meta.has_more'), $second->json('meta.has_more'), $third->json('meta.has_more')]);
        $this->assertSame([130, 81, 80, 31, 30, 1], [$first->json('data.0.token_id'), $first->json('data.49.token_id'), $second->json('data.0.token_id'), $second->json('data.49.token_id'), $third->json('data.0.token_id'), $third->json('data.29.token_id')]);
        $this->assertSame([25, 100, 100], [$this->logs('', $token)->json('meta.per_page'), $this->logs('?per_page=100', $token)->json('meta.per_page'), $this->logs('?per_page=100000', $token)->json('meta.per_page')]);
        $this->assertCount(100, $this->logs('?per_page=100', $token)->json('data'));
        $this->assertSame([1, 25], [$this->logs('?page=0', $token)->json('meta.page'), $this->logs('?per_page=0', $token)->json('meta.per_page')]);
    }

    public function test_a_page_far_beyond_the_end_is_empty_and_is_never_a_costly_scan(): void
    {
        $owner = $this->platformAdmin();
        $token = $this->mintToken($owner);
        $this->line(adminId: $owner['id'], action: 'overview.view', market: null);

        $response = $this->logs('?page=999999999&per_page=100', $token);

        $response->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.page', 1000)->assertJsonPath('meta.has_more', false);
    }

    public function test_the_trail_never_shows_a_secret(): void
    {
        $owner = $this->platformAdmin();
        $token = $this->mintToken($owner);
        $this->postJson(self::PREFIX . '/auth/login', ['email' => $owner['email'], 'password' => $owner['password'], 'device_name' => 'Pixel']);
        $this->postJson(self::PREFIX . '/auth/login', ['email' => $owner['email'], 'password' => 'not-the-password-' . $owner['password'], 'device_name' => 'Pixel']);
        $this->getJson(self::PREFIX . '/me?market=KW', $this->bearer($token));

        $content = $this->logs(token: $token)->getContent();

        foreach ([$owner['password'], $token, hash('sha256', $token), $owner['email'], 'password_fingerprint', 'token_hash'] as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }
        $this->assertStringContainsString('auth.login_failed', $content);
    }

    public function test_reading_the_trail_is_itself_audited(): void
    {
        $owner = $this->platformAdmin();

        $this->readTrail('?page=2&per_page=10', $this->mintToken($owner));

        $line = DB::table('platform_admin_audit_logs')->latest('id')->first();
        $this->assertSame('audit_logs.view', $line->action);
        $this->assertSame($this->sortedByKey(['status' => 200, 'params' => ['page' => '2', 'per_page' => '10']]), $this->contextOf($line));
    }

    private function line(?int $adminId, string $action, ?string $market, ?string $at = null, ?array $context = null, ?int $tokenId = null): void
    {
        DB::table('platform_admin_audit_logs')->insert([
            'admin_id' => $adminId, 'action' => $action, 'market' => $market, 'token_id' => $tokenId,
            'ip' => '203.0.113.7', 'user_agent' => 'TestAgent/1.0', 'context' => $context === null ? null : json_encode($context),
            'created_at' => $at ?? now(),
        ]);
    }

    /** Reads the trail, and takes the line the read itself made out of it, so that the next read sees only what the test put there. */
    private function logs(string $query = '', ?string $token = null): TestResponse
    {
        $response = $this->readTrail($query, $token);
        DB::table('platform_admin_audit_logs')->where('action', 'audit_logs.view')->delete();

        return $response;
    }

    private function readTrail(string $query, ?string $token): TestResponse
    {
        return $this->getJson(self::URL . $query, $this->bearer($token));
    }
}
