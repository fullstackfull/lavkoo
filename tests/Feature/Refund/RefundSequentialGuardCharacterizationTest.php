<?php

namespace Tests\Feature\Refund;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PDO;
use Tests\Support\Loyalty\LoyaltyTables;
use Tests\Support\Refund\RefundFlows;
use Tests\Support\RestoresLanguageFiles;
use Tests\Support\Wallet\WalletTables;
use Tests\TestCase;

/**
 * The admin refund, return-amount and order-status flows, each run twice one after the other.
 *
 * Every test name starts with test_current_behaviour: it pins what the code does today. A pass says the check-then-write guard
 * holds for a repeat that arrives after the first write committed. It does not say the guard holds for two requests that
 * overlap: those races are RF-01, RF-03, RF-04 and IDEM-08, written as skipped MySQL tests in
 * tests/Feature/Concurrency/RefundConcurrencyTest.php. Each finding and its owner decision is in
 * docs/wallet/refund-loyalty-idempotency-proposals.md.
 */
class RefundSequentialGuardCharacterizationTest extends TestCase
{
    use RestoresLanguageFiles;

    private string $languageFilesBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rememberLanguageFiles();
        $this->languageFilesBefore = $this->languageFilesFingerprint();
        RefundFlows::createTables();
        RefundFlows::seedSettingsAndCustomer();
        Event::fake();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();
        $this->assertSame($this->languageFilesBefore, $this->languageFilesFingerprint(), 'resources/lang must be unchanged');
        parent::tearDown();
    }

    /** RF-01, sequential half. See docs/wallet/refund-loyalty-idempotency-proposals.md#rf-01. */
    public function test_current_behaviour_a_second_refunded_status_is_rejected_so_the_wallet_is_credited_and_the_points_debited_once(): void
    {
        RefundFlows::seedOrderLine(orderStatus: 'delivered', sellerId: 1);
        RefundFlows::seedRefundRequest();

        $first = RefundFlows::markRefunded();
        $second = RefundFlows::markRefunded();

        $this->assertArrayHasKey('message', $first);
        $this->assertArrayHasKey('error', $second);
        $this->assertSame(1, DB::table('wallet_transactions')->where('transaction_type', 'order_refund')->count());
        $this->assertSame(RefundFlows::ORDER_AMOUNT, WalletTables::walletBalance(RefundFlows::CUSTOMER_ID));
        $this->assertSame(1, DB::table('loyalty_point_transactions')->where('transaction_type', 'refund_order')->count());
        $this->assertSame(RefundFlows::OPENING_POINTS - RefundFlows::LINE_REWARD_POINTS, LoyaltyTables::loyaltyPointsOf(RefundFlows::CUSTOMER_ID));
        $this->assertSame(1, DB::table('refund_transactions')->count());
    }

    /** RF-02 as it stands: neither ledger row names the refund. See docs/wallet/refund-loyalty-idempotency-proposals.md#rf-02. */
    public function test_current_behaviour_the_refund_credit_is_referenced_by_a_literal_and_the_points_debit_by_the_order_id(): void
    {
        RefundFlows::seedOrderLine(orderStatus: 'delivered', sellerId: 1);
        RefundFlows::seedRefundRequest();

        RefundFlows::markRefunded();

        $this->assertSame('order_refund', DB::table('wallet_transactions')->where('transaction_type', 'order_refund')->value('reference'));
        $this->assertSame((string) RefundFlows::ORDER_ID, (string) DB::table('loyalty_point_transactions')->where('transaction_type', 'refund_order')->value('reference'));
    }

    /**
     * RF-03, sequential half. See docs/wallet/refund-loyalty-idempotency-proposals.md#rf-03.
     *
     * The controller compares the posted amount to the stored one with !==, which only matches when the driver returns the
     * decimal as a string, as MySQL does. Stringified fetches make SQLite answer the same way here.
     */
    public function test_current_behaviour_a_resubmitted_return_amount_is_rejected_so_the_wallet_is_credited_once(): void
    {
        DB::connection()->getPdo()->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        RefundFlows::seedOrderLine(orderStatus: 'confirmed', editReturnAmount: RefundFlows::RETURN_AMOUNT);
        $amount = RefundFlows::storedReturnAmount();

        RefundFlows::returnAmountToWallet(amount: $amount);
        RefundFlows::returnAmountToWallet(amount: $amount);

        $this->assertSame(1, DB::table('wallet_transactions')->where('transaction_type', 'return_order_amount_by_admin')->count());
        $this->assertSame(RefundFlows::RETURN_AMOUNT, WalletTables::walletBalance(RefundFlows::CUSTOMER_ID));
        $this->assertSame(0.0, (float) DB::table('orders')->where('id', RefundFlows::ORDER_ID)->value('edit_return_amount'));
    }

    /** RF-07 as it stands: with natively typed fetches the strict comparison refuses even the exact amount. See docs/wallet/refund-loyalty-idempotency-proposals.md#rf-07. */
    public function test_current_behaviour_the_exact_return_amount_is_refused_when_the_driver_returns_a_number(): void
    {
        RefundFlows::seedOrderLine(orderStatus: 'confirmed', editReturnAmount: RefundFlows::RETURN_AMOUNT);

        RefundFlows::returnAmountToWallet(amount: RefundFlows::storedReturnAmount());

        $this->assertSame(0, DB::table('wallet_transactions')->count());
        $this->assertSame(RefundFlows::RETURN_AMOUNT, (float) DB::table('orders')->where('id', RefundFlows::ORDER_ID)->value('edit_return_amount'));
    }

    /** IDEM-08, sequential half. See docs/wallet/refund-loyalty-idempotency-proposals.md#idem-08. */
    public function test_current_behaviour_a_second_delivered_status_after_reward_delivered_writes_one_order_place_row(): void
    {
        RefundFlows::seedOrderLine(orderStatus: 'confirmed');

        RefundFlows::changeOrderStatus(status: 'delivered');
        RefundFlows::changeOrderStatus(status: 'delivered');

        $this->assertSame(1, DB::table('loyalty_point_transactions')->where('transaction_type', 'order_place')->count());
        $this->assertSame(1, (int) DB::table('order_details_rewards')->where('order_id', RefundFlows::ORDER_ID)->value('reward_delivered'));
    }

    /**
     * LF-6 as it stands: the delivery credit is recomputed from the order total, not taken from the line's recorded reward_amount.
     * See docs/wallet/refund-loyalty-idempotency-proposals.md#lf-6.
     */
    public function test_current_behaviour_the_delivery_credit_is_recomputed_from_the_order_total_not_the_recorded_line_points(): void
    {
        RefundFlows::seedOrderLine(orderStatus: 'confirmed');

        RefundFlows::changeOrderStatus(status: 'delivered');

        $credited = (float) DB::table('loyalty_point_transactions')->where('transaction_type', 'order_place')->value('credit');
        $this->assertSame(10.0, $credited, 'an order total of 100 at 10 points per 100 earns 10 points');
        $this->assertNotSame(RefundFlows::LINE_REWARD_POINTS, $credited, 'the recorded line reward was 20 points');
    }

    /** RF-04, sequential half. See docs/wallet/refund-loyalty-idempotency-proposals.md#rf-04. */
    public function test_current_behaviour_a_second_pos_cancel_finds_the_pos_order_cancel_reference_and_credits_once(): void
    {
        RefundFlows::seedOrderLine(orderStatus: 'confirmed', orderType: 'POS', paymentMethod: 'wallet');

        RefundFlows::changeOrderStatus(status: 'canceled');
        RefundFlows::changeOrderStatus(status: 'canceled');

        $refunds = DB::table('wallet_transactions')->where('transaction_type', 'order_refund')->get();
        $this->assertCount(1, $refunds);
        $this->assertSame('pos_order_cancel_' . RefundFlows::ORDER_ID, $refunds->first()->reference);
        $this->assertSame(RefundFlows::ORDER_AMOUNT, WalletTables::walletBalance(RefundFlows::CUSTOMER_ID));
    }

    private function languageFilesFingerprint(): string
    {
        return (string) shell_exec('git -C ' . escapeshellarg(base_path()) . ' status --porcelain -- resources/lang');
    }
}
