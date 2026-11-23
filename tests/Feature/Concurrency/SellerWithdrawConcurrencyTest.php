<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsSellerWithdrawSchema;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Two requests that move one seller's wallet, from the seller or from an admin deciding a withdraw, sent at the same instant against a real engine.
 *
 * Every worker reads the wallet and the withdraw request before any of them writes. The totals each one writes must still come from
 * the wallet as it stands inside its own transaction, so the wallet and the withdraw requests agree whichever worker lands first.
 *
 * Needs a real MySQL or MariaDB — SQLite in memory is private to one process, so there is nothing to race.
 */
class SellerWithdrawConcurrencyTest extends TestCase
{
    use BuildsSellerWithdrawSchema;
    use RestoresLanguageFiles;

    private const SELLER_ID = 1;

    private const TOKEN = 'race-token-0123456789-0123456789-0123456789';

    private const METHOD_ID = 1;

    private const HOLD_MICROSECONDS = 300000;

    protected function setUp(): void
    {
        parent::setUp();
        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process, so two requests cannot race.');
        }

        $this->rememberLanguageFiles();
        Http::preventStrayRequests();
        Cache::flush();
        $this->buildSellerWithdrawSchema();
        DB::table('business_settings')->insert(['type' => 'currency_model', 'value' => 'single_currency', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sellers')->insert([
            'id' => self::SELLER_ID, 'f_name' => 'Seller', 'email' => 'seller@example.test', 'password' => 'unused', 'status' => 'approved',
            'auth_token' => self::TOKEN, 'bank_name' => 'Bank', 'account_no' => 'SA00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('withdrawal_methods')->insert([
            'id' => self::METHOD_ID, 'method_name' => 'Bank', 'method_fields' => json_encode([['input_name' => 'account']]),
            'is_active' => 1, 'vendor_status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        if (ConcurrentRunner::available()) {
            $this->dropSellerWithdrawTables();
            $this->restoreLanguageFiles();
        }

        parent::tearDown();
    }

    public function test_an_update_and_a_close_of_one_pending_request_at_once_return_the_whole_amount(): void
    {
        $this->insertWallet(totalEarning: 800, pendingWithdraw: 200);
        $this->insertRequest(10, amount: 200);

        $reports = ConcurrentRunner::run('seller_withdraw.update', [
            ['token' => self::TOKEN, 'request_id' => 10, 'method_id' => self::METHOD_ID, 'amount' => 50, 'hold_microseconds' => self::HOLD_MICROSECONDS],
            ['op' => 'seller_withdraw.close', 'token' => self::TOKEN, 'request_id' => 10, 'hold_microseconds' => self::HOLD_MICROSECONDS],
        ]);

        $this->assertSame([], $this->problemsOf($reports));
        $this->assertSame(200, $reports[1]['result']['status'], 'the close lands whether it runs before or after the update');
        $this->assertNull(DB::table('withdraw_requests')->find(10));
        $this->assertSame(['total_earning' => 1000.0, 'pending_withdraw' => 0.0], $this->wallet(), 'the wallet holds the whole amount again');
        $this->assertTrue(ConcurrentRunner::ranAtTheSameTime($reports), 'the processes really ran at the same time');
    }

    public function test_two_updates_of_one_pending_request_at_once_keep_the_wallet_and_the_request_in_step(): void
    {
        $this->insertWallet(totalEarning: 800, pendingWithdraw: 200);
        $this->insertRequest(10, amount: 200);

        $reports = ConcurrentRunner::run('seller_withdraw.update', [
            ['token' => self::TOKEN, 'request_id' => 10, 'method_id' => self::METHOD_ID, 'amount' => 50, 'hold_microseconds' => self::HOLD_MICROSECONDS],
            ['token' => self::TOKEN, 'request_id' => 10, 'method_id' => self::METHOD_ID, 'amount' => 70, 'hold_microseconds' => self::HOLD_MICROSECONDS],
        ]);

        $this->assertSame([], $this->problemsOf($reports));
        $this->assertSame([200, 200], array_column(array_column($reports, 'result'), 'status'));
        $amount = (float) DB::table('withdraw_requests')->where('id', 10)->value('amount');
        $this->assertContains($amount, [50.0, 70.0]);
        $this->assertSame(['total_earning' => 1000.0 - $amount, 'pending_withdraw' => $amount], $this->wallet(), 'pending matches the request that won');
        $this->assertTrue(ConcurrentRunner::ranAtTheSameTime($reports), 'the processes really ran at the same time');
    }

