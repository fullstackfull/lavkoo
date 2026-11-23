<?php

namespace Tests\Feature\PlatformAdmin;

use Illuminate\Support\Facades\DB;

/**
 * The lines the console writes say which account on which machine ran the command. That is for the platform owner; a restricted admin reads their own
 * lines, and the name of a server and of its user is not theirs to see.
 */
class AuditOperatorTest extends PlatformAdminTestCase
{
    private const URL = self::PREFIX . '/audit-logs';

    public function test_the_platform_owner_sees_the_operator_of_a_line(): void
    {
        $owner = $this->platformAdmin();
        $this->consoleLine(adminId: $owner['id'], action: 'mfa.enrolled', context: ['source' => 'console', 'operator' => ['user' => 'deploy', 'host' => 'app-1'], 'replaced' => false]);

        $line = $this->getJson(self::URL, $this->bearer($this->mintToken($owner)))->json('data.0.context');

        $this->assertSame($this->sortedByKey(['user' => 'deploy', 'host' => 'app-1']), $this->sortedByKey($line['operator']), 'a JSON column gives its members back in the order the engine keeps them');
        $this->assertSame('console', $line['source']);
    }

    public function test_a_restricted_admin_sees_the_rest_of_their_own_lines_and_never_the_operator(): void
    {
        $viewer = $this->platformAdmin(countries: ['KW']);
        $this->consoleLine(adminId: $viewer['id'], action: 'mfa.enrolled', context: ['source' => 'console', 'operator' => ['user' => 'deploy', 'host' => 'app-1'], 'replaced' => false]);

        $response = $this->getJson(self::URL, $this->bearer($this->mintToken($viewer)));

        $this->assertSame($this->sortedByKey(['source' => 'console', 'replaced' => false]), $this->sortedByKey($response->json('data.0.context')));
        $this->assertStringNotContainsString('deploy', $response->getContent());
        $this->assertStringNotContainsString('app-1', $response->getContent());
    }

    public function test_a_line_that_was_only_an_operator_has_no_context_left_for_a_restricted_admin(): void
    {
        $viewer = $this->platformAdmin(countries: ['KW']);
        $this->consoleLine(adminId: $viewer['id'], action: 'mfa.status', context: ['operator' => ['user' => 'deploy', 'host' => 'app-1']]);

        $this->assertNull($this->getJson(self::URL, $this->bearer($this->mintToken($viewer)))->json('data.0.context'));
    }

    public function test_only_the_operator_member_of_a_context_is_taken_away_and_the_stored_line_is_not_touched(): void
    {
        $viewer = $this->platformAdmin(countries: ['KW']);
        $this->consoleLine(adminId: $viewer['id'], action: 'grant.updated', context: ['source' => 'console', 'before' => ['operator' => 'kept'], 'operator' => ['user' => 'deploy', 'host' => 'app-1']]);
        $stored = (string) DB::table('platform_admin_audit_logs')->value('context');

        $context = $this->getJson(self::URL, $this->bearer($this->mintToken($viewer)))->json('data.0.context');

        $this->assertSame($this->sortedByKey(['source' => 'console', 'before' => ['operator' => 'kept']]), $this->sortedByKey($context), 'a member of the same name deeper down is somebody else\'s business');
        $this->assertSame($stored, (string) DB::table('platform_admin_audit_logs')->where('action', 'grant.updated')->value('context'));
    }

    /** @param array<string, mixed> $context */
    private function consoleLine(int $adminId, string $action, array $context): void
    {
        DB::table('platform_admin_audit_logs')->insert(['admin_id' => $adminId, 'action' => $action, 'context' => json_encode($context), 'created_at' => now()]);
    }
}
