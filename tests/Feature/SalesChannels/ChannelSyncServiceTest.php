<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\ExternalOrderMapping;
use App\Models\InventoryLocation;
use App\Models\InventoryLocationBalance;
use App\Models\OperationalCostEntry;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Models\SalesChannelSyncState;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\SalesChannels\Support\ChannelOperations;
use App\Services\SalesChannels\Sync\ChannelSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\Support\FakeCurrencyRates;
use Tests\Support\Fixtures\AmazonFixtures as Amazon;
use Tests\Support\Fixtures\TrendyolFixtures as Trendyol;
use Tests\TestCase;

/** The read side end to end, against fixtures: what is fetched, what is recorded, what is left alone, and what a failure does to the state. */
class ChannelSyncServiceTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    private const TY = 'https://apigw.trendyol.com/integration';
    private const AMAZON = 'https://sellingpartnerapi-eu.amazon.com';

    private ChannelSyncService $sync;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => false]);
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00:00 UTC'));
        $this->sync = app(ChannelSyncService::class);
    }

    private function lwa(): array
    {
        return ['https://api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'Atza|t', 'expires_in' => 3600])];
    }

    // ---- orders -----------------------------------------------------------------------------------------

    public function test_orders_are_recorded_once_and_the_watermark_moves_to_the_newest_change(): void
    {
        $account = $this->trendyolAccount();
        Http::fake([self::TY . '/*' => Http::response(Trendyol::stream([Trendyol::package()]))]);

        $first = $this->sync->syncOrders($account);
        $second = $this->sync->syncOrders($account);

        $this->assertSame('ok', $first['result']);
        $this->assertSame(1, $first['stats']['created']);
        $this->assertSame(1, $second['stats']['unchanged'], 'Reading the same order again changes nothing.');
        $this->assertSame(1, ExternalOrderMapping::count());
        $state = SalesChannelSyncState::where('account_id', $account->id)->where('resource', 'orders')->firstOrFail();
        $this->assertSame('2026-11-05T08:00:00+00:00', $state->watermark->utc()->toIso8601String());
        $this->assertNull($state->cursor);
    }

    public function test_an_instant_from_a_utc_channel_comes_back_unchanged_whatever_the_application_timezone(): void
    {
        $original = date_default_timezone_get();
        try {
            foreach (['UTC', 'Asia/Riyadh', 'America/New_York'] as $zone) {
                date_default_timezone_set($zone);
                $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00:00 UTC'));
                ExternalOrderMapping::query()->delete();
                SalesChannelSyncState::query()->delete();
                $account = $this->trendyolAccount();
                Http::fake([self::TY . '/*' => Http::response(Trendyol::stream([Trendyol::package()]))]);

                $this->sync->syncOrders($account);

                $mapping = ExternalOrderMapping::firstOrFail();
                $this->assertSame('2026-11-05T07:50:00+00:00', $mapping->purchased_at->utc()->toIso8601String(), $zone);
                $this->assertSame('2026-11-05T08:00:00+00:00', $mapping->external_updated_at->utc()->toIso8601String(), $zone);
                $watermark = SalesChannelSyncState::firstOrFail()->watermark;
                $this->assertSame('2026-11-05T08:00:00+00:00', $watermark->utc()->toIso8601String(), $zone);
                SalesChannelAccount::query()->delete();
            }
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function test_the_second_run_reads_from_the_watermark_less_the_overlap(): void
    {
        $account = $this->trendyolAccount();
        Http::fake([self::TY . '/*' => Http::response(Trendyol::stream([Trendyol::package()]))]);
        $this->sync->syncOrders($account);

        $this->sync->syncOrders($account);

        $second = Http::recorded()->values()->last()[0];
        parse_str((string) parse_url($second->url(), PHP_URL_QUERY), $query);
        $this->assertSame((string) (CarbonImmutable::parse('2026-11-05 07:50:00 UTC')->getTimestamp() * 1000), $query['lastModifiedStartDate'], '10 minutes before the newest change seen.');
    }

    public function test_a_long_backlog_is_read_in_runs_of_a_few_pages_and_resumes_where_it_stopped(): void
    {
        config(['sales_channels.sync.max_pages_per_run' => 1, 'sales_channels.sync.order_backfill_days' => 5]);
        $account = $this->trendyolAccount();
        $one = Trendyol::package(['shipmentPackageId' => 1, 'orderNumber' => 'A']);
        $two = Trendyol::package(['shipmentPackageId' => 2, 'orderNumber' => 'B', 'lastModifiedDate' => Trendyol::CREATED_MS + 60_000]);
        Http::fake([self::TY . '/*' => Http::sequence()->push(Trendyol::stream([$one], 'CUR-1'))->push(Trendyol::stream([$two]))]);

        $first = $this->sync->syncOrders($account);
        $paused = SalesChannelSyncState::where('account_id', $account->id)->where('resource', 'orders')->firstOrFail();
        $second = $this->sync->syncOrders($account);

        $this->assertSame(['ok', 'ok'], [$first['result'], $second['result']]);
        $this->assertSame('CUR-1', $paused->cursor['cursor']);
        $this->assertArrayHasKey('_started', $paused->cursor);
        $this->assertSame(2, ExternalOrderMapping::count());
        $done = SalesChannelSyncState::where('account_id', $account->id)->where('resource', 'orders')->firstOrFail();
        $this->assertNull($done->cursor);
        $this->assertSame('2026-11-05T08:01:00+00:00', $done->watermark->utc()->toIso8601String(), 'The newest change of the WHOLE sweep, not only of its last run.');
    }

    public function test_a_failing_channel_is_reported_as_a_failure_and_the_watermark_does_not_move(): void
    {
        $account = $this->trendyolAccount();
        Http::fake([self::TY . '/*' => Http::response(['errors' => [['key' => 'internal']]], 500)]);

        $result = $this->sync->syncOrders($account);

        $this->assertSame('failed', $result['result']);
        $state = SalesChannelSyncState::where('account_id', $account->id)->where('resource', 'orders')->firstOrFail();
        $this->assertNull($state->watermark);
        $this->assertNotNull($state->last_error);
    }

    public function test_a_rate_limit_pauses_the_work_without_calling_it_a_failure(): void
    {
        $account = $this->trendyolAccount();
        Http::fake([self::TY . '/*' => Http::response(['errors' => [['key' => 'too.many.requests']]], 429)]);

        $this->assertSame('rate_limited', $this->sync->syncOrders($account)['result']);
    }

    public function test_an_account_without_order_import_is_skipped_not_failed(): void
    {
        $account = $this->trendyolAccount(['connection_test', 'read_only_sync']);
        Http::fake();

        $this->assertSame([], $this->sync->runDue($account), 'Nothing permitted, nothing run.');
        $direct = $this->sync->syncOrders($account);

        $this->assertSame('skipped', $direct['result']);
        Http::assertNothingSent();
    }

    public function test_run_due_reads_only_what_the_account_may_and_only_when_it_is_due(): void
    {
        $account = $this->trendyolAccount(['connection_test', 'read_only_sync', 'order_import']);
        Http::fake([self::TY . '/*' => Http::response(Trendyol::stream([Trendyol::package()]))]);

        $first = $this->sync->runDue($account);
        $second = $this->sync->runDue($account);

        $this->assertSame(['orders'], array_keys($first), 'Listings and statements need their own capabilities.');
        $this->assertSame([], $second, 'Five minutes have not passed.');
    }

    // ---- listings ---------------------------------------------------------------------------------------

    public function test_listings_are_recorded_unlinked_and_a_listing_that_disappears_is_flagged_not_deleted(): void
    {
        $account = $this->trendyolAccount();
        Http::fake([
            self::TY . '/product/sellers/1234567/products/approved*' => Http::sequence()
                ->push(Trendyol::approvedPage([Trendyol::content('BC-1', 'SKU-1'), Trendyol::content('BC-2', 'SKU-2')]))
                ->push(Trendyol::approvedPage([Trendyol::content('BC-1', 'SKU-1')])),
            self::TY . '/product/sellers/1234567/products/unapproved*' => Http::response(['totalElements' => 0, 'totalPages' => 1, 'page' => 0, 'size' => 100, 'content' => []]),
        ]);

        $first = $this->sync->syncListings($account);
        $this->travel(10)->minutes();
        $second = $this->sync->syncListings($account);

        $this->assertSame(2, $first['stats']['created']);
        $this->assertSame(['unmapped', 'unmapped'], SalesChannelProductMapping::orderBy('id')->pluck('status')->all(), 'Reading a channel links nothing and creates no product.');
        $this->assertSame(1, $second['stats']['unseen']);
        $this->assertSame(2, SalesChannelProductMapping::count());
        $this->assertSame('not_seen', SalesChannelProductMapping::where('listing_key', 'BC-2')->firstOrFail()->remote_status);
    }

    // ---- channel-held stock -----------------------------------------------------------------------------

    public function test_fba_stock_goes_to_the_fba_location_alone_and_what_disappears_goes_stale(): void
    {
        $account = $this->amazonAccount();
        Http::fake($this->lwa() + [self::AMAZON . '/*' => Http::sequence()
            ->push(['payload' => ['inventorySummaries' => [Amazon::inventorySummary('SKU-1', 7), Amazon::inventorySummary('SKU-2', 3)]]])
            ->push(['payload' => ['inventorySummaries' => [Amazon::inventorySummary('SKU-1', 5)]]])]);

        $first = $this->sync->syncChannelInventory($account);
        $this->travel(10)->minutes();
        $second = $this->sync->syncChannelInventory($account);

        $this->assertSame(2, $first['stats']['stored']);
        $location = InventoryLocation::where('type', InventoryLocation::TYPE_AMAZON_FBA)->firstOrFail();
        $this->assertFalse((bool) $location->storefront_sellable);
        $this->assertSame([], $location->publishable_channels, 'FBA stock is never offered to a storefront or another channel.');
        $this->assertSame(5, InventoryLocationBalance::where('sku', 'SKU-1')->firstOrFail()->available);
        $this->assertSame(1, $second['stats']['stale']);
        $this->assertSame('stale', InventoryLocationBalance::where('sku', 'SKU-2')->firstOrFail()->quality);
        $this->assertSame(0, InventoryLocationBalance::whereNotIn('location_id', [$location->id])->count(), 'Torod Point and own-warehouse stock are untouched.');
    }

    // ---- statements -------------------------------------------------------------------------------------

    public function test_a_statement_becomes_actual_costs_once_and_a_second_run_adds_nothing(): void
    {
        $account = $this->amazonAccount();
        $this->recordOrder($account);
        Http::fake($this->lwa() + [self::AMAZON . '/*' => Http::response(['payload' => ['transactions' => [Amazon::transaction()]]])]);

        $first = $this->sync->syncFinances($account);
        $this->travel(10)->minutes();
        $second = $this->sync->syncFinances($account);

        $this->assertSame('ok', $first['result']);
        $this->assertSame(2, $first['stats']['costs_recorded'], 'The commission and the FBA fee; the sale itself is not a cost.');
        $this->assertSame(0, $second['stats']['costs_recorded']);
        $this->assertSame(['marketplace_commission' => '20.000000', 'fba_fulfillment' => '10.000000'], OperationalCostEntry::where('state', CostState::Actual->value)->pluck('amount', 'cost_component')->all());
    }

    private function recordOrder($account): ExternalOrderMapping
    {
        return ExternalOrderMapping::create([
            'account_id' => $account->id, 'sales_channel' => $account->channel, 'seller_id' => 0, 'external_order_id' => '171-0000001-0000001', 'external_package_id' => '',
            'status' => 'shipped', 'currency' => 'SAR', 'fulfillment_mode' => 'fba',
        ]);
    }

    public function test_the_actual_replaces_the_missing_commission_and_the_estimate_is_not_added_on_top(): void
    {
        $account = $this->amazonAccount();
        $mapping = $this->recordOrder($account);
        OperationalCostEntry::create([
            'seller_id' => 0, 'dedupe_key' => 'e', 'slot_key' => hash('sha256', implode('|', ['slot', 0, 'channel_order', $mapping->id, CostComponent::MarketplaceCommission->value, ''])),
            'subject_type' => 'channel_order', 'subject_id' => $mapping->id, 'period_date' => '2026-11-05', 'cost_component' => 'marketplace_commission', 'cost_class' => 'marketplace',
            'state' => CostState::Estimated->value, 'is_effective' => true, 'amount' => '18.000000', 'currency' => 'SAR', 'source' => 'rule', 'allocation_status' => 'not_applicable',
        ]);
        Http::fake($this->lwa() + [self::AMAZON . '/*' => Http::response(['payload' => ['transactions' => [Amazon::transaction()]]])]);

        $this->sync->syncFinances($account);

        $effective = OperationalCostEntry::where('is_effective', true)->where('cost_component', 'marketplace_commission')->get();
        $this->assertCount(1, $effective);
        $this->assertSame([CostState::Actual, '20.000000'], [$effective[0]->state, $effective[0]->amount]);
    }
}
