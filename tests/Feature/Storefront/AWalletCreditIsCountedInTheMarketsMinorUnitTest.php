<?php

namespace Tests\Feature\Storefront;

use App\Services\Customer\WalletMinorUnit;
use App\Services\Customer\WalletOrderDebit;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsStorefrontMoneyWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The unit a wallet CREDIT is counted in, per market (E-16), and the one place every writer of a wallet balance asks for it.
 *
 * The debit side already has its own unit per market ({@see AWalletDebitIsCountedInTheMarketsMinorUnitTest}). The credit side had none at
 * all: five writers each computed a credit where it stood and none of them rounded, so a points conversion, a refund and a referral
 * reward of one amount were whatever each path's conversion produced -- in Kuwait, Bahrain and Oman a figure with more places than the
 * currency has coins for.
 *
 * `gcc.wallet.credits_in_the_markets_minor_unit` is the switch and it ships OFF, and off here is stronger than on the debit side: the
 * amount is handed straight BACK, not rounded at two places, because a credit has never been rounded at all.
 *
 * It lives beside the other storefront-money tests rather than in `tests/Feature/Wallet`, because the fixture it needs builds
 * `business_settings` and `currencies` and drops them again, which in the wallet group would take those tables away from every class that
 * ran after it -- the same reason the debit test is here. The figures asserted through the five writers are in
 * `Tests\Feature\Wallet\EveryWriterCreditsTheSameAmountTest`, which that fixture serves.
 *
 * The figure is **10.0155**: 10.02 at two places and 10.016 at three, so every assertion reads a number that could only have come from
 * one of the two units.
 */
