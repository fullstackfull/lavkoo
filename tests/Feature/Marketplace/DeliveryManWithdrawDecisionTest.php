<?php

namespace Tests\Feature\Marketplace;

use App\Http\Controllers\Admin\Deliveryman\DeliverymanWithdrawController;
use App\Http\Controllers\Vendor\DeliveryMan\DeliveryManWithdrawController as VendorDeliveryManWithdrawController;
use App\Http\Requests\Vendor\DeliveryManWithdrawRequest;
use App\Models\Seller;
use App\Services\DeliveryManService;
use App\Services\DeliveryManWalletService;
use App\Services\DeliveryManWithdrawService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsDeliveryManWithdrawSchema;
use Tests\TestCase;

/**
 * A withdrawal request of a delivery man is decided ONCE, whichever screen decides it.
 *
 * Asking for the withdrawal reserves the amount: `pending_withdraw` rises and the balance is left alone, so the money only leaves the
 * balance when the payment is actually approved. Both screens used to work the new totals out from a wallet read with nothing holding it
 * and write them back, and neither asked whether the request was still pending. So re-posting a decision applied the whole movement
 * again -- `total_withdraw` grew a second time, `pending_withdraw` went negative and the delivery man's balance fell twice for one
 * payment, which is money taken from them and books that say they were paid twice.
 *
 * What the request's own row now does is claim the decision: the status is written where it is still pending, and nothing else happens
 * unless that update took exactly one row.
 */
class DeliveryManWithdrawDecisionTest extends TestCase
{
    use BuildsDeliveryManWithdrawSchema;

    private const DELIVERY_MAN = 7;

    private const SELLER = 3;

    private const REQUEST = 10;

    private const AMOUNT = 200.0;

    /** What the two screens post: 1 approves, 2 denies. The column's 0 means the request is still pending. */
    private const APPROVED = 1;

