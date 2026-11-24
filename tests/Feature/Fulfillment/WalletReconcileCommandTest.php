<?php

namespace Tests\Feature\Fulfillment;

use App\Services\Fulfillment\Readiness\Compatibility\CompatibilityReport;
use App\Services\Fulfillment\Readiness\Compatibility\ReadOnlyProbe;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * `wallet:reconcile`: the read-only proof that the balance each customer carries equals the ledger behind it, with every difference in a class.
 * It runs on SQLite and, because it sits in this directory, on MySQL 8 and MariaDB in CI: the arithmetic is the database's own.
 */
class WalletReconcileCommandTest extends FulfillmentTestCase
{
    /** Values that must never appear anywhere in the output. */
    private const CANARIES = ['CANARY-NAME-Wq4', 'canary.wallet', '0599977123'];

    private const MORNING = '2026-11-01 10:00:00';

    /** @var list<string> */
    private array $evidenceFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->restoreTheTablesOfABalance();
    }

    protected function tearDown(): void
    {
        foreach ($this->evidenceFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    /** The core test schema holds orders; a restored copy also carries the users, the two ledgers and the group an order belongs to. */
    private function restoreTheTablesOfABalance(): void
    {
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->decimal('wallet_balance', 40, 20)->nullable();
            $table->decimal('loyalty_point', 18, 4)->nullable()->default(0);
            $table->timestamps();
        });

        Schema::create('wallet_transactions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->uuid('transaction_id');
            $table->decimal('credit', 40, 20)->default(0);
            $table->decimal('debit', 40, 20)->default(0);
            $table->decimal('admin_bonus', 40, 20)->default(0);
            $table->decimal('balance', 40, 20)->default(0);
            $table->string('transaction_type', 191)->nullable();
            $table->string('payment_method')->nullable();
            $table->string('reference', 191)->nullable();
            $table->timestamps();
        });

        Schema::create('loyalty_point_transactions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->uuid('transaction_id');
            $table->decimal('credit', 24, 3)->default(0);
            $table->decimal('debit', 24, 3)->default(0);
            $table->decimal('balance', 24, 3)->default(0);
            $table->string('reference', 191)->nullable();
            $table->string('transaction_type', 191)->nullable();
            $table->timestamps();
        });

        Schema::table('orders', static function (Blueprint $table): void {
            $table->string('order_group_id')->nullable();
        });
    }

    private function user(int $id, string|int|null $wallet = 0, string|int|null $loyalty = 0): int
    {
        DB::table('users')->insert([
            'id' => $id,
            'name' => 'CANARY-NAME-Wq4 ' . $id,
            'email' => "canary.wallet{$id}@leak.test",
            'phone' => '0599977123',
            'wallet_balance' => $wallet,
            'loyalty_point' => $loyalty,
            'created_at' => self::MORNING,
            'updated_at' => self::MORNING,
        ]);

        return $id;
    }

    private function walletRow(?int $user, string $credit = '0', string $debit = '0', string $bonus = '0', string $type = 'add_fund', string $at = self::MORNING, ?string $reference = null): int
    {
        return DB::table('wallet_transactions')->insertGetId([
            'user_id' => $user,
            'transaction_id' => (string) Str::uuid(),
            'credit' => $credit,
            'debit' => $debit,
            'admin_bonus' => $bonus,
            'balance' => 0,
            'transaction_type' => $type,
            'reference' => $reference,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function loyaltyRow(?int $user, string $credit = '0', string $debit = '0', string $type = 'order_place'): int
    {
        return DB::table('loyalty_point_transactions')->insertGetId([
            'user_id' => $user,
            'transaction_id' => (string) Str::uuid(),
            'credit' => $credit,
            'debit' => $debit,
            'balance' => 0,
            'transaction_type' => $type,
            'created_at' => self::MORNING,
            'updated_at' => self::MORNING,
        ]);
    }

    private function walletOrder(int $id, int $customer, string $amount, string $at = self::MORNING, ?string $group = null, string $method = 'pay_by_wallet'): void
    {
        DB::table('orders')->insert([
            'id' => $id,
            'customer_id' => $customer,
            'payment_method' => $method,
            'order_amount' => $amount,
            'order_group_id' => $group,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: string} exit code, decoded report, raw output */
    private function reconcile(array $options = []): array
    {
        $exit = Artisan::call('wallet:reconcile', ['--json' => true] + $options);
        $output = Artisan::output();

        return [$exit, (array) json_decode($output, true), $output];
    }

    /** @return array<string, mixed> */
    private function check(array $report, string $section, string $name): array
    {
        foreach ($report['checks'] ?? [] as $check) {
            if ($check['section'] === $section && $check['name'] === $name) {
                return $check;
            }
        }

        $this->fail("no check '{$name}' in section '{$section}'; the report has: " . implode(', ', array_map(static fn (array $check): string => $check['section'] . '/' . $check['name'], $report['checks'] ?? [])));
    }

    private function hasCheck(array $report, string $section, string $name): bool
    {
        foreach ($report['checks'] ?? [] as $check) {
            if ($check['section'] === $section && $check['name'] === $name) {
                return true;
            }
        }

        return false;
    }

    private function unexplained(array $report, string $section = 'wallet'): array
    {
        return $this->check($report, $section, 'class F: unexplained differences');
    }

    private function evidenceFile(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'wallet-evidence-');
        file_put_contents($path, $contents);
        $this->evidenceFiles[] = $path;

        return $path;
    }

    /** @return array<string, int|string> */
    private function fingerprint(): array
    {
        return [
            'users' => DB::table('users')->count(),
            'wallet_transactions' => DB::table('wallet_transactions')->count(),
            'loyalty_point_transactions' => DB::table('loyalty_point_transactions')->count(),
            'orders' => DB::table('orders')->count(),
            'balances' => (string) DB::table('users')->sum('wallet_balance'),
            'points' => (string) DB::table('users')->sum('loyalty_point'),
        ];
    }

    private function failedChecks(array $report): string
    {
        return (string) json_encode(array_values(array_filter($report['checks'] ?? [], static fn (array $check): bool => $check['status'] === 'fail')));
    }

    // ── a copy that reconciles ────────────────────────────────────────────────────────────────────────────────

    public function test_balances_that_equal_their_ledgers_pass_and_the_run_writes_nothing(): void
    {
        $this->user(1, '75.5', 12);
        $this->walletRow(1, credit: '100', bonus: '5.5');
        $this->walletRow(1, debit: '30', type: 'order_place');
        $this->walletOrder(100, 1, '30');
        $this->user(2, '0', 0);
        $this->user(3, '20.25', 0);
        $lastRow = $this->walletRow(3, credit: '20.25');
        $this->loyaltyRow(1, credit: '20');
        $this->loyaltyRow(1, debit: '8');
        $before = $this->fingerprint();

        [$exit, $report] = $this->reconcile();

        $this->assertSame(0, $exit, $this->failedChecks($report));
        $this->assertSame('pass', $this->unexplained($report, 'wallet')['status']);
        $this->assertSame('pass', $this->unexplained($report, 'loyalty')['status']);
        $compared = $this->check($report, 'wallet', 'ledger and balances compared');
        $this->assertSame(['ledger_rows' => 3, 'users_compared' => 3, 'equal' => 3, 'snapshot_ledger_id' => $lastRow], $compared['facts']);
        $this->assertSame('pass', $this->check($report, 'wallet', 'wallet payments matched to the orders they paid')['status']);
        $this->assertSame('pass', $this->check($report, 'read_only', 'nothing was written')['status']);
        $this->assertSame(0, $report['read_only_proof']['write_statements']);
        $this->assertSame($before, $this->fingerprint());
    }

    public function test_a_second_run_over_the_same_copy_gives_the_same_report(): void
    {
        $this->user(1, '10', 0);
        $this->walletRow(1, credit: '12');

        [, $first] = $this->reconcile();
        [, $second] = $this->reconcile();

        $this->assertSame($first, $second);
    }

    public function test_the_table_output_names_the_verdict_and_what_it_does_not_decide(): void
    {
        $this->artisan('wallet:reconcile')
            ->expectsOutputToContain('Verdict:')
            ->expectsOutputToContain('Class F blocks a migration of balances')
            ->assertSuccessful();
    }

    public function test_the_json_scope_is_the_one_of_this_command_and_the_scope_of_the_other_reports_is_unchanged(): void
    {
        [, $report] = $this->reconcile();

        $this->assertStringContainsString('Stored balances against the ledger rows behind them', $report['scope']);
        $this->assertStringContainsString('does not say which side of a difference is right', $report['scope']);
        $this->assertStringContainsString('Structure and the aggregate shape of the data only', (new CompatibilityReport(checks: [], environment: [], sample: 0, readOnlyProof: null))->toArray()['scope']);
    }

    // ── a difference ──────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_difference_above_the_tolerance_that_nobody_explained_fails_the_run(): void
    {
        $this->user(1, '50', 0);
        $this->walletRow(1, credit: '40');
        $this->user(2, '10', 0);
        $this->walletRow(2, credit: '10');

        [$exit, $report] = $this->reconcile();

        $this->assertSame(1, $exit);
        $this->assertSame('not_compatible', $report['verdict']);
        $check = $this->unexplained($report);
        $this->assertSame('fail', $check['status']);
        $this->assertSame(1, $check['facts']['users']);
        $this->assertSame('10', $check['facts']['net']);
        $this->assertStringContainsString('no migration may start', $check['detail']);
        $this->assertSame('pass', $this->unexplained($report, 'loyalty')['status'], 'the other ledger is judged by itself');
    }

    public function test_the_bonus_of_an_add_fund_counts_as_a_credit_and_a_debit_as_a_withdrawal(): void
    {
        $this->user(1, '47.5', 0);
        $this->walletRow(1, credit: '50', bonus: '2.5');
        $this->walletRow(1, debit: '5', type: 'order_place');

        [, $report] = $this->reconcile(['--skip-order-match' => true]);

        $this->assertSame('pass', $this->unexplained($report)['status']);
        $this->assertSame(1, $this->check($report, 'wallet', 'ledger and balances compared')['facts']['equal']);
    }

    public function test_a_difference_of_at_most_a_millionth_is_float_residue_and_a_wider_tolerance_is_the_operators_to_set(): void
    {
        $this->user(1, '100.0000004', 0);
        $this->walletRow(1, credit: '100');
        $this->user(2, '50.003', 0);
        $this->walletRow(2, credit: '50');

        [$exit, $report] = $this->reconcile();

        $residue = $this->check($report, 'wallet', 'class D: float residue');
        $this->assertSame(1, $exit, 'half a thousandth is not residue under the default');
        $this->assertSame(1, $residue['facts']['users']);
        $this->assertSame('0.0000004', $residue['facts']['sum']);
        $this->assertSame('0.000001', $residue['facts']['tolerance']);
        $this->assertSame(1, $this->unexplained($report)['facts']['users']);
        $this->assertSame('0.003', $this->unexplained($report)['facts']['net']);

        [$wideExit, $wide] = $this->reconcile(['--tolerance' => '0.005']);

        $this->assertSame(0, $wideExit, $this->failedChecks($wide));
        $this->assertSame(2, $this->check($wide, 'wallet', 'class D: float residue')['facts']['users']);
        $this->assertSame('0.005', $this->check($wide, 'wallet', 'class D: float residue')['facts']['tolerance'], 'a widened tolerance is written into the report');
        $this->assertSame('pass', $this->unexplained($wide)['status']);

        [$strictExit, $strict] = $this->reconcile(['--tolerance' => '0.0000001']);

        $this->assertSame(1, $strictExit);
        $this->assertSame(2, $this->unexplained($strict)['facts']['users']);
        $this->assertSame(0, $this->check($strict, 'wallet', 'class D: float residue')['facts']['users']);
    }

    public function test_a_difference_below_zero_counts_the_same_as_one_above(): void
    {
        $this->user(1, '5', 0);
        $this->walletRow(1, credit: '9');

        [$exit, $report] = $this->reconcile();

        $this->assertSame(1, $exit);
        $this->assertSame('-4', $this->unexplained($report)['facts']['net']);
    }

    public function test_the_users_named_in_the_evidence_file_are_class_e_and_the_others_still_fail(): void
    {
        $this->user(1, '30', 0);
        $this->walletRow(1, credit: '20');
        $this->user(2, '15', 0);
        $this->walletRow(2, credit: '10');
        $this->user(3, '8', 0);
        $this->walletRow(3, credit: '8');
        $one = $this->evidenceFile("# refunds booked by hand\n1   # ticket 4521\n\n");
        $both = $this->evidenceFile("1\n2\n");

        [$exit, $report] = $this->reconcile(['--explained' => $one]);

        $this->assertSame(1, $exit);
        $this->assertSame(1, $this->check($report, 'wallet', 'class E: differences explained with evidence')['facts']['users']);
        $this->assertSame(1, $this->unexplained($report)['facts']['users']);
        $this->assertSame('5', $this->unexplained($report)['facts']['net']);

        [$allExit, $all] = $this->reconcile(['--explained' => $both]);

        $this->assertSame(0, $allExit, $this->failedChecks($all));
        $this->assertSame(2, $this->check($all, 'wallet', 'class E: differences explained with evidence')['facts']['users']);
        $this->assertSame('pass', $this->unexplained($all)['status']);
    }

    public function test_an_evidence_file_that_is_not_a_list_of_user_ids_is_refused_before_anything_is_read(): void
    {
        $bad = $this->evidenceFile("1\nnot-an-id\n3\n");
        DB::enableQueryLog();

        $exit = Artisan::call('wallet:reconcile', ['--explained' => $bad]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Line 2 of the evidence file is not a user id', Artisan::output());
        $this->assertSame([], DB::getQueryLog());

        $missing = Artisan::call('wallet:reconcile', ['--explained' => $bad . '.absent']);

        $this->assertSame(1, $missing);
        $this->assertStringContainsString('--explained must name a file that can be read', Artisan::output());
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_an_evidence_file_cannot_name_more_users_than_the_limit(): void
    {
        $tooMany = $this->evidenceFile(implode("\n", range(1, 10001)));

        $exit = Artisan::call('wallet:reconcile', ['--explained' => $tooMany]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('the most it may name is 10000', Artisan::output());
    }

    // ── the classes that are counted and not judged ───────────────────────────────────────────────────────────

    public function test_ledger_rows_with_no_owner_or_of_a_user_that_is_gone_are_counted_and_not_compared(): void
    {
        $this->user(1, '4', 0);
        $this->walletRow(1, credit: '4');
        $this->walletRow(null, credit: '7');
        $this->walletRow(99, credit: '5');
        $this->walletRow(99, credit: '3');

        [$exit, $report] = $this->reconcile();

        $this->assertSame(0, $exit, $this->failedChecks($report));
        $this->assertSame(['rows' => 1, 'net' => '7'], $this->check($report, 'wallet', 'class A: ledger rows with no owner')['facts']);
        $this->assertSame(['rows' => 2, 'users' => 1, 'net' => '8'], $this->check($report, 'wallet', 'class B: ledger rows of a user that no longer exists')['facts']);
        $this->assertSame(1, $this->check($report, 'wallet', 'ledger and balances compared')['facts']['users_compared']);
    }

    public function test_a_null_balance_is_class_c_and_a_user_with_movements_is_compared_as_zero(): void
    {
        $this->user(1, null, 0);
        $this->user(2, null, 0);
        $this->walletRow(2, credit: '12');

        [$exit, $report] = $this->reconcile();

        $null = $this->check($report, 'wallet', 'class C: a NULL balance');
        $this->assertSame(['without_movement' => 1, 'with_movement' => 1], $null['facts']);
        $this->assertSame(1, $exit, 'a NULL balance over twelve units of movement is a difference');
        $this->assertSame('-12', $this->unexplained($report)['facts']['net']);
    }

    public function test_the_loyalty_ledger_is_compared_the_same_way_and_alone_when_asked(): void
    {
        $this->user(1, '0', 30);
        $this->loyaltyRow(1, credit: '20');

        [$exit, $report] = $this->reconcile(['--ledger' => 'loyalty']);

        $this->assertSame(1, $exit);
        $this->assertSame('fail', $this->unexplained($report, 'loyalty')['status']);
        $this->assertSame('10', $this->unexplained($report, 'loyalty')['facts']['net']);
        $this->assertFalse($this->hasCheck($report, 'wallet', 'class F: unexplained differences'));
        $this->assertFalse($this->hasCheck($report, 'wallet', 'wallet payments matched to the orders they paid'), 'the order match belongs to the wallet');

        [$walletExit, $walletOnly] = $this->reconcile(['--ledger' => 'wallet']);

        $this->assertSame(0, $walletExit, $this->failedChecks($walletOnly));
        $this->assertFalse($this->hasCheck($walletOnly, 'loyalty', 'class F: unexplained differences'));

        [, $both] = $this->reconcile();
        $this->assertTrue($this->hasCheck($both, 'wallet', 'class F: unexplained differences'));
        $this->assertTrue($this->hasCheck($both, 'loyalty', 'class F: unexplained differences'));
    }

    public function test_a_cancellation_of_a_pos_order_refunded_twice_is_reported(): void
    {
        $this->user(1, '75', 0);
        $this->walletRow(1, credit: '25', type: 'order_refund', reference: 'pos_order_cancel_9');
        $this->walletRow(1, credit: '25', type: 'order_refund', reference: 'pos_order_cancel_9');
        $this->walletRow(1, credit: '25', type: 'order_refund', reference: 'pos_order_cancel_10');

        [$exit, $report] = $this->reconcile();

        $this->assertSame(0, $exit, $this->failedChecks($report));
        $check = $this->check($report, 'wallet', 'refunds paid more than once');
        $this->assertSame('warn', $check['status']);
        $this->assertSame(['refunds' => 1, 'extra_rows' => 1], $check['facts']);
    }

    /**
     * The same check now sees a refund request paid twice, which it could not while every refund credit carried one literal (RF-02).
     */
    public function test_a_refund_request_credited_twice_is_reported(): void
    {
        $this->user(1, '75', 0);
        $this->walletRow(1, credit: '25', type: 'order_refund', reference: 'refund_request_4');
        $this->walletRow(1, credit: '25', type: 'order_refund', reference: 'refund_request_4');
        $this->walletRow(1, credit: '25', type: 'order_refund', reference: 'refund_request_5');

        [$exit, $report] = $this->reconcile();

        $this->assertSame(0, $exit, $this->failedChecks($report));
        $check = $this->check($report, 'wallet', 'refunds paid more than once');
        $this->assertSame('warn', $check['status']);
        $this->assertSame(['refunds' => 1, 'extra_rows' => 1], $check['facts']);
    }

    /** The literal every refund credit carried before RF-02 is left out: thousands of rows share it and none of them is a repeat of one refund. */
    public function test_the_old_shared_literal_is_not_reported_as_a_repeat(): void
    {
        $this->user(1, '75', 0);
        $this->walletRow(1, credit: '25', type: 'order_refund', reference: 'order_refund');
        $this->walletRow(1, credit: '25', type: 'order_refund', reference: 'order_refund');
        $this->walletRow(1, credit: '25', type: 'order_refund', reference: 'order_refund');

        [, $report] = $this->reconcile();

        $this->assertSame('pass', $this->check($report, 'wallet', 'refunds paid more than once')['status']);
    }

    public function test_the_legacy_reward_point_tables_are_reported_when_they_hold_rows(): void
    {
        [, $absent] = $this->reconcile();
        $this->assertSame('pass', $this->check($absent, 'wallet', 'the legacy customer wallet tables')['status']);

        Schema::create('customer_wallets', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->decimal('balance', 24, 3)->default(0);
            $table->decimal('royality_points', 24, 3)->default(0);
        });
        Schema::create('customer_wallet_histories', static function (Blueprint $table): void {
            $table->id();
            $table->decimal('transaction_amount', 24, 3)->default(0);
        });
        [, $empty] = $this->reconcile();
        $this->assertSame('pass', $this->check($empty, 'wallet', 'the legacy customer wallet tables')['status']);
        $this->assertStringContainsString('exist and are empty', $this->check($empty, 'wallet', 'the legacy customer wallet tables')['detail']);

        DB::table('customer_wallets')->insert(['customer_id' => 1, 'balance' => '12.5', 'royality_points' => '40']);
        DB::table('customer_wallet_histories')->insert([['transaction_amount' => '5'], ['transaction_amount' => '7.5']]);
        [$exit, $held] = $this->reconcile();

        $check = $this->check($held, 'wallet', 'the legacy customer wallet tables');
        $this->assertSame(0, $exit, 'a second balance is a warning to judge, not a failure');
        $this->assertSame('warn', $check['status']);
        $this->assertSame(1, $check['facts']['customer_wallets_rows']);
        $this->assertSame('12.5', $check['facts']['customer_wallets_balance']);
        $this->assertSame('40', $check['facts']['customer_wallets_points']);
        $this->assertSame(2, $check['facts']['customer_wallet_histories_rows']);
        $this->assertSame('12.5', $check['facts']['customer_wallet_histories_amount']);
    }

    // ── debits against the orders they paid ───────────────────────────────────────────────────────────────────

    public function test_a_wallet_debit_is_matched_to_the_orders_of_its_checkout_and_the_rest_is_counted(): void
    {
        $this->user(1, '0', 0);
        $this->user(2, '0', 0);
        $this->user(3, '0', 0);
        $this->user(4, '0', 0);
        $this->user(5, '0', 0);
        foreach ([1 => '40', 2 => '50', 3 => '15'] as $customer => $amount) {
            $this->walletRow($customer, credit: $amount);
        }
        $this->walletRow(1, debit: '40', type: 'order_place', at: '2026-11-01 10:00:03');
        $this->walletRow(2, debit: '50', type: 'order_place', at: '2026-11-01 10:00:06');
        $this->walletRow(3, debit: '15', type: 'order_place', at: '2026-11-01 10:00:00');
        $this->walletOrder(100, 1, '40', '2026-11-01 10:00:00');
        $this->walletOrder(101, 2, '30', '2026-11-01 10:00:05', group: 'G7');
        $this->walletOrder(102, 2, '20', '2026-11-01 10:00:05', group: 'G7');
        $this->walletOrder(103, 4, '12.5', '2026-11-01 10:00:00', method: 'wallet');
        $this->walletOrder(104, 5, '99', '2026-11-01 10:00:00', method: 'cash_on_delivery');

        [$exit, $report] = $this->reconcile();

        $check = $this->check($report, 'wallet', 'wallet payments matched to the orders they paid');
        $this->assertSame(0, $exit, $this->failedChecks($report));
        $this->assertSame('warn', $check['status']);
        $this->assertSame([
            'debits' => 3,
            'matched_debits' => 2,
            'unmatched_debits' => 1,
            'wallet_order_groups' => 3,
            'unmatched_groups' => 1,
            'matched_amount' => '90',
        ], $check['facts']);
    }

    public function test_a_debit_outside_the_window_or_the_amount_tolerance_is_matched_only_when_the_operator_widens_them(): void
    {
        $this->user(1, '0', 0);
        $this->walletRow(1, credit: '80');
        $this->walletRow(1, debit: '40.5', type: 'order_place', at: '2026-11-01 12:00:00');
        $this->walletRow(1, debit: '39', type: 'order_place', at: '2026-11-01 10:00:00');
        $this->walletOrder(100, 1, '40', '2026-11-01 10:00:00');

        [, $strict] = $this->reconcile();
        $this->assertSame(2, $this->check($strict, 'wallet', 'wallet payments matched to the orders they paid')['facts']['unmatched_debits']);
        $this->assertSame(1, $this->check($strict, 'wallet', 'wallet payments matched to the orders they paid')['facts']['unmatched_groups']);

        [, $wide] = $this->reconcile(['--match-window' => '10000', '--match-tolerance' => '1.5']);
        $this->assertSame(1, $this->check($wide, 'wallet', 'wallet payments matched to the orders they paid')['facts']['unmatched_debits'], 'one order is paid by one debit');
        $this->assertSame(0, $this->check($wide, 'wallet', 'wallet payments matched to the orders they paid')['facts']['unmatched_groups']);
    }

    public function test_a_gap_of_exactly_the_tolerance_still_matches(): void
    {
        $this->user(1, '0', 0);
        $this->walletRow(1, credit: '40.02');
        $this->walletRow(1, debit: '40.02', type: 'order_place');
        $this->walletOrder(100, 1, '40');

        [, $report] = $this->reconcile();

        $this->assertSame('pass', $this->check($report, 'wallet', 'wallet payments matched to the orders they paid')['status']);
    }

    public function test_the_order_match_can_be_skipped(): void
    {
        $this->user(1, '0', 0);

        [, $report] = $this->reconcile(['--skip-order-match' => true]);

        $this->assertFalse($this->hasCheck($report, 'wallet', 'wallet payments matched to the orders they paid'));
        $this->assertTrue($this->hasCheck($report, 'wallet', 'class F: unexplained differences'));
    }

    public function test_customers_are_matched_page_after_page(): void
    {
        $customers = range(1, 405);
        foreach (array_chunk($customers, 100) as $page) {
            DB::table('users')->insert(array_map(static fn (int $id): array => ['id' => $id, 'name' => 'Customer', 'wallet_balance' => '0', 'loyalty_point' => 0], $page));
            DB::table('wallet_transactions')->insert(array_merge(...array_map(fn (int $id): array => [
                ['user_id' => $id, 'transaction_id' => (string) Str::uuid(), 'credit' => '10', 'debit' => '0', 'admin_bonus' => '0', 'balance' => 0, 'transaction_type' => 'add_fund', 'created_at' => self::MORNING, 'updated_at' => self::MORNING],
                ['user_id' => $id, 'transaction_id' => (string) Str::uuid(), 'credit' => '0', 'debit' => '10', 'admin_bonus' => '0', 'balance' => 0, 'transaction_type' => 'order_place', 'created_at' => self::MORNING, 'updated_at' => self::MORNING],
            ], $page)));
            DB::table('orders')->insert(array_map(static fn (int $id): array => ['id' => 1000 + $id, 'customer_id' => $id, 'payment_method' => 'pay_by_wallet', 'order_amount' => '10', 'created_at' => self::MORNING, 'updated_at' => self::MORNING], $page));
        }

        [$exit, $report] = $this->reconcile();

        $this->assertSame(0, $exit, $this->failedChecks($report));
        $this->assertSame('pass', $this->check($report, 'wallet', 'wallet payments matched to the orders they paid')['status']);
        $this->assertSame(405, $this->check($report, 'wallet', 'wallet payments matched to the orders they paid')['facts']['matched_debits']);
        $this->assertSame(405, $this->check($report, 'wallet', 'ledger and balances compared')['facts']['equal']);
    }

    // ── what the report says about people ─────────────────────────────────────────────────────────────────────

    public function test_the_listed_ids_are_the_largest_differences_and_never_more_than_asked(): void
    {
        foreach ([1 => 5, 2 => 50, 3 => 20, 4 => 0] as $id => $extra) {
            $this->user($id, (string) (10 + $extra), 0);
            $this->walletRow($id, credit: '10');
        }

        [, $none] = $this->reconcile();
        $this->assertArrayNotHasKey('listed_user_ids', $this->unexplained($none)['facts']);

        [, $two] = $this->reconcile(['--list-unexplained' => '2']);
        $this->assertSame([2, 3], $this->unexplained($two)['facts']['listed_user_ids']);
        $this->assertSame(3, $this->unexplained($two)['facts']['users']);

        [, $clamped] = $this->reconcile(['--list-unexplained' => '1000']);
        $this->assertSame([2, 3, 1], $this->unexplained($clamped)['facts']['listed_user_ids']);
        $this->assertSame(200, $this->unexplained($clamped)['facts']['listed_limit']);

        [, $without] = $this->reconcile(['--list-unexplained' => '5', '--explained' => $this->evidenceFile("2\n")]);
        $this->assertSame([3, 1], $this->unexplained($without)['facts']['listed_user_ids'], 'an explained user is not listed again');
    }

    public function test_no_name_contact_detail_or_row_reaches_the_output(): void
    {
        $this->user(1, '9', 0);
        $this->walletRow(1, credit: '5', reference: 'CANARY-NAME-Wq4');
        $this->walletOrder(100, 1, '9');
        DB::table('orders')->where('id', 100)->update(['order_note' => 'canary.wallet 0599977123']);

        [, , $json] = $this->reconcile(['--list-unexplained' => '5']);
        Artisan::call('wallet:reconcile', ['--list-unexplained' => '5']);
        $table = Artisan::output();

        foreach (self::CANARIES as $canary) {
            $this->assertStringNotContainsString($canary, $json, $canary);
            $this->assertStringNotContainsString($canary, $table, $canary);
        }
        $this->assertStringContainsString('"listed_user_ids"', $json);
    }

    // ── structure that is missing, options that are wrong ─────────────────────────────────────────────────────

    public function test_a_ledger_or_a_column_that_is_missing_is_a_failed_check_and_not_a_crash(): void
    {
        $this->user(1, '1', 0);
        Schema::table('wallet_transactions', static function (Blueprint $table): void {
            $table->dropColumn('admin_bonus');
        });
        Schema::drop('loyalty_point_transactions');

        [$exit, $report] = $this->reconcile();

        $wallet = $this->check($report, 'wallet', 'the ledger can be compared');
        $loyalty = $this->check($report, 'loyalty', 'the ledger can be compared');
        $this->assertSame(1, $exit);
        $this->assertSame('fail', $wallet['status']);
        $this->assertStringContainsString('has no column admin_bonus', $wallet['detail']);
        $this->assertSame('fail', $loyalty['status']);
        $this->assertStringContainsString('loyalty_point_transactions does not exist', $loyalty['detail']);
    }

    public function test_an_orders_table_without_an_amount_is_a_warning_of_the_match_and_not_a_crash(): void
    {
        $this->user(1, '0', 0);
        Schema::table('orders', static function (Blueprint $table): void {
            $table->dropColumn('order_amount');
        });

        [$exit, $report] = $this->reconcile();

        $check = $this->check($report, 'wallet', 'wallet payments matched to the orders they paid');
        $this->assertSame(0, $exit, $this->failedChecks($report));
        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('has no column order_amount', $check['detail']);
    }

    public function test_the_options_are_checked_before_the_database_is_touched(): void
    {
        DB::enableQueryLog();

        foreach ([
            [['--ledger' => 'cash'], '--ledger must be wallet, loyalty or both'],
            [['--tolerance' => '-1'], '--tolerance must be a plain number'],
            [['--tolerance' => 'a lot'], '--tolerance must be a plain number'],
            [['--match-tolerance' => '1e3'], '--match-tolerance must be a plain number'],
            [['--list-unexplained' => '-3'], '--list-unexplained must be a whole number'],
            [['--match-window' => '1.5'], '--match-window must be a whole number'],
        ] as [$options, $message]) {
            $this->assertSame(1, Artisan::call('wallet:reconcile', $options), json_encode($options));
            $this->assertStringContainsString($message, Artisan::output());
        }

        $this->assertSame([], DB::getQueryLog());
    }

    public function test_the_probe_runs_a_select_and_refuses_anything_that_is_not_a_read(): void
    {
        $this->user(1, '3', 0);
        $probe = new ReadOnlyProbe(DB::connection());

        $rows = $probe->run(static fn (ReadOnlyProbe $probe): array => $probe->select('SELECT id, wallet_balance FROM users WHERE id = ?', [1]));

        $this->assertSame(1, (int) $rows[0]['id']);
        foreach (['DELETE FROM users', 'UPDATE users SET wallet_balance = 0', 'INSERT INTO users (id) VALUES (9)', 'DROP TABLE users'] as $statement) {
            try {
                $probe->select($statement);
                $this->fail("the probe ran: {$statement}");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('The read-only probe runs reads only.', $exception->getMessage());
            }
        }
        $this->assertSame(1, DB::table('users')->count());
    }

    // ── refusals ──────────────────────────────────────────────────────────────────────────────────────────────

    public function test_in_production_it_refuses_before_reading_anything_and_runs_only_with_the_read_only_flag(): void
    {
        $this->app['env'] = 'production';
        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        DB::enableQueryLog();

        [$exit, $report] = $this->reconcile();

        $this->assertSame(1, $exit);
        $this->assertSame('refused', $report['verdict']);
        $this->assertStringContainsString('APP_ENV is production', $report['checks'][0]['detail']);
        $this->assertSame([], DB::getQueryLog());

        [$allowedExit, $allowed] = $this->reconcile(['--allow-production-read-only' => true]);

        $this->assertSame(0, $allowedExit, $this->failedChecks($allowed));
        $this->assertSame(0, $allowed['read_only_proof']['write_statements']);
    }

    public function test_it_refuses_a_database_other_than_the_one_expected(): void
    {
        $actual = (string) config('database.connections.' . config('database.default') . '.database');
        DB::enableQueryLog();

        [$exit, $report] = $this->reconcile(['--expect-database' => 'pharmacy_production']);

        $this->assertSame(1, $exit);
        $this->assertSame('refused', $report['verdict']);
        $this->assertStringContainsString('different database', $report['checks'][0]['detail']);
        $this->assertSame([], DB::getQueryLog());

        [$matchingExit, $matching] = $this->reconcile(['--expect-database' => $actual]);
        $this->assertSame(0, $matchingExit, $this->failedChecks($matching));
    }

    public function test_the_connection_the_application_uses_afterwards_is_unchanged_and_writable_again(): void
    {
        $default = DB::getDefaultConnection();

        $this->reconcile();

        $this->assertSame($default, DB::getDefaultConnection());
        $this->user(7, '1', 0);
        $this->assertSame(1, DB::table('users')->where('id', 7)->count(), 'the session is writable again after the run');
    }
}
