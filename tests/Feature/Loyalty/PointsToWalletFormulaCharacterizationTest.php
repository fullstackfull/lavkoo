<?php

namespace Tests\Feature\Loyalty;

use App\Http\Controllers\RestAPI\v1\UserLoyaltyController as ApiUserLoyaltyController;
use App\Http\Controllers\Web\UserLoyaltyController as WebUserLoyaltyController;
use App\Http\Requests\Web\LoyaltyExchangeCurrencyRequest;
use App\Models\User;
use App\Services\Loyalty\PointsToWalletConversion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Loyalty\LoyaltyTables;
use Tests\Support\Refund\RefundTables;
use Tests\Support\RestoresLanguageFiles;
use Tests\Support\Wallet\WalletTables;
use Tests\TestCase;

/**
 * What the REST and web points-to-wallet conversions credit today for the same 100 points, with SAR as the default currency at
 * 3.75 to the dollar and 100 points to one unit.
 *
 * These are characterization tests of current behaviour, not of correct behaviour. The two controllers use different formulas
 * (LF-1 under multi_currency, LF-2 under single_currency with a stored rate), so the same points buy a different wallet credit
 * depending on which client the customer uses. The expected values below describe that divergence. Which value is right is an
 * owner decision: see docs/wallet/refund-loyalty-idempotency-proposals.md#lf-1 and #lf-2.
 */
class PointsToWalletFormulaCharacterizationTest extends TestCase
{
    use RestoresLanguageFiles;

    private const CUSTOMER_ID = 1;
    private const POINTS = 100;
    private const SAR_PER_USD = 3.75;
    private const DELTA = 0.0001;

    /** The REST conversion multiplies by Convert::default(1) and divides by the same rate again: one dollar, 3.75 SAR. */
    private const API_MULTI_CURRENCY_CREDIT = 1.0;

    /** The web conversion goes from points to the session currency, then divides by the session rate twice: 1 SAR, in dollars. */
    private const WEB_MULTI_CURRENCY_CREDIT = 1.0 / self::SAR_PER_USD;

    /** Under single_currency the REST conversion ignores the stored rate. */
    private const API_SINGLE_CURRENCY_CREDIT = 1.0;

    /** Under single_currency the web conversion still divides by the stored session rate. */
    private const WEB_SINGLE_CURRENCY_CREDIT = 1.0 / self::SAR_PER_USD;

    private string $languageFilesBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rememberLanguageFiles();
        $this->languageFilesBefore = $this->languageFilesFingerprint();
        WalletTables::create();
        LoyaltyTables::create();
        RefundTables::create();
        WalletTables::setSetting('wallet_status', '1');
        WalletTables::setSetting('loyalty_point_minimum_point', '10');
        WalletTables::setSetting('system_default_currency', '2');
        LoyaltyTables::enableLoyaltyPoints();
        RefundTables::seedCurrency(id: 1, code: 'USD', exchangeRate: 1.0);
        RefundTables::seedCurrency(id: 2, code: 'SAR', exchangeRate: self::SAR_PER_USD);
        WalletTables::seedCustomer(self::CUSTOMER_ID, 0.0);
        DB::table('users')->where('id', self::CUSTOMER_ID)->update(['loyalty_point' => 1000]);
        session(['currency_code' => 'SAR', 'currency_exchange_rate' => self::SAR_PER_USD]);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();
        $this->assertSame($this->languageFilesBefore, $this->languageFilesFingerprint(), 'resources/lang must be unchanged');
        parent::tearDown();
    }

    public function test_current_behaviour_under_multi_currency_the_api_credits_one_dollar_for_one_hundred_points(): void
    {
        WalletTables::setSetting('currency_model', 'multi_currency');

        $this->convertThroughApi();

        $this->assertEqualsWithDelta(self::API_MULTI_CURRENCY_CREDIT, $this->walletCredit(), self::DELTA);
    }

    public function test_current_behaviour_under_multi_currency_the_web_credits_one_riyal_in_dollars_for_one_hundred_points(): void
    {
        WalletTables::setSetting('currency_model', 'multi_currency');

        $this->convertThroughWeb();

        $this->assertEqualsWithDelta(self::WEB_MULTI_CURRENCY_CREDIT, $this->walletCredit(), self::DELTA);
    }

    public function test_current_behaviour_under_single_currency_the_api_credits_one_unit_for_one_hundred_points(): void
    {
        WalletTables::setSetting('currency_model', 'single_currency');

        $this->convertThroughApi();

        $this->assertEqualsWithDelta(self::API_SINGLE_CURRENCY_CREDIT, $this->walletCredit(), self::DELTA);
    }

    public function test_current_behaviour_under_single_currency_the_web_divides_by_the_stored_rate(): void
    {
        WalletTables::setSetting('currency_model', 'single_currency');

        $this->convertThroughWeb();

        $this->assertEqualsWithDelta(self::WEB_SINGLE_CURRENCY_CREDIT, $this->walletCredit(), self::DELTA);
    }

    private function convertThroughApi(): void
    {
        $request = Request::create('/api/v1/customer/loyalty/loyalty-exchange-currency', 'POST', ['point' => self::POINTS]);
        $request->setUserResolver(fn () => User::query()->find(self::CUSTOMER_ID));

        $response = app(ApiUserLoyaltyController::class)->loyalty_exchange_currency($request, app(PointsToWalletConversion::class));

        $this->assertSame(200, $response->getStatusCode());
    }

    private function convertThroughWeb(): void
    {
        $this->actingAs(User::query()->findOrFail(self::CUSTOMER_ID), 'customer');
        $request = LoyaltyExchangeCurrencyRequest::create('/loyalty-exchange-currency', 'POST', ['point' => self::POINTS]);

        app(WebUserLoyaltyController::class)->getLoyaltyExchangeCurrency($request);

        $this->assertSame(1000.0 - self::POINTS, LoyaltyTables::loyaltyPointsOf(self::CUSTOMER_ID), 'the web conversion must have run');
    }

    private function walletCredit(): float
    {
        $this->assertSame(1, DB::table('wallet_transactions')->where('transaction_type', 'loyalty_point')->count());

        return (float) DB::table('wallet_transactions')->where('transaction_type', 'loyalty_point')->value('credit');
    }

    private function languageFilesFingerprint(): string
    {
        return (string) shell_exec('git -C ' . escapeshellarg(base_path()) . ' status --porcelain -- resources/lang');
    }
}
