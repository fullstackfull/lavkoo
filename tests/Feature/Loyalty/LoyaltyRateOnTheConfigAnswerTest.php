<?php

namespace Tests\Feature\Loyalty;

use App\Services\Storefront\StorefrontCurrency;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsApiConfigWorld;
use Tests\Support\BuildsStorefrontCatalog;

/**
 * E-02 and J-V2: GET /api/v1/config publishes the loyalty programme OF THE STOREFRONT the app asked for.
 *
 * This is the half of E-02 that nothing asserted. The four loyalty settings are per market
 * (`StorefrontCurrency::LOYALTY_SETTINGS`), MarketLoyaltyProgrammeTest proves the override, and the claim that "the API's /config
 * follows, so both apps follow" was made in that file's docblock -- in prose. A test of the override is not a test of the answer, and the
 * answer is what the app reads: if the config route did not resolve a storefront, every market would be published the Saudi figures and
 * no test anywhere would have noticed.
 *
 * It does resolve one, and not through the `storefront` middleware, which this route does not carry: `StorefrontCatalog` falls back to
 * resolving the request itself, and the request is where the app's `X-Storefront` header is. That is the whole mechanism, and it is what
 * these tests pin.
 *
 * Owner decision E-02 is honoured and asserted: a market given no programme of its own is published the platform's, so no market starts
 * earning or converting on its own.
 */
class LoyaltyRateOnTheConfigAnswerTest extends ApiAddressTestCase
{
    use BuildsApiConfigWorld;
    use BuildsStorefrontCatalog;

    private const CONFIG = '/api/v1/config';

    /** The platform's own programme: 10 per cent earned, 100 points to one unit of money, 50 the fewest convertible. */
    private const PLATFORM = ['loyalty_point_status' => '1', 'loyalty_point_item_purchase_point' => '10', 'loyalty_point_exchange_rate' => '100', 'loyalty_point_minimum_point' => '50'];

    /** What an operator gave Kuwait: a quarter of the earn rate and two and a half times the points per dinar. */
    private const KUWAIT = ['loyalty_point_status' => '1', 'loyalty_point_item_purchase_point' => '4', 'loyalty_point_exchange_rate' => '250', 'loyalty_point_minimum_point' => '20'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildApiConfigWorld();
        foreach (['cash_on_delivery', 'digital_payment', 'offline_payment'] as $method) {
            DB::table('business_settings')->updateOrInsert(['type' => $method], ['value' => '{"status":"1"}']);
        }
        foreach (self::PLATFORM as $type => $value) {
            DB::table('business_settings')->updateOrInsert(['type' => $type], ['value' => $value]);
        }
        DB::table('currencies')->insert(['name' => 'Kuwaiti Dinar', 'symbol' => 'KD', 'code' => 'KWD', 'exchange_rate' => 0.307, 'status' => 1]);

        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.currency.enabled' => true,
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.storefronts.countries.AE.active' => true,
        ]);
        clearWebConfigCacheKeys();

        // StorefrontCatalog resolves the storefront of an unbound request and refuses while the application thinks it is in the console,
        // which a PHPUnit process does. Without this every case below would read as the default market and pass for the wrong reason.
        $this->serveAsHttpRequest(self::CONFIG);
    }

    /** @param array<string, string> $programme */
    private function giveKuwaitItsOwnProgramme(array $programme = self::KUWAIT): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['type' => StorefrontCurrency::amountRowName('KW')],
            ['value' => json_encode(['currency' => 'KWD'] + $programme)],
        );
        clearWebConfigCacheKeys();
    }

    /** @return array<string, int|float> the four loyalty figures of the answer, as the app reads them */
    private function loyaltyOf(?string $country): array
    {
        $response = $country === null ? $this->getJson(self::CONFIG) : $this->getJson(self::CONFIG, ['X-Storefront' => $country]);
        $config = json_decode($response->assertOk()->getContent(), true);

        return [
            'loyalty_point_status' => $config['loyalty_point_status'],
            'loyalty_point_item_purchase_point' => $config['loyalty_point_item_purchase_point'],
            'loyalty_point_exchange_rate' => $config['loyalty_point_exchange_rate'],
            'loyalty_point_minimum_point' => $config['loyalty_point_minimum_point'],
        ];
    }

    /**
     * The platform's figures in the types the answer carries them in. The earn rate is cast to a float by the controller and JSON prints a
     * whole float without a point, so what the app parses back is an integer -- which is what is asserted, because what the app parses is
     * the contract.
     *
     * @return array<string, int>
     */
    private function platformFigures(): array
    {
        return ['loyalty_point_status' => 1, 'loyalty_point_item_purchase_point' => 10, 'loyalty_point_exchange_rate' => 100, 'loyalty_point_minimum_point' => 50];
    }

    public function test_a_market_given_no_programme_is_published_the_platforms(): void
    {
        $this->assertSame($this->platformFigures(), $this->loyaltyOf('KW'), 'owner decision E-02: a market does not start earning on its own');
        $this->assertSame($this->platformFigures(), $this->loyaltyOf('AE'));
        $this->assertSame($this->platformFigures(), $this->loyaltyOf(null), 'and a client that names no storefront reads what it always read');
    }

    public function test_the_answer_a_market_gets_is_that_markets_own_programme(): void
    {
        $this->giveKuwaitItsOwnProgramme();

        $this->assertSame(
            ['loyalty_point_status' => 1, 'loyalty_point_item_purchase_point' => 4, 'loyalty_point_exchange_rate' => 250, 'loyalty_point_minimum_point' => 20],
            $this->loyaltyOf('KW'),
        );
    }

    public function test_one_markets_programme_reaches_no_other_market_and_not_the_platform(): void
    {
        $this->giveKuwaitItsOwnProgramme();

        $this->assertSame($this->platformFigures(), $this->loyaltyOf('AE'), 'the Emirati answer is untouched by what Kuwait was given');
        $this->assertSame($this->platformFigures(), $this->loyaltyOf('SA'), 'and so is the Saudi one');
        $this->assertSame($this->platformFigures(), $this->loyaltyOf(null));
    }

    public function test_a_market_that_earns_nothing_is_published_as_earning_nothing(): void
    {
        $this->giveKuwaitItsOwnProgramme(['loyalty_point_status' => '0'] + self::KUWAIT);

        $this->assertSame(0, $this->loyaltyOf('KW')['loyalty_point_status']);
        $this->assertSame(1, $this->loyaltyOf('SA')['loyalty_point_status'], 'while every other market still earns');
    }

    public function test_an_exchange_rate_typed_against_another_currency_is_not_published(): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['type' => StorefrontCurrency::amountRowName('KW')],
            ['value' => json_encode(['currency' => 'SAR'] + self::KUWAIT)],
        );
        clearWebConfigCacheKeys();

        $this->assertSame(
            100,
            $this->loyaltyOf('KW')['loyalty_point_exchange_rate'],
            'a rate typed against the riyal is the wrong rate for a dinar market, so the platform figure stands',
        );
    }

    public function test_nothing_is_published_per_market_while_the_money_switch_is_off(): void
    {
        config(['gcc.storefronts.currency.enabled' => false]);
        $this->giveKuwaitItsOwnProgramme();

        $this->assertSame($this->platformFigures(), $this->loyaltyOf('KW'), 'off is the platform of today, whatever a market was given');
    }
}
