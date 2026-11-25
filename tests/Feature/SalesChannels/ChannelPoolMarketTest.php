<?php

namespace Tests\Feature\SalesChannels;

use App\Models\InventoryLocation;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Inventory\Allocation\PoolMembers;
use App\Services\SalesChannels\Inventory\InventoryLocationMarket;
use App\Services\SalesChannels\Inventory\PoolKey;
use App\Services\SalesChannels\Publishing\ChannelMarketMatch;
use App\Services\SalesChannels\Publishing\StockListingGate;
use App\Services\Storefront\StorefrontMarketRows;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/**
 * A marketplace account takes part only in the pools of ITS OWN market's products, and only from a shelf of that market (E11-F19; C2-05,
 * C2-12).
 *
 * The pool is where this bites. A pool is keyed by a product, a variant and a location, and every linked, opted-in listing shares it --
 * so without a market check a Saudi Amazon account would take a share of a KUWAITI product's units and advertise them on the Saudi
 * marketplace, and a listing fed from a Kuwaiti shelf would publish those units to a Saudi channel. The figures add up either way; only
 * the market is wrong, which is why nobody would see it.
 *
 * The world: product 4242 is Saudi and product 4343 is Kuwaiti. The accounts are the suite's own, which name Saudi Arabia in their own
 * settings: Trendyol by `storefront_code`, noon by `country_code`, Amazon by its marketplace id.
 */
class ChannelPoolMarketTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    private const SAUDI_PRODUCT = 4242;

    private const KUWAITI_PRODUCT = 4343;

    private InventoryLocation $own;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();
        app(StorefrontMarketRows::class)->forget();
        app(InventoryLocationMarket::class)->forget();

        DB::table('products')->insert([
            ['id' => self::SAUDI_PRODUCT, 'name' => 'Saudi product', 'storefront_country' => 'SA'],
            ['id' => self::KUWAITI_PRODUCT, 'name' => 'Kuwaiti product', 'storefront_country' => 'KW'],
        ]);

        $this->own = app(\App\Services\SalesChannels\Inventory\InventoryLocationService::class)->ensureDefaults()[2];
        $this->admit($this->own, 'storefront', 'trendyol', 'amazon_fbm', 'noon');
    }

    private function admit(InventoryLocation $location, string ...$channels): void
    {
        $location->forceFill(['publishable_channels' => $channels])->save();
    }

    private function listing(SalesChannelAccount $account, int $productId, array $overrides = []): SalesChannelProductMapping
    {
        return SalesChannelProductMapping::create($overrides + [
            'account_id' => $account->id, 'product_id' => $productId, 'listing_key' => 'L-' . $account->id . '-' . $productId,
            'external_sku' => 'SKU-' . $productId, 'external_barcode' => 'BC-' . $productId, 'status' => 'mapped',
            'stock_location_id' => $this->own->id, 'fulfillment_mode' => 'merchant', 'inventory_publish' => true,
            'price_publish' => false, 'price_source' => 'manual', 'snapshot' => [],
        ]);
    }

    private function refusalFor(SalesChannelAccount $account, int $productId): ?string
    {
        return app(StockListingGate::class)->refusal($account, $this->listing($account, $productId), $this->own);
    }

    // ---- dormant until the storefronts are switched on -----------------------------------------------------------------------------

    public function test_with_the_storefronts_off_the_market_of_a_product_refuses_nothing(): void
    {
        $this->assertNull($this->refusalFor($this->trendyolAccount(), self::KUWAITI_PRODUCT), 'a Saudi account and a Kuwaiti product: today\'s answer');
        $this->assertNull(app(ChannelMarketMatch::class)->marketOf($this->trendyolAccount(capabilities: self::ALL_CAPABILITIES, writes: false, sellerId: '9999')));
    }

    public function test_before_the_products_carry_a_market_nothing_is_refused_either(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        Schema::table('products', fn ($table) => $table->dropColumn('storefront_country'));
        app(StorefrontMarketRows::class)->forget();

        $this->assertNull($this->refusalFor($this->trendyolAccount(), self::KUWAITI_PRODUCT));

        (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();
        app(StorefrontMarketRows::class)->forget();
    }

    // ---- the refusals --------------------------------------------------------------------------------------------------------------

    public function test_a_saudi_account_takes_no_share_of_a_kuwaiti_products_pool(): void
    {
        config(['gcc.storefronts.enabled' => true]);

        foreach ([$this->trendyolAccount(), $this->amazonAccount(), $this->noonAccount()] as $account) {
            $this->assertSame(ChannelMarketMatch::PRODUCT_OF_ANOTHER_MARKET, $this->refusalFor($account, self::KUWAITI_PRODUCT), $account->channel);
            $this->assertNull($this->refusalFor($account, self::SAUDI_PRODUCT), $account->channel . ' and its own market');
        }
    }

    public function test_the_pool_of_a_kuwaiti_product_holds_no_saudi_accounts_listing(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $saudi = $this->trendyolAccount();
        $mine = $this->listing($saudi, self::SAUDI_PRODUCT);
        $foreign = $this->listing($saudi, self::KUWAITI_PRODUCT);

        $members = app(PoolMembers::class)->of(new PoolKey(locationId: $this->own->id, productId: self::KUWAITI_PRODUCT, variantKey: (string) $foreign->variant_key), $this->own);
        $this->assertSame([], array_map(static fn ($member): int => (int) $member->listing->id, $members), 'the Kuwaiti pool is empty for a Saudi account');

        $own = app(PoolMembers::class)->of(new PoolKey(locationId: $this->own->id, productId: self::SAUDI_PRODUCT, variantKey: (string) $mine->variant_key), $this->own);
        $this->assertSame([(int) $mine->id], array_map(static fn ($member): int => (int) $member->listing->id, $own), 'its own market\'s pool still holds it');
    }

    public function test_a_shelf_of_another_market_feeds_no_listing_of_this_one(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $this->own->forceFill(['storefront_country' => 'KW'])->save();
        app(InventoryLocationMarket::class)->forget();

        $this->assertSame(ChannelMarketMatch::LOCATION_OF_ANOTHER_MARKET, $this->refusalFor($this->trendyolAccount(), self::SAUDI_PRODUCT));
    }

    public function test_an_account_that_sells_where_we_have_no_catalogue_takes_nothing_at_all(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.catalog.country_scoped_codes' => true]);
        $turkish = $this->trendyolAccount(capabilities: self::ALL_CAPABILITIES, writes: true, sellerId: '7654321');
        $turkish->forceFill(['settings' => ['storefront_code' => 'TR', 'integration_name' => 'PharmacyHub']])->save();

        $this->assertSame(ChannelMarketMatch::ACCOUNT_SELLS_WHERE_WE_HAVE_NO_CATALOGUE, $this->refusalFor($turkish, self::SAUDI_PRODUCT));
    }

    /**
     * An account whose settings name NO country is not narrowed. That is `ChannelAccountCountry`'s own recorded rule and today's behaviour,
     * and it is asserted rather than left as a silence: refusing such an account would stop every installation's publishing the day the
     * storefronts are switched on.
     */
    public function test_an_account_that_names_no_country_is_not_narrowed(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $nameless = $this->trendyolAccount(capabilities: self::ALL_CAPABILITIES, writes: true, sellerId: '1111111');
        $nameless->forceFill(['settings' => ['integration_name' => 'PharmacyHub']])->save();

        $this->assertNull(app(ChannelMarketMatch::class)->marketOf($nameless));
        $this->assertNull($this->refusalFor($nameless, self::KUWAITI_PRODUCT));
    }

    /** The plainest true reason: a listing the channel fulfils is refused for being the channel's, never for a market. */
    public function test_the_older_refusals_still_come_first(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $amazon = $this->amazonAccount();
        $fba = $this->listing($amazon, self::KUWAITI_PRODUCT, ['fulfillment_mode' => 'fba']);

        $this->assertSame('fba_listing', app(StockListingGate::class)->refusal($amazon, $fba, $this->own));

        $this->admit($this->own, 'storefront');
        $merchant = $this->listing($amazon, self::KUWAITI_PRODUCT, ['listing_key' => 'L-second']);
        $this->assertSame('location_not_for_this_channel', app(StockListingGate::class)->refusal($amazon, $merchant, $this->own));
    }
}
