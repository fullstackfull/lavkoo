<?php

namespace Tests\Feature\Storefront;

use App\Services\Customer\WalletOrderDebit;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsStorefrontMoneyWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The unit a wallet order debit is counted in, per market (E-16).
 *
 * `WalletOrderDebit` rounded the stored amount half up to TWO places, unconditionally, for the balance check and the debit alike. Two is
 * the riyal's, the dirham's, the Qatari riyal's and the dollar's smallest coin. The Kuwaiti dinar, the Bahraini dinar and the Omani rial
 * have THREE -- ISO 4217, and `gcc.storefronts.currency.decimals` says so -- so in those three markets the platform rounded money to a
 * unit ten times coarser than the smallest coin the currency has.
 *
 * `gcc.wallet.debit_in_the_markets_minor_unit` is the switch, OFF as it ships, and the first group below is that: with it off all six
 * markets round at two places, which is the platform of today to the digit.
 *
 * It lives beside the other storefront-money tests rather than in `tests/Feature/Wallet`, because the fixture it needs builds
 * `business_settings` and `currencies` and drops them again, which in the wallet group would take those tables away from every class that
 * ran after it. `StorefrontWalletClosedTest` is here for the same reason.
 *
 * The figure the tests are built on is **10.0155**. At two places it is 10.02 and at three it is 10.016, so every assertion here reads a
 * number that could only have come from one of the two units -- a figure that rounded the same way at both would prove nothing.
 */
class AWalletDebitIsCountedInTheMarketsMinorUnitTest extends TestCase
{
    use BuildsStorefrontMoneyWorld;
    use RestoresLanguageFiles;

    /** 10.02 at two places, 10.016 at three. */
    private const AMOUNT = 10.0155;

    private const AT_TWO_PLACES = 10.02;

    private const AT_THREE_PLACES = 10.016;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildStorefrontMoneyWorld();
        config([WalletOrderDebit::MARKET_MINOR_UNIT_SWITCH => false]);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();
        $this->dropStorefrontMoneyWorld();

