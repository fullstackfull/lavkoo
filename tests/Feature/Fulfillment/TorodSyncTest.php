<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderCatalogItem;
use App\Models\ProviderProductMapping;
use App\Models\ProviderSyncState;
use App\Services\Fulfillment\FulfillmentRouter;
use App\Services\Fulfillment\ProviderMappingService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class TorodSyncTest extends TorodTestCase
{
    /** @return list<array<string, mixed>> assumed shape: the document gives no Get Product List response */
    private function warehouseProducts(): array
    {
        return [
            ['id' => 1421521, 'externalId' => '1', 'name' => 'milk 11', 'sku' => '13222', 'barcode' => '13222', 'quantity' => 40, 'price' => 6],
            ['id' => 1421522, 'externalId' => null, 'name' => 'Vitamin C', 'sku' => 'VC1000', 'barcode' => '6281000000011', 'quantity' => 12, 'price' => 19],
        ];
    }

    private function fakeProductPages(array ...$pages): void
    {
        $call = 0;
        Http::fake([self::V5 . '/public/fulfillment/product*' => function () use (&$call, $pages) {
            return Http::response($pages[min($call++, count($pages) - 1)]);
        }]);
    }

    public function test_the_warehouse_product_list_is_staged_for_matching_without_creating_any_product(): void
    {
        $this->fakeProductPages($this->warehouseProducts());

        $result = $this->adapter()->syncCatalog();

        $this->assertSame(['created' => 2, 'updated' => 0, 'unchanged' => 0, 'unidentified' => 0], $result['stats']);
        $this->assertFalse($result['verified'], 'the response schema is undocumented, so the map starts unverified');
        $this->assertSame(0, DB::table('products')->count());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/public/fulfillment/product')
            && str_contains($request->url(), 'page=1') && str_contains($request->url(), 'pageSize=100')
            && $request->header('company-id')[0] === '714');
    }

    public function test_the_list_is_paged_until_a_short_page(): void
    {
        $full = array_map(fn (int $i): array => ['id' => $i, 'name' => 'P' . $i, 'sku' => 'S' . $i], range(1, 100));
        $this->fakeProductPages($full, [['id' => 101, 'name' => 'P101', 'sku' => 'S101']]);

        $stats = $this->adapter()->syncCatalog()['stats'];

        $this->assertSame(101, $stats['created']);
        Http::assertSentCount(2);
    }

    public function test_a_server_that_ignores_paging_cannot_loop_the_sync_forever(): void
    {
        $full = array_map(fn (int $i): array => ['id' => $i, 'name' => 'P' . $i], range(1, 100));
        $this->fakeProductPages($full);

        $stats = $this->adapter()->syncCatalog()['stats'];

        $this->assertSame(100, $stats['created']);
        Http::assertSentCount(2);
    }

    public function test_syncing_twice_never_duplicates_a_warehouse_product(): void
    {
        $this->fakeProductPages($this->warehouseProducts());

        $this->adapter()->syncCatalog();
        $second = $this->adapter()->syncCatalog()['stats'];

        $this->assertSame(0, $second['created']);
        $this->assertSame(2, $second['unchanged']);
        $this->assertSame(2, ProviderCatalogItem::count());
    }

    public function test_a_failing_list_is_recorded_without_leaking_credentials(): void
    {
        Http::fake([self::V5 . '/public/fulfillment/product*' => Http::response(['message' => 'Invalid password wh-pass-secret-9'], 401)]);

        $result = $this->adapter()->syncCatalog();

        $this->assertFalse($result['ok']);
        $state = ProviderSyncState::where(['provider' => 'torod_point', 'capability' => 'products'])->first();
        $this->assertNotNull($state->last_failure_at);
        $this->assertStringContainsString('Invalid password', (string) $state->last_error);
    }

    public function test_the_sample_shows_the_raw_response_so_an_administrator_can_see_whether_stock_is_in_it(): void
    {
        $this->fakeProductPages($this->warehouseProducts());

        $sample = $this->adapter()->sample('products');

        $this->assertTrue($sample['ok']);
        $this->assertSame('quantity', $sample['proposal']['stock'], 'proposed, not trusted');
        $this->assertSame('id', $sample['proposal']['id']);
        $this->assertCount(2, $sample['items']);
    }

    // ---- stock: refused until verified AND confirmed ---------------------------------------------

    public function test_warehouse_stock_sync_is_refused_while_the_schema_is_unverified_and_says_why(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['inventory_sync_enabled' => true, 'company_id' => '714', 'dry_run' => false]);
        Http::fake();

        $result = $this->adapter()->syncInventory();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no inventory endpoint is documented', $result['error']);
        Http::assertNothingSent();
    }

    public function test_a_verified_map_alone_is_not_enough_the_stock_field_must_be_confirmed_by_a_person(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['inventory_sync_enabled' => true, 'company_id' => '714', 'dry_run' => false]);
        $this->verifyProductsMap(['id' => 'id', 'name' => 'name', 'sku' => 'sku', 'stock' => 'quantity'], $this->warehouseProducts());
        Http::fake();

        $result = $this->adapter()->syncInventory();

        $this->assertFalse($result['ok']);
        Http::assertNothingSent();
        $this->assertSame('unverified', $this->adapter()->readiness()->capability('inventory_sync')->state);
    }

    public function test_confirmed_warehouse_stock_overwrites_store_stock_minus_unsent_orders(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['inventory_sync_enabled' => true, 'stock_field_confirmed_at' => now()->toDateTimeString(), 'company_id' => '714', 'dry_run' => false]);
        $this->verifyProductsMap(['id' => 'id', 'name' => 'name', 'sku' => 'sku', 'stock' => 'quantity'], $this->warehouseProducts());
        $this->makeProduct(1, ProviderCode::TorodPoint, ['current_stock' => 0]);
        ProviderProductMapping::create(['provider' => 'torod_point', 'product_id' => 1, 'variant_key' => '', 'external_id' => '1421521', 'external_variant_id' => '', 'external_sku' => '13222', 'status' => MappingStatus::Approved]);
        $this->makeOrder(100);
        $this->addLine(100, 1, 3, 30, ProviderCode::TorodPoint);
        app(FulfillmentRouter::class)->route(100);
        $this->fakeProductPages($this->warehouseProducts());

        $result = $this->adapter()->syncInventory();

        $this->assertTrue($result['ok']);
        $this->assertSame(37, (int) DB::table('products')->where('id', 1)->value('current_stock'), '40 in the warehouse minus 3 sold and not yet handed over');
        $mapping = ProviderProductMapping::first();
        $this->assertSame(40, $mapping->provider_stock);
        $this->assertSame('provider_sync', $mapping->stock_source->value);
    }

    public function test_manually_entered_stock_is_labelled_manual_and_never_mistaken_for_synced(): void
    {
        $this->makeProduct(1, ProviderCode::TorodPoint);
        $mapping = ProviderProductMapping::create(['provider' => 'torod_point', 'product_id' => 1, 'variant_key' => '', 'external_id' => '1', 'external_variant_id' => '', 'status' => MappingStatus::Approved]);

        app(ProviderMappingService::class)->setManualStock($mapping->id, 25);

        $fresh = $mapping->fresh();
        $this->assertSame(25, $fresh->provider_stock);
        $this->assertSame('manual', $fresh->stock_source->value);
    }

    // ---- matching ------------------------------------------------------------------------------

    public function test_matching_suggests_candidates_but_never_links_anything_by_itself(): void
    {
        $this->makeProduct(1, null, ['code' => '13222', 'barcode' => '13222']);
        $this->makeProduct(2, null, ['code' => 'OTHER', 'barcode' => '6281000000011']);
        $this->makeProduct(3, null, ['code' => 'NOPE', 'barcode' => 'NOPE']);
        $this->fakeProductPages($this->warehouseProducts());
        $this->adapter()->syncCatalog();
        $service = app(ProviderMappingService::class);

        $milk = $service->suggestionsFor(ProviderCatalogItem::where('external_id', '1421521')->first(), echoedExternalId: '1');
        $vitamin = $service->suggestionsFor(ProviderCatalogItem::where('external_id', '1421522')->first());

        $this->assertSame([1], array_column($milk, 'product_id'));
        $this->assertEqualsCanonicalizing(['barcode_match', 'sku_match', 'external_id_echo'], $milk[0]['reasons']);
        $this->assertSame([2], array_column($vitamin, 'product_id'));
        $this->assertSame(['barcode_match'], $vitamin[0]['reasons']);
        $this->assertSame(0, ProviderProductMapping::count(), 'a suggestion is not a mapping');
        $this->assertNull(DB::table('products')->where('id', 1)->value('fulfillment_provider'), 'and never changes ownership');
    }

    public function test_a_suggestion_is_not_offered_for_a_product_another_provider_owns(): void
    {
        $this->makeProduct(1, ProviderCode::M5azn, ['code' => '13222']);
        $this->fakeProductPages($this->warehouseProducts());
        $this->adapter()->syncCatalog();

        $suggestions = app(ProviderMappingService::class)->suggestionsFor(ProviderCatalogItem::where('external_id', '1421521')->first());

        $this->assertSame([], $suggestions);
    }

    public function test_approving_a_mapping_is_explicit_and_does_not_assign_the_product(): void
    {
        $this->makeProduct(1, null, ['code' => '13222']);

        $mapping = app(ProviderMappingService::class)->approve(ProviderCode::TorodPoint, 1, '', '1421521', externalSku: '13222', approvedBy: 7);

        $this->assertSame(MappingStatus::Approved, $mapping->status);
        $this->assertSame(7, $mapping->approved_by);
        $this->assertNull(DB::table('products')->where('id', 1)->value('fulfillment_provider'), 'ownership is a separate, explicit step');
    }

    public function test_a_warehouse_item_cannot_be_mapped_to_two_store_products(): void
    {
        $this->makeProduct(1, null);
        $this->makeProduct(2, null);
        $service = app(ProviderMappingService::class);
        $service->approve(ProviderCode::TorodPoint, 1, '', '1421521');

        $this->expectException(\InvalidArgumentException::class);
        $service->approve(ProviderCode::TorodPoint, 2, '', '1421521');
    }

    public function test_a_product_owned_by_m5azn_cannot_be_mapped_to_torod_point(): void
    {
        $this->makeProduct(1, ProviderCode::M5azn);

        $this->expectException(\InvalidArgumentException::class);
        app(ProviderMappingService::class)->approve(ProviderCode::TorodPoint, 1, '', '1421521');
    }

    public function test_the_same_barcode_can_be_mapped_under_both_providers_independently(): void
    {
        $this->makeProduct(1, null);
        $this->makeProduct(2, null);
        $service = app(ProviderMappingService::class);

        $service->approve(ProviderCode::TorodPoint, 1, '', 'TP-1', externalBarcode: '6281000000011');
        $service->approve(ProviderCode::M5azn, 2, '', 'MZ-1', externalBarcode: '6281000000011');

        $this->assertSame(2, ProviderProductMapping::where('external_barcode', '6281000000011')->count());
    }

    // ---- pushing products to the warehouse ----------------------------------------------------------

    public function test_pushing_products_is_off_by_default(): void
    {
        $this->makeProduct(1, null);
        Http::fake();

        $result = $this->adapter()->pushProducts([1]);

        $this->assertFalse($result['ok']);
        Http::assertNothingSent();
    }

    public function test_a_dry_run_push_previews_the_payload_and_never_includes_quantity(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['push_products_enabled' => true, 'dry_run' => true, 'company_id' => '714']);
        $this->makeProduct(1, null, ['name' => 'Vitamin C', 'code' => 'VC1000', 'barcode' => '6281000000011', 'unit_price' => 19]);
        Http::fake();

        $result = $this->adapter()->pushProducts([1]);

        $this->assertTrue($result['dry_run']);
        $this->assertSame([['externalId' => 1, 'name' => 'Vitamin C', 'sku' => 'VC1000', 'barcode' => '6281000000011', 'price' => '19', 'description' => '.']], $result['preview']);
        $this->assertArrayNotHasKey('quantity', $result['preview'][0]);
        Http::assertNothingSent();
    }

    public function test_a_live_push_sends_the_documented_body_merges_operator_defaults_and_maps_what_came_back(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, [
            'push_products_enabled' => true, 'dry_run' => false, 'company_id' => '714',
            'product_defaults' => ['categoryId' => 309, 'storageType' => 'BIN', 'pickingMethod' => 'FIFO', 'isExpiryBlock' => true],
        ]);
        $this->makeProduct(1, null, ['name' => 'Vitamin C', 'code' => 'VC1000', 'barcode' => '6281000000011', 'unit_price' => 19]);
        $this->makeProduct(2, null, ['name' => 'Bad', 'code' => 'BAD']);
        Http::fake([self::V5 . '/public/fulfillment/product/bulk' => Http::response([
            ['feedBack' => null, 'externalId' => '1', 'id' => 1421521, 'status' => 'synced'],
            ['feedBack' => 'SKU already exists', 'externalId' => '2', 'id' => null, 'status' => 'failed'],
        ])]);

        $result = $this->adapter()->pushProducts([1, 2], adminId: 9);

        $this->assertSame(1, $result['pushed']);
        $this->assertSame('SKU already exists', $result['skipped'][2]);
        Http::assertSent(function (Request $request): bool {
            $item = $request->data()['list'][0] ?? [];

            return $request->url() === self::V5 . '/public/fulfillment/product/bulk'
                && $item['externalId'] === 1 && $item['sku'] === 'VC1000' && $item['categoryId'] === 309 && $item['isExpiryBlock'] === true
                && !array_key_exists('quantity', $item) && $request->header('username')[0] === 'wh-user';
        });
        $mapping = ProviderProductMapping::where('product_id', 1)->first();
        $this->assertSame('1421521', $mapping->external_id);
        $this->assertSame(MappingStatus::Approved, $mapping->status);
        $this->assertSame(9, $mapping->approved_by);
        $this->assertNull(ProviderProductMapping::where('product_id', 2)->first(), 'a failed row is not mapped');
    }

    public function test_variant_products_are_not_pushed_because_variants_are_not_modelled_in_the_bulk_call(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['push_products_enabled' => true, 'dry_run' => false, 'company_id' => '714']);
        $this->makeProduct(1, null, ['variation' => json_encode([['type' => 'S', 'price' => 1, 'sku' => 'S', 'qty' => 1]])]);
        Http::fake();

        $result = $this->adapter()->pushProducts([1]);

        $this->assertSame('variants_not_supported', $result['skipped'][1]);
        Http::assertNothingSent();
    }

    public function test_only_admin_owned_physical_products_can_be_pushed(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['push_products_enabled' => true, 'dry_run' => true, 'company_id' => '714']);
        $this->makeProduct(1, null, ['added_by' => 'seller']);
        $this->makeProduct(2, null, ['product_type' => 'digital']);

        $result = $this->adapter()->pushProducts([1, 2]);

        $this->assertSame([], $result['preview']);
    }
}
