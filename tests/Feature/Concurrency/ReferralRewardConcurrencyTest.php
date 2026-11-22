<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\BuildsReferralRewardWorld;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\TestCase;

/**
 * Several processes granting one referee's first-order referral reward at the same instant, against a real engine.
 *
 * Two delivery requests for one order, or the delivery-man app and the seller API delivering it together, each reach a
 * referral writer. Only the unique key on referral_reward_grants stands between them and a second credit.
 *
 * Needs a real MySQL or MariaDB: SQLite in memory is private to one process, so there is nothing to race.
 */
class ReferralRewardConcurrencyTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use BuildsReferralRewardWorld;

    protected function setUp(): void
    {
        parent::setUp();
        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }
        $this->startFromAnEmptyTestDatabase();
        $this->setUpReferralRewardWorld();
        $this->migrateReferralRewardGrants();
        $this->makeOrder(id: 100);
    }

    protected function tearDown(): void
    {
        if (ConcurrentRunner::available()) {
            $this->restoreLanguageFiles();
            $this->startFromAnEmptyTestDatabase();
        }
        parent::tearDown();
    }

    public function test_one_reward_granted_by_several_processes_at_once_is_credited_once(): void
    {
        $reports = ConcurrentRunner::run('referral.credit_once', array_fill(0, 5, $this->grantArguments(writer: 'shared')));

        $this->assertSame([], $this->problemsOf($reports));
        $this->assertSame(1, $this->workersWhere($reports, static fn (array $result): bool => $result['credited']), 'exactly one process credited');
        $this->assertSame(1, $this->referralRows()->count(), 'one wallet row, whatever five processes tried');
        $grant = DB::table('referral_reward_grants')->sole();
        $this->assertSame((int) $this->referralRows()->sole()->id, (int) $grant->wallet_transaction_id);
        $this->assertEqualsWithDelta(10.0, $this->walletBalance(self::REFERRER_ID), 0.000001, 'the balance holds a single credit');
        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the processes really ran at the same time');
    }

    public function test_the_shared_writer_and_the_customer_manager_writer_racing_for_one_reward_credit_once(): void
    {
        $arguments = [];
        foreach (['shared', 'customer_manager', 'shared', 'customer_manager'] as $writer) {
            $arguments[] = $this->grantArguments(writer: $writer);
        }

        $reports = ConcurrentRunner::run('referral.credit_once', $arguments);

        $this->assertSame([], $this->problemsOf($reports));
        $this->assertSame(1, $this->workersWhere($reports, static fn (array $result): bool => $result['credited']));
        $this->assertSame(1, $this->referralRows()->count());
        $this->assertSame(1, DB::table('referral_reward_grants')->count());
        $this->assertEqualsWithDelta(10.0, $this->walletBalance(self::REFERRER_ID), 0.000001);
    }

    private function grantArguments(string $writer): array
    {
        return [
            'referee' => self::REFEREE_ID, 'beneficiary' => self::REFERRER_ID, 'order' => 100,
            'writer' => $writer, 'reward_setting' => self::REWARD_SETTING, 'hold_microseconds' => 300000,
        ];
    }

    /** @return list<string> */
    private function problemsOf(array $reports): array
    {
        $problems = [];
        foreach ($reports as $index => $report) {
            if (!($report['ok'] ?? false)) {
                $problems[] = "worker {$index}: " . (string) ($report['error'] ?? '?') . ' ' . (string) ($report['message'] ?? '');
            }
        }

        return $problems;
    }

    private function workersWhere(array $reports, callable $matches): int
    {
        return count(array_filter($reports, static fn (array $report): bool => ($report['ok'] ?? false) && is_array($report['result'] ?? null) && $matches($report['result'])));
    }
}
