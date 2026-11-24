<?php

namespace Tests\Feature\Refund;

use App\Services\Refund\WhenACancellationRefundsTheWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Refund\RefundFlows;
use Tests\Support\RestoresLanguageFiles;
use Tests\Support\Wallet\WalletTables;
use Tests\TestCase;

/**
 * E-13's last criterion, finding E04-F10: whether an admin cancelling a wallet-paid order pays the customer back.
 *
 * Today a POS sale paid with the wallet refunds itself and anything else does not, and only the POS half had ever been pinned, so the
 * half that IS the finding -- a customer whose money stays with the platform until a person remembers -- could have moved without a test
 * noticing. It is pinned here. Which answer is right is the owner's: automatic means an operator's click moves money, manual means a
 * forgotten refund is never made (docs/gcc/OPEN-FINANCIAL-DECISIONS.md section 3). So the two other answers are proved reachable by one
 * setting, with their amounts, their references and their idempotency, and the default adopts none of them.
 *
 * Every refund here is written by the real admin controller through the real wallet writer, not by a fixture.
 */
class WhenACancellationRefundsTheWalletTest extends TestCase
{
    use RestoresLanguageFiles;

    private const CARD = 'ssl_commerz';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        RefundFlows::createTables();
        RefundFlows::seedSettingsAndCustomer();
        Event::fake();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function underRule(string $rule): void
    {
        config(['gcc.refunds.when_a_cancellation_refunds_the_wallet' => $rule]);
    }

    /** An order the customer has actually paid for: what the options newly cover is paid money, never an unpaid order. */
    private function paidOrder(string $orderType, string $paymentMethod): void
    {
        RefundFlows::seedOrderLine(orderStatus: 'confirmed', orderType: $orderType, paymentMethod: $paymentMethod);
        DB::table('orders')->where('id', RefundFlows::ORDER_ID)->update(['paid_amount' => RefundFlows::ORDER_AMOUNT]);
    }

    private function refundsWritten(): array
    {
        return DB::table('wallet_transactions')->where('transaction_type', 'order_refund')->get()->all();
    }

    public function test_the_rule_in_force_is_todays_behaviour_until_the_owner_chooses(): void
    {
        $this->assertSame(WhenACancellationRefundsTheWallet::MANUAL_AS_TODAY, WhenACancellationRefundsTheWallet::rule());
    }

    /** A typo in the environment cannot start moving money by itself. */
    public function test_an_unrecognised_rule_is_read_as_todays_behaviour(): void
    {
        $this->underRule('refund_everything_always');

        $this->assertSame(WhenACancellationRefundsTheWallet::MANUAL_AS_TODAY, WhenACancellationRefundsTheWallet::rule());
    }

    public function test_today_a_pos_wallet_cancel_pays_the_customer_back(): void
    {
        $this->paidOrder(orderType: 'POS', paymentMethod: 'wallet');

        RefundFlows::changeOrderStatus(status: 'canceled');

        $this->assertSame(RefundFlows::ORDER_AMOUNT, WalletTables::walletBalance(RefundFlows::CUSTOMER_ID));
    }

    /**
     * The finding, pinned: the same money, paid the same way, on an order that did not come from the counter, stays with the platform.
     * This test is what makes answering the question a visible change rather than a silent one.
     */
    public function test_today_a_non_pos_wallet_cancel_leaves_the_debit_with_the_platform(): void
    {
        $this->paidOrder(orderType: 'default_type', paymentMethod: 'wallet');

        RefundFlows::changeOrderStatus(status: 'canceled');

        $this->assertSame([], $this->refundsWritten(), 'nothing is credited, and a person has to refund it by hand');
        $this->assertSame(0.0, WalletTables::walletBalance(RefundFlows::CUSTOMER_ID));
    }

    public function test_option_a_pays_back_a_non_pos_cancellation_of_a_paid_order(): void
    {
        $this->underRule(WhenACancellationRefundsTheWallet::EVERY_PAID_CANCELLATION);
        $this->paidOrder(orderType: 'default_type', paymentMethod: self::CARD);

        RefundFlows::changeOrderStatus(status: 'canceled');

        $this->assertSame(RefundFlows::ORDER_AMOUNT, WalletTables::walletBalance(RefundFlows::CUSTOMER_ID));
        $this->assertSame('order_cancel_' . RefundFlows::ORDER_ID, $this->refundsWritten()[0]->reference);
    }

    public function test_option_c_pays_back_a_wallet_payment_and_leaves_a_card_payment_to_a_person(): void
    {
        $this->underRule(WhenACancellationRefundsTheWallet::WALLET_PAYMENTS_ONLY);
        $this->paidOrder(orderType: 'default_type', paymentMethod: 'wallet');

        RefundFlows::changeOrderStatus(status: 'canceled');

        $this->assertSame(RefundFlows::ORDER_AMOUNT, WalletTables::walletBalance(RefundFlows::CUSTOMER_ID));
        $this->assertFalse(
            WhenACancellationRefundsTheWallet::refunds(
                ['customer_id' => RefundFlows::CUSTOMER_ID, 'payment_method' => self::CARD, 'order_type' => 'default_type', 'order_amount' => 100.0, 'paid_amount' => 100.0],
                'canceled',
            ),
            'a card payment has to go back the way it came in',
        );
    }

