<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\Order;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;
use App\Services\Fulfillment\Contracts\ProviderAdapter;
use App\Services\Fulfillment\Data\SubmissionResult;
use App\Services\Fulfillment\FulfillmentSchema;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\FakeM5aznAdapter;
use Tests\Support\FakeTorodAdapter;
use Tests\TestCase;

/**
 * Base for every fulfillment test: a clean in-memory database with the inferred core tables plus the
 * feature's real migrations, integrations switched on, the real network forbidden, and both providers
 * replaced by scriptable fakes unless a test opts into the real adapter.
 */
abstract class FulfillmentTestCase extends TestCase
{
    use BuildsFulfillmentSchema;

    protected FakeM5aznAdapter $m5azn;

    protected FakeTorodAdapter $torod;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        FulfillmentSchema::flush();
        $this->buildCoreSchema();
        $this->runFulfillmentMigrations();

        // No test may ever reach a real provider.
        Http::preventStrayRequests();

        config([
            'fulfillment.enabled' => true,
            // Tests exercise real sends against faked HTTP; ExternalWriteGuardTest proves what happens when this is off.
            'fulfillment.external_writes_enabled' => true,
            'fulfillment.currency' => 'SAR',
            'fulfillment.cod.allow_mixed' => false,
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
        ]);
        \Illuminate\Support\Facades\Queue::fake();

        $this->useFakeAdapters();
        $this->singleCurrencySar();
    }

    protected function useFakeAdapters(): void
    {
        config([
            'fulfillment.providers.m5azn.adapter' => FakeM5aznAdapter::class,
            'fulfillment.providers.torod_point.adapter' => FakeTorodAdapter::class,
        ]);
        $this->app->singleton(FakeM5aznAdapter::class);
        $this->app->singleton(FakeTorodAdapter::class);
        $this->m5azn = $this->app->make(FakeM5aznAdapter::class);
        $this->torod = $this->app->make(FakeTorodAdapter::class);
    }

    /** Single-currency store whose default currency is SAR: stored amounts ARE riyals. */
    protected function singleCurrencySar(): void
    {
        DB::table('currencies')->insert(['id' => 1, 'name' => 'Saudi Riyal', 'code' => 'SAR', 'symbol' => 'SAR', 'exchange_rate' => 1, 'status' => 1]);
        DB::table('business_settings')->insert([
            ['type' => 'currency_model', 'value' => json_encode('single_currency')],
            ['type' => 'system_default_currency', 'value' => json_encode(1)],
            ['type' => 'decimal_point_settings', 'value' => json_encode(2)],
        ]);
        Cache::flush();
    }

    /** Multi-currency store: stored amounts are USD-based, SAR rate 3.75. */
    protected function multiCurrencyUsdBase(): void
    {
        DB::table('currencies')->truncate();
        DB::table('business_settings')->truncate();
        DB::table('currencies')->insert([
            ['id' => 1, 'name' => 'US Dollar', 'code' => 'USD', 'symbol' => '$', 'exchange_rate' => 1, 'status' => 1],
            ['id' => 2, 'name' => 'Saudi Riyal', 'code' => 'SAR', 'symbol' => 'SAR', 'exchange_rate' => 3.75, 'status' => 1],
        ]);
        DB::table('business_settings')->insert([
            ['type' => 'currency_model', 'value' => json_encode('multi_currency')],
            ['type' => 'system_default_currency', 'value' => json_encode(2)],
        ]);
        Cache::flush();
    }

    /** Enable a provider with the given non-secret settings. */
    protected function enableProvider(ProviderCode $provider, array $settings = [], array $secrets = []): void
    {
        app(ProviderConnectionManager::class)->save(
            provider: $provider,
            enabled: true,
            baseUrl: null,
            settings: $settings + ['dispatch_enabled' => true, 'auto_dispatch' => true, 'dry_run' => false],
            secrets: $secrets,
        );
    }

    /** Sends the way the dispatcher does: the mode is whatever the provider's Dry run switch says at the moment the order is claimed. */
    protected function submitAsClaimed(ProviderAdapter $adapter, ProviderFulfillment $fulfillment): SubmissionResult
    {
        $dryRun = app(ProviderConnectionManager::class)->config($adapter->code())->bool('dry_run', true);

        return $adapter->submit($fulfillment, dryRun: $dryRun);
    }

    protected function makeProduct(int $id, ?ProviderCode $provider = null, array $overrides = []): int
    {
        DB::table('products')->insert($overrides + [
            'id' => $id,
            'name' => 'Product ' . $id,
            'code' => 'SKU' . $id,
            'barcode' => 'BC' . $id,
            'added_by' => 'admin',
            'product_type' => 'physical',
            'unit_price' => 10,
            'current_stock' => 50,
            'variation' => '[]',
            'status' => 1,
            'fulfillment_provider' => $provider?->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    protected function makeOrder(int $id, array $overrides = []): Order
    {
        DB::table('orders')->insert($overrides + [
            'id' => $id,
            'customer_id' => 1,
            'order_status' => 'confirmed',
            'payment_status' => 'paid',
            'payment_method' => 'ssl_commerz',
            'order_amount' => 100,
            'paid_amount' => 0,
            'order_type' => 'default_type',
            'shipping_address_data' => json_encode([
                'contact_person_name' => 'Sara Alharbi', 'phone' => '0551234567', 'address' => 'King Fahd Rd 12',
                'city' => 'الرياض', 'country' => 'SA', 'email' => 'sara@example.test',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Order::findOrFail($id);
    }

    /** One order line, plus (for a provider product) its immutable ownership snapshot. */
    protected function addLine(int $orderId, int $productId, int $qty = 1, float $lineTotal = 50, ?ProviderCode $provider = null, string $variant = ''): int
    {
        $detailId = DB::table('order_details')->insertGetId([
            'order_id' => $orderId, 'product_id' => $productId, 'qty' => $qty, 'price' => $lineTotal / max(1, $qty),
            'product_details' => json_encode(['id' => $productId]), 'variant' => $variant ?: null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($provider !== null) {
            ProviderFulfillmentItem::create([
                'order_id' => $orderId, 'order_detail_id' => $detailId, 'product_id' => $productId, 'variant_key' => $variant,
                'provider' => $provider->value, 'external_product_id' => 'EXT' . $productId, 'external_sku' => 'XSKU' . $productId,
                'sku' => 'SKU' . $productId, 'name' => 'Product ' . $productId, 'quantity' => $qty,
                'unit_price' => $lineTotal / max(1, $qty), 'line_total' => $lineTotal, 'status' => ProviderFulfillmentItem::STATUS_UNROUTED,
            ]);
        }

        return $detailId;
    }

    protected function fulfillmentFor(int $orderId, ProviderCode $provider): ?ProviderFulfillment
    {
        return ProviderFulfillment::where('order_id', $orderId)->where('provider', $provider->value)->orderByDesc('generation')->first();
    }

    protected function currencyRates(): CurrencyRateSource
    {
        return $this->app->make(CurrencyRateSource::class);
    }
}
