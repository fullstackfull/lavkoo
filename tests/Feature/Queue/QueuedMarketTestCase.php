<?php

namespace Tests\Feature\Queue;

use App\Jobs\Middleware\ServeInItsStorefront;
use App\Services\Fulfillment\FulfillmentSchema;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RefusesToTouchALiveDatabase;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The world a queued job reads its market out of: orders of three markets, the shipments and the stored provider events of those orders,
 * and the products of those markets.
 *
 * `orders` and `products` are an INFERRED copy with the columns this feature reads, because the real ones come from the installation dump
 * that is not in this repository. The three columns the feature is ABOUT are not invented here: `orders.storefront_country` and
 * `products.storefront_country` are added by their own real migrations, run on a table built WITHOUT them, so the column a test reads is
 * the column production gets — `char(2) ascii_bin` on a real engine, which is what makes a byte-for-byte market comparison mean anything.
 * The provider fulfillment tables are the real migration in full.
 *
 * A test that wants a database which has NOT run the orders migration leaves it out, which is the state most installations are in.
 */
abstract class QueuedMarketTestCase extends TestCase
{
    use RefusesToTouchALiveDatabase;
    use RestoresLanguageFiles;

    protected const SAUDI_ORDER = 101;

    protected const KUWAITI_ORDER = 102;

    protected const EMIRATI_ORDER = 103;

    /** An order from before the snapshot existed, or sold while the switches were off: it records no market. */
    protected const UNRECORDED_ORDER = 104;

    protected const SAUDI_PRODUCT = 201;

    protected const KUWAITI_PRODUCT = 202;

    private const INFERRED_TABLES = ['orders', 'products'];

    private const FULFILLMENT_TABLES = [
        'integration_settings', 'provider_fulfillments', 'provider_fulfillment_items', 'provider_fulfillment_events',
        'provider_api_logs', 'provider_sync_states', 'provider_product_mappings', 'provider_catalog_items',
        'provider_price_changes', 'provider_cities', 'provider_city_aliases', 'product_provider_changes',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildTheWorldQueuedWorkReadsItsMarketFrom();

        config([
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.storefronts.countries.AE.active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();
        app()->forgetInstance(StorefrontContext::class);

        parent::tearDown();
    }

    /** Both switches on: storefronts exist, and a job reads its market off the record it carries. */
    protected function switchOnTheMarketOfTheRecord(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.queues.market_from_record' => true]);
    }

    protected function storefront(string $country): StorefrontContext
    {
        return StorefrontResolver::fromConfig()->contextOfRecordedCountry(countryCode: $country);
    }

    /** The market bound right now, as a job's own code would read it, or null when the worker is unbound. */
    protected function marketInForce(): ?string
    {
        return app()->bound(StorefrontContext::class) ? app(StorefrontContext::class)->country : null;
    }

    /** The market a job's queue middleware runs its work in, read by running that middleware and nothing else. */
    protected function marketTheJobRunsIn(object $job): ?string
    {
        $market = null;

        foreach ($job->middleware() as $middleware) {
            if ($middleware instanceof ServeInItsStorefront) {
                $market = $middleware->handle($job, fn (): ?string => $this->marketInForce());
            }
        }

        return $market;
    }

    protected function buildTheWorldQueuedWorkReadsItsMarketFrom(bool $ordersRecordTheirMarket = true, bool $productsRecordTheirMarket = true): void
    {
        $this->refuseUnlessTheDatabaseIsThrowaway();

        foreach ([...self::FULFILLMENT_TABLES, ...self::INFERRED_TABLES] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('order_status', 32)->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        (require database_path('migrations/2026_11_05_100000_create_provider_fulfillment_tables.php'))->up();

        if ($ordersRecordTheirMarket) {
            (require database_path('migrations/2026_11_26_100000_add_storefront_snapshot_to_orders_table.php'))->up();
        }

        if ($productsRecordTheirMarket) {
            (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();
        }

        FulfillmentSchema::flush();
        StorefrontCatalog::flush();

        $this->seedTheOrdersAndTheirShipments(ordersRecordTheirMarket: $ordersRecordTheirMarket, productsRecordTheirMarket: $productsRecordTheirMarket);
    }

    /** The shipment of one order, as the fulfillment tables hold it. Returns the shipment's id. */
    protected function shipmentOf(int $orderId, string $provider = 'm5azn'): int
    {
        return (int) DB::table('provider_fulfillments')->insertGetId([
            'reference' => 'ref-' . $orderId,
            'order_id' => $orderId,
            'provider' => $provider,
            'idempotency_key' => 'key-' . $orderId,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * One stored inbound provider event about a shipment. Returns the event's id.
     *
     * `$eventId` is there so a test can give the event the id of ANOTHER market's shipment. Without it the first event and the first
     * shipment are both id 1, and a reader that confused the two tables would answer the right market by coincidence: the teeth check for
     * the event -> shipment -> order hop came back inert exactly that way before this existed.
     */
    protected function storedEventOf(int $fulfillmentId, ?int $eventId = null): int
    {
        // `provider`, `source` and `event_type` are NOT NULL and have no default. Leaving them out INSERTED ANYWAY on MariaDB, because
        // config/database.php sets `strict` => false, and was refused by SQLite -- the usual engine trap running the other way round.
        $row = [
            'provider' => 'm5azn',
            'source' => 'webhook',
            'event_type' => 'status',
            'provider_fulfillment_id' => $fulfillmentId,
            'dedupe_key' => 'dedupe-' . $fulfillmentId . '-' . ($eventId ?? 'next'),
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($eventId !== null) {
            $row['id'] = $eventId;
        }

        return (int) DB::table('provider_fulfillment_events')->insertGetId($row);
    }

    private function seedTheOrdersAndTheirShipments(bool $ordersRecordTheirMarket, bool $productsRecordTheirMarket): void
    {
        $markets = [
            self::SAUDI_ORDER => 'SA',
            self::KUWAITI_ORDER => 'KW',
            self::EMIRATI_ORDER => 'AE',
            self::UNRECORDED_ORDER => null,
        ];

        foreach ($markets as $orderId => $market) {
            $row = ['id' => $orderId, 'order_status' => 'confirmed', 'created_at' => now(), 'updated_at' => now()];

            if ($ordersRecordTheirMarket) {
                $row['storefront_country'] = $market;
            }

            DB::table('orders')->insert($row);
        }

        foreach ([self::SAUDI_PRODUCT => 'SA', self::KUWAITI_PRODUCT => 'KW'] as $productId => $market) {
            $row = ['id' => $productId, 'name' => 'A product of ' . ($market ?? 'nowhere'), 'created_at' => now(), 'updated_at' => now()];

            if ($productsRecordTheirMarket) {
                $row['storefront_country'] = $market;
            }

            DB::table('products')->insert($row);
        }
    }
}
