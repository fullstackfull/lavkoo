<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderCity;
use App\Models\ProviderFulfillment;
use App\Models\ProviderProductMapping;
use App\Services\Fulfillment\FieldMapStore;
use App\Services\Fulfillment\FulfillmentRouter;
use App\Services\Fulfillment\Providers\TorodPoint\TorodFieldMaps;
use App\Services\Fulfillment\Providers\TorodPoint\TorodInvoiceRoundTrip;
use App\Services\Fulfillment\Providers\TorodPoint\TorodPointAdapter;
use App\Services\Fulfillment\Support\ArabicText;
use Illuminate\Support\Facades\DB;

/**
 * The REAL Torod Point adapter against faked HTTP.
 *
 * Request shapes follow the three LogesTechs documents exactly. Response shapes the documents leave out
 * (Get Product List, Add Order, Get Order List) are ASSUMED fixtures used only to exercise our handling;
 * they are not evidence of what the provider returns.
 */
abstract class TorodTestCase extends FulfillmentTestCase
{
    protected const V5 = 'https://apisv5.logestechs.com/api';
    protected const V2 = 'https://apisv2.logestechs.com/api';

    protected function setUp(): void
    {
        parent::setUp();

        config(['fulfillment.providers.torod_point.adapter' => TorodPointAdapter::class]);
        $this->enableProvider(ProviderCode::TorodPoint, [
            'company_id' => '714', 'dry_run' => false, 'dispatch_enabled' => true, 'webhooks_enabled' => true,
            'product_sync_enabled' => true, 'cod_collection_method' => '',
        ], [
            'fulfillment_username' => 'wh-user', 'fulfillment_password' => 'wh-pass-secret-9',
            'lastmile_email' => 'lm@example.test', 'lastmile_password' => 'lm-pass-secret-9', 'webhook_secret' => 'hook-secret-abcdef',
        ]);
        $this->seedCities();
        $this->confirmInvoiceRoundTrip();
    }

    /**
     * The proof a real send waits for: the orders map reads our invoice number from `invoiceNumber`, and a master administrator recorded
     * that it comes back. Tests of the gate itself withdraw it; every other test of a real send starts with it in place.
     */
    protected function confirmInvoiceRoundTrip(): void
    {
        app(FieldMapStore::class)->save(ProviderCode::TorodPoint, TorodFieldMaps::ORDERS, ['invoice_number' => 'invoiceNumber', 'reference' => 'barcode', 'status' => 'status'], null);
        app(TorodInvoiceRoundTrip::class)->confirm(adminId: 1, adminName: 'Owner', evidence: 'The provider confirmed in writing that the invoice number comes back (test fixture).', seenPath: 'invoiceNumber');
    }

    protected function adapter(): TorodPointAdapter
    {
        return app(TorodPointAdapter::class);
    }

    protected function seedCities(): void
    {
        foreach ([['41125', 'الرياض', 'Riyadh', '1335'], ['41126', 'جدة', 'Jeddah', '1336'], ['41127', 'الدمام', 'Dammam', '1337']] as [$id, $ar, $en, $region]) {
            ProviderCity::create([
                'provider' => 'torod_point', 'external_id' => $id, 'name_ar' => $ar, 'name_en' => $en, 'region_external_id' => $region,
                'normalized_ar' => ArabicText::normalize($ar), 'normalized_en' => ArabicText::normalize($en),
            ]);
        }
    }

    /** One Torod product line (2 units, 60 SAR) plus one M5AZN line, routed. */
    protected function torodFulfillment(array $orderOverrides = [], int $orderId = 100, bool $mapped = true): ProviderFulfillment
    {
        DB::table('products')->where('id', 1)->exists() || $this->makeProduct(1, ProviderCode::TorodPoint);
        DB::table('products')->where('id', 2)->exists() || $this->makeProduct(2, ProviderCode::M5azn);
        $this->makeOrder($orderId, $orderOverrides + ['order_amount' => 100]);
        $mappingId = null;
        if ($mapped) {
            $mappingId = ProviderProductMapping::firstOrCreate(
                ['provider' => 'torod_point', 'product_id' => 1, 'variant_key' => ''],
                ['external_id' => '1421521', 'external_variant_id' => '', 'external_sku' => '13222', 'status' => MappingStatus::Approved],
            )->id;
        }
        $this->addLine($orderId, 1, 2, 60, ProviderCode::TorodPoint);
        DB::table('provider_fulfillment_items')->where('order_id', $orderId)->update([
            'mapping_id' => $mappingId, 'external_product_id' => $mapped ? '1421521' : null, 'external_sku' => $mapped ? '13222' : null,
        ]);
        $this->addLine($orderId, 2, 1, 40, ProviderCode::M5azn);
        app(FulfillmentRouter::class)->route($orderId);

        return $this->fulfillmentFor($orderId, ProviderCode::TorodPoint)->load('items');
    }

    protected function verifyProductsMap(array $paths, array $sample, ?string $itemsPath = null): void
    {
        $store = app(FieldMapStore::class);
        $store->save(ProviderCode::TorodPoint, 'products', $paths, $itemsPath);
        $this->assertSame([], $store->verify(ProviderCode::TorodPoint, 'products', $sample, TorodFieldMaps::REQUIRED['products']));
    }

    /** @return array<string, mixed> LogesTechs' documented Lastmile webhook sample, adapted to our ids */
    protected function lastmilePayload(string $status, string $barcode = 'KSA100422577363', int|string|null $time = null, array $extra = []): array
    {
        return $extra + [
            'packageId' => 368200054, 'newStatus' => $status, 'bundleId' => 0, 'barcode' => $barcode,
            'cod' => 0, 'invoiceNumber' => '100-TP1', 'time' => $time ?? (int) (microtime(true) * 1000),
            'driverName' => 'Driver N1', 'driverPhone' => '0598985000', 'attachmentUrls' => ['https://example-bucket.s3.amazonaws.com/proof.png'], 'paymentType' => 'cash',
        ];
    }
}
