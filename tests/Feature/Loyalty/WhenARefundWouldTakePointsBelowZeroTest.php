<?php

namespace Tests\Feature\Loyalty;

use App\Contracts\Repositories\LoyaltyPointTransactionRepositoryInterface;
use App\Services\Loyalty\WhenARefundWouldTakePointsBelowZero;
use App\Utils\CustomerManager;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Loyalty\LoyaltyTables;
use Tests\Support\Wallet\WalletTables;
use Tests\TestCase;

/**
 * E-14 prepared, not adopted: what happens when a refund takes back more loyalty points than the customer still holds.
 *
 * Today the balance goes NEGATIVE -- a customer holding 10 points whose order is refunded for 30 ends on -20, and the next
 * order they place starts by paying that off. Both writers check a `point_to_wallet` debit against the balance and neither
 * checks a `refund_order` debit at all. The criterion names its own three answers and says the choice is the owner's
 * (`docs/gcc/OPEN-FINANCIAL-DECISIONS.md` section 5), so all three live behind one setting that ships as today's.
 *
 * Every case runs through BOTH writers of a point balance -- the repository the admin and vendor refund screens use and
 * `CustomerManager`'s copy the seller API uses -- because two writers of one customer's points disagreeing about this is the
 * defect underneath the defect.
 */
