<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\Support\Refund\RefundFlows;
use Tests\TestCase;

/**
 * The same admin money or points action sent by several processes at the same instant, on a real MySQL or MariaDB.
 *
 * Each flow checks a status, flag or reference and then writes, with nothing holding the check. These tests assert the outcome a
 * fix must give: one credit, one debit, one ledger row. They are skipped until the owner approves a fix, because no fix is part
 * of this change. Each skip names its finding; the proposal and the owner decision are in
 * docs/wallet/refund-loyalty-idempotency-proposals.md. The sequential halves run on SQLite in
 * tests/Feature/Refund/RefundSequentialGuardCharacterizationTest.php.
 */
class RefundConcurrencyTest extends TestCase
{
    private const WRITERS = 4;

    protected function setUp(): void
    {
        parent::setUp();
        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }
        RefundFlows::dropTables();
        RefundFlows::createTables();
        RefundFlows::seedSettingsAndCustomer();
    }

    protected function tearDown(): void
    {
        if (ConcurrentRunner::available()) {
            RefundFlows::dropTables();
        }
        parent::tearDown();
    }

    public function test_simultaneous_refunded_statuses_credit_the_wallet_and_debit_the_points_once(): void
    {
        $this->skipUntilOwnerDecision(finding: 'RF-01', proposal: 'claim the refund with a conditional UPDATE before paying it');
        RefundFlows::seedOrderLine(orderStatus: 'delivered', sellerId: 1);
        RefundFlows::seedRefundRequest();

        $reports = $this->race('refunds.mark_refunded', []);

        $this->assertSame(1, DB::table('wallet_transactions')->where('transaction_type', 'order_refund')->count());
        $this->assertSame(1, DB::table('loyalty_point_transactions')->where('transaction_type', 'refund_order')->count());
        $this->assertSame(1, DB::table('refund_transactions')->count());
        $this->assertSame(1, count(array_filter($reports, fn (array $report): bool => $report['result'] === true)));
    }

    public function test_simultaneous_return_amount_submissions_credit_the_wallet_once(): void
    {
        $this->skipUntilOwnerDecision(finding: 'RF-03', proposal: 'claim edit_return_amount with a conditional UPDATE before crediting it');
        RefundFlows::seedOrderLine(orderStatus: 'confirmed', editReturnAmount: RefundFlows::RETURN_AMOUNT);

        $this->race('refunds.return_amount_to_wallet', ['amount' => RefundFlows::storedReturnAmount()]);

        $this->assertSame(1, DB::table('wallet_transactions')->where('transaction_type', 'return_order_amount_by_admin')->count());
        $this->assertEqualsWithDelta(RefundFlows::RETURN_AMOUNT, (float) DB::table('users')->where('id', RefundFlows::CUSTOMER_ID)->value('wallet_balance'), 0.0001);
    }

    public function test_simultaneous_pos_cancels_credit_the_wallet_once(): void
    {
        $this->skipUntilOwnerDecision(finding: 'RF-04', proposal: 'make pos_order_cancel_{id} a claimed key rather than a looked-up one');
        RefundFlows::seedOrderLine(orderStatus: 'confirmed', orderType: 'POS', paymentMethod: 'wallet');

        $this->race('refunds.change_order_status', ['status' => 'canceled']);

        $this->assertSame(1, DB::table('wallet_transactions')->where('reference', 'pos_order_cancel_' . RefundFlows::ORDER_ID)->count());
    }

    public function test_simultaneous_delivered_statuses_credit_the_order_points_once(): void
    {
        $this->skipUntilOwnerDecision(finding: 'IDEM-08', proposal: 'claim reward_delivered with a conditional UPDATE before crediting');
        RefundFlows::seedOrderLine(orderStatus: 'confirmed');

        $this->race('refunds.change_order_status', ['status' => 'delivered']);

        $this->assertSame(1, DB::table('loyalty_point_transactions')->where('transaction_type', 'order_place')->count());
    }

    private function skipUntilOwnerDecision(string $finding, string $proposal): void
    {
        $this->markTestSkipped("{$finding}: owner decision pending ({$proposal}). See docs/wallet/refund-loyalty-idempotency-proposals.md.");
    }

    /** @return list<array<string, mixed>> */
    private function race(string $operation, array $args): array
    {
        $reports = ConcurrentRunner::run($operation, array_fill(0, self::WRITERS, $args));

        $this->assertSame([], array_values(array_filter($reports, fn (array $report): bool => !$report['ok'])), 'a worker failed');
        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the requests did not overlap, so the test proves nothing');

        return $reports;
    }
}
