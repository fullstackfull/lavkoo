<?php

namespace Tests\Feature\SalesChannels;

use App\Models\InventoryLocation;
use App\Models\InventoryPool;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Inventory\Allocation\DirtyPools;
use App\Services\SalesChannels\Inventory\Allocation\PoolAllocationService;
use App\Services\SalesChannels\Inventory\Allocation\PoolMarket;
use App\Services\SalesChannels\Inventory\Allocation\PoolMembers;
use App\Services\SalesChannels\Inventory\InventoryLocationMarket;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use App\Services\SalesChannels\Inventory\PoolKey;
use App\Services\SalesChannels\Publishing\StockPricePublisher;
use App\Services\SalesChannels\Support\ChannelOperations;
use App\Services\Storefront\StorefrontMarketRows;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsRemoteOrders;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\BuildsStockPools;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/**
 * A stock pool belongs to ONE market, and so does the key that identifies it (M-656, M-745).
 *
 * A pool is one physical stock as the channels are offered it, and its identity was `(location, product, variant)` -- three things that name
 * no country. So the row the single writer serialises every division on, the division it writes onto each listing (`allocation.pool`) and
 * the figure an operator reads could not be attributed to a market, and two countries' accounts offering one SKU met in one row: Kuwait's
 * accounts were handed Saudi units, and Kuwait published more than Kuwait holds while every figure added up.
 *
 * The world below: the Torod warehouse is the Saudi shelf and names no market, as every shelf of this platform does today; the M5AZN
 * supplier stock is marked Kuwaiti. Product 50 is a Saudi product that names no market either, 52 names Saudi Arabia out loud and 51 is the
 * Kuwaiti twin of the same SKU on the Kuwaiti shelf. Two Trendyol accounts, one for each market.
 */
class PoolCountryIdentityTest extends TestCase
{
    use BuildsRemoteOrders;
    use BuildsSalesChannelSchema;
    use BuildsStockPools;
    use CreatesChannelAccounts;

    private const UNMARKED_SAUDI_PRODUCT = 50;

    private const KUWAITI_PRODUCT = 51;

    private const MARKED_SAUDI_PRODUCT = 52;

    private const SAUDI_UNITS = 40;

    private const KUWAITI_UNITS = 10;

    private const SAUDI_SELLER = '1234567';

    private const KUWAITI_SELLER = '7654321';

    private SalesChannelAccount $saudi;

    private SalesChannelAccount $kuwaiti;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00 UTC'));

        $this->saudi = $this->trendyolAccount(sellerId: self::SAUDI_SELLER);
        $this->kuwaiti = $this->accountOf(self::KUWAITI_SELLER, 'KW');

        $this->storeProduct(self::UNMARKED_SAUDI_PRODUCT);
        $this->storeProduct(self::MARKED_SAUDI_PRODUCT);
        $this->storeProduct(self::KUWAITI_PRODUCT, provider: 'm5azn');
        $this->providerFigure(self::UNMARKED_SAUDI_PRODUCT, self::SAUDI_UNITS, now()->subMinutes(30));
        $this->providerFigure(self::MARKED_SAUDI_PRODUCT, self::SAUDI_UNITS, now()->subMinutes(30));
        $this->providerFigure(self::KUWAITI_PRODUCT, self::KUWAITI_UNITS, now()->subMinutes(30), provider: 'm5azn');

