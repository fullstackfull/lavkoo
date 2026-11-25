<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use App\Services\Theme\SectionDataResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * F-10: the store-stats bar counts this market's products, orders and customers.
 *
 * The bar is marketing copy a visitor reads as a statement about the shop they are in, so a Kuwaiti visitor told the platform's total order
 * count is being told a figure about six countries. Products already followed the market -- `Product::active()` ends with
 * `inCurrentStorefront()` -- while `orders` was `Order::count()` and `customers` was `User::count()`.
 *
 * Brands and categories stay platform-wide, deliberately: neither table has a country and a category is the same category in every market, so
 * a count of them is not one market's figure gone wrong, it is a figure with no market. That is asserted here too, so the decision is visible
 * rather than an omission.
 */
class StoreStatsPerMarketTest extends TestCase
{
    use BuildsStorefrontCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontCatalogTables();
        $this->buildProductRelationTables();
        $this->runStorefrontCountryMigration();
        $this->buildTheCustomersAndOrders();
        StorefrontCatalog::flush();
    }

    protected function tearDown(): void
    {
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    /** The first test of the switch: while storefronts are off the bar shows the counts it has always shown. */
    public function test_while_storefronts_are_off_the_bar_counts_the_whole_platform(): void
    {
        $stats = app(SectionDataResolver::class)->storeStats();

        $this->assertSame(['products' => 3, 'orders' => 4, 'customers' => 5], $this->figuresOf($stats));
    }

    public function test_the_bar_of_the_default_market_counts_its_own_orders_and_every_account(): void
    {
        $this->switchStorefrontsOn();
        $this->serveMarket('SA');

        $stats = app(SectionDataResolver::class)->storeStats();

        // Two orders of the default market: the one it recorded and the one that recorded nothing, which the platform reads as its own.
        $this->assertSame(['products' => 1, 'orders' => 2, 'customers' => 5], $this->figuresOf($stats));
    }

    public function test_the_bar_of_another_market_counts_that_markets_orders_and_the_customers_who_shopped_there(): void
    {
        $this->switchStorefrontsOn();
        $this->serveMarket('KW');

        $stats = app(SectionDataResolver::class)->storeStats();

        // One Kuwaiti product, two Kuwaiti orders, and those orders were placed by two of the five accounts.
        $this->assertSame(['products' => 1, 'orders' => 2, 'customers' => 2], $this->figuresOf($stats));
    }

    /** A market that has sold nothing says so, rather than borrowing another market's figures. */
    public function test_a_market_that_has_sold_nothing_shows_no_orders_and_no_customers(): void
    {
        $this->switchStorefrontsOn();
        $this->serveMarket('AE');

        $stats = app(SectionDataResolver::class)->storeStats();

        $this->assertSame(['products' => 0, 'orders' => 0, 'customers' => 0], $this->figuresOf($stats));
    }

    /** Brands and categories are shared by design: no table carries a country, so the count has no market to be narrowed to. */
    public function test_brands_and_categories_are_the_platforms_in_every_market(): void
    {
        $this->switchStorefrontsOn();

        $this->serveMarket('SA');
        $saudi = app(SectionDataResolver::class)->storeStats();
        $this->serveMarket('KW');
        $kuwait = app(SectionDataResolver::class)->storeStats();

        $this->assertSame($saudi['brands'], $kuwait['brands']);
        $this->assertSame($saudi['categories'], $kuwait['categories']);
    }

    /** A panel, a queue or a console run belongs to no market and reads the platform's figures, as it always did. */
    public function test_a_run_that_belongs_to_no_market_counts_the_whole_platform(): void
    {
        $this->switchStorefrontsOn();
        app()->forgetInstance(StorefrontContext::class);

        $stats = app(SectionDataResolver::class)->storeStats();

        $this->assertSame(4, $stats['orders']);
        $this->assertSame(5, $stats['customers']);
    }

    /** @return array{products: int, orders: int, customers: int} */
    private function figuresOf(array $stats): array
    {
        return ['products' => $stats['products'], 'orders' => $stats['orders'], 'customers' => $stats['customers']];
    }

    private function switchStorefrontsOn(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.KW.active' => true, 'gcc.storefronts.countries.AE.active' => true]);
        StorefrontCatalog::flush();
    }

    private function serveMarket(string $country): void
    {
        app()->instance(StorefrontContext::class, StorefrontResolver::fromConfig()->context(explicitChoice: $country));
    }

    /**
     * Five accounts, three products in three markets and four orders: one recorded in Saudi Arabia, two in Kuwait and one that recorded no
     * market at all -- the order of a platform that had no snapshot yet, which the default market reads as its own.
     */
    private function buildTheCustomersAndOrders(): void
    {
        $this->insertCatalogProduct(id: 1, name: 'Saudi serum');
        $this->insertCatalogProduct(id: 2, name: 'Kuwaiti serum', country: 'KW');
        $this->insertCatalogProduct(id: 3, name: 'Qatari serum', country: 'QA');

        Schema::dropIfExists('categories');
        Schema::create('categories', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->integer('position')->default(0);
            $table->timestamps();
        });
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Skin', 'slug' => 'skin', 'position' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Hair', 'slug' => 'hair', 'position' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('brands')->insert([['id' => 1, 'status' => true]]);

        Schema::dropIfExists('users');
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('f_name')->nullable();
            $table->timestamps();
        });
        for ($customer = 1; $customer <= 5; $customer++) {
            DB::table('users')->insert(['id' => $customer, 'f_name' => 'Customer ' . $customer, 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::dropIfExists('orders');
        Schema::create('orders', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->timestamps();
        });
        (require base_path('database/migrations/2026_11_26_100000_add_storefront_snapshot_to_orders_table.php'))->up();

        foreach ([
            ['id' => 1, 'customer_id' => 1, 'storefront_country' => 'SA'],
            ['id' => 2, 'customer_id' => 2, 'storefront_country' => 'KW'],
            ['id' => 3, 'customer_id' => 3, 'storefront_country' => 'KW'],
            ['id' => 4, 'customer_id' => 4, 'storefront_country' => null],
        ] as $order) {
            DB::table('orders')->insert($order + ['created_at' => now(), 'updated_at' => now()]);
        }
    }
}