class WhenARefundWouldTakePointsBelowZeroTest extends TestCase
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

    /** @return array<string, array{0: string}> */
    public static function writers(): array
    {
        return ['repository' => ['repository'], 'customer manager' => ['customer_manager']];
    }

    public function test_the_setting_ships_as_todays_behaviour(): void
    {
        $this->assertSame(WhenARefundWouldTakePointsBelowZero::ALLOW_AS_TODAY, WhenARefundWouldTakePointsBelowZero::rule());
    }

    #[DataProvider('writers')]
    public function test_as_it_ships_a_refund_takes_the_points_below_zero_through_either_writer(string $writer): void
    {
        $this->withPoints(10);

        $this->assertTrue($this->refund($writer, points: 30.0), 'the row is written');
        $this->assertSame(-20.0, LoyaltyTables::loyaltyPointsOf(self::CUSTOMER_ID), 'and the balance is negative, which is the platform of today');
        $this->assertSame(30.0, $this->debitWritten(), 'the row carries the whole debit');
        $this->assertSame(-20.0, $this->balanceWritten(), 'and its running balance is the negative figure, so the ledger still adds up');
    }

    #[DataProvider('writers')]
    public function test_under_refuse_nothing_is_written_and_the_points_stand(string $writer): void
    {
        config(['gcc.loyalty.when_a_refund_would_take_points_below_zero' => WhenARefundWouldTakePointsBelowZero::REFUSE]);
        $this->withPoints(10);

        $this->assertFalse($this->refund($writer, points: 30.0));
        $this->assertSame(10.0, LoyaltyTables::loyaltyPointsOf(self::CUSTOMER_ID));
        $this->assertSame(0, DB::table('loyalty_point_transactions')->count(), 'and no row at all, which is the shape a conversion above the balance already has');
    }

    #[DataProvider('writers')]
    public function test_under_clamp_the_refund_takes_what_is_there_and_the_balance_lands_on_zero(string $writer): void
    {
        config(['gcc.loyalty.when_a_refund_would_take_points_below_zero' => WhenARefundWouldTakePointsBelowZero::CLAMP_TO_ZERO]);
        $this->withPoints(10);

        $this->assertTrue($this->refund($writer, points: 30.0));
        $this->assertSame(0.0, LoyaltyTables::loyaltyPointsOf(self::CUSTOMER_ID));
        $this->assertSame(10.0, $this->debitWritten(), 'the row records the debit that was actually taken and not the one that was asked for');
        $this->assertSame(0.0, $this->balanceWritten(), 'so the running balance is the balance');
    }

    #[DataProvider('writers')]
    public function test_under_clamp_a_customer_with_no_points_left_has_nothing_taken_and_no_row_written(string $writer): void
    {
        config(['gcc.loyalty.when_a_refund_would_take_points_below_zero' => WhenARefundWouldTakePointsBelowZero::CLAMP_TO_ZERO]);
        $this->withPoints(0);

        $this->assertFalse($this->refund($writer, points: 30.0));
        $this->assertSame(0.0, LoyaltyTables::loyaltyPointsOf(self::CUSTOMER_ID));
        $this->assertSame(0, DB::table('loyalty_point_transactions')->count(), 'a debit of nothing is not a row');
    }

    #[DataProvider('writers')]
    public function test_a_refund_within_the_balance_is_the_same_under_every_rule(string $writer): void
    {
        foreach ([WhenARefundWouldTakePointsBelowZero::ALLOW_AS_TODAY, WhenARefundWouldTakePointsBelowZero::REFUSE, WhenARefundWouldTakePointsBelowZero::CLAMP_TO_ZERO] as $rule) {
            config(['gcc.loyalty.when_a_refund_would_take_points_below_zero' => $rule]);
            DB::table('loyalty_point_transactions')->delete();
            $this->withPoints(50);

            $this->assertTrue($this->refund($writer, points: 30.0), $rule);
            $this->assertSame(20.0, LoyaltyTables::loyaltyPointsOf(self::CUSTOMER_ID), $rule . ': a refund the customer can afford is untouched by any of the three');
            $this->assertSame(30.0, $this->debitWritten(), $rule);
        }
    }

    #[DataProvider('writers')]
    public function test_a_value_the_setting_does_not_recognise_is_read_as_todays_behaviour(string $writer): void
    {
        config(['gcc.loyalty.when_a_refund_would_take_points_below_zero' => 'refuze']);
        $this->withPoints(10);

        $this->assertSame(WhenARefundWouldTakePointsBelowZero::ALLOW_AS_TODAY, WhenARefundWouldTakePointsBelowZero::rule());
        $this->assertTrue($this->refund($writer, points: 30.0));
        $this->assertSame(-20.0, LoyaltyTables::loyaltyPointsOf(self::CUSTOMER_ID), 'a typo must not change a customer\'s points');
    }

    public function test_a_conversion_above_the_balance_is_still_refused_under_every_rule(): void
    {
        foreach ([WhenARefundWouldTakePointsBelowZero::ALLOW_AS_TODAY, WhenARefundWouldTakePointsBelowZero::REFUSE, WhenARefundWouldTakePointsBelowZero::CLAMP_TO_ZERO] as $rule) {
            config(['gcc.loyalty.when_a_refund_would_take_points_below_zero' => $rule]);
            $this->withPoints(10);

            $this->assertFalse(
                $this->repository()->addLoyaltyPointTransaction(self::CUSTOMER_ID, 'convert', 30.0, 'point_to_wallet'),
                $rule . ': this setting is about refunds and must not reach the conversion guard',
            );
            $this->assertSame(10.0, LoyaltyTables::loyaltyPointsOf(self::CUSTOMER_ID), $rule);
        }
    }

    private function refund(string $writer, float $points): bool
    {
        if ($writer === 'repository') {
            return $this->repository()->addLoyaltyPointTransaction(self::CUSTOMER_ID, 'refund-order', $points, 'refund_order');
        }

        return (bool) CustomerManager::create_loyalty_point_transaction(self::CUSTOMER_ID, 'refund-order', $points, 'refund_order');
    }

    private function debitWritten(): float
    {
        return (float) DB::table('loyalty_point_transactions')->orderByDesc('id')->value('debit');
    }

    private function balanceWritten(): float
    {
        return (float) DB::table('loyalty_point_transactions')->orderByDesc('id')->value('balance');
    }

    private function withPoints(float $points): void
    {
        DB::table('users')->where('id', self::CUSTOMER_ID)->update(['loyalty_point' => $points]);
    }

    private function repository(): LoyaltyPointTransactionRepositoryInterface
    {
        return app(LoyaltyPointTransactionRepositoryInterface::class);
    }
}
