<?php

namespace Tests\Feature\Loyalty;

use App\Http\Controllers\RestAPI\v1\UserLoyaltyController as ApiUserLoyaltyController;
use App\Http\Controllers\Web\UserLoyaltyController as WebUserLoyaltyController;
use App\Http\Requests\Web\LoyaltyExchangeCurrencyRequest;
use App\Models\User;
use App\Services\Loyalty\PointsToWalletConversion;
use App\Services\Loyalty\WhatAPointIsWorth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Loyalty\LoyaltyTables;
use Tests\Support\Refund\RefundTables;
use Tests\Support\RestoresLanguageFiles;
use Tests\Support\Wallet\WalletTables;
use Tests\TestCase;

/**
 * The owner's answer to LF-1 reaches the two endpoints a customer actually converts points through (E-14).
 *
 * `App\Services\Loyalty\WhatAPointIsWorth` was built so that answering "what is a loyalty point worth"
 * would be ONE SETTING rather than a hunt through the call sites, and `docs/gcc/OPEN-FINANCIAL-DECISIONS.md`
 * says so of itself. **It was wired into `App\Services\Customer\WalletPosting`, and nothing calls that
 * class.** Both loyalty controllers even import it and then use the old writers:
 * `RestAPI\v1\UserLoyaltyController` calls `CustomerManager::create_wallet_transaction` and
 * `Web\UserLoyaltyController` calls `CustomerTrait::createWalletTransaction`, each of which carried its own
 * copy of a formula. So the setting existed, was tested, was documented as the way to adopt an answer --
 * and moved no money at all. The owner could have answered LF-1, set the value, and watched nothing happen.
 *
 * Both writers now ask the one class. These cases are the proof of that, and they are deliberately driven
 * through the controllers rather than through the class: a test of the helper is what let this go unnoticed
 * in the first place.
 *
 * The figures are the ones `PointsToWalletFormulaCharacterizationTest` has measured all along -- 100 points
 * at a rate of 100 with the riyal at 3.75 -- so the first case here and that whole file are the same claim
 * read twice: **with the setting as it ships, each path credits exactly what it credited before.**
 */
class TheLoyaltyDecisionReachesTheRealEndpointsTest extends TestCase
{
    use RestoresLanguageFiles;

    private const CUSTOMER_ID = 1;
    private const POINTS = 100;
    private const SAR_PER_USD = 3.75;

    /** The ledger column holds three decimals on a real server, so 1 / 3.75 is stored as 0.267. */
    private const DELTA = 0.0005;

    /** `rate` points buy one US dollar's worth: one stored unit, which is 3.75 riyals. */
    private const ONE_DOLLAR = 1.0;

    /** `rate` points buy one unit of the currency in force: one riyal, which is 0.267 stored units. */
    private const ONE_RIYAL = 1.0 / self::SAR_PER_USD;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        WalletTables::create();
        LoyaltyTables::create();
        RefundTables::create();
        WalletTables::setSetting('wallet_status', '1');
        WalletTables::setSetting('loyalty_point_minimum_point', '10');
        WalletTables::setSetting('system_default_currency', '2');
        WalletTables::setSetting('currency_model', 'multi_currency');
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

