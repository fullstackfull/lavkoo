<?php

namespace Tests\Feature\PlatformAdmin;

use App\Services\PlatformAdmin\PlatformAdminAudit;
use App\Services\PlatformAdmin\PlatformAdminAuditVerifier;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Whatever takes the tail of the audit trail ends a transaction that may be the victim of a deadlock with another that holds the rows the other way round. The
 * database rolls it back; it is done again, up to three times, and only then does the failure reach the caller.
 */
class DeadlockRetryTest extends MfaTestCase
{
    public function test_a_line_whose_transaction_is_the_victim_of_a_deadlock_is_written_by_the_next_try_and_only_once(): void
    {
        $this->deadlockOnTheNextInserts(table: 'platform_admin_audit_logs', times: 1);

        app(PlatformAdminAudit::class)->record(action: 'grant.created', adminId: 7);

        $this->assertSame(1, DB::table('platform_admin_audit_logs')->count());
        $this->assertTrue(app(PlatformAdminAuditVerifier::class)->verify()->intact);
    }

    public function test_a_deadlock_that_keeps_happening_reaches_the_caller_after_three_tries_and_leaves_nothing(): void
    {
        $this->deadlockOnTheNextInserts(table: 'platform_admin_audit_logs', times: 3);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Deadlock found');

        try {
            app(PlatformAdminAudit::class)->record(action: 'grant.created', adminId: 7);
        } finally {
            $this->assertSame(0, DB::table('platform_admin_audit_logs')->count());
            $this->assertTrue(app(PlatformAdminAuditVerifier::class)->verify()->intact);
        }
    }

    public function test_a_second_step_whose_transaction_is_the_victim_of_a_deadlock_is_done_again_and_its_code_is_used_once(): void
    {
        $this->travelTo('2026-11-17 10:00:15');
        $admin = $this->enrolledAdmin();
        $challenge = $this->challengeFor($admin);
        $this->deadlockOnTheNextInserts(table: 'platform_admin_tokens', times: 1);

        $this->second(['challenge' => $challenge, 'code' => $this->totpFor($admin['factor'])])->assertOk()->assertJsonPath('data.mfa.method', 'totp');

        $this->assertSame(1, DB::table('platform_admin_tokens')->count());
        $this->assertSame(1, DB::table('platform_admin_mfa_challenges')->whereNotNull('consumed_at')->count());
        $this->assertTrue(app(PlatformAdminAuditVerifier::class)->verify()->intact);
    }

    public function test_a_one_step_sign_in_whose_transaction_is_the_victim_of_a_deadlock_is_done_again(): void
    {
        config(['gcc.platform_admin.mfa.required' => false]);
        $admin = $this->platformAdmin();
        $this->deadlockOnTheNextInserts(table: 'platform_admin_tokens', times: 2);

        $this->postJson(self::LOGIN, ['email' => $admin['email'], 'password' => $admin['password'], 'device_name' => 'Pixel'])->assertOk();

        $this->assertSame(1, DB::table('platform_admin_tokens')->count());
    }

    public function test_a_challenge_whose_transaction_is_the_victim_of_a_deadlock_is_given_by_the_next_try_and_only_once(): void
    {
        $admin = $this->enrolledAdmin();
        $this->deadlockOnTheNextInserts(table: 'platform_admin_mfa_challenges', times: 1);

        $this->login($admin)->assertOk()->assertJsonPath('data.mfa_required', true);

        $this->assertSame(1, DB::table('platform_admin_mfa_challenges')->count());
    }

    private function deadlockOnTheNextInserts(string $table, int $times): void
    {
        DB::beforeExecuting(static function (string $query) use ($table, &$times): void {
            if ($times > 0 && str_starts_with($query, 'insert into') && str_contains($query, $table)) {
                $times--;

                throw new QueryException(connectionName: 'testing', sql: $query, bindings: [], previous: new Exception('Deadlock found when trying to get lock; try restarting transaction'));
            }
        });
    }
}
