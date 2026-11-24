<?php

namespace Tests\Feature\Storefront;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\InventoryReservation;
use App\Models\SalesChannelAccount;
use App\Services\Catalog\ProductCodeScope;
use App\Services\Fulfillment\InventorySyncService;
use App\Services\SalesChannels\Inventory\PoolNet;
use App\Services\SalesChannels\Sync\ProductMatcher;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsRemoteOrders;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\BuildsStockPools;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/**
 * A1-18: two countries' copies of one product keep their stock apart, all the way through the machinery that moves it.
 *
 * Each country has its own product row (GCC-02/03), so separate figures follow from the design — but every piece of inventory machinery is
 * keyed on something, and a piece keyed on the SKU rather than the product would quietly join the two countries back together. One SKU is
 * exactly what two copies of a product share. So the subject of these tests is the keys: the provider figure, the store order, the channel
 * hold, the pool, the reservation, the outbox row and the SKU matcher are each driven for one country and read back for the other.
 *
 * The world is the real one: the real migrations, the real sync service, the real ingestor and the real pool arithmetic. Only the provider's
 * HTTP is faked.
 */
class StorefrontInventoryIsolationTest extends TestCase
{
    use BuildsRemoteOrders;
    use BuildsSalesChannelSchema;
    use BuildsStockPools;
    use CreatesChannelAccounts;

    /** The two copies of one product: the Saudi row and the Kuwaiti row, sharing a code and a barcode as two copies of one product do. */
    private const SAUDI = 70;

    private const KUWAITI = 71;

    private const SHARED_CODE = 'SKU-SHARED';

    private const SHARED_BARCODE = 'BC-SHARED';

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSalesChannelSchema();
        config([
            'sales_channels.enabled' => true,
            'sales_channels.external_writes_enabled' => true,
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.countries.KW.active' => true,
            ProductCodeScope::FLAG => true,
        ]);
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00 UTC'));

        $this->runProductCountryMigration();
        $this->twoCopiesOfOneProduct();

