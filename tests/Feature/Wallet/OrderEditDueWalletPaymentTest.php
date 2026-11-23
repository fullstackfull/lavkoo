<?php

namespace Tests\Feature\Wallet;

use App\Http\Controllers\RestAPI\v1\OrderEditController;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\KeepsTranslateOffLanguageFiles;
use Tests\Support\Wallet\OrderDuePayingHost;
use Tests\Support\Wallet\OrderDueTables;
use Tests\Support\Wallet\WalletTables;
use Tests\TestCase;

/**
 * The due left on an edited order, paid from the customer's wallet: paid once and completely, or not at all, and only by the customer
 * who owns the order.
 */
class OrderEditDueWalletPaymentTest extends TestCase
{
    use KeepsTranslateOffLanguageFiles;

    private const CUSTOMER_ID = 1;
    private const STRANGER_ID = 2;
    private const ORDER_ID = 100001;
    private const ORDER_AMOUNT = 200.0;
    private const DUE = 60.0;

    protected function setUp(): void
    {
        parent::setUp();
        OrderDueTables::drop();
        OrderDueTables::create();
        OrderDueTables::seedEditedOrder(self::ORDER_ID, self::CUSTOMER_ID, self::ORDER_AMOUNT, self::DUE);
        $this->keepTranslateOffLanguageFiles();
    }

    protected function tearDown(): void
    {
        $this->assertLanguageFilesUntouched();

        parent::tearDown();
    }

    /** @return array{due: float, order_amount: float, payment_status: string, history_status: ?string, admin_pending: float, ledger_rows: int} */
    private function unpaidState(): array
    {
        return [
            'due' => self::DUE, 'order_amount' => self::ORDER_AMOUNT, 'payment_status' => 'unpaid', 'history_status' => 'unpaid',
            'admin_pending' => OrderDueTables::ADMIN_PENDING, 'ledger_rows' => 0,
        ];
    }

    /** @return array{due: float, order_amount: float, payment_status: string, history_status: ?string, admin_pending: float, ledger_rows: int} */
    private function paidState(): array
    {
        return [
            'due' => 0.0, 'order_amount' => self::ORDER_AMOUNT + self::DUE, 'payment_status' => 'paid', 'history_status' => 'paid',
            'admin_pending' => OrderDueTables::ADMIN_PENDING + self::DUE, 'ledger_rows' => 1,
        ];
    }

    private function pay(int $customerId = self::CUSTOMER_ID): array
    {
        return app(OrderDuePayingHost::class)->payFromWallet(self::ORDER_ID, $customerId);
    }

    public function test_the_due_is_paid_from_the_wallet_once_and_completely(): void
    {
        WalletTables::seedCustomer(self::CUSTOMER_ID, 100.0);

        $this->assertTrue($this->pay()['status']);

        $this->assertSame($this->paidState(), OrderDueTables::state(self::ORDER_ID));
        $this->assertSame(40.0, WalletTables::walletBalance(self::CUSTOMER_ID));
    }

    public function test_a_debit_the_wallet_refuses_changes_nothing_and_is_reported_as_a_refusal(): void
    {
        WalletTables::seedCustomer(self::CUSTOMER_ID, 100.0);
        $customerAsChecked = User::findOrFail(self::CUSTOMER_ID);
        DB::table('users')->where('id', self::CUSTOMER_ID)->update(['wallet_balance' => 10]);

        $result = app(OrderDuePayingHost::class)->payEditOrderDueByCustomerWallet(
            order: Order::findOrFail(self::ORDER_ID),
            customer: $customerAsChecked,
        );

        $this->assertFalse($result['status'], 'the balance read before the payment said 100, the locked wallet holds 10');
        $this->assertSame($this->unpaidState(), OrderDueTables::state(self::ORDER_ID));
        $this->assertSame(10.0, WalletTables::walletBalance(self::CUSTOMER_ID));
    }

    public function test_a_wallet_below_the_due_is_refused_before_anything_is_written(): void
    {
        WalletTables::seedCustomer(self::CUSTOMER_ID, 50.0);

        $this->assertFalse($this->pay()['status']);

        $this->assertSame($this->unpaidState(), OrderDueTables::state(self::ORDER_ID));
        $this->assertSame(50.0, WalletTables::walletBalance(self::CUSTOMER_ID));
    }

    public function test_a_second_submission_finds_no_due_and_debits_nothing(): void
    {
        WalletTables::seedCustomer(self::CUSTOMER_ID, 200.0);
        $staleOrder = Order::findOrFail(self::ORDER_ID);
        $this->assertTrue($this->pay()['status']);

        $second = app(OrderDuePayingHost::class)->payEditOrderDueByCustomerWallet(order: $staleOrder, customer: User::findOrFail(self::CUSTOMER_ID));

        $this->assertFalse($second['status'], 'the order read before the first payment still says 60 is due');
        $this->assertSame($this->paidState(), OrderDueTables::state(self::ORDER_ID));
        $this->assertSame(140.0, WalletTables::walletBalance(self::CUSTOMER_ID));
    }

    public function test_another_customer_cannot_pay_the_due_from_their_own_wallet(): void
    {
        WalletTables::seedCustomer(self::CUSTOMER_ID, 0.0);
        WalletTables::seedCustomer(self::STRANGER_ID, 500.0);

        $this->assertFalse($this->pay(self::STRANGER_ID)['status']);

        $this->assertSame($this->unpaidState(), OrderDueTables::state(self::ORDER_ID));
        $this->assertSame(500.0, WalletTables::walletBalance(self::STRANGER_ID));
    }

    private function payThroughTheApp(int $customerId): JsonResponse
    {
        $request = Request::create('/api/v1/edit-order/due-payment-by-wallet', 'POST', ['order_id' => self::ORDER_ID, 'payment_method' => 'wallet']);
        $customer = User::findOrFail($customerId);
        $request->setUserResolver(fn (): User => $customer);

        return app(OrderEditController::class)->duePaymentByWallet($request);
    }

    public function test_the_app_does_not_find_the_order_of_another_customer(): void
    {
        WalletTables::seedCustomer(self::CUSTOMER_ID, 0.0);
        WalletTables::seedCustomer(self::STRANGER_ID, 500.0);

        $response = $this->payThroughTheApp(self::STRANGER_ID);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame($this->unpaidState(), OrderDueTables::state(self::ORDER_ID));
        $this->assertSame(500.0, WalletTables::walletBalance(self::STRANGER_ID));
    }

    public function test_the_app_pays_the_owners_due_and_refuses_the_second_submission(): void
    {
        WalletTables::seedCustomer(self::CUSTOMER_ID, 200.0);

        $this->assertSame(200, $this->payThroughTheApp(self::CUSTOMER_ID)->getStatusCode());
        $this->assertSame(403, $this->payThroughTheApp(self::CUSTOMER_ID)->getStatusCode());

        $this->assertSame($this->paidState(), OrderDueTables::state(self::ORDER_ID));
        $this->assertSame(140.0, WalletTables::walletBalance(self::CUSTOMER_ID));
    }
}
