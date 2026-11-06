<?php

namespace Tests\Scratch\MysqlSchema;

use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\StockSource;
use App\Models\OperationalCostAllocation;
use App\Models\OperationalCostEntry;
use App\Models\ProviderProductMapping;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\OperationalCosts\ContractService;
use App\Services\OperationalCosts\StorageCostService;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\FakeCurrencyRates;
use Tests\TestCase;

class StorageVariantTest extends TestCase
{
    use BuildsSalesChannelSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'fulfillment.currency' => 'SAR']);
    }

    public function test_two_variants_of_one_product_at_the_same_provider(): void
    {
        [$torod] = app(InventoryLocationService::class)->ensureDefaults();
        foreach (['S', 'M'] as $variant) {
            ProviderProductMapping::create([
                'provider' => 'torod_point', 'product_id' => 1, 'variant_key' => $variant, 'external_id' => 'ext-1', 'external_variant_id' => $variant,
                'status' => MappingStatus::Approved, 'stock_source' => StockSource::ProviderSync, 'provider_stock' => 5, 'provider_stock_synced_at' => now(),
            ]);
        }
        $contracts = app(ContractService::class);
        $contract = $contracts->createDraft(['party_type' => 'fulfillment_provider', 'provider_code' => 'torod_point', 'party_name' => 'Warehouse', 'currency' => 'SAR', 'effective_from' => '2026-01-01'], 1);
        $contracts->addRule($contract, ['service_type' => 'storage_daily', 'calc_method' => 'per_day', 'params' => ['amount' => '0.1', 'multiply_by' => 'units']], 1);
        $contracts->activate($contract, 1, 'test');

        $failure = null;
        try {
            app(StorageCostService::class)->accrue($torod, CarbonImmutable::parse('2026-11-10'));
        } catch (\Throwable $e) {
            $failure = $e::class . ': ' . substr($e->getMessage(), 0, 200);
        }
        fwrite(STDERR, "\nRESULT failure=" . var_export($failure, true) . ' entries=' . OperationalCostEntry::count() . ' allocations=' . OperationalCostAllocation::count() . "\n");
        $this->assertNull($failure);
    }
}