        parent::tearDown();
    }

    private function theRuleIs(string $rule): void
    {
        config(['gcc.loyalty.point_value' => $rule]);
    }

    // ---------------------------------------------------------------- as it ships

    public function test_as_it_ships_each_path_credits_exactly_what_it_credited_before(): void
    {
        $this->theRuleIs(WhatAPointIsWorth::AS_EACH_PATH_DOES_TODAY);

        $this->assertEqualsWithDelta(self::ONE_DOLLAR, $this->creditedThroughTheApi(), self::DELTA);
        $this->assertEqualsWithDelta(self::ONE_RIYAL, $this->creditedThroughTheWeb(), self::DELTA);
    }

    /** A value nobody recognises is read as today's behaviour: a typo in an environment file must not move money. */
    public function test_a_value_nobody_recognises_is_read_as_today(): void
    {
        $this->theRuleIs('whatever_somebody_typed');

        $this->assertEqualsWithDelta(self::ONE_DOLLAR, $this->creditedThroughTheApi(), self::DELTA);
        $this->assertEqualsWithDelta(self::ONE_RIYAL, $this->creditedThroughTheWeb(), self::DELTA);
    }

    public function test_with_no_value_at_all_each_path_credits_what_it_credited_before(): void
    {
        config(['gcc.loyalty.point_value' => null]);

        $this->assertEqualsWithDelta(self::ONE_DOLLAR, $this->creditedThroughTheApi(), self::DELTA);
        $this->assertEqualsWithDelta(self::ONE_RIYAL, $this->creditedThroughTheWeb(), self::DELTA);
    }

    // ---------------------------------------------------------------- the two answers, adopted

    /**
     * Option A: `rate` points buy one US dollar's worth everywhere, which is what the apps already pay and
     * what the admin field's own label promises. The WEBSITE is what moves -- 3.75 times more generous on a
     * Saudi store -- and that is the whole point of the measurement.
     */
    public function test_option_a_makes_both_paths_credit_one_dollars_worth(): void
    {
        $this->theRuleIs(WhatAPointIsWorth::ONE_DOLLAR_PER_RATE);

        $this->assertEqualsWithDelta(self::ONE_DOLLAR, $this->creditedThroughTheApi(), self::DELTA);
        $this->assertEqualsWithDelta(self::ONE_DOLLAR, $this->creditedThroughTheWeb(), self::DELTA, 'the website still used its own formula, so the answer reached nothing');
    }

    /** Option B: `rate` points buy one unit of the currency in force. The APPS are what move, by a third. */
    public function test_option_b_makes_both_paths_credit_one_unit_in_force(): void
    {
        $this->theRuleIs(WhatAPointIsWorth::ONE_UNIT_IN_FORCE_PER_RATE);

        $this->assertEqualsWithDelta(self::ONE_RIYAL, $this->creditedThroughTheApi(), self::DELTA, 'the REST path still used its own formula, so the answer reached nothing');
        $this->assertEqualsWithDelta(self::ONE_RIYAL, $this->creditedThroughTheWeb(), self::DELTA);
    }

    /** Whichever answer is adopted, the two paths agree -- which is the criterion the decision unblocks. */
    public function test_under_either_answer_the_two_paths_credit_the_same_amount(): void
    {
        foreach ([WhatAPointIsWorth::ONE_DOLLAR_PER_RATE, WhatAPointIsWorth::ONE_UNIT_IN_FORCE_PER_RATE] as $rule) {
            $this->theRuleIs($rule);

            $this->assertEqualsWithDelta(
                $this->creditedThroughTheApi(),
                $this->creditedThroughTheWeb(),
                self::DELTA,
                "the two paths still disagree under {$rule}",
            );
        }
    }

    // ---------------------------------------------------------------- the market's own rate

    /**
     * The REST writer read `loyalty_point_exchange_rate` STRAIGHT OFF `business_settings` -- the one reader of
     * that setting the per-market override could not reach (E-02) -- so a conversion made in a market with its
     * own rate converted at the PLATFORM's. It now reads through `getWebConfig`, as every other reader of the
     * four loyalty settings does, and the figure below says which rate was read: the platform's is 100, Kuwait
     * is given 50, so the same 100 points are worth twice as much there.
     *
     * DRIVEN THROUGH THE WRITER AND NOT THROUGH THE ENDPOINT, because the endpoint cannot be reached in such a
     * market and that is a finding of its own rather than a gap in this test. A storefront trading in its own
     * currency has its WALLET CLOSED (GCC-05 slice 5b): `getWebConfig('wallet_status')` answers 0 there -- which
     * this test measured at 0 for Kuwait -- so both loyalty controllers refuse with 422 before any rate is read.
     * The same switch that makes a market answer its own loyalty settings is the switch that closes its wallet,
     * so the per-market exchange rate cannot be observed end to end on any market today. Recorded in
     * coverage-gaps. The writer is the thing this change moved, and the writer is what is measured.
     */
    public function test_the_rest_writer_reads_the_exchange_rate_of_the_market_being_served(): void
    {
        $this->theRuleIs(WhatAPointIsWorth::AS_EACH_PATH_DOES_TODAY);
        $this->aMarketWithItsOwnExchangeRate('KW', rate: '50');

        $credited = $this->servedBy('KW', fn (): float => $this->creditedThroughTheRestWriter());

        $this->assertEqualsWithDelta(2 * self::ONE_DOLLAR, $credited, self::DELTA, "the market's own exchange rate was not read");
    }

    public function test_a_market_with_no_rate_of_its_own_converts_at_the_platforms(): void
    {
        $this->theRuleIs(WhatAPointIsWorth::AS_EACH_PATH_DOES_TODAY);
        $this->aMarketWithItsOwnExchangeRate('KW', rate: '50');

        $credited = $this->servedBy('SA', fn (): float => $this->creditedThroughTheRestWriter());

        $this->assertEqualsWithDelta(self::ONE_DOLLAR, $credited, self::DELTA, "a market with no rate of its own read another market's");
    }

    /** The default market, with no storefront served at all: the platform of today. */
    public function test_with_no_market_served_the_writer_converts_at_the_platforms_rate(): void
    {
        $this->theRuleIs(WhatAPointIsWorth::AS_EACH_PATH_DOES_TODAY);
        $this->aMarketWithItsOwnExchangeRate('KW', rate: '50');

        $this->assertEqualsWithDelta(self::ONE_DOLLAR, $this->creditedThroughTheRestWriter(), self::DELTA);
    }

    // ---------------------------------------------------------------- driving the real endpoints

    private function creditedThroughTheApi(): float
    {
        $request = Request::create('/api/v1/customer/loyalty/loyalty-exchange-currency', 'POST', ['point' => self::POINTS]);
        $request->setUserResolver(fn () => User::query()->find(self::CUSTOMER_ID));

        $this->assertSame(200, app(ApiUserLoyaltyController::class)->loyalty_exchange_currency($request, app(PointsToWalletConversion::class))->getStatusCode());

        return $this->theOneCreditAndThenForgetIt();
    }

    /**
     * The writer the REST endpoint calls, asked directly. `create_wallet_transaction` reads `wallet_status` off
     * `business_settings` rather than through the storefront seam, so it still writes in a market whose wallet
     * the controller above it has closed -- which is what makes the rate measurable at all.
     */
    private function creditedThroughTheRestWriter(): float
    {
        \App\Utils\CustomerManager::create_wallet_transaction(self::CUSTOMER_ID, self::POINTS, 'loyalty_point', 'point_to_wallet');

        return $this->theOneCreditAndThenForgetIt();
    }

    private function creditedThroughTheWeb(): float
    {
        $this->actingAs(User::query()->findOrFail(self::CUSTOMER_ID), 'customer');

        app(WebUserLoyaltyController::class)->getLoyaltyExchangeCurrency(
            LoyaltyExchangeCurrencyRequest::create('/loyalty-exchange-currency', 'POST', ['point' => self::POINTS]),
        );

        return $this->theOneCreditAndThenForgetIt();
    }

    /**
     * The credit of the conversion just made, and then the row is removed so a second conversion in the same
     * test method reads its own figure rather than whichever row came back first.
     */
    private function theOneCreditAndThenForgetIt(): float
    {
        $rows = DB::table('wallet_transactions')->where('transaction_type', 'loyalty_point')->get();
        $this->assertCount(1, $rows, 'the conversion did not run, or ran twice');
        DB::table('wallet_transactions')->where('transaction_type', 'loyalty_point')->delete();

        return (float) $rows->first()->credit;
    }

    /** What an operator gives a market with `gcc:storefront:money`, and the switches that make a market answer it. */
    private function aMarketWithItsOwnExchangeRate(string $country, string $rate): void
    {
        RefundTables::seedCurrency(id: 3, code: 'KWD', exchangeRate: 0.307);
        DB::table('business_settings')->updateOrInsert(
            ['type' => 'storefront_money_' . strtolower($country)],
            ['value' => json_encode(['currency' => 'KWD', 'loyalty_point_status' => '1', 'loyalty_point_exchange_rate' => $rate])],
        );

        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.currency.enabled' => true,
            'gcc.storefronts.countries.' . strtoupper($country) . '.active' => true,
        ]);
        clearWebConfigCacheKeys();
    }

    private function servedBy(string $market, callable $read): mixed
    {
        return \App\Services\Storefront\StorefrontResolver::fromConfig()->context(explicitChoice: $market)->serve($read);
    }
}