    /** Whatever the rule: an order nobody paid for has nothing to pay back, and a zero ledger row is not an audit trail. */
    public function test_no_rule_refunds_an_order_nothing_was_paid_on(): void
    {
        $unpaid = ['customer_id' => RefundFlows::CUSTOMER_ID, 'payment_method' => self::CARD, 'order_type' => 'default_type', 'order_amount' => 100.0, 'paid_amount' => 0.0];

        foreach ([WhenACancellationRefundsTheWallet::EVERY_PAID_CANCELLATION, WhenACancellationRefundsTheWallet::WALLET_PAYMENTS_ONLY] as $rule) {
            $this->underRule($rule);
            $this->assertFalse(WhenACancellationRefundsTheWallet::refunds($unpaid, 'canceled'), "nothing is refunded under {$rule}");
        }
    }

    /** A guest order has no wallet to pay into. */
    public function test_no_rule_refunds_a_guest_order(): void
    {
        $guest = ['customer_id' => 0, 'payment_method' => 'wallet', 'order_type' => 'POS', 'order_amount' => 100.0, 'paid_amount' => 100.0];

        foreach ([WhenACancellationRefundsTheWallet::MANUAL_AS_TODAY, WhenACancellationRefundsTheWallet::EVERY_PAID_CANCELLATION, WhenACancellationRefundsTheWallet::WALLET_PAYMENTS_ONLY] as $rule) {
            $this->underRule($rule);
            $this->assertFalse(WhenACancellationRefundsTheWallet::refunds($guest, 'canceled'), "no wallet is credited under {$rule}");
        }
    }

    /** Only a cancellation refunds anything. Delivering an order is not a refund under any rule. */
    public function test_no_rule_refunds_a_status_that_is_not_a_cancellation(): void
    {
        $order = ['customer_id' => RefundFlows::CUSTOMER_ID, 'payment_method' => 'wallet', 'order_type' => 'POS', 'order_amount' => 100.0, 'paid_amount' => 100.0];

        foreach ([WhenACancellationRefundsTheWallet::MANUAL_AS_TODAY, WhenACancellationRefundsTheWallet::EVERY_PAID_CANCELLATION, WhenACancellationRefundsTheWallet::WALLET_PAYMENTS_ONLY] as $rule) {
            $this->underRule($rule);
            $this->assertFalse(WhenACancellationRefundsTheWallet::refunds($order, 'delivered'), "delivering pays nothing back under {$rule}");
        }
    }

    /**
     * A POS sale is credited its whole amount, as it always has been -- it is paid in full at the counter. Every order the options newly
     * cover is credited what was actually paid on it, so a part-paid order cannot be refunded more than the customer handed over.
     */
    public function test_a_part_paid_order_is_refunded_only_what_was_paid(): void
    {
        $this->underRule(WhenACancellationRefundsTheWallet::EVERY_PAID_CANCELLATION);
        $partPaid = ['customer_id' => RefundFlows::CUSTOMER_ID, 'payment_method' => self::CARD, 'order_type' => 'default_type', 'order_amount' => 100.0, 'paid_amount' => 40.0];

        $this->assertSame(40.0, WhenACancellationRefundsTheWallet::amountFor($partPaid));
        $this->assertSame(
            100.0,
            WhenACancellationRefundsTheWallet::amountFor(['order_type' => 'POS', 'payment_method' => 'wallet', 'order_amount' => 100.0, 'paid_amount' => 0.0]),
            'a POS wallet sale keeps the figure cancelling it has always credited',
        );
    }

    /** The reference is keyed on what the order is, never on the rule, so changing the rule cannot make one cancellation look like two. */
    public function test_the_reference_a_refund_carries_does_not_depend_on_the_rule(): void
    {
        $pos = ['id' => 7, 'order_type' => 'POS'];
        $other = ['id' => 7, 'order_type' => 'default_type'];

        foreach ([WhenACancellationRefundsTheWallet::MANUAL_AS_TODAY, WhenACancellationRefundsTheWallet::EVERY_PAID_CANCELLATION, WhenACancellationRefundsTheWallet::WALLET_PAYMENTS_ONLY] as $rule) {
            $this->underRule($rule);
            $this->assertSame('pos_order_cancel_7', WhenACancellationRefundsTheWallet::referenceFor($pos));
            $this->assertSame('order_cancel_7', WhenACancellationRefundsTheWallet::referenceFor($other));
        }
    }

    /** And the property that makes an automatic refund safe at all: one cancellation pays once, under an option as under today's rule. */
    public function test_a_second_cancellation_pays_nothing_more_under_an_option(): void
    {
        $this->underRule(WhenACancellationRefundsTheWallet::EVERY_PAID_CANCELLATION);
        $this->paidOrder(orderType: 'default_type', paymentMethod: self::CARD);

        RefundFlows::changeOrderStatus(status: 'canceled');
        RefundFlows::changeOrderStatus(status: 'canceled');

        $this->assertCount(1, $this->refundsWritten());
        $this->assertSame(RefundFlows::ORDER_AMOUNT, WalletTables::walletBalance(RefundFlows::CUSTOMER_ID));
    }
}
