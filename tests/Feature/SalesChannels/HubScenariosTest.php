<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\StockSource;
use App\Models\ExternalOrderMapping;
use App\Models\FulfillmentRoutingDecision;
use App\Models\FulfillmentRoutingRule;
use App\Models\InventoryLocation;
use App\Models\InventoryLocationBalance;
use App\Models\Order;
use App\Models\OperationalCostEntry;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;
use App\Models\ProviderProductMapping;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Models\SalesChannelSyncState;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\OperationalCosts\CostRefresher;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use App\Services\SalesChannels\Inventory\LocationStockReader;
use App\Services\SalesChannels\Shipments\ChannelShipmentNotifier;
use App\Services\SalesChannels\Shipments\ExternalShipmentLookup;
use App\Services\SalesChannels\Shipments\ShipmentCandidate;
use App\Services\SalesChannels\Support\ChannelOperations;
use App\Services\SalesChannels\Sync\ChannelSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use App\Services\OperationalCosts\Profit\ProfitabilityService;
use App\Services\OperationalCosts\Sales\SaleDocumentReader;
use App\Enums\OperationalCosts\CostState;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\Support\FakeCurrencyRates;
use Tests\Support\Fixtures\AmazonFixtures as Amazon;
use Tests\Support\Fixtures\TrendyolFixtures as Trendyol;
use Tests\TestCase;

/**
 * The whole path, order by order, against fixtures: what is read, what is decided, what is linked, what is told to the channel, and what must NOT happen.
 * Nothing here reaches a real channel or provider, so these prove the application's behaviour, not that a live account works.
 */
class HubScenariosTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    private const TY = 'https://apigw.trendyol.com/integration';
    private const AMAZON = 'https://sellingpartnerapi-eu.amazon.com';

    private ChannelSyncService $sync;

    private InventoryLocation $torod;

    private InventoryLocation $m5azn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00:00 UTC'));
        [$this->torod, $this->m5azn] = app(InventoryLocationService::class)->ensureDefaults();
        $this->sync = app(ChannelSyncService::class);
    }

    private function stocked(SalesChannelAccount $account, string $listingKey, string $sku, int $productId, InventoryLocation $location, int $stock = 40): void
    {
        SalesChannelProductMapping::create(['account_id' => $account->id, 'product_id' => $productId, 'listing_key' => $listingKey, 'external_sku' => $sku, 'external_barcode' => $listingKey, 'status' => 'mapped', 'stock_location_id' => $location->id]);
        ProviderProductMapping::create(['provider' => $location->provider, 'product_id' => $productId, 'variant_key' => '', 'external_id' => 'ext-' . $productId, 'status' => MappingStatus::Approved, 'stock_source' => StockSource::ProviderSync, 'provider_stock' => $stock, 'provider_stock_synced_at' => now()]);
    }

    private function rule(array $values): FulfillmentRoutingRule
    {
        return FulfillmentRoutingRule::create($values + ['name' => 'Rule', 'priority' => 100, 'is_active' => true]);
    }

    private function lwa(): array
    {
        return ['https://api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'Atza|t', 'expires_in' => 3600])];
    }

    /** A clean fake: a later Http::fake() adds stubs behind the earlier ones. */
    private function fakeOnly(array $stubs): void
    {
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    /** @return list<Request> */
    private function writes(): array
    {
        return array_values(array_filter(Http::recorded()->map(fn (array $pair): Request => $pair[0])->all(), static fn (Request $request): bool => in_array($request->method(), ['PUT', 'DELETE', 'PATCH'], true) || ($request->method() === 'POST' && !str_contains($request->url(), 'auth/o2/token'))));
    }

    private function lookupFinding(string $reference, string $tracking): void
    {
        $this->app->bind(ExternalShipmentLookup::class, fn () => new class($reference, $tracking) implements ExternalShipmentLookup {
            public function __construct(private string $reference, private string $tracking)
            {
            }

            public function available(): bool
            {
                return true;
            }

            public function candidates(ExternalOrderMapping $mapping): array
            {
                return [new ShipmentCandidate('torod_point', $this->reference, $this->tracking, 'SMSA', 'created', 'order_number')];
            }
        });
        $this->sync = app(ChannelSyncService::class);
    }

    private function assertNothingCreatedInTheStoreOrFulfilment(): void
    {
        $this->assertSame([0, 0, 0], [Order::count(), ProviderFulfillment::count(), ProviderFulfillmentItem::count()], 'The existing order and provider-fulfilment tables are untouched.');
    }

    // ---- Trendyol -> Torod, shipment already arranged ---------------------------------------------------

    public function test_trendyol_linked_to_torod_the_existing_shipment_is_found_followed_and_reported_once(): void
    {
        $account = $this->trendyolAccount();
        $this->stocked($account, '6281234567890', 'SKU-1', 10, $this->torod);
        $this->rule(['name' => 'Trendyol via Torod', 'action' => 'monitor_only', 'sales_channel' => 'trendyol', 'target_provider' => 'torod_point']);
        $this->lookupFinding('TOR-777', 'TRK-777');
        $package = Trendyol::package(['whoPays' => 1]);
        $this->fakeOnly([
            self::TY . '/order/sellers/1234567/orders/stream*' => Http::response(Trendyol::stream([$package])),
            self::TY . '/order/sellers/1234567/v2/orders*' => Http::response(Trendyol::stream([$package])),
            self::TY . '/order/sellers/*' => Http::response('', 200),
        ]);

        $run = $this->sync->syncOrders($account);
        $mapping = ExternalOrderMapping::firstOrFail();

        $this->assertSame(1, $run['stats']['created']);
        $this->assertSame(1, $run['stats']['decisions']);
        $this->assertSame(1, $run['stats']['linked']);
        $this->assertSame(['externally_managed', 'torod_point', 'linked', 'TOR-777', 'TRK-777'], [$mapping->execution_policy, $mapping->provider, $mapping->shipment_link_state, $mapping->shipment_reference, $mapping->tracking_number]);
        $this->assertNothingCreatedInTheStoreOrFulfilment();
        $this->assertCount(0, $this->writes(), 'Reading and following an order writes nothing to Trendyol.');

        $notifier = app(ChannelShipmentNotifier::class);
        $first = $notifier->notifyDue($account);
        $second = $notifier->notifyDue($account);

        $this->assertSame([1, 0], [$first['sent'], $second['sent']]);
        $this->assertCount(2, $this->writes(), 'Picking, then the tracking number: once.');

        $this->travel(10)->minutes();
        $this->sync->syncOrders($account);
        $this->assertSame(1, FulfillmentRoutingDecision::count(), 'A later read re-decides nothing: the shipment is linked.');
        $this->assertSame(1, ExternalOrderMapping::count());
    }

    // ---- Trendyol, the application asked to ship it itself ----------------------------------------------

    public function test_trendyol_app_driven_is_recorded_as_wanted_and_waits_because_creating_the_shipment_is_not_available(): void
    {
        $account = $this->trendyolAccount();
        $this->stocked($account, '6281234567890', 'SKU-1', 10, $this->torod);
        $this->rule(['name' => 'App ships it', 'action' => 'route_to_provider', 'sales_channel' => 'trendyol']);
        $this->fakeOnly([self::TY . '/*' => Http::response(Trendyol::stream([Trendyol::package(['whoPays' => 1])]))]);

        $this->sync->syncOrders($account);

        $decision = FulfillmentRoutingDecision::firstOrFail();
        $this->assertSame(['manual_review', 'managed_dispatch_not_available', 'torod_point'], [$decision->action, $decision->reason_code, $decision->inputs['wanted_provider']]);
        $this->assertSame('manual_review', ExternalOrderMapping::firstOrFail()->execution_policy);
        $this->assertNothingCreatedInTheStoreOrFulfilment();
        $this->assertCount(0, $this->writes());
    }

    public function test_a_package_trendyol_pays_the_carrier_for_never_gets_a_second_shipment_whatever_the_rule(): void
    {
        $account = $this->trendyolAccount();
        $this->stocked($account, '6281234567890', 'SKU-1', 10, $this->torod);
        $this->rule(['name' => 'App ships it', 'action' => 'route_to_provider', 'priority' => 1]);
        config(['sales_channels.managed_dispatch_enabled' => true]);
        $this->fakeOnly([self::TY . '/*' => Http::response(Trendyol::stream([Trendyol::package()]))]);

        $this->sync->syncOrders($account);

        $this->assertSame('carrier_arranged_by_channel', FulfillmentRoutingDecision::firstOrFail()->reason_code);
        $this->assertNothingCreatedInTheStoreOrFulfilment();
    }

    // ---- Amazon FBA -------------------------------------------------------------------------------------

    public function test_amazon_fba_is_never_routed_its_stock_stays_apart_and_its_fee_is_missing_until_the_statement(): void
    {
        $account = $this->amazonAccount();
        $this->stocked($account, 'SKU-1', 'SKU-1', 10, $this->torod, stock: 25);
        $this->rule(['name' => 'Everything to Torod', 'action' => 'route_to_provider', 'priority' => 1]);
        config(['sales_channels.managed_dispatch_enabled' => true]);
        $fba = Amazon::order('171-0000002-0000002', 'AMAZON', 'SHIPPED');
        $this->fakeOnly($this->lwa() + [self::AMAZON . '/*' => Http::sequence()
            ->push(Amazon::searchOrders([$fba]))
            ->push(['payload' => ['inventorySummaries' => [Amazon::inventorySummary('SKU-1', 7)]]])]);

        $this->sync->syncOrders($account);
        $this->sync->syncChannelInventory($account);

        $decision = FulfillmentRoutingDecision::firstOrFail();
        $this->assertSame(['channel_fulfills', 'channel_fulfills_order', null], [$decision->action, $decision->reason_code, $decision->provider]);
        $this->assertNothingCreatedInTheStoreOrFulfilment();

        $fbaLocation = InventoryLocation::where('type', InventoryLocation::TYPE_AMAZON_FBA)->firstOrFail();
        $reader = app(LocationStockReader::class);
        $this->assertSame(25, $reader->availability($this->torod, 10)->available, 'Torod stock is what the provider reports, untouched by FBA.');
        $this->assertSame(7, InventoryLocationBalance::where('location_id', $fbaLocation->id)->firstOrFail()->available);
        $this->assertSame([], $fbaLocation->publishable_channels, 'FBA stock is never offered as ours.');

        app(CostRefresher::class)->refreshPeriod(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));
        $this->assertSame('awaiting_channel_statement', OperationalCostEntry::where('cost_component', 'fba_fulfillment')->firstOrFail()->missing_reason);
    }

    public function test_the_statement_then_supplies_the_amazon_fees_as_actuals(): void
    {
        $account = $this->amazonAccount();
        $this->fakeOnly($this->lwa() + [self::AMAZON . '/orders/*' => Http::response(Amazon::searchOrders([Amazon::order('171-0000001-0000001', 'AMAZON', 'SHIPPED')])), self::AMAZON . '/finances/*' => Http::response(['payload' => ['transactions' => [Amazon::transaction()]]])]);
        $this->sync->syncOrders($account);
        app(CostRefresher::class)->refreshPeriod(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));

        $this->sync->syncFinances($account);

        // The statement supplied the figure. The `Missing` marker is still a row -- the channel's net has its own slot since
        // 2026-11-11 -- but it must no longer be REPORTED as missing, because saying the fees never arrived while they sit
        // in the same report is the one thing a person would act on wrongly.
        $rows = OperationalCostEntry::where('is_effective', true)->where('cost_component', 'fba_fulfillment')->orderBy('id')->get();
        $this->assertSame([CostState::Missing, CostState::Actual], $rows->pluck('state')->all());
        $this->assertSame('10.000000', $rows->last()->amount, 'the statement supplied the figure');

        $mapping = \App\Models\ExternalOrderMapping::where('sales_channel', 'amazon')->firstOrFail();
        $profit = app(ProfitabilityService::class)->forDocument(app(SaleDocumentReader::class)->forChannelOrder($mapping->id));

        $this->assertNotContains('fba_fulfillment', $profit->missingComponents, 'a figure arrived, so nothing about it is missing any more');
        $this->assertSame('10.000000', $profit->buckets['fba_fulfillment']->actual->toStorage());
        $this->assertContains('fba_fulfillment', $profit->pendingActualComponents, 'and arriving is still not settling');
    }

    // ---- Amazon FBM -------------------------------------------------------------------------------------

    public function test_amazon_merchant_fulfilled_order_is_followed_at_torod_and_confirmed_with_amazon(): void
    {
        $account = $this->amazonAccount();
        $this->stocked($account, 'SKU-1', 'SKU-1', 10, $this->torod);
        $this->rule(['name' => 'FBM via Torod', 'action' => 'monitor_only', 'sales_channel' => 'amazon', 'target_provider' => 'torod_point']);
        $this->lookupFinding('TOR-5', 'TRK-5');
        $this->fakeOnly($this->lwa() + [self::AMAZON . '/orders/2026-01-01/*' => Http::response(Amazon::searchOrders([Amazon::order()])), self::AMAZON . '/orders/v0/*' => Http::response('', 204)]);

        $this->sync->syncOrders($account);
        ExternalOrderMapping::firstOrFail()->forceFill(['shipped_at' => now()])->save();
        $sent = app(ChannelShipmentNotifier::class)->notifyDue($account);

        $mapping = ExternalOrderMapping::firstOrFail();
        $this->assertSame(['followed' => 'externally_managed', 'linked' => 'TOR-5', 'sent' => 1], ['followed' => $mapping->execution_policy, 'linked' => $mapping->shipment_reference, 'sent' => $sent['sent']]);
        $this->assertCount(1, $this->writes());
        $this->assertNothingCreatedInTheStoreOrFulfilment();
    }

    // ---- failures ---------------------------------------------------------------------------------------

    public function test_a_failing_channel_backs_off_and_the_next_run_recovers_everything_once(): void
    {
        config(['sales_channels.sync.order_backfill_days' => 5]);
        $account = $this->trendyolAccount();
        $this->fakeOnly([self::TY . '/*' => Http::sequence()->push('', 500)->push('', 500)->push('', 500)->push('', 500)->push(Trendyol::stream([Trendyol::package()]))]);

        $failed = $this->sync->syncOrders($account);
        $blocked = $this->sync->syncOrders($account);
        $this->assertSame(['failed', 'busy'], [$failed['result'], $blocked['result']], 'While backing off, the channel is not hammered.');
        $this->assertSame(0, ExternalOrderMapping::count());

        $this->travel(10)->minutes();
        $recovered = $this->sync->syncOrders($account);

        $this->assertSame('ok', $recovered['result']);
        $this->assertSame(1, ExternalOrderMapping::count());
        $state = SalesChannelSyncState::where('account_id', $account->id)->where('resource', 'orders')->firstOrFail();
        $this->assertSame([0, null], [$state->consecutive_failures, $state->last_error]);

        $this->travel(10)->minutes();
        $this->sync->syncOrders($account);
        $this->assertSame(1, ExternalOrderMapping::count(), 'Reading again duplicates nothing.');
    }

    // ---- isolation --------------------------------------------------------------------------------------

    public function test_a_sellers_channel_costs_carry_that_seller_and_do_not_appear_in_the_platforms(): void
    {
        $platform = $this->trendyolAccount();
        $seller = $this->trendyolAccount(sellerId: '7654321');
        $seller->forceFill(['seller_id' => 7])->save();
        $statement = fn (string $account) => fn (Request $request) => Http::response(str_contains($request->url(), 'transactionType=Sale')
            ? Trendyol::statement([Trendyol::settlement('r', -25.0, 170.0, ['orderNumber' => 'O-' . $account, 'shipmentPackageId' => 5000 + (int) $account])])
            : Trendyol::statement([]));
        $this->fakeOnly([
            self::TY . '/finance/che/sellers/1234567/*' => $statement('1'),
            self::TY . '/finance/che/sellers/7654321/*' => $statement('2'),
        ]);
        foreach (['O-1' => $platform, 'O-2' => $seller] as $orderNumber => $owner) {
            ExternalOrderMapping::create(['account_id' => $owner->id, 'sales_channel' => 'trendyol', 'seller_id' => (int) $owner->seller_id, 'external_order_id' => $orderNumber, 'external_package_id' => (string) (5000 + (int) substr($orderNumber, 2)), 'status' => 'delivered', 'currency' => 'SAR']);
        }

        $this->sync->syncFinances($platform);
        $this->sync->syncFinances($seller->fresh());

        $bySeller = OperationalCostEntry::where('is_effective', true)->where('cost_component', 'marketplace_commission')->get()->groupBy('seller_id')->map->count()->all();
        $this->assertSame([0 => 1, 7 => 1], $bySeller, 'Each cost carries the seller whose channel account earned it.');
        $this->assertSame(0, OperationalCostEntry::where('seller_id', 7)->where('account_id', $platform->id)->count());
    }
}