        $this->account = $this->trendyolAccount();
        $this->providerFigure(self::SAUDI, 20, now()->subHour());
        $this->providerFigure(self::KUWAITI, 20, now()->subHour());
    }

    private function runProductCountryMigration(): void
    {
        (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();
    }

    private function twoCopiesOfOneProduct(): void
    {
        $this->storeProduct(self::SAUDI);
        $this->storeProduct(self::KUWAITI);

        foreach ([self::SAUDI => 'SA', self::KUWAITI => 'KW'] as $id => $country) {
            DB::table('products')->where('id', $id)->update([
                'code' => self::SHARED_CODE,
                'barcode' => self::SHARED_BARCODE,
                'storefront_country' => $country,
            ]);
        }
    }

    private function storefrontStockOf(int $productId): int
    {
        return (int) DB::table('products')->where('id', $productId)->value('current_stock');
    }

    /** The figures the warehouse reports for both copies, applied the way the scheduled `fulfillment:sync --only=inventory` applies them. */
    private function providerSync(int $saudiUnits = 20, int $kuwaitiUnits = 20): void
    {
        app(InventorySyncService::class)->applyToAssignedProducts(
            ProviderCode::TorodPoint,
            ['ext-' . self::SAUDI => $saudiUnits, 'ext-' . self::KUWAITI => $kuwaitiUnits],
            now(),
        );
    }

    private function channelOrderFor(int $productId, int $units, string $listingKey, string $orderId): void
    {
        $this->stockListing($this->account, $productId, $this->torodLocation(), $listingKey);
        $this->ingestOrder($this->account, $orderId, $listingKey, $units);
    }

    public function test_a_channel_order_against_one_countrys_copy_leaves_the_other_countrys_stock_alone(): void
    {
        $this->channelOrderFor(self::KUWAITI, 2, 'BAR-KW', 'ORD-KW');

        $this->providerSync();

        $this->assertSame(18, $this->storefrontStockOf(self::KUWAITI), '20 at the warehouse, 2 promised to a channel');
        $this->assertSame(20, $this->storefrontStockOf(self::SAUDI), 'the Saudi copy promised nothing and keeps its 20');
    }

    public function test_a_store_order_in_one_country_does_not_come_off_the_other_countrys_stock(): void
    {
        $this->storeOrder(1, self::KUWAITI, 3);

        $this->providerSync();

        $this->assertSame(17, $this->storefrontStockOf(self::KUWAITI));
        $this->assertSame(20, $this->storefrontStockOf(self::SAUDI));
    }

    public function test_each_countrys_pool_nets_its_own_figure(): void
    {
        $this->channelOrderFor(self::KUWAITI, 2, 'BAR-KW', 'ORD-KW');
        $this->storeOrder(1, self::SAUDI, 5);

        $this->providerSync();

        $this->assertSame(18, app(PoolNet::class)->for($this->torodLocation(), self::KUWAITI)->net(), 'the Kuwaiti pool answers the Kuwaiti promises');
        $this->assertSame(15, app(PoolNet::class)->for($this->torodLocation(), self::SAUDI)->net(), 'and the Saudi pool its own');
    }

    public function test_a_reservation_belongs_to_the_product_of_its_own_market(): void
    {
        $this->channelOrderFor(self::KUWAITI, 2, 'BAR-KW', 'ORD-KW');

        $this->assertSame(
            [self::KUWAITI],
            InventoryReservation::query()->distinct()->pluck('product_id')->map(intval(...))->all(),
            'a hold names the product it was taken against, never the other country\'s copy of it',
        );
    }

    public function test_an_outbox_row_names_only_the_product_of_its_own_market(): void
    {
        $this->channelOrderFor(self::KUWAITI, 2, 'BAR-KW', 'ORD-KW');

        $named = DB::table('channel_stock_events')->distinct()->pluck('product_id')->map(intval(...))->all();

        $this->assertNotSame([], $named, 'the change wrote to the outbox at all');
        $this->assertSame([self::KUWAITI], $named, 'nothing asks the channels to re-read the other country\'s figure');
    }

    /** Both copies sold on channels: each channel order moves its own country's figure, and neither moves the other's. */
    public function test_two_countries_sold_on_channels_at_once_keep_their_own_figures(): void
    {
        $this->channelOrderFor(self::SAUDI, 5, 'BAR-SA', 'ORD-SA');
        $this->channelOrderFor(self::KUWAITI, 2, 'BAR-KW', 'ORD-KW');

        $this->providerSync(saudiUnits: 20, kuwaitiUnits: 30);

        $this->assertSame(15, $this->storefrontStockOf(self::SAUDI), '20 - 5');
        $this->assertSame(28, $this->storefrontStockOf(self::KUWAITI), '30 - 2');
    }

    /** The key the machinery must not use: the SKU. A channel account that sells in one country is only ever offered that country's copy. */
    public function test_a_shared_sku_proposes_only_the_products_of_the_channels_own_country(): void
    {
        $matcher = app(ProductMatcher::class);

        $this->assertSame(
            [self::KUWAITI],
            array_column($matcher->candidates(self::SHARED_CODE, null, 'KW'), 'product_id'),
        );
        $this->assertSame(
            [self::SAUDI],
            array_column($matcher->candidates(self::SHARED_BARCODE, null, 'SA'), 'product_id'),
        );
    }

    /** And the reason the country has to be asked for: without one the shared code is ambiguous, which is the state the switch exists to end. */
    public function test_a_shared_sku_with_no_country_is_ambiguous(): void
    {
        $this->assertSame(
            [self::SAUDI, self::KUWAITI],
            array_column(app(ProductMatcher::class)->candidates(self::SHARED_CODE, null, null), 'product_id'),
        );
    }
}