        Http::fake(['apigw.trendyol.com/*' => Http::response(['batchRequestId' => 'batch-1'])]);
    }

    // ---- the world ------------------------------------------------------------------------------------------------------------------

    /** A Trendyol account of one market: the storefront code is the setting Trendyol keeps its own storefront under. */
    private function accountOf(string $sellerId, string $storefrontCode): SalesChannelAccount
    {
        $account = $this->trendyolAccount(sellerId: $sellerId);
        $account->forceFill(['settings' => ['storefront_code' => $storefrontCode, 'integration_name' => 'PharmacyHub']])->save();

        return $account->fresh();
    }

    /** The markets the products and the shelves carry, and the pool table keyed by one. Run per test, because the dormant cases need the schema without them. */
    private function marketsExist(): void
    {
        (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();
        (require database_path('migrations/2027_01_07_100000_add_market_to_inventory_pools.php'))->up();
        DB::table('products')->where('id', self::KUWAITI_PRODUCT)->update(['storefront_country' => 'KW']);
        DB::table('products')->where('id', self::MARKED_SAUDI_PRODUCT)->update(['storefront_country' => 'SA']);
        $this->kuwaitiShelf()->forceFill(['storefront_country' => 'KW'])->save();
        $this->forgetMarkets();
    }

    /** The Kuwaiti shelf. A shelf belongs to a market of its own (E11-F19), so two markets' twins of one SKU are not two pools of one shelf but one pool each. */
    private function kuwaitiShelf(): InventoryLocation
    {
        return app(InventoryLocationService::class)->ensureDefaults()[1];
    }

    private function shelfOf(int $productId): InventoryLocation
    {
        return $productId === self::KUWAITI_PRODUCT ? $this->kuwaitiShelf() : $this->torodLocation();
    }

    private function forgetMarkets(): void
    {
        app(StorefrontMarketRows::class)->forget();
        app(InventoryLocationMarket::class)->forget();
    }

    private function listing(SalesChannelAccount $account, int $productId): SalesChannelProductMapping
    {
        return $this->stockListing($account, $productId, $this->shelfOf($productId), $this->barcode($account, $productId));
    }

    private function barcode(SalesChannelAccount $account, int $productId): string
    {
        return 'L-' . $account->external_account_id . '-' . $productId;
    }

    /** The key of a product's pool, through the one factory every caller uses, and without writing a listing for it. */
    private function keyFor(int $productId): PoolKey
    {
        $listing = (new SalesChannelProductMapping())->forceFill(['product_id' => $productId, 'variant_key' => '']);

        return PoolKey::ofListing($listing, (int) $this->shelfOf($productId)->id);
    }

    /** @return array<string, int> the quantity each barcode was sent, the last one sent for it */
    private function published(SalesChannelAccount ...$accounts): array
    {
        $this->completeOrderSweep(...$accounts);
        foreach ($accounts as $account) {
            app(StockPricePublisher::class)->publishStock($account);
        }

        $sent = [];
        foreach (Http::recorded(static fn (Request $request): bool => str_contains($request->url(), '/products/price-and-inventory')) as $pair) {
            foreach ($pair[0]->data()['items'] as $item) {
                $sent[$item['barcode']] = $item['quantity'];
            }
        }

        return $sent;
    }

    /**
     * How many units each ACCOUNT may be shown, added up over the pools of the products given: the entitlement the single writer worked
     * out, which is what bounds a market whether or not its own marketplace calls go out today.
     *
     * @return array<int, int> units by account id
     */
    private function unitsAllowedPerAccount(int ...$productIds): array
    {
        $units = [];
        foreach ($productIds as $productId) {
            foreach ($this->divisionOf($productId) as $accountId => $allowed) {
                $units[$accountId] = ($units[$accountId] ?? 0) + $allowed;
            }
        }

        return $units;
    }

    /** @return array<int, int> what one pool's division gives each account, by account id */
    private function divisionOf(int $productId): array
    {
        $units = [];
        foreach (app(PoolAllocationService::class)->allocate($this->keyFor($productId))->shares as $listingId => $share) {
            $accountId = (int) SalesChannelProductMapping::findOrFail($listingId)->account_id;
            $units[$accountId] = ($units[$accountId] ?? 0) + $share->publish();
        }

        return $units;
    }

    // ---- dormant: with the switch off, the pool is the one it has always been -------------------------------------------------------

    /**
     * The FIRST assertion of this feature: with the storefronts off, a pool's key is byte for byte the one it was -- its text, which is
     * persisted on every listing it has ever divided, and its columns, which are the three the pool table has always been keyed by.
     */
    public function test_with_the_storefronts_off_the_pool_key_is_the_one_it_has_always_been(): void
    {
        $this->marketsExist();
        config(['gcc.storefronts.enabled' => false]);
        $this->forgetMarkets();

        foreach ([self::UNMARKED_SAUDI_PRODUCT, self::KUWAITI_PRODUCT, self::MARKED_SAUDI_PRODUCT] as $productId) {
            $key = $this->keyFor($productId);
            $this->assertSame(PoolKey::DEFAULT_MARKET, $key->market, 'product ' . $productId);
            $this->assertSame($this->shelfOf($productId)->id . ':' . $productId . ':', (string) $key, 'product ' . $productId);
        }

        $this->assertSame(['location_id', 'product_id', 'variant_key'], array_keys($this->keyFor(self::KUWAITI_PRODUCT)->columns()), 'the three columns every stock table shares never gain a fourth');
        $this->assertSame(
            [PoolKey::MARKET_COLUMN => '', 'location_id' => $this->kuwaitiShelf()->id, 'product_id' => self::KUWAITI_PRODUCT, 'variant_key' => ''],
            $this->keyFor(self::KUWAITI_PRODUCT)->poolColumns(),
            'the column is written, because the table has it; the value is the default market, because nothing names another',
        );
    }

    /** And before the pool table is keyed by a market at all, the key has no market column to write. */
    public function test_before_the_migration_the_pool_table_is_not_keyed_by_a_market(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();
        DB::table('products')->where('id', self::KUWAITI_PRODUCT)->update(['storefront_country' => 'KW']);
        $this->forgetMarkets();

        $this->assertFalse(app(PoolMarket::class)->keyedByMarket(), 'the pool table has no market column yet');
        $this->assertFalse(app(PoolMarket::class)->applies(), 'so no pool has a market, whatever the switch says');
        $this->assertSame(PoolKey::DEFAULT_MARKET, app(PoolMarket::class)->ofProduct(self::KUWAITI_PRODUCT), 'the Kuwaiti product\'s pool is the default market\'s until the column exists');
        $this->assertSame(['location_id', 'product_id', 'variant_key'], array_keys($this->keyFor(self::KUWAITI_PRODUCT)->poolColumns()), 'and a key writes no column the table has not got');
    }

    /** With the switch off, two markets' products still divide and publish exactly as they did: one pool each, the whole net each. */
    public function test_with_the_switch_off_every_account_divides_the_pools_it_always_did(): void
    {
        $this->marketsExist();
        config(['gcc.storefronts.enabled' => false]);
        $this->forgetMarkets();
        $this->listing($this->saudi, self::UNMARKED_SAUDI_PRODUCT);
        $this->listing($this->kuwaiti, self::KUWAITI_PRODUCT);

        $sent = $this->published($this->saudi, $this->kuwaiti);

        $this->assertSame(self::SAUDI_UNITS, $sent[$this->barcode($this->saudi, self::UNMARKED_SAUDI_PRODUCT)] ?? null, 'the Saudi account is offered its own 40, as before');
        $this->assertSame(self::KUWAITI_UNITS, $sent[$this->barcode($this->kuwaiti, self::KUWAITI_PRODUCT)] ?? null, 'and the second account its 10: with the switch off no market narrows anything');
        $this->assertSame('', (string) DB::table('inventory_pools')->where('product_id', self::KUWAITI_PRODUCT)->value(PoolKey::MARKET_COLUMN), 'and no pool row names a market while the switch is off');
    }

    // ---- the pool carries its market -----------------------------------------------------------------------------------------------

    public function test_a_pool_of_another_market_is_a_pool_of_its_own(): void
    {
        $this->marketsExist();
        config(['gcc.storefronts.enabled' => true]);
        $this->forgetMarkets();

        $kuwaiti = $this->keyFor(self::KUWAITI_PRODUCT);
        $this->assertSame('KW', $kuwaiti->market, 'the pool of a Kuwaiti product is Kuwait\'s');
        $this->assertSame($this->kuwaitiShelf()->id . ':' . self::KUWAITI_PRODUCT . ':@KW', (string) $kuwaiti, 'and says so in the text that is persisted on every listing it divides');
        $this->assertSame('KW', $kuwaiti->poolColumns()[PoolKey::MARKET_COLUMN], 'and in the column its row is keyed by');
    }

    /** A product that names the DEFAULT market keeps the pool every product has today: naming Saudi Arabia out loud must not split a shelf's units in two. */
    public function test_a_product_of_the_default_market_keeps_the_pool_it_had(): void
    {
        $this->marketsExist();
        config(['gcc.storefronts.enabled' => true]);
        $this->forgetMarkets();

        foreach ([self::UNMARKED_SAUDI_PRODUCT, self::MARKED_SAUDI_PRODUCT] as $productId) {
            $key = $this->keyFor($productId);
            $this->assertSame(PoolKey::DEFAULT_MARKET, $key->market, 'product ' . $productId);
            $this->assertSame($this->torodLocation()->id . ':' . $productId . ':', (string) $key, 'product ' . $productId);
        }
    }

    /** A pool row written before the market existed is the DEFAULT market's: the division finds that very row again and never opens a second one beside it. */
    public function test_a_pool_that_exists_already_is_the_default_markets_pool(): void
    {
        $this->listing($this->saudi, self::UNMARKED_SAUDI_PRODUCT);
        app(PoolAllocationService::class)->allocate(new PoolKey(locationId: (int) $this->torodLocation()->id, productId: self::UNMARKED_SAUDI_PRODUCT));
        $before = InventoryPool::where('product_id', self::UNMARKED_SAUDI_PRODUCT)->sole();

        $this->marketsExist();
        config(['gcc.storefronts.enabled' => true]);
        $this->forgetMarkets();
        app(PoolAllocationService::class)->allocate($this->keyFor(self::UNMARKED_SAUDI_PRODUCT));

        $this->assertSame(1, InventoryPool::where('product_id', self::UNMARKED_SAUDI_PRODUCT)->count(), 'no second pool is opened beside the one that was already there');
        $after = InventoryPool::where('product_id', self::UNMARKED_SAUDI_PRODUCT)->sole();
        $this->assertSame((int) $before->id, (int) $after->id, 'the same row, not a second one');
        $this->assertSame('', (string) $after->storefront_country, 'an existing pool is the default storefront\'s');
    }

    /** A pool marked dirty is worked out again as the market it was marked under. */
    public function test_a_mark_on_another_markets_pool_comes_back_as_that_markets_pool(): void
    {
        $this->marketsExist();
        config(['gcc.storefronts.enabled' => true]);
        $this->forgetMarkets();

        app(DirtyPools::class)->mark([$this->keyFor(self::KUWAITI_PRODUCT), $this->keyFor(self::UNMARKED_SAUDI_PRODUCT)]);

        $pending = array_map(static fn (PoolKey $key): string => (string) $key, app(DirtyPools::class)->pending());
        $this->assertSame([
            $this->kuwaitiShelf()->id . ':' . self::KUWAITI_PRODUCT . ':@KW',
            $this->torodLocation()->id . ':' . self::UNMARKED_SAUDI_PRODUCT . ':',
        ], $pending, 'a pool comes back from the table as the market it was marked under');
    }

    // ---- what the markets may publish of each other's stock ------------------------------------------------------------------------

    /**
     * The criterion itself: two countries' accounts offering one SKU, each bounded by ITS OWN country's stock.
     *
     * Both accounts are linked to both products, which is what an operator typing SKUs does. Saudi Arabia holds 40 units of its product and
     * Kuwait 10 of its own, and neither market may publish a unit more than its own -- not by being handed the other market's pool, and not
     * by having its own pool divided with an account that sells elsewhere.
     */
    public function test_two_countries_accounts_publishing_one_sku_never_exceed_each_stock(): void
    {
        $this->marketsExist();
        config(['gcc.storefronts.enabled' => true]);
        $this->forgetMarkets();
        $this->listing($this->saudi, self::UNMARKED_SAUDI_PRODUCT);
        $this->listing($this->saudi, self::KUWAITI_PRODUCT);
        $this->listing($this->kuwaiti, self::KUWAITI_PRODUCT);
        $this->listing($this->kuwaiti, self::UNMARKED_SAUDI_PRODUCT);

        $allowed = $this->unitsAllowedPerAccount(self::UNMARKED_SAUDI_PRODUCT, self::KUWAITI_PRODUCT);

        $this->assertSame(self::SAUDI_UNITS, $allowed[(int) $this->saudi->id] ?? null, 'the Saudi account may be shown its own 40 and no more');
        $this->assertSame(self::KUWAITI_UNITS, $allowed[(int) $this->kuwaiti->id] ?? null, 'the Kuwaiti account may be shown its own 10 and no more');
        $this->assertSame([(int) $this->saudi->id], array_keys($this->divisionOf(self::UNMARKED_SAUDI_PRODUCT)), 'the Saudi stock is shared out among Saudi accounts only');
        $this->assertSame([(int) $this->kuwaiti->id], array_keys($this->divisionOf(self::KUWAITI_PRODUCT)), 'and the Kuwaiti stock among Kuwaiti accounts only');
    }

    /**
     * The same world on the wire: the Saudi account is sent its own figure for its own product, and nothing at all for the Kuwaiti one.
     *
     * The Kuwaiti account sends nothing here for a reason of its own -- Kuwait is not open for sale, so `ChannelAccountMarketStop` makes no
     * write call for it at all (H2-12). That is a SECOND protection, independent of the pool's market, and it is asserted rather than worked
     * around so that neither is mistaken for the other.
     */
    public function test_on_the_wire_each_account_is_sent_its_own_markets_figure(): void
    {
        $this->marketsExist();
        config(['gcc.storefronts.enabled' => true]);
        $this->forgetMarkets();
        $this->listing($this->saudi, self::UNMARKED_SAUDI_PRODUCT);
        $this->listing($this->saudi, self::KUWAITI_PRODUCT);
        $this->listing($this->kuwaiti, self::KUWAITI_PRODUCT);

        $sent = $this->published($this->saudi);

        $this->assertSame([$this->barcode($this->saudi, self::UNMARKED_SAUDI_PRODUCT) => self::SAUDI_UNITS], $sent, 'the Saudi account is sent its own product and nothing of the Kuwaiti one');
        $this->assertSame('market_stopped', app(StockPricePublisher::class)->publishStock($this->kuwaiti)['detail'], 'Kuwait is not open for sale');
    }

    /** The pool of one market holds no listing of another's account, so its units are divided among its own market only. */
    public function test_the_pool_of_one_market_holds_only_that_markets_accounts(): void
    {
        $this->marketsExist();
        config(['gcc.storefronts.enabled' => true]);
        $this->forgetMarkets();
        $mine = $this->listing($this->kuwaiti, self::KUWAITI_PRODUCT);
        $this->listing($this->saudi, self::KUWAITI_PRODUCT);

        $members = app(PoolMembers::class)->of($this->keyFor(self::KUWAITI_PRODUCT), $this->kuwaitiShelf());

        $this->assertSame([(int) $mine->id], array_map(static fn ($member): int => (int) $member->listing->id, $members), 'the Kuwaiti pool holds the Kuwaiti account\'s listing alone');
    }

    /**
     * An account whose settings name NO country is not narrowed -- that is `ChannelAccountCountry`'s recorded rule, and it holds for the
     * pools of the default market, which is every pool this platform has. A pool of ANOTHER market has no such history, and an account that
     * cannot say it sells there is given none of its units.
     */
    public function test_an_account_that_names_no_country_takes_units_of_the_default_markets_pool_only(): void
    {
        $this->marketsExist();
        config(['gcc.storefronts.enabled' => true]);
        $this->forgetMarkets();
        $nameless = $this->accountOf('1111111', '');
        $saudi = $this->listing($nameless, self::UNMARKED_SAUDI_PRODUCT);
        $this->listing($nameless, self::KUWAITI_PRODUCT);

        $this->assertSame(
            [(int) $saudi->id],
            array_map(static fn ($member): int => (int) $member->listing->id, app(PoolMembers::class)->of($this->keyFor(self::UNMARKED_SAUDI_PRODUCT), $this->torodLocation())),
            'the default market\'s pool still holds it',
        );
        $this->assertSame([], app(PoolMembers::class)->of($this->keyFor(self::KUWAITI_PRODUCT), $this->kuwaitiShelf()), 'Kuwait\'s pool does not');
    }

    /** Two markets' pools of one shelf and one product can both exist: the old unique key, which named no market, could not hold them. */
    public function test_the_pool_table_can_hold_two_markets_pools_of_one_shelf(): void
    {
        $this->marketsExist();
        config(['gcc.storefronts.enabled' => true]);
        $this->forgetMarkets();
        $location = (int) $this->torodLocation()->id;

        app(DirtyPools::class)->mark([
            new PoolKey(locationId: $location, productId: self::UNMARKED_SAUDI_PRODUCT),
            new PoolKey(locationId: $location, productId: self::UNMARKED_SAUDI_PRODUCT, market: 'KW'),
        ]);

        $this->assertSame(2, InventoryPool::where('product_id', self::UNMARKED_SAUDI_PRODUCT)->count(), 'two markets, two pool rows: the key without a market could hold only one');
        $this->assertTrue(Schema::hasColumn('inventory_pools', PoolKey::MARKET_COLUMN), 'the market is a column of the pool, not a string parsed out of one');
    }
}