    private const DENIED = 2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildDeliveryManWithdrawSchema();
        DB::table('delivery_men')->insert([
            'id' => self::DELIVERY_MAN, 'seller_id' => self::SELLER, 'f_name' => 'Rider', 'email' => 'rider@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sellers')->insert([
            'id' => self::SELLER, 'f_name' => 'Shop', 'email' => 'shop@example.test', 'password' => 'unused',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropDeliveryManWithdrawTables();

        parent::tearDown();
    }

    /** The wallet as the delivery man's own screens read it. @return array<string, float> */
    private function wallet(): array
    {
        $row = DB::table('deliveryman_wallets')->where('delivery_man_id', self::DELIVERY_MAN)->first();

        return [
            'current_balance' => (float) $row->current_balance,
            'pending_withdraw' => (float) $row->pending_withdraw,
            'total_withdraw' => (float) $row->total_withdraw,
        ];
    }

    /** A delivery man with a balance who has asked for part of it, which is the state a decision is made in. */
    private function aPendingRequest(float $balance = 1000.0, float $amount = self::AMOUNT): void
    {
        DB::table('deliveryman_wallets')->insert([
            'delivery_man_id' => self::DELIVERY_MAN, 'current_balance' => $balance, 'cash_in_hand' => 0,
            'pending_withdraw' => $amount, 'total_withdraw' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('withdraw_requests')->insert([
            'id' => self::REQUEST, 'delivery_man_id' => self::DELIVERY_MAN, 'seller_id' => self::SELLER, 'amount' => $amount,
            'approved' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function decideAtTheAdminScreen(int $approved): object
    {
        $request = $this->decisionRequest($approved);

        return app(DeliverymanWithdrawController::class)->updateStatus(
            request: $request,
            withdrawId: self::REQUEST,
            deliveryManWithdrawService: app(DeliveryManWithdrawService::class),
            deliveryManWalletService: app(DeliveryManWalletService::class),
        );
    }

    private function decideAtTheVendorScreen(int $approved): object
    {
        $this->actingAs((new Seller())->forceFill(['id' => self::SELLER, 'f_name' => 'Shop']), 'seller');

        return (new VendorDeliveryManWithdrawController(
            app(\App\Contracts\Repositories\WithdrawRequestRepositoryInterface::class),
            app(DeliveryManWithdrawService::class),
            app(DeliveryManService::class),
        ))->updateStatus($this->decisionRequest($approved), self::REQUEST);
    }

    /** The decision a screen posts: the status and the note, which is all either handler reads off the request. */
    private function decisionRequest(int $approved): DeliveryManWithdrawRequest
    {
        return DeliveryManWithdrawRequest::create('/', 'POST', ['approved' => $approved, 'note' => 'decided']);
    }

    /** @return array<string, array{0: string}> the two screens that decide one of these requests */
    public static function theScreens(): array
    {
        return ['the admin screen' => ['admin'], 'the vendor screen' => ['vendor']];
    }

    private function decide(string $screen, int $approved): object
    {
        return $screen === 'admin' ? $this->decideAtTheAdminScreen($approved) : $this->decideAtTheVendorScreen($approved);
    }

    #[DataProvider('theScreens')]
    public function test_an_approval_pays_the_request_once_and_takes_the_amount_from_the_balance(string $screen): void
    {
        $this->aPendingRequest();

        $this->decide($screen, approved: self::APPROVED);

        $this->assertSame(
            ['current_balance' => 800.0, 'pending_withdraw' => 0.0, 'total_withdraw' => self::AMOUNT],
            $this->wallet(),
            'the amount leaves the balance, the reservation is released and the payment is recorded once',
        );
        $this->assertSame(self::APPROVED, (int) DB::table('withdraw_requests')->where('id', self::REQUEST)->value('approved'));
    }

    #[DataProvider('theScreens')]
    public function test_repeating_an_approval_decides_nothing_and_moves_no_money(string $screen): void
    {
        $this->aPendingRequest();
        $this->decide($screen, approved: self::APPROVED);
        $afterTheFirst = $this->wallet();

        $answer = $this->decide($screen, approved: self::APPROVED);

        $this->assertSame($afterTheFirst, $this->wallet(), 'the second approval of one request pays nothing a second time');
        $this->assertSame(['error' => translate('this_withdraw_request_has_already_been_processed')], (array) $answer->getData(true));
    }

    #[DataProvider('theScreens')]
    public function test_a_denial_releases_the_reservation_and_leaves_the_balance_where_it_was(string $screen): void
    {
        $this->aPendingRequest();

        $this->decide($screen, approved: self::DENIED);

        $this->assertSame(
            ['current_balance' => 1000.0, 'pending_withdraw' => 0.0, 'total_withdraw' => 0.0],
            $this->wallet(),
            'a refused request gives the reservation back and was never taken out of the balance',
        );
        $this->assertSame(self::DENIED, (int) DB::table('withdraw_requests')->where('id', self::REQUEST)->value('approved'), 'and the row says so, so it is no longer pending');
    }

    /**
     * A decision of 0 is the column's own "still pending", so writing it back decides nothing while releasing the reservation --
     * repeatable, and each repeat raised the withdrawable balance, which is the balance less the reservation, by the amount. Both screens
     * refuse it now, and so does the settlement whoever calls it.
     */
    #[DataProvider('theScreens')]
    public function test_a_decision_that_is_neither_an_approval_nor_a_denial_moves_nothing(string $screen): void
    {
        $this->aPendingRequest();
        $before = $this->wallet();

        try {
            $this->decide($screen, approved: 0);
        } catch (HttpResponseException $refused) {
            $this->assertSame(200, $refused->getResponse()->getStatusCode(), 'the screens answer with the validation error they always answer with');
        }

        $this->assertSame($before, $this->wallet(), 'nothing moved');
        $this->assertSame(0, (int) DB::table('withdraw_requests')->where('id', self::REQUEST)->value('approved'), 'and the request is still pending');
        $this->assertFalse(
            app(DeliveryManWithdrawService::class)->settle(
                request: DeliveryManWithdrawRequest::create('/', 'POST', ['approved' => 0, 'note' => 'decided']),
                withdraw: (object) ['id' => self::REQUEST, 'delivery_man_id' => self::DELIVERY_MAN, 'amount' => self::AMOUNT],
            ),
            'the settlement itself refuses it, whoever calls it',
        );
        $this->assertSame($before, $this->wallet());
    }

    #[DataProvider('theScreens')]
    public function test_repeating_a_denial_decides_nothing(string $screen): void
    {
        $this->aPendingRequest();
        $this->decide($screen, approved: self::DENIED);
        $afterTheFirst = $this->wallet();

        $this->decide($screen, approved: self::DENIED);

        $this->assertSame($afterTheFirst, $this->wallet(), 'the reservation is released once, so the balance cannot drift');
    }

    /** A decision already taken by one screen is refused by the other: the gate is the request's row, not the screen. */
    public function test_a_request_the_admin_has_approved_cannot_be_decided_again_at_the_vendor_screen(): void
    {
        $this->aPendingRequest();
        $this->decideAtTheAdminScreen(approved: self::APPROVED);
        $afterTheApproval = $this->wallet();

        $answer = $this->decideAtTheVendorScreen(approved: self::DENIED);

        $this->assertSame($afterTheApproval, $this->wallet(), 'the denial that came second changed nothing');
        $this->assertSame(['error' => translate('this_withdraw_request_has_already_been_processed')], (array) $answer->getData(true));
        $this->assertSame(self::APPROVED, (int) DB::table('withdraw_requests')->where('id', self::REQUEST)->value('approved'));
    }

    /** The settlement is one transaction with the wallet's row locked in it, which is what makes two decisions at once safe. */
    public function test_the_settlement_locks_the_wallet_inside_the_transaction_that_writes_it(): void
    {
        $source = (string) file_get_contents(app_path('Services/DeliveryManWithdrawService.php'));
        $transactionAt = strpos($source, 'return DB::transaction(');
        $lockAt = strpos($source, '->lockForUpdate()');
        $claimAt = strpos($source, "->where(['id' => \$withdraw['id'], 'approved' => 0])");

        $this->assertNotFalse($transactionAt, 'the decision is made in one transaction');
        $this->assertGreaterThan($transactionAt, $lockAt, 'the wallet is read under its row lock inside that transaction');
        $this->assertGreaterThan($lockAt, $claimAt, 'and the request is claimed after the lock is held, so two decisions serialise');
    }
}
