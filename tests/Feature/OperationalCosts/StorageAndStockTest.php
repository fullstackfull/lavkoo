<?php

namespace Tests\Feature\OperationalCosts;

use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\StockSource;
use App\Models\InventoryLocation;
use App\Models\InventoryLocationBalance;
use App\Models\OperationalCostEntry;
use App\Models\ProviderProductMapping;
use App\Models\SalesChannelAccount;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\OperationalCosts\ContractService;
use App\Services\OperationalCosts\StorageCostService;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use App\Services\SalesChannels\Inventory\LocationStockReader;
use App\Services\SalesChannels\Inventory\ReservationService;
use App\Services\SalesChannels\Inventory\StockAvailability;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\FakeCurrencyRates;
use Tests\TestCase;

/** Stock figures and prices are arbitrary numbers made up for these tests. */
class StorageAndStockTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private InventoryLocationService $locations;

    private LocationStockReader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'fulfillment.currency' => 'SAR']);
        $this->locations = app(InventoryLocationService::class);
        $this->reader = app(LocationStockReader::class);
    }

    private function mapping(int $productId, ?int $stock, StockSource $source, ?string $syncedAt = null, string $provider = 'torod_point'): ProviderProductMapping
    {
        return ProviderProductMapping::create([
            'provider' => $provider, 'product_id' => $productId, 'variant_key' => '', 'external_id' => 'ext-' . $productId, 'status' => MappingStatus::Approved,
            'stock_source' => $source, 'provider_stock' => $stock, 'provider_stock_synced_at' => $syncedAt ?? now(),
        ]);
    }

    public function test_the_three_default_locations_are_created_once_and_fba_is_never_storefront_stock(): void
    {
        $this->locations->ensureDefaults();
        $this->locations->ensureDefaults();
        $account = SalesChannelAccount::create(['channel' => 'amazon', 'name' => 'Amazon KSA']);
        $fba = $this->locations->ensureFbaLocation($account, 'A17E79C6D8DWNP');
        $this->locations->ensureFbaLocation($account, 'A17E79C6D8DWNP');

        $this->assertSame(4, InventoryLocation::count());
        $this->assertFalse($fba->storefront_sellable);
        $this->assertSame([], $fba->publishable_channels);
        $this->assertSame(InventoryLocation::TRUTH_CHANNEL, $fba->source_of_truth);
        $this->assertTrue(InventoryLocation::where('code', 'torod-warehouse')->first()->mayPublishTo('trendyol'));
        $this->assertFalse($fba->mayPublishTo('trendyol'), 'Amazon\'s stock is never offered on another channel.');
    }

    public function test_provider_stock_is_read_where_the_integration_keeps_it_and_only_what_it_vouches_for_is_confirmed(): void
    {
        [$torod] = $this->locations->ensureDefaults();
        $this->mapping(1, 12, StockSource::ProviderSync);
        $this->mapping(2, 7, StockSource::Manual);
        $this->mapping(3, 9, StockSource::None);

        $synced = $this->reader->availability($torod, 1);
        $typed = $this->reader->availability($torod, 2);
        $unverified = $this->reader->availability($torod, 3);

        $this->assertSame([12, StockAvailability::CONFIRMED], [$synced->available, $synced->quality]);
        $this->assertSame([7, StockAvailability::MANUAL], [$typed->available, $typed->quality]);
        $this->assertSame(StockAvailability::UNKNOWN, $unverified->quality, 'A number the integration does not vouch for is not stock.');
        $this->assertTrue($synced->isPublishable());
        $this->assertTrue($typed->isPublishable());
        $this->assertFalse($unverified->isPublishable());
    }

    public function test_a_confirmed_figure_that_has_not_been_refreshed_is_stale_and_not_publishable(): void
    {
        [$torod] = $this->locations->ensureDefaults();
        config(['sales_channels.stock.stale_after_minutes' => 60]);
        $this->mapping(1, 12, StockSource::ProviderSync, now()->subHours(3)->toDateTimeString());

        $availability = $this->reader->availability($torod, 1);

        $this->assertSame(StockAvailability::STALE, $availability->quality);
        $this->assertFalse($availability->isPublishable());
    }

    public function test_a_product_with_no_mapping_has_unknown_stock_not_zero(): void
    {
        [$torod] = $this->locations->ensureDefaults();

        $availability = $this->reader->availability($torod, 99);

        $this->assertNull($availability->available);
        $this->assertNull($availability->sellable());
    }

    public function test_fba_stock_is_its_own_balance_and_torod_stock_does_not_count_for_it(): void
    {
        [$torod] = $this->locations->ensureDefaults();
        $account = SalesChannelAccount::create(['channel' => 'amazon', 'name' => 'Amazon KSA']);
        $fba = $this->locations->ensureFbaLocation($account);
        $this->mapping(1, 100, StockSource::ProviderSync);
        InventoryLocationBalance::create(['location_id' => $fba->id, 'product_id' => 1, 'variant_key' => '', 'sku' => 'SKU-1', 'available' => 5, 'quality' => 'confirmed', 'source_synced_at' => now()]);

        $this->assertSame(100, $this->reader->availability($torod, 1)->available);
        $this->assertSame(5, $this->reader->availability($fba, 1)->available);
        $this->assertNull($this->reader->availability($fba, 2)->available, 'A product FBA holds none of is unknown at FBA, whatever Torod holds.');
    }

    public function test_reserved_units_are_taken_off_what_may_be_offered_once_and_only_until_the_provider_counts_them(): void
    {
        [$torod] = $this->locations->ensureDefaults();
        $reservations = app(ReservationService::class);
        $this->mapping(1, 10, StockSource::ProviderSync, now()->subMinutes(30)->toDateTimeString());

        $reservations->reserve('line-1', $torod->id, 1, '', 3);
        $reservations->reserve('line-1', $torod->id, 1, '', 3);
        $this->assertSame(7, $this->reader->availability($torod, 1)->sellable(), 'The same order line reserves once.');

        $reservations->commit('line-1');
        $this->assertSame(7, $this->reader->availability($torod, 1)->sellable(), 'Handed to the provider, whose count has not moved yet.');

        ProviderProductMapping::where('product_id', 1)->update(['provider_stock' => 7, 'provider_stock_synced_at' => now()->addMinute()]);
        $this->assertSame(7, $this->reader->availability($torod, 1)->sellable(), 'The provider now counts 7: the 3 units are not taken off twice.');
    }

    public function test_a_released_reservation_gives_the_units_back(): void
    {
        [$torod] = $this->locations->ensureDefaults();
        $reservations = app(ReservationService::class);
        $this->mapping(1, 10, StockSource::ProviderSync);
        $reservations->reserve('line-1', $torod->id, 1, '', 3);

        $reservations->release('line-1', 'order cancelled');

        $this->assertSame(10, $this->reader->availability($torod, 1)->sellable());
    }

    private function torodContract(array $rules): void
    {
        $contracts = app(ContractService::class);
        $contract = $contracts->createDraft(['party_type' => 'fulfillment_provider', 'provider_code' => 'torod_point', 'party_name' => 'Warehouse', 'currency' => 'SAR', 'effective_from' => '2026-01-01'], 1);
        foreach ($rules as $rule) {
            $contracts->addRule($contract, $rule, 1);
        }
        $contracts->activate($contract, 1, 'test');
    }

    public function test_daily_storage_is_priced_per_unit_and_shared_across_products_by_their_units(): void
    {
        [$torod] = $this->locations->ensureDefaults();
        $this->mapping(1, 30, StockSource::ProviderSync);
        $this->mapping(2, 10, StockSource::ProviderSync);
        $this->torodContract([['service_type' => 'storage_daily', 'calc_method' => 'per_day', 'params' => ['amount' => '0.1', 'multiply_by' => 'units']]]);

        app(StorageCostService::class)->accrue($torod, CarbonImmutable::parse('2026-11-10'));
        app(StorageCostService::class)->accrue($torod, CarbonImmutable::parse('2026-11-10'));

        $entry = OperationalCostEntry::where('cost_component', 'storage_daily')->first();
        $this->assertSame('4.000000', $entry->amount, '40 units at 0.10 for one day.');
        $this->assertSame(1, OperationalCostEntry::where('cost_component', 'storage_daily')->count(), 'The same day accrues once.');
        $this->assertSame(['3.000000', '1.000000'], $entry->allocations()->orderBy('product_id')->pluck('amount')->all());
    }

    public function test_the_variants_of_one_product_are_one_share_of_the_storage_cost(): void
    {
        [$torod] = $this->locations->ensureDefaults();
        foreach (['S' => 5, 'M' => 15] as $variant => $stock) {
            ProviderProductMapping::create([
                'provider' => 'torod_point', 'product_id' => 1, 'variant_key' => $variant, 'external_id' => 'ext-1', 'external_variant_id' => $variant,
                'status' => MappingStatus::Approved, 'stock_source' => StockSource::ProviderSync, 'provider_stock' => $stock, 'provider_stock_synced_at' => now(),
            ]);
        }
        $this->mapping(2, 20, StockSource::ProviderSync);
        $this->torodContract([['service_type' => 'storage_daily', 'calc_method' => 'per_day', 'params' => ['amount' => '0.1', 'multiply_by' => 'units']]]);

        app(StorageCostService::class)->accrue($torod, CarbonImmutable::parse('2026-11-10'));

        $entry = OperationalCostEntry::where('cost_component', 'storage_daily')->firstOrFail();
        $this->assertSame('4.000000', $entry->amount, '40 units at 0.10 for one day.');
        $this->assertSame('allocated', $entry->allocation_status);
        $this->assertSame(['2.000000', '2.000000'], $entry->allocations()->orderBy('product_id')->pluck('amount')->all(), 'Product 1 holds 20 units over two variants, product 2 holds 20.');
    }

    public function test_a_flat_monthly_fee_is_charged_on_the_last_day_of_the_month_only(): void
    {
        [$torod] = $this->locations->ensureDefaults();
        $this->mapping(1, 30, StockSource::ProviderSync);
        $this->torodContract([['service_type' => 'storage_monthly', 'calc_method' => 'fixed', 'params' => ['amount' => '300']]]);

        app(StorageCostService::class)->accrue($torod, CarbonImmutable::parse('2026-11-15'));
        $this->assertSame(0, OperationalCostEntry::count());

        app(StorageCostService::class)->accrue($torod, CarbonImmutable::parse('2026-11-30'));
        $this->assertSame('300.000000', OperationalCostEntry::where('cost_component', 'storage_monthly')->first()->amount);
    }

    public function test_with_no_trustworthy_stock_figure_the_storage_cost_is_missing_not_free(): void
    {
        [$torod] = $this->locations->ensureDefaults();
        $this->mapping(1, 30, StockSource::None);
        $this->torodContract([['service_type' => 'storage_daily', 'calc_method' => 'per_day', 'params' => ['amount' => '0.1', 'multiply_by' => 'units']]]);

        app(StorageCostService::class)->accrue($torod, CarbonImmutable::parse('2026-11-10'));

        $entry = OperationalCostEntry::where('cost_component', 'storage_daily')->first();
        $this->assertNull($entry->amount);
        $this->assertSame('stock_unknown', $entry->missing_reason);
    }

    public function test_amazon_fba_storage_is_never_priced_from_a_contract(): void
    {
        $account = SalesChannelAccount::create(['channel' => 'amazon', 'name' => 'Amazon KSA']);
        $fba = $this->locations->ensureFbaLocation($account);

        $this->assertSame([], app(StorageCostService::class)->accrue($fba, CarbonImmutable::parse('2026-11-30')));
    }
}