    public function test_closing_two_pending_requests_of_one_seller_at_once_returns_both_amounts(): void
    {
        $this->insertWallet(totalEarning: 600, pendingWithdraw: 400);
        $this->insertRequest(10, amount: 150);
        $this->insertRequest(11, amount: 250);

        $reports = ConcurrentRunner::run('seller_withdraw.close', [
            ['token' => self::TOKEN, 'request_id' => 10, 'hold_microseconds' => self::HOLD_MICROSECONDS],
            ['token' => self::TOKEN, 'request_id' => 11, 'hold_microseconds' => self::HOLD_MICROSECONDS],
        ]);

        $this->assertSame([], $this->problemsOf($reports));
        $this->assertSame([200, 200], array_column(array_column($reports, 'result'), 'status'));
        $this->assertSame(0, DB::table('withdraw_requests')->count());
        $this->assertSame(['total_earning' => 1000.0, 'pending_withdraw' => 0.0], $this->wallet(), 'neither close overwrote the other');
        $this->assertTrue(ConcurrentRunner::ranAtTheSameTime($reports), 'the processes really ran at the same time');
    }

    public function test_two_creates_that_each_fit_the_balance_alone_but_not_together_give_exactly_one_request(): void
    {
        $this->insertWallet(totalEarning: 100, pendingWithdraw: 0);

        $reports = ConcurrentRunner::run('seller_withdraw.create', [
            ['token' => self::TOKEN, 'method_id' => self::METHOD_ID, 'amount' => 60, 'hold_microseconds' => self::HOLD_MICROSECONDS],
            ['token' => self::TOKEN, 'method_id' => self::METHOD_ID, 'amount' => 60, 'hold_microseconds' => self::HOLD_MICROSECONDS],
        ]);

        $this->assertSame([], $this->problemsOf($reports));
        $statuses = array_column(array_column($reports, 'result'), 'status');
        sort($statuses);
        $this->assertSame([200, 403], $statuses, 'one create is accepted and the other is refused');
        $this->assertSame(1, DB::table('withdraw_requests')->where('seller_id', self::SELLER_ID)->count());
        $this->assertSame(['total_earning' => 40.0, 'pending_withdraw' => 60.0], $this->wallet(), 'the wallet holds back one request only');
        $this->assertTrue(ConcurrentRunner::ranAtTheSameTime($reports), 'the processes really ran at the same time');
    }

    public function test_two_admin_approvals_of_one_request_at_once_pay_it_once(): void
    {
        $this->insertWallet(totalEarning: 800, pendingWithdraw: 200);
        $this->insertRequest(10, amount: 200);

        $reports = ConcurrentRunner::run('seller_withdraw.decide', [
            ['request_id' => 10, 'approved' => 1, 'hold_microseconds' => self::HOLD_MICROSECONDS],
            ['request_id' => 10, 'approved' => 1, 'hold_microseconds' => self::HOLD_MICROSECONDS],
        ]);

        $this->assertSame([], $this->problemsOf($reports));
        $this->assertSame(1, (int) DB::table('withdraw_requests')->where('id', 10)->value('approved'));
        $this->assertSame(['total_earning' => 800.0, 'pending_withdraw' => 0.0, 'withdrawn' => 200.0], $this->walletWithWithdrawn(), 'withdrawn grew by the amount once');
        $this->assertTrue(ConcurrentRunner::ranAtTheSameTime($reports), 'the processes really ran at the same time');
    }

