<?php

namespace Tests\Support;

use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Enums\Fulfillment\StockSource;
use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Models\ExternalOrderMapping;
use App\Models\FulfillmentRoutingRule;
use App\Models\InventoryLocation;
use App\Models\ProviderProductMapping;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Dispatch\ChannelOrderDispatcher;
use App\Services\SalesChannels\Dispatch\ChannelOrderMarker;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use App\Services\SalesChannels\Inventory\PoolNet;
use App\Services\SalesChannels\Routing\DynamicFulfillmentRoutingEngine;
use App\Services\SalesChannels\Support\PlatformSchemaCheck;
use App\Services\SalesChannels\Sync\ChannelOrderIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Fixtures\TrendyolFixtures as Trendyol;

/**
 * The ground every dispatch test stands on: a Trendyol account with the managed-dispatch switches open and approved, a product the store holds at Torod Point, and a rule that sends everything there.
 * Every Trendyol answer is a fixture. A test class using it brings the provider: the platform's fake adapter (DispatchTestCase) or the real Torod Point adapter against faked HTTP (TorodTestCase).
 */
trait BuildsDispatchWorld
{
    use BuildsRemoteOrders;
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    protected const TY = 'https://apigw.trendyol.com/integration';
    protected const NAME = 'Test Buyer';
    protected const PHONE_NATIONAL = '0500000000';
    protected const STREET = '1 Test Street';

    protected SalesChannelAccount $account;

    /** @var array<string, string> package id => order number, of the orders placed so far */
    protected array $placedOrders = [];

    protected InventoryLocation $torodLocation;

