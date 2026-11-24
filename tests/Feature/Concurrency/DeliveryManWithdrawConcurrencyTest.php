<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsDeliveryManWithdrawSchema;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Two decisions on one delivery man's withdrawal, sent at the same instant against a real engine.
 *
 * Both screens read the request and the wallet before either of them writes, so each worker starts from the same picture. What has to
 * hold afterwards: the request is settled one way only, the money moves once, and the wallet's totals come from the wallet as it stood
 * inside the transaction that wrote them rather than from the stale read the worker started with.
 *
 * Needs a real MySQL or MariaDB — SQLite in memory is private to one process, so there is nothing to race. The decision that cannot
 * race, a repeat, is measured on both engines by Tests\Feature\Marketplace\DeliveryManWithdrawDecisionTest.
 */
class DeliveryManWithdrawConcurrencyTest extends TestCase
{
    use BuildsDeliveryManWithdrawSchema;
    use RestoresLanguageFiles;

    private const DELIVERY_MAN = 7;

    private const SELLER = 3;

    private const REQUEST = 10;

    private const AMOUNT = 200.0;

    private const BALANCE = 1000.0;

    private const APPROVED = 1;

    private const DENIED = 2;

    private const HOLD_MICROSECONDS = 300000;

    protected function setUp(): void
    {
        parent::setUp();
        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process, so two decisions cannot race.');
        }