    public function test_an_approval_and_a_denial_of_one_request_at_once_settle_it_one_way_only(): void
    {
        $this->insertWallet(totalEarning: 800, pendingWithdraw: 200);
        $this->insertRequest(10, amount: 200);

        $reports = ConcurrentRunner::run('seller_withdraw.decide', [
            ['request_id' => 10, 'approved' => 1, 'hold_microseconds' => self::HOLD_MICROSECONDS],
            ['request_id' => 10, 'approved' => 2, 'hold_microseconds' => self::HOLD_MICROSECONDS],
        ]);

        $this->assertSame([], $this->problemsOf($reports));
        $approved = (int) DB::table('withdraw_requests')->where('id', 10)->value('approved');
        $this->assertContains($approved, [1, 2]);
        $expected = $approved === 1
            ? ['total_earning' => 800.0, 'pending_withdraw' => 0.0, 'withdrawn' => 200.0]
            : ['total_earning' => 1000.0, 'pending_withdraw' => 0.0, 'withdrawn' => 0.0];
        $this->assertSame($expected, $this->walletWithWithdrawn(), 'the wallet follows the decision that was stored');
        $this->assertTrue(ConcurrentRunner::ranAtTheSameTime($reports), 'the processes really ran at the same time');
    }

    public function test_repeating_an_approval_after_it_landed_is_refused_and_changes_nothing(): void
    {
        $this->insertWallet(totalEarning: 800, pendingWithdraw: 200);
        $this->insertRequest(10, amount: 200);

        $first = ConcurrentRunner::run('seller_withdraw.decide', [['request_id' => 10, 'approved' => 1, 'note' => 'first']]);
        $this->assertSame([], $this->problemsOf($first));
        $afterFirst = [$this->walletWithWithdrawn(), (array) DB::table('withdraw_requests')->find(10)];

        $repeat = ConcurrentRunner::run('seller_withdraw.decide', [['request_id' => 10, 'approved' => 1, 'note' => 'repeat']]);

        $this->assertSame([], $this->problemsOf($repeat));
        $this->assertSame(302, $repeat[0]['result']['status']);
        $this->assertSame($afterFirst, [$this->walletWithWithdrawn(), (array) DB::table('withdraw_requests')->find(10)]);
        $this->assertSame(['total_earning' => 800.0, 'pending_withdraw' => 0.0, 'withdrawn' => 200.0], $afterFirst[0]);
        $this->assertSame('first', $afterFirst[1]['transaction_note']);
    }

    /** @param list<array{ok: bool, error?: string, message?: string}> $reports */
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

    /** @return array{total_earning: float, pending_withdraw: float} */
    private function wallet(): array
    {
        $row = DB::table('seller_wallets')->where('seller_id', self::SELLER_ID)->first();

        return ['total_earning' => (float) $row->total_earning, 'pending_withdraw' => (float) $row->pending_withdraw];
    }

    /** @return array{total_earning: float, pending_withdraw: float, withdrawn: float} */
    private function walletWithWithdrawn(): array
    {
        $withdrawn = (float) DB::table('seller_wallets')->where('seller_id', self::SELLER_ID)->value('withdrawn');

        return [...$this->wallet(), 'withdrawn' => $withdrawn];
    }

    private function insertWallet(float $totalEarning, float $pendingWithdraw): void
    {
        DB::table('seller_wallets')->insert([
            'seller_id' => self::SELLER_ID, 'total_earning' => $totalEarning, 'pending_withdraw' => $pendingWithdraw, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertRequest(int $id, float $amount): void
    {
        DB::table('withdraw_requests')->insert([
            'id' => $id, 'seller_id' => self::SELLER_ID, 'amount' => $amount, 'withdrawal_method_id' => self::METHOD_ID,
            'withdrawal_method_fields' => json_encode(['method_name' => 'Bank']), 'approved' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
