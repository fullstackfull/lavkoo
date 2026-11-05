<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProductProviderChange;
use App\Models\ProviderCatalogItem;
use App\Models\ProviderPriceChange;
use App\Models\ProviderProductMapping;
use App\Services\Fulfillment\CatalogStagingService;
use App\Services\Fulfillment\Data\ImportResult;
use App\Services\Fulfillment\Providers\M5azn\M5aznProductImporter;
use App\Services\Fulfillment\Support\RemoteImageFetcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class M5aznImporterTest extends M5aznTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No network and no files: the image step has its own tests.
        $this->app->instance(RemoteImageFetcher::class, new class extends RemoteImageFetcher {
            public function fetch(string $url): ?\Illuminate\Http\UploadedFile
            {
                return null;
            }
        });
    }

    private function importer(): M5aznProductImporter
    {
        return app(M5aznProductImporter::class);
    }

    /** Stage records as a sync would, and verify the catalog map against them. */
    private function stage(?array $records = null, ?array $paths = null): void
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

    private function staged(string $externalId = '1947201'): ProviderCatalogItem
    {
        return ProviderCatalogItem::where('external_id', $externalId)->firstOrFail();
    }

    public function test_nothing_is_imported_until_the_field_map_is_verified(): void
    {
        app(CatalogStagingService::class)->stage(ProviderCode::M5azn, 'api', [['external_id' => '1', 'name' => 'X', 'payload' => ['id' => 1]]]);

        $result = $this->importer()->import($this->staged('1')->id);

        $this->assertSame(ImportResult::BLOCKED, $result->outcome);
        $this->assertSame('field_map_unverified', $result->reason);
        $this->assertSame(0, DB::table('products')->count());
    }

    public function test_import_is_off_unless_the_operator_enabled_it_and_chose_a_category(): void
    {
        $this->stage();
        $this->enableProvider(ProviderCode::M5azn, ['import_enabled' => false, 'default_category_id' => 5]);

        $this->assertSame('import_not_enabled', $this->importer()->import($this->staged()->id)->reason);

        $this->enableProvider(ProviderCode::M5azn, ['import_enabled' => true, 'default_category_id' => null]);
        $this->assertSame('import_not_enabled', $this->importer()->import($this->staged()->id)->reason);
    }

    public function test_a_staged_product_becomes_an_unpublished_store_product_owned_by_m5azn(): void
    {
        $this->stage();

        $result = $this->importer()->import($this->staged()->id, actorId: 1);

        $this->assertSame(ImportResult::IMPORTED, $result->outcome);
        $product = DB::table('products')->find($result->productId);
        $this->assertSame('Vitamin C 1000mg', $product->name);
        $this->assertSame('VC1000', $product->code);
        $this->assertSame('6281000000011', $product->barcode);
        $this->assertSame('admin', $product->added_by);
        $this->assertSame('physical', $product->product_type);
        $this->assertSame(40, (int) $product->current_stock);
        $this->assertSame(5, (int) $product->category_id);
        $this->assertSame(0, (int) $product->status, 'imported products start unpublished for a human review');
        $this->assertSame('m5azn', $product->fulfillment_provider, 'every imported M5AZN product is assigned to M5AZN');
        $this->assertSame('Tablets', $product->details);
    }

    public function test_the_provider_link_is_an_approved_mapping_keyed_on_the_providers_own_id(): void
    {
        $this->stage();

        $productId = $this->importer()->import($this->staged()->id)->productId;

        $mapping = ProviderProductMapping::where('product_id', $productId)->first();
        $this->assertSame('m5azn', $mapping->provider);
        $this->assertSame('1947201', $mapping->external_id);
        $this->assertSame('VC1000', $mapping->external_sku);
        $this->assertSame(MappingStatus::Approved, $mapping->status);
        $this->assertSame('provider_sync', $mapping->stock_source->value);
        $this->assertNotSame((string) $productId, $mapping->external_id, 'the provider id is kept separate from our product id');
    }

    public function test_the_supplier_cost_lives_on_the_mapping_not_in_purchase_price(): void
    {
        $this->stage();

        $productId = $this->importer()->import($this->staged()->id)->productId;

        $this->assertEqualsWithDelta(12.5, (float) ProviderProductMapping::where('product_id', $productId)->value('supplier_cost'), 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) DB::table('products')->where('id', $productId)->value('purchase_price'), 0.0001, 'the platform resets purchase_price to 0 on every edit, so cost cannot live there');
    }

    public function test_the_selling_price_is_the_providers_retail_price_with_no_markup_added(): void
    {
        $this->stage();

        $productId = $this->importer()->import($this->staged()->id)->productId;

        $this->assertEqualsWithDelta(19.0, (float) DB::table('products')->where('id', $productId)->value('unit_price'), 0.0001);
    }

    public function test_prices_are_converted_into_the_platforms_stored_units(): void
    {
        $this->multiCurrencyUsdBase();
        $this->stage();

        $productId = $this->importer()->import($this->staged()->id)->productId;

        $this->assertEqualsWithDelta(19.0 / 3.75, (float) DB::table('products')->where('id', $productId)->value('unit_price'), 0.0001);
    }

    public function test_every_price_the_import_sets_is_recorded_in_the_history(): void
    {
        $this->stage();

        $productId = $this->importer()->import($this->staged()->id)->productId;

        $fields = ProviderPriceChange::where('product_id', $productId)->pluck('new_value', 'field')->map(fn ($v) => (float) $v)->all();
        $this->assertEqualsWithDelta(12.5, $fields['supplier_cost'], 0.001);
        $this->assertEqualsWithDelta(19.0, $fields['supplier_retail_price'], 0.001);
        $this->assertEqualsWithDelta(19.0, $fields['selling_price'], 0.001);
    }

    public function test_the_provider_assignment_is_audited_as_a_system_action(): void
    {
        $this->stage();

        $productId = $this->importer()->import($this->staged()->id, actorId: 1)->productId;

        $change = ProductProviderChange::where('product_id', $productId)->first();
        $this->assertNull($change->from_provider);
        $this->assertSame('m5azn', $change->to_provider);
        $this->assertSame('system', $change->actor_type);
        $this->assertSame('m5azn_import', $change->reason);
    }

    public function test_the_staged_item_is_marked_imported_and_linked_to_its_product(): void
    {
        $this->stage();

        $productId = $this->importer()->import($this->staged()->id)->productId;

        $item = $this->staged();
        $this->assertSame(ProviderCatalogItem::STATUS_IMPORTED, $item->status);
        $this->assertSame($productId, $item->product_id);
        $this->assertNotNull($item->imported_at);
    }

    public function test_importing_the_same_item_again_updates_it_and_never_creates_a_duplicate(): void
    {
        $this->stage();
        $first = $this->importer()->import($this->staged()->id);
        $second = $this->importer()->import($this->staged()->id);
        $third = $this->importer()->import($this->staged()->id);

        $this->assertSame(ImportResult::IMPORTED, $first->outcome);
        $this->assertSame(ImportResult::UPDATED, $second->outcome);
        $this->assertSame(ImportResult::UPDATED, $third->outcome);
        $this->assertSame($first->productId, $second->productId);
        $this->assertSame(1, DB::table('products')->count());
        $this->assertSame(1, ProviderProductMapping::count());
    }

    public function test_a_store_product_with_the_same_sku_is_not_adopted_it_is_reported_for_a_human(): void
    {
        $this->makeProduct(900, null, ['code' => 'VC1000']);
        $this->stage();

        $result = $this->importer()->import($this->staged()->id);

        $this->assertSame(ImportResult::NEEDS_REVIEW, $result->outcome);
        $this->assertSame('sku_or_barcode_already_used_by_another_product', $result->reason);
        $this->assertNull(DB::table('products')->where('id', 900)->value('fulfillment_provider'), 'a matching SKU must never change ownership');
        $this->assertSame(1, DB::table('products')->count());
        $this->assertSame(ProviderCatalogItem::STATUS_NEEDS_REVIEW, $this->staged()->status);
    }

    public function test_a_barcode_match_is_not_adopted_either(): void
    {
        $this->makeProduct(900, null, ['code' => 'OTHER', 'barcode' => '6281000000011']);
        $this->stage();

        $this->assertSame(ImportResult::NEEDS_REVIEW, $this->importer()->import($this->staged()->id)->outcome);
        $this->assertNull(DB::table('products')->where('id', 900)->value('fulfillment_provider'));
    }

    public function test_an_operator_can_force_the_import_as_a_separate_new_product(): void
    {
        $this->makeProduct(900, null, ['code' => 'VC1000']);
        $this->stage();

        $result = $this->importer()->import($this->staged()->id, force: true);

        $this->assertSame(ImportResult::IMPORTED, $result->outcome);
        $this->assertNotSame(900, $result->productId);
        $this->assertNull(DB::table('products')->where('id', 900)->value('fulfillment_provider'));
    }

    public function test_under_the_default_policy_the_store_price_never_follows_the_provider_after_import(): void
    {
        $this->stage();
        $productId = $this->importer()->import($this->staged()->id)->productId;
        DB::table('products')->where('id', $productId)->update(['unit_price' => 25]);

        $changed = $this->catalogFixture();
        $changed[0]['price'] = 30.0;
        $changed[0]['cost'] = 14.0;
        $this->stage($changed);
        $this->importer()->import($this->staged()->id);

        $this->assertEqualsWithDelta(25.0, (float) DB::table('products')->where('id', $productId)->value('unit_price'), 0.0001, 'the store owns its selling price');
        $mapping = ProviderProductMapping::where('product_id', $productId)->first();
        $this->assertEqualsWithDelta(14.0, (float) $mapping->supplier_cost, 0.0001);
        $this->assertEqualsWithDelta(30.0, (float) $mapping->supplier_retail_price, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) DB::table('products')->where('id', $productId)->value('purchase_price'), 0.0001);
    }

    public function test_supplier_cost_changes_are_kept_as_history(): void
    {
        $this->stage();
        $productId = $this->importer()->import($this->staged()->id)->productId;
        $changed = $this->catalogFixture();
        $changed[0]['cost'] = 15.25;
        $this->stage($changed);

        $this->importer()->import($this->staged()->id);

        $cost = ProviderPriceChange::where('product_id', $productId)->where('field', 'supplier_cost')->orderBy('id')->get();
        $this->assertCount(2, $cost);
        $this->assertEqualsWithDelta(12.5, (float) $cost[1]->old_value, 0.0001);
        $this->assertEqualsWithDelta(15.25, (float) $cost[1]->new_value, 0.0001);
        $this->assertSame('sync', $cost[1]->source);
    }

    public function test_under_the_supplier_retail_policy_the_store_price_follows_with_nothing_added_on_top(): void
    {
        $this->enableProvider(ProviderCode::M5azn, ['import_enabled' => true, 'default_category_id' => 5, 'price_policy' => 'supplier_retail', 'dry_run' => false]);
        $this->stage();
        $productId = $this->importer()->import($this->staged()->id)->productId;
        $changed = $this->catalogFixture();
        $changed[0]['price'] = 21.5;
        $this->stage($changed);

        $this->importer()->import($this->staged()->id);

        $this->assertEqualsWithDelta(21.5, (float) DB::table('products')->where('id', $productId)->value('unit_price'), 0.0001);
        $sellingChanges = ProviderPriceChange::where('product_id', $productId)->where('field', 'selling_price')->count();
        $this->assertSame(2, $sellingChanges, 'the initial price and the follow-up are both on record');
    }

    public function test_stock_is_refreshed_on_every_sync_without_creating_anything(): void
    {
        $this->stage();
        $productId = $this->importer()->import($this->staged()->id)->productId;
        $changed = $this->catalogFixture();
        $changed[0]['quantity'] = 6;
        $this->stage($changed);

        $this->importer()->import($this->staged()->id);

        $this->assertSame(6, (int) DB::table('products')->where('id', $productId)->value('current_stock'));
        $this->assertSame(1, DB::table('products')->where('code', 'VC1000')->count());
    }

    public function test_an_operator_can_opt_in_to_publishing_but_never_without_a_price(): void
    {
        $this->enableProvider(ProviderCode::M5azn, ['import_enabled' => true, 'default_category_id' => 5, 'publish_on_import' => true, 'dry_run' => false]);
        $records = $this->catalogFixture();
        unset($records[1]['price']);
        $this->stage($records);

        $priced = $this->importer()->import($this->staged('1947201')->id)->productId;
        $unpriced = $this->importer()->import($this->staged('313658')->id)->productId;

        $this->assertSame(1, (int) DB::table('products')->where('id', $priced)->value('status'));
        $this->assertSame(0, (int) DB::table('products')->where('id', $unpriced)->value('status'), 'no price, no publication');
    }

    public function test_an_item_without_a_name_is_not_invented(): void
    {
        $this->stage();
        app(CatalogStagingService::class)->stage(ProviderCode::M5azn, 'api', [['external_id' => '77', 'sku' => 'NONAME', 'payload' => ['id' => 77, 'sku' => 'NONAME']]]);

        $result = $this->importer()->import($this->staged('77')->id);

        $this->assertSame(ImportResult::NEEDS_REVIEW, $result->outcome);
        $this->assertSame('name_missing', $result->reason);
        $this->assertSame(0, DB::table('products')->count());
    }

    private function variantPayload(array $variants): array
    {
        return ['id' => 555, 'name' => 'T-Shirt', 'description' => 'Cotton', 'variants' => $variants];
    }

    private function variantPaths(): array
    {
        return $this->catalogPaths() + ['variants' => 'variants', 'variant_sku' => 'sku', 'variant_name' => 'name', 'variant_stock' => 'qty', 'variant_retail_price' => 'price', 'variant_cost' => 'cost'];
    }

    public function test_a_product_with_variants_is_imported_with_a_mapping_per_variant(): void
    {
        $payload = $this->variantPayload([
            ['sku' => 'TS-S', 'name' => 'Small', 'qty' => 4, 'price' => 30, 'cost' => 18],
            ['sku' => 'TS-L', 'name' => 'Large XL', 'qty' => 6, 'price' => 32, 'cost' => 19],
        ]);
        $this->stage([$payload], $this->variantPaths());

        $result = $this->importer()->import($this->staged('555')->id);

        $this->assertSame(ImportResult::IMPORTED, $result->outcome);
        $product = DB::table('products')->find($result->productId);
        $variation = json_decode($product->variation, true);
        $this->assertSame(['Small', 'LargeXL'], array_column($variation, 'type'));
        $this->assertSame(['TS-S', 'TS-L'], array_column($variation, 'sku'));
        $this->assertSame([4, 6], array_column($variation, 'qty'));
        $this->assertSame(10, (int) $product->current_stock, 'the product total is the sum of its variants');
        $this->assertEqualsWithDelta(30.0, (float) $product->unit_price, 0.0001, 'the lowest variant price is the display price');
        $choice = json_decode($product->choice_options, true);
        $this->assertSame(['Small', 'Large XL'], $choice[0]['options']);
        $this->assertStringStartsWith('choice_', $choice[0]['name']);

        $mappings = ProviderProductMapping::where('product_id', $result->productId)->orderBy('id')->get();
        $this->assertSame(['Small', 'LargeXL'], $mappings->pluck('variant_key')->all());
        $this->assertSame(['TS-S', 'TS-L'], $mappings->pluck('external_sku')->all());
        $this->assertSame(['555', '555'], $mappings->pluck('external_id')->all());
    }

    public function test_a_variant_without_a_sku_is_sent_for_review_because_orders_need_the_exact_sku(): void
    {
        $payload = $this->variantPayload([['sku' => 'TS-S', 'name' => 'Small', 'qty' => 4, 'price' => 30], ['name' => 'Large', 'qty' => 6, 'price' => 32]]);
        $this->stage([$payload], $this->variantPaths());

        $result = $this->importer()->import($this->staged('555')->id);

        $this->assertSame(ImportResult::NEEDS_REVIEW, $result->outcome);
        $this->assertSame('variant_sku_missing', $result->reason);
        $this->assertSame(0, DB::table('products')->count());
    }

    public function test_a_single_variant_is_treated_as_a_simple_product(): void
    {
        $payload = $this->variantPayload([['sku' => 'ONLY', 'name' => 'Default', 'qty' => 9, 'price' => 12, 'cost' => 7]]);
        $this->stage([$payload], $this->variantPaths());

        $result = $this->importer()->import($this->staged('555')->id);

        $product = DB::table('products')->find($result->productId);
        $this->assertSame('ONLY', $product->code);
        $this->assertSame('[]', $product->variation);
        $this->assertSame('', ProviderProductMapping::where('product_id', $result->productId)->value('variant_key'));
    }

    public function test_remote_images_are_never_fetched_from_private_addresses(): void
    {
        Http::fake();
        $fetcher = new RemoteImageFetcher();

        foreach (['https://127.0.0.1/a.png', 'https://10.0.0.5/a.png', 'https://192.168.1.10/a.png', 'https://169.254.169.254/latest/meta-data', 'http://example.com/a.png', 'https://user:pw@example.com/a.png', 'ftp://example.com/a.png', 'not a url'] as $url) {
            $this->assertNull($fetcher->fetch($url), $url);
        }

        Http::assertNothingSent();
    }

    public function test_a_response_that_is_not_an_image_is_rejected(): void
    {
        Http::fake(['https://1.1.1.1/*' => Http::response('<html>not an image</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->assertNull((new RemoteImageFetcher())->fetch('https://1.1.1.1/a.png'));
    }

    public function test_a_real_image_from_a_public_host_is_accepted(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        Http::fake(['https://1.1.1.1/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);

        $file = (new RemoteImageFetcher())->fetch('https://1.1.1.1/a.png');

        $this->assertNotNull($file);
        $this->assertSame('png', $file->getClientOriginalExtension());
    }
}
