<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderCatalogItem;
use App\Services\Fulfillment\CatalogStagingService;
use App\Services\Fulfillment\FieldMapStore;
use App\Services\Fulfillment\Providers\M5azn\M5aznAdapter;
use App\Services\Fulfillment\Providers\M5azn\M5aznFieldMaps;
use App\Services\Fulfillment\Providers\M5azn\M5aznProductImporter;
use App\Services\Fulfillment\Support\RemoteImageFetcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * The REAL M5AZN adapter against faked HTTP. Response shapes used here are ASSUMED fixtures — M5AZN's
 * official documentation gives no response schema for catalog or order endpoints — and exist only to
 * exercise our handling. They must be replaced by captured real payloads when M5AZN access is available.
 */
abstract class M5aznTestCase extends FulfillmentTestCase
{
    protected const BASE = 'https://m5azn.com';

    protected function setUp(): void
    {
        parent::setUp();

        config(['fulfillment.providers.m5azn.adapter' => M5aznAdapter::class]);
        $this->enableProvider(ProviderCode::M5azn, [
            'dry_run' => false, 'dispatch_enabled' => true, 'product_sync_enabled' => true, 'inventory_sync_enabled' => true,
            'import_enabled' => true, 'default_category_id' => 5, 'platform_name' => 'Pharmacy KSA',
        ], ['email' => 'seller@example.test', 'password' => 'pw-very-secret-1']);
    }

    protected function adapter(): M5aznAdapter
    {
        return app(M5aznAdapter::class);
    }

    protected function jwt(int $exp): string
    {
        $encode = static fn (array $data): string => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');

        return $encode(['typ' => 'JWT', 'alg' => 'RS256']) . '.' . $encode(['sub' => '59653', 'exp' => $exp]) . '.signature';
    }

    protected function loginResponse(?string $token = null): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response([
            'status' => 200, 'success' => true, 'error' => false, 'message' => 'Successfully logged In',
            'data' => ['accessToken' => $token ?? $this->jwt(time() + 86400 * 300), 'userId' => 59653, 'roles' => [['userRole' => 'seller']]],
        ]);
    }

    /** Verify a map the way an administrator would, from a sample of the assumed fixture. */
    protected function verifyMap(string $capability, array $paths, array $sample, ?string $itemsPath = null): void
    {
        $store = app(FieldMapStore::class);
        $store->save(ProviderCode::M5azn, $capability, $paths, $itemsPath);
        $problems = $store->verify(ProviderCode::M5azn, $capability, $sample, M5aznFieldMaps::REQUIRED[$capability]);
        $this->assertSame([], $problems);
    }

    /** @return list<array<string, mixed>> */
    protected function catalogFixture(): array
    {
        return [
            ['id' => 1947201, 'name' => 'Vitamin C 1000mg', 'sku' => 'VC1000', 'barcode' => '6281000000011', 'description' => 'Tablets', 'quantity' => 40, 'cost' => 12.5, 'price' => 19.0],
            ['id' => 313658, 'name' => 'Hand Sanitizer 500ml', 'sku' => 'HS500', 'barcode' => '6281000000028', 'quantity' => 8, 'cost' => 4.0, 'price' => 9.0],
        ];
    }

    /** @return array<string, string> */
    protected function catalogPaths(): array
    {
        return ['id' => 'id', 'name' => 'name', 'sku' => 'sku', 'barcode' => 'barcode', 'description' => 'description', 'stock' => 'quantity', 'cost' => 'cost', 'retail_price' => 'price'];
    }

    protected function importer(): M5aznProductImporter
    {
        return app(M5aznProductImporter::class);
    }

    /** Stage records as a sync would, and verify the catalog map against them. */
    protected function stage(?array $records = null, ?array $paths = null): void
    {
        $records ??= $this->catalogFixture();
        $paths ??= $this->catalogPaths();
        $this->verifyMap('catalog', $paths, $records);

        app(CatalogStagingService::class)->stage(ProviderCode::M5azn, 'api', array_map(fn (array $row): array => [
            'external_id' => (string) $row['id'], 'name' => $row['name'] ?? null, 'sku' => $row['sku'] ?? null,
            'barcode' => $row['barcode'] ?? null, 'stock' => $row['quantity'] ?? null, 'cost' => $row['cost'] ?? null,
            'retail_price' => $row['price'] ?? null, 'payload' => $row,
        ], $records));
    }

    protected function staged(string $externalId = '1947201'): ProviderCatalogItem
    {
        return ProviderCatalogItem::where('external_id', $externalId)->firstOrFail();
    }

    /**
     * @param list<array<string, mixed>> $variants
     * @return array<string, mixed>
     */
    protected function variantPayload(array $variants): array
    {
        return ['id' => 555, 'name' => 'T-Shirt', 'description' => 'Cotton', 'variants' => $variants];
    }

    /** @return array<string, string> */
    protected function variantPaths(): array
    {
        return $this->catalogPaths() + ['variants' => 'variants', 'variant_sku' => 'sku', 'variant_name' => 'name', 'variant_stock' => 'qty', 'variant_retail_price' => 'price', 'variant_cost' => 'cost'];
    }

    /** No network and no files: the image step has its own tests. */
    protected function withoutImageDownloads(): void
    {
        $this->app->instance(RemoteImageFetcher::class, new class extends RemoteImageFetcher {
            public function fetch(string $url): ?UploadedFile
            {
                return null;
            }
        });
    }
}