class AWalletCreditIsCountedInTheMarketsMinorUnitTest extends TestCase
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
        config([WalletMinorUnit::CREDITS_SWITCH => false]);
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
        return AWalletDebitIsCountedInTheMarketsMinorUnitTest::everyMarket();
    }

    // ---- the switch is off: every market is the platform of today -------------------------------------------------------

    /**
     * Off, a credit is the float it was handed, in every market -- the same object-identical value, places and all, and NOT the two-place
     * rounding the debit side falls back to. A credit rounded "by default" would move money on every path that reaches a writer.
     */
    #[DataProvider('everyMarket')]
    public function test_with_the_switch_off_every_market_credits_the_amount_untouched(string $country, string $currency, int $places): void
    {
        $credited = $this->inStorefront($country, static fn (): float => WalletMinorUnit::credited(self::AMOUNT));

        $this->assertSame(self::AMOUNT, $credited, $country . ' credits what it always credited');
    }

    /** Off, a figure smaller than any coin survives, which is the difference between handing an amount back and rounding it at two. */
    #[DataProvider('everyMarket')]
    public function test_with_the_switch_off_a_figure_below_the_smallest_coin_is_not_rounded_away(string $country, string $currency, int $places): void
    {
        $this->assertSame(0.0005, $this->inStorefront($country, static fn (): float => WalletMinorUnit::credited(0.0005)));
    }

    // ---- the switch is on: a market's money is counted in its own smallest coin -----------------------------------------

    #[DataProvider('everyMarket')]
    public function test_with_the_switch_on_each_market_credits_at_the_places_its_currency_has(string $country, string $currency, int $places): void
    {
        config([WalletMinorUnit::CREDITS_SWITCH => true]);

        $expected = $places === 3 ? self::AT_THREE_PLACES : self::AT_TWO_PLACES;

        $this->assertSame($places, $this->inStorefront($country, static fn (): int => WalletMinorUnit::placesUnder(WalletMinorUnit::CREDITS_SWITCH)), $currency . ' has ' . $places . ' places');
        $this->assertSame($expected, $this->inStorefront($country, static fn (): float => WalletMinorUnit::credited(self::AMOUNT)), $country . ' credits at its own smallest coin');
    }

    /**
     * **The money this moves, stated as figures.** A Kuwaiti reward of 10.0155 was credited as 10.0155 -- a figure the customer's money
     * cannot express, and one the admin wallet report prints rounded while the balance keeps the remainder. It is now credited as 10.016,
     * the nearest fils, and the five-hundred-thousandth that cannot be paid is gone rather than hidden.
     */
    public function test_a_kuwaiti_reward_is_credited_in_fils_rather_than_in_a_figure_the_money_cannot_express(): void
    {
        $unrounded = $this->inStorefront('KW', static fn (): float => WalletMinorUnit::credited(self::AMOUNT));

        config([WalletMinorUnit::CREDITS_SWITCH => true]);
        $inFils = $this->inStorefront('KW', static fn (): float => WalletMinorUnit::credited(self::AMOUNT));

        $this->assertSame(self::AMOUNT, $unrounded, 'off, the remainder is kept');
        $this->assertSame(self::AT_THREE_PLACES, $inFils);
        $this->assertSame(0.0005, round($inFils - $unrounded, 5), 'half a fils, which is the most a round half up can move');
    }

    /** The two sides of one market agree: a credit and a debit are counted in the same coin, which is why they share the helper. */
    public function test_the_credit_and_the_debit_of_one_market_are_counted_in_the_same_coin(): void
    {
        config([WalletMinorUnit::CREDITS_SWITCH => true, WalletOrderDebit::MARKET_MINOR_UNIT_SWITCH => true]);

        foreach (self::everyMarket() as $market) {
            $country = $market['country'];
            $credit = $this->inStorefront($country, static fn (): int => WalletMinorUnit::placesUnder(WalletMinorUnit::CREDITS_SWITCH));
            $debit = $this->inStorefront($country, static fn (): int => WalletOrderDebit::places());

            $this->assertSame($debit, $credit, $country . ' counts a credit and a debit in one unit');
        }
    }

    /** Each side is switched on its own: the credit unit is not reached by turning the debit's on, which is what a separate switch means. */
    public function test_the_debit_switch_does_not_turn_the_credit_unit_on(): void
    {
        config([WalletOrderDebit::MARKET_MINOR_UNIT_SWITCH => true]);

        $this->assertSame(self::AMOUNT, $this->inStorefront('KW', static fn (): float => WalletMinorUnit::credited(self::AMOUNT)));
        $this->assertSame(self::AT_THREE_PLACES, $this->inStorefront('KW', static fn (): float => WalletOrderDebit::forOrderAmount(self::AMOUNT)->amountInUsd()));
    }

    // ---- what is not a market, and what the operator cannot move --------------------------------------------------------

    /**
     * A request that serves no storefront is not a claim about any market's money: a panel, a queue worker and a console command round at
     * two places, which is Saudi Arabia's, the United Arab Emirates' and Qatar's unit whatever the switch says.
     */
    public function test_a_request_serving_no_storefront_credits_at_two_places_with_the_switch_on(): void
    {
        config([WalletMinorUnit::CREDITS_SWITCH => true]);

        $this->assertSame(WalletMinorUnit::TWO_PLACES, WalletMinorUnit::placesUnder(WalletMinorUnit::CREDITS_SWITCH));
        $this->assertSame(self::AT_TWO_PLACES, WalletMinorUnit::credited(self::AMOUNT));
    }

    /** With the currency switch above it off, no storefront sells in its own money, so no market has a unit of its own to be counted in. */
    public function test_with_the_storefront_currency_switch_off_every_market_credits_at_two_places(): void
    {
        config([WalletMinorUnit::CREDITS_SWITCH => true, 'gcc.storefronts.currency.enabled' => false]);

        $this->assertSame(self::AT_TWO_PLACES, $this->inStorefront('KW', static fn (): float => WalletMinorUnit::credited(self::AMOUNT)));
    }

    /**
     * **The places are the CURRENCY's and never the operator's display setting**, for the same reason the debit side says so: a field whose
     * own admin form allows 0 must not be able to make a wallet credit whole dinars. The Saudi half of this bites by mutation; the Kuwaiti
     * half is inert for the reason the debit test records, because inside a storefront trading in its own money the override IS
     * `decimalsOf($code)`.
     */
    public function test_the_operators_display_setting_cannot_change_the_unit_a_credit_is_counted_in(): void
    {
        config([WalletMinorUnit::CREDITS_SWITCH => true]);
        DB::table('business_settings')->where('type', 'decimal_point_settings')->update(['value' => '0']);
        clearWebConfigCacheKeys();

        $this->assertSame(self::AT_THREE_PLACES, $this->inStorefront('KW', static fn (): float => WalletMinorUnit::credited(self::AMOUNT)));
        $this->assertSame(self::AT_TWO_PLACES, $this->inStorefront('SA', static fn (): float => WalletMinorUnit::credited(self::AMOUNT)));
    }
}
