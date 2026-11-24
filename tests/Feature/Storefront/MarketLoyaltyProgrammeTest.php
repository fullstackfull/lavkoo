<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontCurrency;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsStorefrontMoneyWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * E-02: each market has its own loyalty programme -- whether it runs, what an order earns, how many points buy one unit of money, and how
 * few may be converted.
 *
 * The four settings were one platform row each, so a Kuwaiti customer earned at the Saudi rate and converted at the Saudi rate, on a
 * balance held in dinars. They now go through the per-storefront override that `getWebConfig()` already consults, which is what makes this
 * one change reach the website, the API's /config (so both apps follow), the loyalty screens, the seller API and the server-side earn
 * writers at once, rather than each of them being taught about markets separately.
 *
 * The first test is the one that matters most: a market that has been given no programme runs the platform's, so nothing changes until an
 * operator decides otherwise. The default market is never overridden at all.
 *
 * What is NOT decided here: what each market's rate should BE. The owner sets that with `gcc:storefront:money`, and the open question of
 * what a point is worth is LF-1 (docs/gcc/OPEN-FINANCIAL-DECISIONS.md section 1), which this does not touch.
 */
class MarketLoyaltyProgrammeTest extends TestCase
{
    use BuildsStorefrontMoneyWorld;
    use RestoresLanguageFiles;

    /** What the platform's own loyalty programme says, as the world seeds it. */
    private const PLATFORM = ['loyalty_point_status' => '1', 'loyalty_point_item_purchase_point' => '10', 'loyalty_point_exchange_rate' => '100', 'loyalty_point_minimum_point' => '50'];

    private const KUWAIT = ['loyalty_point_status' => '1', 'loyalty_point_item_purchase_point' => '4', 'loyalty_point_exchange_rate' => '250', 'loyalty_point_minimum_point' => '20'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildStorefrontMoneyWorld();
        DB::table('business_settings')->insert(array_map(
            static fn (string $type, string $value): array => ['type' => $type, 'value' => $value],
            array_keys(self::PLATFORM),
            array_values(self::PLATFORM),
        ));
        clearWebConfigCacheKeys();
    }

    protected function tearDown(): void
    {
        $this->dropStorefrontMoneyWorld();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** @return array<string, string> what each of the four settings answers inside this market */
    private function programmeOf(string $country): array
    {
        return $this->inStorefront($country, function (): array {
            $answers = [];
            foreach (StorefrontCurrency::LOYALTY_SETTINGS as $setting) {
                $answers[$setting] = (string) getWebConfig(name: $setting);
            }

            return $answers;
        });
    }

    public static function everyMarket(): array
    {
        return [['SA'], ['AE'], ['KW'], ['QA'], ['BH'], ['OM']];
    }

    /**
     * The dormancy rule, first: a market that has been given no programme runs the platform's. Until an operator decides otherwise, the six
     * markets earn and convert exactly as the one market does today.
     */
    #[DataProvider('everyMarket')]
    public function test_a_market_given_no_programme_runs_the_platforms(string $country): void
    {
        $this->assertSame(self::PLATFORM, $this->programmeOf($country));
    }

    public function test_a_market_given_its_own_programme_answers_it(): void
    {
        $this->giveStorefrontAmounts(country: 'KW', currency: 'KWD', amounts: self::KUWAIT);

        $this->assertSame(self::KUWAIT, $this->programmeOf('KW'), 'Kuwait earns 4%, converts at 250 points to the dinar, from 20 points');
    }

    /** And it is Kuwait's alone: the other five, and the platform's own market, are untouched by it. */
    public function test_one_markets_programme_reaches_no_other_market(): void
    {
        $this->giveStorefrontAmounts(country: 'KW', currency: 'KWD', amounts: self::KUWAIT);

        foreach (['SA', 'AE', 'QA', 'BH', 'OM'] as $country) {
            $this->assertSame(self::PLATFORM, $this->programmeOf($country), "{$country} still runs the platform's programme");
        }
    }

    /** A market may switch its programme off without switching the platform's off, which is what "independent" has to mean. */
    public function test_a_market_can_earn_no_points_while_every_other_market_still_does(): void
    {
        $this->giveStorefrontAmounts(country: 'BH', currency: 'BHD', amounts: ['loyalty_point_status' => '0']);

        $this->assertSame('0', $this->programmeOf('BH')['loyalty_point_status']);
        $this->assertSame('1', $this->programmeOf('SA')['loyalty_point_status']);
        $this->assertSame('10', $this->programmeOf('BH')['loyalty_point_item_purchase_point'], 'what it did not say is still the platform\'s');
    }

    /**
     * The exchange rate is points per one unit of the market's money, so a row typed against another currency holds the wrong rate and is
     * read as nothing -- the same guard the per-market amounts have. The settings that are not money still answer, because a percentage, a
     * flag and a count of points do not change when a currency does.
     */
    public function test_an_exchange_rate_typed_against_another_currency_is_not_read(): void
    {
        $this->giveStorefrontAmounts(country: 'KW', currency: 'SAR', amounts: self::KUWAIT);

        $programme = $this->programmeOf('KW');
        $this->assertSame(self::PLATFORM['loyalty_point_exchange_rate'], $programme['loyalty_point_exchange_rate'], 'the platform rate stands rather than a dinar rate read as riyals');
        $this->assertSame('4', $programme['loyalty_point_item_purchase_point'], 'the earn rate is a percentage and is read');
        $this->assertSame('20', $programme['loyalty_point_minimum_point'], 'the minimum is a count of points and is read');
    }

    /** A value that is not a plain non-negative number is not a programme: the platform's stands. */
    public function test_a_value_that_is_not_a_number_is_not_read(): void
    {
        $this->giveStorefrontAmounts(country: 'QA', currency: 'QAR', amounts: ['loyalty_point_item_purchase_point' => 'five', 'loyalty_point_minimum_point' => '-3']);

        $this->assertSame(self::PLATFORM, $this->programmeOf('QA'));
    }

    /** A panel, a queue worker or a console command serves no market and reads the platform's programme, whatever any market was given. */
    public function test_a_reader_serving_no_market_reads_the_platforms_programme(): void
    {
        $this->giveStorefrontAmounts(country: 'KW', currency: 'KWD', amounts: self::KUWAIT);

        $answers = [];
        foreach (StorefrontCurrency::LOYALTY_SETTINGS as $setting) {
            $answers[$setting] = (string) getWebConfig(name: $setting);
        }

        $this->assertSame(self::PLATFORM, $answers);
    }

    /** With the storefront switches off -- how the platform ships -- a market's programme is not read at all. */
    public function test_nothing_is_read_while_the_switches_are_off(): void
    {
        $this->giveStorefrontAmounts(country: 'KW', currency: 'KWD', amounts: self::KUWAIT);
        config(['gcc.storefronts.currency.enabled' => false]);

        $this->assertSame(self::PLATFORM, $this->programmeOf('KW'));
    }
}
