<?php

namespace Tests\Feature\Loyalty;

use App\Contracts\Repositories\LoyaltyPointTransactionRepositoryInterface;
use App\Http\Controllers\RestAPI\v1\UserLoyaltyController;
use App\Models\User;
use App\Services\Loyalty\PointsToWalletConversion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\Support\Loyalty\LoyaltyTables;
use Tests\Support\RestoresLanguageFiles;
use Tests\Support\Wallet\WalletTables;
use Tests\TestCase;

/** The REST points-to-wallet endpoint: its refusal branch and its success branch, through the conversion service. */
class LoyaltyExchangeEndpointTest extends TestCase
{
    use RestoresLanguageFiles;

    private const CUSTOMER_ID = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rememberLanguageFiles();
        WalletTables::create();
        LoyaltyTables::create();
        WalletTables::setSetting('wallet_status', '1');
        WalletTables::setSetting('loyalty_point_minimum_point', '10');
        LoyaltyTables::enableLoyaltyPoints();
        WalletTables::seedCustomer(self::CUSTOMER_ID, 0.0);
        DB::table('users')->where('id', self::CUSTOMER_ID)->update(['loyalty_point' => 100.0]);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();
        parent::tearDown();
    }

    public function test_a_refused_points_debit_answers_with_the_insufficient_points_message_and_rolls_back_the_wallet_credit(): void
    {
        $this->mock(LoyaltyPointTransactionRepositoryInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('addLoyaltyPointTransaction')->once()->andReturn(false);
        });

        $response = $this->exchange(60);

        $response->assertStatus(422);
        $response->assertJsonPath('message', translate('insufficient_point!'));
        $this->assertSame(0, DB::table('wallet_transactions')->count());
        $this->assertSame(100.0, LoyaltyTables::loyaltyPointsOf(self::CUSTOMER_ID));
    }

    public function test_a_completed_conversion_moves_the_points_into_the_wallet(): void
    {
        $response = $this->exchange(60);

        $response->assertStatus(200);
        $this->assertSame(40.0, LoyaltyTables::loyaltyPointsOf(self::CUSTOMER_ID));
        $this->assertSame(1, DB::table('wallet_transactions')->count());
        $this->assertSame(1, DB::table('loyalty_point_transactions')->where('transaction_type', 'point_to_wallet')->count());
    }

    public function test_more_points_than_the_customer_has_are_refused_before_anything_is_written(): void
    {
        $response = $this->exchange(150);

        $response->assertStatus(422);
        $this->assertSame(100.0, LoyaltyTables::loyaltyPointsOf(self::CUSTOMER_ID));
        $this->assertSame(0, DB::table('wallet_transactions')->count());
    }

    private function exchange(int $points): TestResponse
    {
        $request = Request::create('/api/v1/customer/loyalty/exchange', 'POST', ['point' => $points]);
        $request->setUserResolver(fn () => User::query()->find(self::CUSTOMER_ID));

        $response = app(UserLoyaltyController::class)->loyalty_exchange_currency($request, app(PointsToWalletConversion::class));

        return TestResponse::fromBaseResponse($response);
    }
}
