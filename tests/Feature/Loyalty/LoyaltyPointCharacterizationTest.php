<?php

namespace Tests\Feature\Loyalty;

use App\Contracts\Repositories\LoyaltyPointTransactionRepositoryInterface;
use App\Models\WalletTransaction;
use App\Services\Loyalty\PointsToWalletConversion;
use App\Utils\CustomerManager;
use Closure;
use Illuminate\Support\Facades\DB;
use Tests\Support\Loyalty\LoyaltyTables;
use Tests\Support\Wallet\WalletTables;
use Tests\TestCase;

/**
 * Loyalty points as the writers handle them today, and the points-to-wallet conversion that the REST and web controllers run.
 *
 * Tests whose names start with test_current_behaviour pin what the code does now. The owner has not endorsed that behaviour.
 */
class LoyaltyPointCharacterizationTest extends TestCase
{
    private const CUSTOMER_ID = 1;

    protected function setUp(): void
    {
        parent::setUp();
        LoyaltyTables::drop();
        WalletTables::drop();
        WalletTables::create();
        LoyaltyTables::create();
        WalletTables::setSetting('wallet_status', '1');
        LoyaltyTables::enableLoyaltyPoints();
        WalletTables::seedCustomer(self::CUSTOMER_ID, 0.0);
    }

    protected function tearDown(): void
    {
        LoyaltyTables::drop();
        WalletTables::drop();
        parent::tearDown();
    }

    public function test_an_order_credit_rounds_the_points_down_to_whole_points(): void
    {
        $this->withPoints(0);

        $written = $this->repository()->addLoyaltyPointTransaction(self::CUSTOMER_ID, 'order-1', 99.99, 'order_place');

        $this->assertTrue($written);
        $this->assertSame(9.0, LoyaltyTables::loyaltyPointsOf(self::CUSTOMER_ID));
    }

    public function test_an_order_worth_less_than_one_point_writes_nothing_through_the_repository(): void
    {
        $this->withPoints(0);

        $this->assertFalse($this->repository()->addLoyaltyPointTransaction(self::CUSTOMER_ID, 'order-2', 5.0, 'order_place'));
        $this->assertSame(0, DB::table('loyalty_point_transactions')->count());
    }

    /** Not endorsed. The repository refuses the same zero-point credit that CustomerManager writes as a zero-point row. */
    public function test_current_behaviour_customer_manager_writes_a_zero_point_row_for_the_same_order(): void
    {
        $this->withPoints(0);

        $this->assertTrue(CustomerManager::create_loyalty_point_transaction(self::CUSTOMER_ID, 'order-3', 5.0, 'order_place'));
        $this->assertSame(1, DB::table('loyalty_point_transactions')->count());
    }

    public function test_with_loyalty_points_switched_off_nothing_is_written(): void
    {
        $this->withPoints(50);
        WalletTables::setSetting('loyalty_point_status', '0');

        $this->assertTrue($this->repository()->addLoyaltyPointTransaction(self::CUSTOMER_ID, 'order-4', 100.0, 'order_place'));
        $this->assertSame(50.0, LoyaltyTables::loyaltyPointsOf(self::CUSTOMER_ID));
        $this->assertSame(0, DB::table('loyalty_point_transactions')->count());
    }

    public function test_a_points_debit_above_the_balance_is_refused_and_nothing_is_written(): void
    {
        $this->withPoints(100);

        $this->assertFalse($this->repository()->addLoyaltyPointTransaction(self::CUSTOMER_ID, 'convert-5', 150.0, 'point_to_wallet'));
        $this->assertSame(100.0, LoyaltyTables::loyaltyPointsOf(self::CUSTOMER_ID));
        $this->assertSame(0, DB::table('loyalty_point_transactions')->count());
    }

    /** Not endorsed. Refund debits are not checked against the balance. Whether a refund may take points negative is an owner decision. */
    public function test_current_behaviour_a_refund_can_take_the_points_below_zero(): void
    {
        $this->withPoints(10);

        $this->assertTrue($this->repository()->addLoyaltyPointTransaction(self::CUSTOMER_ID, 'refund-6', 30.0, 'refund_order'));
        $this->assertSame(-20.0, LoyaltyTables::loyaltyPointsOf(self::CUSTOMER_ID));
    }

    public function test_a_missing_customer_is_refused_by_the_repository(): void
    {
        $this->assertFalse($this->repository()->addLoyaltyPointTransaction(999, 'order-7', 100.0, 'order_place'));
        $this->assertSame(0, DB::table('loyalty_point_transactions')->count());
    }

    public function test_a_conversion_refused_for_lack_of_points_rolls_back_the_wallet_credit(): void
    {
        $this->withPoints(100);

        $result = $this->conversion()->convert(self::CUSTOMER_ID, 150.0, $this->creditWallet(150.0));

        $this->assertFalse($result);
        $this->assertSame(0.0, WalletTables::walletBalance(self::CUSTOMER_ID));
        $this->assertSame(0, DB::table('wallet_transactions')->count());
        $this->assertSame(100.0, LoyaltyTables::loyaltyPointsOf(self::CUSTOMER_ID));
        $this->assertSame(0, DB::table('loyalty_point_transactions')->count());
    }

    public function test_a_completed_conversion_credits_the_wallet_and_debits_the_points_once(): void
    {
        $this->withPoints(100);

        $wallet = $this->conversion()->convert(self::CUSTOMER_ID, 60.0, $this->creditWallet(60.0));

        $this->assertInstanceOf(WalletTransaction::class, $wallet);
        $this->assertSame(40.0, LoyaltyTables::loyaltyPointsOf(self::CUSTOMER_ID));
        $this->assertSame((float) $wallet->credit, WalletTables::walletBalance(self::CUSTOMER_ID));
        $this->assertSame(1, DB::table('wallet_transactions')->count());
        $this->assertSame(1, DB::table('loyalty_point_transactions')->where('transaction_type', 'point_to_wallet')->count());
        $this->assertSame((string) $wallet->transaction_id, DB::table('loyalty_point_transactions')->value('reference'));
    }

    public function test_a_wallet_credit_that_is_refused_leaves_the_points_alone(): void
    {
        $this->withPoints(100);

        $result = $this->conversion()->convert(self::CUSTOMER_ID, 60.0, fn () => false);

        $this->assertFalse($result);
        $this->assertSame(100.0, LoyaltyTables::loyaltyPointsOf(self::CUSTOMER_ID));
        $this->assertSame(0, DB::table('loyalty_point_transactions')->count());
    }

    private function repository(): LoyaltyPointTransactionRepositoryInterface
    {
        return app(LoyaltyPointTransactionRepositoryInterface::class);
    }

    private function conversion(): PointsToWalletConversion
    {
        return app(PointsToWalletConversion::class);
    }

    /** The wallet credit the REST conversion makes, for a given number of points. */
    private function creditWallet(float $points): Closure
    {
        return fn () => CustomerManager::create_wallet_transaction(
            user_id: self::CUSTOMER_ID,
            amount: $points,
            transaction_type: 'loyalty_point',
            reference: 'point_to_wallet',
        );
    }

    private function withPoints(float $points): void
    {
        DB::table('users')->where('id', self::CUSTOMER_ID)->update(['loyalty_point' => $points]);
    }
}