        parent::tearDown();
    }

    /** @return array<string, array{country: string, currency: string, places: int}> every market the programme serves, and the places of its money */
    public static function everyMarket(): array
    {
        return [
            'Saudi Arabia' => ['country' => 'SA', 'currency' => 'SAR', 'places' => 2],
            'United Arab Emirates' => ['country' => 'AE', 'currency' => 'AED', 'places' => 2],
            'Qatar' => ['country' => 'QA', 'currency' => 'QAR', 'places' => 2],
            'Kuwait' => ['country' => 'KW', 'currency' => 'KWD', 'places' => 3],
            'Bahrain' => ['country' => 'BH', 'currency' => 'BHD', 'places' => 3],
            'Oman' => ['country' => 'OM', 'currency' => 'OMR', 'places' => 3],
        ];
    }

    // ---- the switch is off: every market is the platform of today -------------------------------------------------------

    #[DataProvider('everyMarket')]
    public function test_with_the_switch_off_every_market_debits_at_two_places(string $country, string $currency, int $places): void
    {
        $debit = $this->inStorefront($country, static fn (): WalletOrderDebit => WalletOrderDebit::forOrderAmount(self::AMOUNT));

        $this->assertSame(self::AT_TWO_PLACES, $debit->amountInUsd(), $country . ' rounds where it always did');
        $this->assertSame(WalletOrderDebit::TWO_PLACES, $this->inStorefront($country, static fn (): int => WalletOrderDebit::places()));
    }

    /** And the check reads the balance at the same unit, so a wallet holding the third place is not told it covers a two-place debit. */
    public function test_with_the_switch_off_a_three_decimal_market_still_judges_the_balance_at_two_places(): void
    {
        $covered = $this->inStorefront('KW', static fn (): bool => WalletOrderDebit::forOrderAmount(self::AMOUNT)->isCoveredBy('10.016'));

        $this->assertTrue($covered, '10.016 rounds to 10.02, which is the two-place debit');
    }

    // ---- the switch is on: a market's money is counted in its own smallest coin -----------------------------------------

    #[DataProvider('everyMarket')]
    public function test_with_the_switch_on_each_market_debits_at_the_places_its_currency_has(string $country, string $currency, int $places): void
    {
        config([WalletOrderDebit::MARKET_MINOR_UNIT_SWITCH => true]);

        $expected = $places === 3 ? self::AT_THREE_PLACES : self::AT_TWO_PLACES;
        $debit = $this->inStorefront($country, static fn (): WalletOrderDebit => WalletOrderDebit::forOrderAmount(self::AMOUNT));

        $this->assertSame($places, $this->inStorefront($country, static fn (): int => WalletOrderDebit::places()), $currency . ' has ' . $places . ' places');
        $this->assertSame($expected, $debit->amountInUsd(), $country . ' debits at its own smallest coin');
    }

    /**
     * **The money this moves, stated as figures.** In Kuwait the customer was charged 10.02 for an order of 10.0155 -- forty-five
     * hundred-thousandths more than the order -- and is now charged 10.016, which is five hundred-thousandths more, the most a round half
     * up to the fils can be out by. The difference between the two charges is 0.004, on every such order, in a currency that can express
     * it.
     */
    public function test_a_kuwaiti_order_is_no_longer_charged_more_than_it_costs(): void
    {
        $atTwo = $this->inStorefront('KW', static fn (): float => WalletOrderDebit::forOrderAmount(self::AMOUNT)->amountInUsd());

        config([WalletOrderDebit::MARKET_MINOR_UNIT_SWITCH => true]);
        $atThree = $this->inStorefront('KW', static fn (): float => WalletOrderDebit::forOrderAmount(self::AMOUNT)->amountInUsd());

        $this->assertSame(0.004, round($atTwo - $atThree, 5), 'the two units charge different money');
        $this->assertSame(0.0045, round($atTwo - self::AMOUNT, 5), 'the old unit was out by forty-five hundred-thousandths');
        $this->assertSame(0.0005, round($atThree - self::AMOUNT, 5), 'the new one by five, which is half a fils');
        $this->assertGreaterThan($atThree, $atTwo, 'the old unit charged more than the new one, and both more than the order');
    }

    /** The check follows the debit, or a balance would be judged at one unit and taken at another. */
    public function test_with_the_switch_on_a_three_decimal_balance_is_judged_at_three_places(): void
    {
        config([WalletOrderDebit::MARKET_MINOR_UNIT_SWITCH => true]);

        $this->assertTrue($this->inStorefront('KW', static fn (): bool => WalletOrderDebit::forOrderAmount(self::AMOUNT)->isCoveredBy('10.016')));
        $this->assertFalse($this->inStorefront('KW', static fn (): bool => WalletOrderDebit::forOrderAmount(self::AMOUNT)->isCoveredBy('10.0154')), 'a tenth of a fils short is short');
    }

    // ---- what is not a market, and what the operator cannot move --------------------------------------------------------

    /**
     * A request that serves no storefront is not a claim about any market's money: a panel, a queue worker and a console command round
     * where they always did, which is the same rule `MarketBalanceReader` follows for a reader serving no market.
     */
    public function test_a_request_serving_no_storefront_debits_at_two_places_with_the_switch_on(): void
    {
        config([WalletOrderDebit::MARKET_MINOR_UNIT_SWITCH => true]);

        $this->assertSame(WalletOrderDebit::TWO_PLACES, WalletOrderDebit::places());
        $this->assertSame(self::AT_TWO_PLACES, WalletOrderDebit::forOrderAmount(self::AMOUNT)->amountInUsd());
    }

    /** With the currency switch above it off, no storefront sells in its own money, so no market has a unit of its own to be counted in. */
    public function test_with_the_storefront_currency_switch_off_every_market_debits_at_two_places(): void
    {
        config([WalletOrderDebit::MARKET_MINOR_UNIT_SWITCH => true, 'gcc.storefronts.currency.enabled' => false]);

        $this->assertSame(self::AT_TWO_PLACES, $this->inStorefront('KW', static fn (): float => WalletOrderDebit::forOrderAmount(self::AMOUNT)->amountInUsd()));
    }

    /**
     * **The places are the CURRENCY's and never the operator's display setting.** `decimal_point_settings` is a field whose own admin form
     * allows 0, and reading it as a money unit is the defect `ATopUpCreditsWhatWasPaidTest` measures on the top-up path: a customer charged
     * 100.50 credited 100. Setting it to 0 must not make a wallet debit whole dinars or whole riyals.
     *
     * **One half of this has teeth and the other cannot, and saying so is the point.** Measured on SQLite 8.3 by mutation:
     *
     *  - the SAUDI half bites. Make the no-storefront branch of `WalletOrderDebit::places()` read `decimalsInForce()` and this test fails:
     *    a panel, a queue worker and the default market would start counting money in whatever an operator typed into a display field;
     *  - the KUWAITI half is INERT, and not for want of trying. `StorefrontCurrency::override('decimal_point_settings')` is itself defined
     *    as `decimalsOf($currency->code)` for a storefront trading in its own money, so inside such a storefront the display setting and
     *    the currency's places are the SAME number in every reachable state -- swapping one reader for the other changes nothing there.
     *    Reading `decimalsOf()` is therefore a choice about what this depends on rather than a behaviour difference in that branch, and it
     *    is written here rather than left as coverage this test does not have.
     */
    public function test_the_operators_display_setting_cannot_change_the_unit_money_is_counted_in(): void
    {
        config([WalletOrderDebit::MARKET_MINOR_UNIT_SWITCH => true]);
        DB::table('business_settings')->where('type', 'decimal_point_settings')->update(['value' => '0']);
        clearWebConfigCacheKeys();

        $this->assertSame(self::AT_THREE_PLACES, $this->inStorefront('KW', static fn (): float => WalletOrderDebit::forOrderAmount(self::AMOUNT)->amountInUsd()));
        $this->assertSame(self::AT_TWO_PLACES, $this->inStorefront('SA', static fn (): float => WalletOrderDebit::forOrderAmount(self::AMOUNT)->amountInUsd()));
    }
}