    /** Everything the dispatch tests need once the platform's own test schema and provider setup exist. */
    protected function prepareDispatchWorld(): void
    {
        $this->pinTheWorldClock();
        $this->runSalesChannelMigrations();
        // The platform's own default for this column is 1: units never taken off the store's stock must not be given back by a cancellation.
        Schema::table('order_details', static fn (Blueprint $table) => $table->boolean('is_stock_decreased')->default(true));
        $this->addTheColumnsTheRealTablesHave();
        ChannelOrderMarker::forget();
        Schema::create('audit_logs', static function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
        config([
            'sales_channels.enabled' => true, 'sales_channels.managed_dispatch_enabled' => true, 'sales_channels.buyer_data_transfer_enabled' => true,
            'sales_channels.dispatch.retry_minutes' => [5, 15, 60], 'sales_channels.dispatch.max_attempts' => 3, 'operational_costs.base_currency' => 'SAR',
        ]);
        $this->enableProvider(ProviderCode::TorodPoint, ['dry_run' => true, 'auto_dispatch' => false]);
        [$this->torodLocation] = app(InventoryLocationService::class)->ensureDefaults();

        $account = $this->trendyolAccount();
        $account->forceFill([
            'settings' => ['shipment_creation' => 'app'] + (array) $account->settings,
            'capability_approvals' => ['buyer_data_transfer' => ['by' => 1, 'at' => now()->toIso8601String(), 'reason' => 'owner approved']] + (array) $account->capability_approvals,
        ])->save();
        $this->account = $account->fresh();

        $this->makeProduct(10);
        $this->approveAtTorod(10);
        SalesChannelProductMapping::create(['account_id' => $this->account->id, 'product_id' => 10, 'listing_key' => 'SKU-1', 'external_sku' => 'SKU-1', 'external_barcode' => 'SKU-1', 'status' => 'mapped', 'stock_location_id' => $this->torodLocation->id]);
        FulfillmentRoutingRule::create(['name' => 'Torod for everything', 'priority' => 1, 'is_active' => true, 'action' => 'route_to_provider', 'target_provider' => 'torod_point']);
        $this->recipientAnswers();
    }

    /**
     * The world stands at a fixed instant an hour before its orders are bought, so a provider a test takes live
     * goes live before them — the order every shipping test needs, and the order of life: an operator opens the
     * provider, and orders arrive after that.
     *
     * Without this the clock was the real one and `go_live_at` was stamped from it, while the orders carried a
     * calendar date. The two crossed at {@see BuildsRemoteOrders::PURCHASED_AT}, and from that instant on every
     * shipment was held `BEFORE_GO_LIVE` and every test that expected one failed — not now and then, but for good.
     */
    protected function pinTheWorldClock(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::PURCHASED_AT)->subHour());
    }

    /** The test schema is rebuilt from the code and lacks columns the real tables have; the dispatcher writes them when they exist, so the tables are given the ones it names (nullable, as a dump's are not guaranteed to be). */
    protected function addTheColumnsTheRealTablesHave(): void
    {
        $types = ['verification_code' => 'integer', 'is_guest' => 'boolean', 'shipping_address' => 'integer', 'billing_address' => 'integer', 'product_details' => 'text', 'variation' => 'text',
            'discount_amount' => 'decimal', 'init_order_amount' => 'decimal', 'total_tax_amount' => 'decimal', 'shipping_cost' => 'decimal', 'tax' => 'decimal', 'discount' => 'decimal'];
        foreach (['orders', 'order_details'] as $table) {
            $named = [...PlatformSchemaCheck::TABLES[$table]['columns'], ...PlatformSchemaCheck::TABLES[$table]['optional']];
            foreach (array_diff($named, Schema::getColumnListing($table)) as $column) {
                Schema::table($table, static function (Blueprint $blueprint) use ($column, $types): void {
                    ($types[$column] ?? 'string') === 'decimal' ? $blueprint->decimal($column, 24, 6)->nullable() : $blueprint->{$types[$column] ?? 'string'}($column)->nullable();
                });
            }
        }
    }

    /** @return array{0: int, 1: int, 2: int} units the store's own orders hold, units the channel orders hold, and what can be offered, for the product at the provider's location */
    protected function figures(): array
    {
        $pool = app(PoolNet::class)->for($this->torodLocation, 10);

        return [$pool->storeHolds, $pool->channelHolds, $pool->net()];
    }

    protected function approveAtTorod(int $productId): void
    {
        ProviderProductMapping::create([
            'provider' => 'torod_point', 'product_id' => $productId, 'variant_key' => '', 'external_id' => 'ext-' . $productId, 'external_sku' => 'XSKU' . $productId,
            'status' => MappingStatus::Approved, 'stock_source' => StockSource::ProviderSync, 'provider_stock' => 50, 'provider_stock_synced_at' => now(),
        ]);
    }

    /** The channel answers for whichever package is asked about: the one of the order placed with that package id (the default order when none was placed). */
    protected function recipientAnswers(array $packageOverrides = []): void
    {
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake([self::TY . '/order/*' => function ($request) use ($packageOverrides) {
            $package = (string) ($request->data()['shipmentPackageIds'] ?? '3012345678');

            return Http::response(Trendyol::stream([Trendyol::package(['shipmentPackageId' => (int) $package, 'shipmentNumber' => (int) $package, 'orderNumber' => $this->placedOrders[$package] ?? '10234567890'] + $packageOverrides)]));
        }]);
    }

    protected function placed(array $overrides = [], string $orderId = '10234567890', string $package = '3012345678'): ExternalOrderMapping
    {
        $order = $this->remoteOrder($orderId, $package, ChannelOrderStatus::New, [$this->remoteLine('L1', 'SKU-1', 'SKU-1', 2, '200')], overrides: $overrides + ['fulfillmentChannelRaw' => 'seller_pays', 'fulfillmentMode' => FulfillmentMode::MerchantManaged]);
        $this->placedOrders[$package] = $orderId;
        $mapping = app(ChannelOrderIngestor::class)->ingest($this->account, $order)->mapping;
        app(DynamicFulfillmentRoutingEngine::class)->route($mapping->refresh());

        return $mapping->refresh();
    }

    protected function dispatcher(): ChannelOrderDispatcher
    {
        return app(ChannelOrderDispatcher::class);
    }

    protected function recipientReads(): int
    {
        return count(Http::recorded(fn ($request): bool => str_contains($request->url(), '/v2/orders')));
    }

    /** Everything stored anywhere except the internal order itself and the product/order tables, as one string. */
    protected function storedOutsideTheOrder(): string
    {
        $skip = ['orders', 'order_details', 'migrations'];
        $all = '';
        foreach (Schema::getTables(schema: Schema::getCurrentSchemaListing()) as $table) {
            if (in_array($table['name'], $skip, true)) {
                continue;
            }
            $all .= $table['name'] . json_encode(DB::table($table['name'])->get()->all(), JSON_UNESCAPED_UNICODE);
        }

        return $all;
    }
}