        $this->rememberLanguageFiles();
        Http::preventStrayRequests();
        Cache::flush();
        $this->buildDeliveryManWithdrawSchema();
        DB::table('business_settings')->insert(['type' => 'currency_model', 'value' => 'single_currency', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sellers')->insert([
            'id' => self::SELLER, 'f_name' => 'Shop', 'email' => 'shop@example.test', 'password' => 'unused', 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('delivery_men')->insert([
            'id' => self::DELIVERY_MAN, 'seller_id' => self::SELLER, 'f_name' => 'Rider', 'email' => 'rider@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('deliveryman_wallets')->insert([
            'delivery_man_id' => self::DELIVERY_MAN, 'current_balance' => self::BALANCE, 'cash_in_hand' => 0,
            'pending_withdraw' => self::AMOUNT, 'total_withdraw' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('withdraw_requests')->insert([
            'id' => self::REQUEST, 'delivery_man_id' => self::DELIVERY_MAN, 'seller_id' => self::SELLER, 'amount' => self::AMOUNT,
            'approved' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        if (ConcurrentRunner::available()) {
            $this->dropDeliveryManWithdrawTables();
            $this->restoreLanguageFiles();
        }

        parent::tearDown();
    }

    /**
     * @param list<array<string, mixed>> $reports
     * @return list<string> what went wrong in each worker that did not finish, or nothing
     */
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

    /** @return array<string, float> */
    private function wallet(): array
    {
        $row = DB::table('deliveryman_wallets')->where('delivery_man_id', self::DELIVERY_MAN)->first();

        return [
            'current_balance' => (float) $row->current_balance,
            'pending_withdraw' => (float) $row->pending_withdraw,
            'total_withdraw' => (float) $row->total_withdraw,
        ];
    }

    private function decision(): int
    {
        return (int) DB::table('withdraw_requests')->where('id', self::REQUEST)->value('approved');
    }

    public function test_two_approvals_of_one_request_at_once_pay_it_once(): void
    {
        $reports = ConcurrentRunner::run('deliveryman_withdraw.decide_as_admin', [
            ['request_id' => self::REQUEST, 'approved' => self::APPROVED, 'hold_microseconds' => self::HOLD_MICROSECONDS],
            ['request_id' => self::REQUEST, 'approved' => self::APPROVED, 'hold_microseconds' => self::HOLD_MICROSECONDS],
        ]);

        $this->assertSame([], $this->problemsOf($reports));
        $this->assertSame(self::APPROVED, $this->decision());
        $this->assertSame(
            ['current_balance' => 800.0, 'pending_withdraw' => 0.0, 'total_withdraw' => self::AMOUNT],
            $this->wallet(),
            'the amount left the balance once and was recorded as withdrawn once',
        );
        $this->assertTrue(ConcurrentRunner::ranAtTheSameTime($reports), 'the processes really ran at the same time');
    }

    public function test_an_approval_and_a_denial_at_once_settle_the_request_one_way_only(): void
    {
        $reports = ConcurrentRunner::run('deliveryman_withdraw.decide_as_admin', [
            ['request_id' => self::REQUEST, 'approved' => self::APPROVED, 'hold_microseconds' => self::HOLD_MICROSECONDS],
            ['request_id' => self::REQUEST, 'approved' => self::DENIED, 'hold_microseconds' => self::HOLD_MICROSECONDS],
        ]);

        $this->assertSame([], $this->problemsOf($reports));
        $decision = $this->decision();
        $this->assertContains($decision, [self::APPROVED, self::DENIED], 'one of the two decisions landed');
        $this->assertSame(
            $decision === self::APPROVED
                ? ['current_balance' => 800.0, 'pending_withdraw' => 0.0, 'total_withdraw' => self::AMOUNT]
                : ['current_balance' => self::BALANCE, 'pending_withdraw' => 0.0, 'total_withdraw' => 0.0],
            $this->wallet(),
            'and the wallet followed the decision that was stored, not the other one',
        );
        $this->assertTrue(ConcurrentRunner::ranAtTheSameTime($reports), 'the processes really ran at the same time');
    }

    /** The gate is the request's own row, so the two screens cannot each settle it: whichever claims it first is the decision. */
    public function test_the_two_screens_deciding_at_once_settle_it_once(): void
    {
        $reports = ConcurrentRunner::run('deliveryman_withdraw.decide_as_admin', [
            ['request_id' => self::REQUEST, 'approved' => self::APPROVED, 'hold_microseconds' => self::HOLD_MICROSECONDS],
            [
                'op' => 'deliveryman_withdraw.decide_as_vendor', 'request_id' => self::REQUEST, 'seller_id' => self::SELLER,
                'approved' => self::APPROVED, 'hold_microseconds' => self::HOLD_MICROSECONDS,
            ],
        ]);

        $this->assertSame([], $this->problemsOf($reports));
        $this->assertSame(self::APPROVED, $this->decision());
        $this->assertSame(
            ['current_balance' => 800.0, 'pending_withdraw' => 0.0, 'total_withdraw' => self::AMOUNT],
            $this->wallet(),
            'one payment, whichever screen claimed the request',
        );
        $this->assertTrue(ConcurrentRunner::ranAtTheSameTime($reports), 'the processes really ran at the same time');
    }

    /** Two requests of one delivery man, decided at once: neither decision may overwrite the other's totals. */
    public function test_two_requests_of_one_delivery_man_decided_at_once_both_land(): void
    {
        DB::table('deliveryman_wallets')->where('delivery_man_id', self::DELIVERY_MAN)->update(['pending_withdraw' => 350]);
        DB::table('withdraw_requests')->where('id', self::REQUEST)->update(['amount' => 150]);
        DB::table('withdraw_requests')->insert([
            'id' => 11, 'delivery_man_id' => self::DELIVERY_MAN, 'seller_id' => self::SELLER, 'amount' => 200,
            'approved' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $reports = ConcurrentRunner::run('deliveryman_withdraw.decide_as_admin', [
            ['request_id' => self::REQUEST, 'approved' => self::APPROVED, 'hold_microseconds' => self::HOLD_MICROSECONDS],
            ['request_id' => 11, 'approved' => self::APPROVED, 'hold_microseconds' => self::HOLD_MICROSECONDS],
        ]);

        $this->assertSame([], $this->problemsOf($reports));
        $this->assertSame([1, 1], [
            (int) DB::table('withdraw_requests')->where('id', self::REQUEST)->value('approved'),
            (int) DB::table('withdraw_requests')->where('id', 11)->value('approved'),
        ]);
        $this->assertSame(
            ['current_balance' => 650.0, 'pending_withdraw' => 0.0, 'total_withdraw' => 350.0],
            $this->wallet(),
            'both payments left the balance and neither decision overwrote the other',
        );
        $this->assertTrue(ConcurrentRunner::ranAtTheSameTime($reports), 'the processes really ran at the same time');
    }
}
