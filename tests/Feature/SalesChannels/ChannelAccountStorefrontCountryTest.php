<?php

namespace Tests\Feature\SalesChannels;

use App\Models\InventoryLocation;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\Inventory\Allocation\PoolMembers;
use App\Services\SalesChannels\Inventory\InventoryLocationMarket;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use App\Services\SalesChannels\Inventory\PoolKey;
use App\Services\SalesChannels\Support\ChannelAccountCountry;
use App\Services\SalesChannels\Support\ChannelAccountMarketStop;
use App\Services\SalesChannels\Support\ChannelSettings;
use App\Services\Storefront\StorefrontMarketRows;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\BuildsStockPools;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/**
 * A channel account DECLARES the storefront country it sells in, and refuses a marketplace of another country (M-744).
 *
 * Until this, the country of an account was only ever DERIVED from whichever setting each channel happens to keep it under -- Trendyol's
 * `storefront_code`, noon's `country_code`, the countries of an Amazon account's marketplace ids -- and nothing checked the answer against
 * anything. An Amazon account of two countries' marketplaces, or of a marketplace this platform has never heard of, therefore had NO
 * country, and an account with no country is the one state in which two markets can meet in one stock pool: it is narrowed to no market at
 * all. Nothing stopped an operator from writing `SA` beside an Emirati marketplace either, because there was nothing to write it in.
 *
 * The declaration is a setting of the account, validated where every account setting is validated, and {@see ChannelAccountCountry} prefers
 * it to the derived answer. An EMPTY declaration says nothing and changes nothing, which is what every account that exists today has.
 */
class ChannelAccountStorefrontCountryTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use BuildsStockPools;
    use CreatesChannelAccounts;

    private const KUWAITI_PRODUCT = 61;

    private const AMAZON_SAUDI_MARKETPLACE = 'A17E79C6D8DWNP';

    private const AMAZON_EMIRATI_MARKETPLACE = 'A2VIGQ35RCS4UG';

    /** Well formed, and not one of the marketplaces this platform lists: Amazon opening one is a fact about Amazon, not a contradiction. */
    private const AMAZON_UNKNOWN_MARKETPLACE = 'A1UNLISTED99';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        config(['sales_channels.enabled' => true]);
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00 UTC'));
    }

    // ---- helpers --------------------------------------------------------------------------------------------------------------------

    private function refusalFor(string $channel, array $settings): string
    {
        try {
            ChannelSettings::validate($channel, $settings);
        } catch (InvalidArgumentException $refusal) {
            return $refusal->getMessage();
        }

        return '';
    }

    /** An Amazon account as the admin would have set it up, with whatever settings a test needs. */
    private function amazonAccountWith(array $settings, string $sellerId = 'A2SELLER'): SalesChannelAccount
    {
        return app(ChannelAccountService::class)->create(
            ['channel' => 'amazon', 'name' => 'Amazon', 'external_account_id' => $sellerId, 'currency' => 'SAR', 'settings' => $settings + ['region' => 'eu', 'marketplace_ids' => []]],
            ['lwa_client_id' => 'amzn1.application-oa2-client.test', 'lwa_client_secret' => 'client-secret-xyz', 'refresh_token' => 'Atzr|refresh-token-abc'],
            adminId: 1,
        );
    }

    private function storefrontsAreOn(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();
        (require database_path('migrations/2027_01_07_100000_add_market_to_inventory_pools.php'))->up();
        app(StorefrontMarketRows::class)->forget();
        app(InventoryLocationMarket::class)->forget();
    }

    private function kuwaitiShelf(): InventoryLocation
    {
        return app(InventoryLocationService::class)->ensureDefaults()[1];
    }

    // ---- dormant while the storefronts are off --------------------------------------------------------------------------------------

    /** The first assertion: with the storefronts off there is no such setting to declare, so nothing an account stores today changes. */
    public function test_with_the_storefronts_off_an_account_declares_no_country(): void
    {
        foreach (['amazon', 'trendyol', 'noon'] as $channel) {
            $this->assertFalse(ChannelSettings::marketMayBeDeclared($channel), $channel);
            $this->assertArrayNotHasKey(ChannelSettings::MARKET, ChannelSettings::defaults($channel), $channel . ' defaults');
        }

        $saved = ChannelSettings::validate('amazon', ['region' => 'eu', 'marketplace_ids' => [], ChannelSettings::MARKET => 'AE']);
        $this->assertArrayNotHasKey(ChannelSettings::MARKET, $saved, 'a declaration sent while storefronts are off is dropped, not stored');
        $this->assertSame('SA', app(ChannelAccountCountry::class)->of($this->amazonAccount()), 'and an Amazon account of the Saudi marketplace is read exactly as before: derived from its marketplace');
    }

    // ---- the declaration ------------------------------------------------------------------------------------------------------------

    /**
     * The case derivation cannot answer at all: an account with no marketplace id has no country to derive, and an account without a country
     * is narrowed to no market. Now it says which market it sells in, and the pool, the market stop and the matcher all read that answer.
     */
    public function test_an_account_that_derives_no_country_declares_one(): void
    {
        $this->storefrontsAreOn();
        $account = $this->amazonAccountWith([ChannelSettings::MARKET => 'KW']);

        $this->assertSame('KW', $account->setting(ChannelSettings::MARKET), 'the declaration is stored on the account');
        $this->assertSame('KW', app(ChannelAccountCountry::class)->of($account), 'and is the country the account sells in');
        $this->assertSame('KW', app(ChannelAccountMarketStop::class)->marketOf($account), 'the market stop reads the same one answer');
    }

    /** An account that declares nothing is derived exactly as it was: the rule that such an account is never narrowed is untouched. */
    public function test_an_account_that_declares_nothing_is_still_derived(): void
    {
        $this->storefrontsAreOn();

        $this->assertSame('SA', app(ChannelAccountCountry::class)->of($this->amazonAccount()), 'the Saudi marketplace still answers SA');
        $this->assertNull(app(ChannelAccountCountry::class)->of($this->amazonAccountWith([])), 'and an account with no marketplace and no declaration names none');
    }

    /**
     * Which answer wins when a row holds both, and they disagree.
     *
     * Saving such a pair is refused (below), so this is a row written another way -- a hand edit, or an account whose marketplaces were
     * changed in the database. The DECLARATION wins, because it is the statement a person made and can be held to, and the derivation is an
     * inference from a setting that was never meant to carry a market.
     */
    public function test_the_declaration_is_the_answer_where_a_row_holds_both(): void
    {
        $this->storefrontsAreOn();
        $account = $this->amazonAccount();
        $account->forceFill(['settings' => ['region' => 'eu', 'marketplace_ids' => [self::AMAZON_SAUDI_MARKETPLACE], ChannelSettings::MARKET => 'KW']])->save();

        $this->assertSame('KW', app(ChannelAccountCountry::class)->of($account->fresh()), 'the declared market, not the one derived from the marketplace');
    }

    // ---- what is refused -----------------------------------------------------------------------------------------------------------

    public function test_a_marketplace_of_another_country_is_refused(): void
    {
        $this->storefrontsAreOn();

        $this->assertStringContainsString(
            'sells in AE, so it cannot declare the SA storefront',
            $this->refusalFor('amazon', ['region' => 'eu', 'marketplace_ids' => [self::AMAZON_EMIRATI_MARKETPLACE], ChannelSettings::MARKET => 'SA']),
            'an Amazon account of the Emirati marketplace cannot claim the Saudi storefront',
        );
        $this->assertStringContainsString(
            'sells in AE, so it cannot declare the SA storefront',
            $this->refusalFor('noon', ['country_code' => 'ae', ChannelSettings::MARKET => 'SA']),
            'nor a noon account whose prices are Emirati',
        );
        $this->assertStringContainsString(
            'sells in SA, so it cannot declare the KW storefront',
            $this->refusalFor('trendyol', ['storefront_code' => 'SA', 'integration_name' => 'PharmacyHub', ChannelSettings::MARKET => 'KW']),
            'nor a Trendyol account whose storefront is the Saudi one',
        );
    }

    /** An empty Trendyol storefront code is not silence: Trendyol's own setting means the Turkish marketplace, which has no storefront here. */
    public function test_a_trendyol_account_of_the_turkish_marketplace_declares_no_gulf_storefront(): void
    {
        $this->storefrontsAreOn();

        $this->assertStringContainsString(
            'sells in TR, so it cannot declare the SA storefront',
            $this->refusalFor('trendyol', ['storefront_code' => '', 'integration_name' => 'PharmacyHub', ChannelSettings::MARKET => 'SA']),
        );
    }

    public function test_a_country_the_platform_has_no_storefront_for_is_refused(): void
    {
        $this->storefrontsAreOn();

        $this->assertStringContainsString(
            'The storefront country must be one of: SA, AE, KW, QA, BH, OM, or empty.',
            $this->refusalFor('amazon', ['region' => 'eu', 'marketplace_ids' => [], ChannelSettings::MARKET => 'TR']),
        );
    }

    /**
     * An account of two countries' marketplaces can declare NEITHER, and that is the honest answer rather than a guess: every marketplace it
     * holds would have to be of the market it names. Whether such an account should exist at all is the owner's open question (#76).
     */
    public function test_an_account_of_two_countries_marketplaces_can_declare_neither(): void
    {
        $this->storefrontsAreOn();
        $both = ['region' => 'eu', 'marketplace_ids' => [self::AMAZON_SAUDI_MARKETPLACE, self::AMAZON_EMIRATI_MARKETPLACE]];

        $this->assertStringContainsString('cannot declare the SA storefront', $this->refusalFor('amazon', $both + [ChannelSettings::MARKET => 'SA']));
        $this->assertStringContainsString('cannot declare the AE storefront', $this->refusalFor('amazon', $both + [ChannelSettings::MARKET => 'AE']));
        $this->assertSame('', $this->refusalFor('amazon', $both), 'and the account itself is still allowed to exist, with no country');
    }

    /** A marketplace this platform does not list is not a contradiction: refusing it would stop an account the day Amazon opens a marketplace. */
    public function test_a_marketplace_this_platform_does_not_know_is_not_a_contradiction(): void
    {
        $this->storefrontsAreOn();

        $input = ['region' => 'eu', 'marketplace_ids' => [self::AMAZON_UNKNOWN_MARKETPLACE], ChannelSettings::MARKET => 'AE'];

        $this->assertSame('', $this->refusalFor('amazon', $input), 'a marketplace whose country we do not know refuses nothing');
        $this->assertSame('AE', ChannelSettings::validate('amazon', $input)[ChannelSettings::MARKET], 'and the declaration is stored');
    }

    // ---- what the declaration is for ------------------------------------------------------------------------------------------------

    /**
     * Why it matters, and the tie to the pool (M-745): an account that declares its market takes part in THAT market's pool, and the same
     * account declaring nothing takes part in none of another market's.
     */
    public function test_a_declared_market_is_what_lets_an_account_join_that_markets_pool(): void
    {
        $this->storefrontsAreOn();
        $this->storeProduct(self::KUWAITI_PRODUCT, provider: 'm5azn');
        DB::table('products')->where('id', self::KUWAITI_PRODUCT)->update(['storefront_country' => 'KW']);
        $this->kuwaitiShelf()->forceFill(['storefront_country' => 'KW'])->save();
        app(StorefrontMarketRows::class)->forget();
        app(InventoryLocationMarket::class)->forget();

        $nameless = $this->amazonAccountWith([]);
        $kuwaiti = $this->amazonAccountWith([ChannelSettings::MARKET => 'KW'], sellerId: 'A3SELLER');
        $this->stockListing($nameless, self::KUWAITI_PRODUCT, $this->kuwaitiShelf(), 'L-NAMELESS');
        $mine = $this->stockListing($kuwaiti, self::KUWAITI_PRODUCT, $this->kuwaitiShelf(), 'L-KUWAITI');

        $key = new PoolKey(locationId: (int) $this->kuwaitiShelf()->id, productId: self::KUWAITI_PRODUCT, market: 'KW');
        $members = app(PoolMembers::class)->of($key, $this->kuwaitiShelf());

        $this->assertSame(
            [(int) $mine->id],
            array_map(static fn ($member): int => (int) $member->listing->id, $members),
            'the account that declares Kuwait shares Kuwait\'s pool; the one that declares nothing does not',
        );
    }
}
