<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Enums\Fulfillment\StockSource;
use App\Models\ProviderCatalogItem;
use App\Models\ProviderProductMapping;
use App\Models\ProviderSyncState;
use App\Services\Fulfillment\FieldMapStore;
use App\Services\Fulfillment\Providers\M5azn\M5aznFieldMaps;
use App\Services\Fulfillment\Support\FieldMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class M5aznCatalogSyncTest extends M5aznTestCase
{
    private ?array $catalogBody = null;

    /** Http::fake() stubs accumulate and the first match wins, so the body lives in a property the one stub reads. */
    private function fakeCatalog(array $items, string $wrapper = 'data'): void
    {
        $alreadyFaked = $this->catalogBody !== null;
        $this->catalogBody = $wrapper === '' ? $items : [$wrapper => $items, 'success' => true];

        if (!$alreadyFaked) {
            Http::fake([
                self::BASE . '/api/v1/auth/login' => $this->loginResponse(),
                self::BASE . '/api/v1/seller/myproductlist*' => fn () => Http::response($this->catalogBody),
            ]);
        }
    }

    public function test_the_sellers_selected_products_are_pulled_and_staged_once_each(): void
    {
        $this->fakeCatalog($this->catalogFixture());

        $first = $this->adapter()->syncCatalog();
        $second = $this->adapter()->syncCatalog();

        $this->assertSame(['created' => 2, 'updated' => 0, 'unchanged' => 0, 'unidentified' => 0], array_slice($first['stats'], 0, 4));
        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 2, 'unidentified' => 0], array_slice($second['stats'], 0, 4), 'syncing twice must not duplicate anything');
        $this->assertFalse($first['stats']['truncated']);
        $this->assertFalse($first['stats']['partial']);
        $this->assertSame(2, ProviderCatalogItem::count());
        $this->assertSame(0, DB::table('products')->count(), 'staging never creates a store product');
    }

    public function test_the_catalog_request_uses_the_documented_endpoint_and_filters(): void
    {
        $this->enableProvider(ProviderCode::M5azn, ['catalog_product_type' => 'global', 'catalog_category_id' => '12']);
        $this->fakeCatalog($this->catalogFixture());

        $this->adapter()->syncCatalog();

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v1/seller/myproductlist')
            && str_contains($request->url(), 'product_type=global') && str_contains($request->url(), 'category_id=12')
            && $request->method() === 'GET');
    }

    public function test_a_changed_product_updates_its_staged_copy_instead_of_adding_another(): void
    {
        $this->fakeCatalog($this->catalogFixture());
        $this->adapter()->syncCatalog();

        $changed = $this->catalogFixture();
        $changed[0]['quantity'] = 3;
        $this->fakeCatalog($changed);
        $stats = $this->adapter()->syncCatalog()['stats'];

        $this->assertSame(1, $stats['updated']);
        $this->assertSame(1, $stats['unchanged']);
        $this->assertSame(3, ProviderCatalogItem::where('external_id', '1947201')->value('stock'));
        $this->assertSame(2, ProviderCatalogItem::count());
    }

    public function test_a_bare_array_and_a_wrapped_list_are_both_understood(): void
    {
        $this->fakeCatalog($this->catalogFixture(), wrapper: '');
        $this->assertSame(2, $this->adapter()->syncCatalog()['stats']['created']);

        ProviderCatalogItem::query()->delete();
        $this->fakeCatalog($this->catalogFixture(), wrapper: 'products');
        $this->assertSame(2, $this->adapter()->syncCatalog()['stats']['created']);
    }

    public function test_records_whose_id_cannot_be_found_are_counted_not_invented(): void
    {
        $this->verifyMap('catalog', ['id' => 'id', 'name' => 'name'], [['id' => 5, 'name' => 'Has id']], itemsPath: 'data');
        $this->fakeCatalog([['name' => 'No id here'], ['id' => 5, 'name' => 'Has id']]);

        $stats = $this->adapter()->syncCatalog()['stats'];

        $this->assertSame(1, $stats['created']);
        $this->assertSame(1, $stats['unidentified']);
    }

    public function test_without_a_verified_map_a_proposed_mapping_is_only_used_when_it_fits_nearly_every_record(): void
    {
        $this->fakeCatalog([['name' => 'No id here'], ['id' => 5, 'name' => 'Has id']]);

        $stats = $this->adapter()->syncCatalog()['stats'];

        $this->assertSame(0, $stats['created'], 'an id found in only half the sample is not trusted');
        $this->assertSame(2, $stats['unidentified']);
    }

    public function test_a_failed_sync_is_recorded_with_its_error_and_changes_nothing(): void
    {
        Http::fake([
            self::BASE . '/api/v1/auth/login' => $this->loginResponse(),
            self::BASE . '/api/v1/seller/myproductlist*' => Http::response(['message' => 'Server exploded'], 500),
        ]);

        $result = $this->adapter()->syncCatalog();

        $this->assertFalse($result['ok']);
        $state = ProviderSyncState::where(['provider' => 'm5azn', 'capability' => 'products'])->first();
        $this->assertNotNull($state->last_failure_at);
        $this->assertNull($state->last_success_at);
        $this->assertStringContainsString('Server exploded', (string) $state->last_error);
        $this->assertSame(0, ProviderCatalogItem::count());
    }

    public function test_a_successful_sync_stamps_the_last_success_and_clears_the_error(): void
    {
        $this->fakeCatalog($this->catalogFixture());
        $this->adapter()->syncCatalog();

        $state = ProviderSyncState::where(['provider' => 'm5azn', 'capability' => 'products'])->first();
        $this->assertNotNull($state->last_success_at);
        $this->assertNull($state->last_error);
        $this->assertSame(2, $state->stats['created']);
    }

    public function test_the_sample_view_shows_raw_records_beside_a_proposed_mapping_and_redacts_secrets(): void
    {
        $fixture = $this->catalogFixture();
        $fixture[0]['api_token'] = 'leaky-token-value';
        $this->fakeCatalog($fixture);

        $sample = $this->adapter()->sample('catalog');

        $this->assertTrue($sample['ok']);
        $this->assertSame('id', $sample['proposal']['id']);
        $this->assertSame('quantity', $sample['proposal']['stock']);
        $this->assertSame('price', $sample['proposal']['retail_price']);
        $this->assertStringNotContainsString('leaky-token-value', json_encode($sample));
    }

    public function test_a_map_is_only_trusted_after_it_is_verified_and_editing_it_un_verifies_it(): void
    {
        $store = app(FieldMapStore::class);
        $store->save(ProviderCode::M5azn, 'catalog', $this->catalogPaths(), null);
        $this->assertFalse($store->get(ProviderCode::M5azn, 'catalog')->verified);

        $this->assertSame([], $store->verify(ProviderCode::M5azn, 'catalog', $this->catalogFixture(), M5aznFieldMaps::REQUIRED['catalog']));
        $this->assertTrue($store->get(ProviderCode::M5azn, 'catalog')->verified);

        $paths = $this->catalogPaths();
        $paths['name'] = 'title';
        $store->save(ProviderCode::M5azn, 'catalog', $paths, null);

        $this->assertFalse($store->get(ProviderCode::M5azn, 'catalog')->verified, 'changing a path must reset verification');
    }

    public function test_its_maps_are_still_read_the_way_they_always_were(): void
    {
        $store = app(FieldMapStore::class);
        $store->save(ProviderCode::M5azn, 'orders', ['reference' => 'status', 'status' => 'status'], null);
        $flags = array_map(static fn (int $n): array => ['status' => true], range(1, 5));

        $this->assertSame([], $store->verify(ProviderCode::M5azn, 'orders', $flags, ['reference', 'status']), 'a flag in a required field is no stricter a problem than it was');
        $this->assertTrue($store->get(ProviderCode::M5azn, 'orders')->verified);
    }

    public function test_a_map_that_does_not_resolve_against_the_real_sample_is_refused(): void
    {
        $store = app(FieldMapStore::class);
        $store->save(ProviderCode::M5azn, 'catalog', ['id' => 'product.identifier', 'name' => 'name'], null);

        $problems = $store->verify(ProviderCode::M5azn, 'catalog', $this->catalogFixture(), M5aznFieldMaps::REQUIRED['catalog']);

        $this->assertSame(['id'], $problems);
        $this->assertFalse($store->get(ProviderCode::M5azn, 'catalog')->verified);
    }

    public function test_the_field_map_locates_nested_lists_and_values(): void
    {
        $response = ['data' => ['items' => [['product' => ['id' => 7]]]]];

        $this->assertSame([['product' => ['id' => 7]]], FieldMap::extractItems($response));
        $this->assertSame('7', (new FieldMap(['id' => 'product.id']))->string($response['data']['items'][0], 'id'));
    }

    // ---- stock feed --------------------------------------------------------------------------

    private function mapProduct(int $productId, string $externalId, int $stock = 10, string $variantKey = '', string $variantId = ''): ProviderProductMapping
    {
        return ProviderProductMapping::create([
            'provider' => 'm5azn', 'product_id' => $productId, 'variant_key' => $variantKey, 'external_id' => $externalId,
            'external_variant_id' => $variantId, 'status' => MappingStatus::Approved, 'provider_stock' => $stock, 'stock_source' => StockSource::ProviderSync,
        ]);
    }

    public function test_stock_sync_is_refused_until_the_inventory_map_is_verified(): void
    {
        Http::fake();

        $result = $this->adapter()->syncInventory();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not verified', $result['error']);
        Http::assertNothingSent();
    }

    public function test_the_stock_change_feed_overwrites_store_stock_with_the_providers_number(): void
    {
        $this->makeProduct(1, ProviderCode::M5azn, ['current_stock' => 99]);
        $this->mapProduct(1, '1947201');
        $this->verifyMap('inventory', ['id' => 'id', 'stock' => 'quantity'], [['id' => 1947201, 'quantity' => 5]], itemsPath: 'data');
        Http::fake([
            self::BASE . '/api/v1/auth/login' => $this->loginResponse(),
            self::BASE . '/api/v1/products/qty-changed*' => Http::response(['data' => [['id' => 1947201, 'quantity' => 7], ['id' => 999, 'quantity' => 3]]]),
        ]);

        $result = $this->adapter()->syncInventory();

        $this->assertSame(['updated' => 1, 'unchanged' => 0, 'unmapped' => 1], $result['stats']);
        $this->assertSame(7, (int) DB::table('products')->where('id', 1)->value('current_stock'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'qty-changed') && str_contains($request->url(), 'hours='));
    }

    public function test_stock_already_sold_but_not_yet_with_the_provider_is_subtracted_so_it_cannot_be_oversold(): void
    {
        $this->makeProduct(1, ProviderCode::M5azn, ['current_stock' => 0]);
        $this->mapProduct(1, '1947201');
        $this->makeOrder(100);
        $this->addLine(100, 1, 3, 60, ProviderCode::M5azn);
        app(\App\Services\Fulfillment\FulfillmentRouter::class)->route(100);
        $this->verifyMap('inventory', ['id' => 'id', 'stock' => 'quantity'], [['id' => 1, 'quantity' => 5]], itemsPath: 'data');
        Http::fake([
            self::BASE . '/api/v1/auth/login' => $this->loginResponse(),
            self::BASE . '/api/v1/products/qty-changed*' => Http::response(['data' => [['id' => 1947201, 'quantity' => 10]]]),
        ]);

        $this->adapter()->syncInventory();

        $this->assertSame(7, (int) DB::table('products')->where('id', 1)->value('current_stock'), '10 at the provider minus 3 sold and not yet handed over');
    }

    /** Stock 10 at M5AZN, one order of 3 in the given condition: what the store should show after a sync. */
    private function storeStockAfterSyncWithOrder(array $orderOverrides, ?callable $shape = null): int
    {
        $this->makeProduct(1, ProviderCode::M5azn, ['current_stock' => 7]);
        $this->mapProduct(1, '1947201');
        $this->makeOrder(100, $orderOverrides);
        $this->addLine(100, 1, 3, 60, ProviderCode::M5azn);
        $shape !== null && $shape();
        $this->verifyMap('inventory', ['id' => 'id', 'stock' => 'quantity'], [['id' => 1, 'quantity' => 5]], itemsPath: 'data');
        Http::fake([
            self::BASE . '/api/v1/auth/login' => $this->loginResponse(),
            self::BASE . '/api/v1/products/qty-changed*' => Http::response(['data' => [['id' => 1947201, 'quantity' => 10]]]),
        ]);

        $this->adapter()->syncInventory();

        return (int) DB::table('products')->where('id', 1)->value('current_stock');
    }

    public function test_units_in_an_order_that_has_not_been_routed_yet_stay_reserved(): void
    {
        // A cash-on-delivery order waiting to be confirmed: the store already took 3 at checkout.
        $stock = $this->storeStockAfterSyncWithOrder(['order_status' => 'pending', 'payment_method' => 'cash_on_delivery', 'payment_status' => 'unpaid']);

        $this->assertSame(7, $stock, 'a sync must not hand the 3 back to the next customer');
    }

    public function test_units_in_a_shipment_the_provider_refused_stay_reserved_until_a_person_resolves_it(): void
    {
        $stock = $this->storeStockAfterSyncWithOrder([], function (): void {
            app(\App\Services\Fulfillment\FulfillmentRouter::class)->route(100);
            \App\Models\ProviderFulfillment::query()->update(['status' => \App\Enums\Fulfillment\FulfillmentStatus::Failed]);
        });

        $this->assertSame(7, $stock);
    }

    public function test_units_the_provider_already_holds_are_the_providers_to_count(): void
    {
        $stock = $this->storeStockAfterSyncWithOrder([], function (): void {
            app(\App\Services\Fulfillment\FulfillmentRouter::class)->route(100);
            \App\Models\ProviderFulfillment::query()->update(['status' => \App\Enums\Fulfillment\FulfillmentStatus::Submitted]);
        });

        $this->assertSame(10, $stock, 'M5AZN\'s own number already reflects an order it accepted');
    }

    public function test_units_in_a_cancelled_order_are_not_reserved(): void
    {
        $this->assertSame(10, $this->storeStockAfterSyncWithOrder(['order_status' => 'canceled']));
    }

    public function test_units_in_a_delivered_order_are_not_reserved(): void
    {
        $this->assertSame(10, $this->storeStockAfterSyncWithOrder(['order_status' => 'delivered']));
    }

    public function test_syncing_the_same_numbers_twice_changes_nothing_the_second_time(): void
    {
        $this->makeProduct(1, ProviderCode::M5azn, ['current_stock' => 1]);
        $this->mapProduct(1, '1947201');
        $this->verifyMap('inventory', ['id' => 'id', 'stock' => 'quantity'], [['id' => 1, 'quantity' => 5]], itemsPath: 'data');
        Http::fake([
            self::BASE . '/api/v1/auth/login' => $this->loginResponse(),
            self::BASE . '/api/v1/products/qty-changed*' => Http::response(['data' => [['id' => 1947201, 'quantity' => 12]]]),
        ]);

        $first = $this->adapter()->syncInventory()['stats'];
        $second = $this->adapter()->syncInventory()['stats'];

        $this->assertSame(1, $first['updated']);
        $this->assertSame(1, $second['unchanged']);
    }

    public function test_a_variant_stock_updates_only_that_variant_and_the_product_total_follows(): void
    {
        $this->makeProduct(1, ProviderCode::M5azn, ['current_stock' => 30, 'variation' => json_encode([
            ['type' => 'Small', 'price' => 5, 'sku' => 'S', 'qty' => 10],
            ['type' => 'Large', 'price' => 6, 'sku' => 'L', 'qty' => 20],
        ])]);
        $this->mapProduct(1, '555', variantKey: 'Small', variantId: 'S');
        $this->mapProduct(1, '555', variantKey: 'Large', variantId: 'L');
        $this->verifyMap('inventory', ['id' => 'id', 'stock' => 'quantity', 'variant_sku' => 'sku'], [['id' => 555, 'sku' => 'S', 'quantity' => 4]], itemsPath: 'data');
        Http::fake([
            self::BASE . '/api/v1/auth/login' => $this->loginResponse(),
            self::BASE . '/api/v1/products/qty-changed*' => Http::response(['data' => [['id' => 555, 'sku' => 'S', 'quantity' => 4]]]),
        ]);

        $this->adapter()->syncInventory();

        $variation = json_decode((string) DB::table('products')->where('id', 1)->value('variation'), true);
        $this->assertSame([4, 20], array_column($variation, 'qty'));
        $this->assertSame(24, (int) DB::table('products')->where('id', 1)->value('current_stock'));
    }

    public function test_a_stock_change_leaves_a_movement_record_with_its_reason(): void
    {
        \Illuminate\Support\Facades\Schema::create('stock_movements', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('type');
            $table->integer('qty_change');
            $table->integer('balance_after')->nullable();
            $table->string('reason')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_type')->nullable();
            $table->timestamps();
        });
        $this->makeProduct(1, ProviderCode::M5azn, ['current_stock' => 2]);
        $this->mapProduct(1, '1947201');
        $this->verifyMap('inventory', ['id' => 'id', 'stock' => 'quantity'], [['id' => 1, 'quantity' => 5]], itemsPath: 'data');
        Http::fake([
            self::BASE . '/api/v1/auth/login' => $this->loginResponse(),
            self::BASE . '/api/v1/products/qty-changed*' => Http::response(['data' => [['id' => 1947201, 'quantity' => 9]]]),
        ]);

        $this->adapter()->syncInventory();

        $movement = DB::table('stock_movements')->first();
        $this->assertSame('provider_sync', $movement->reason);
        $this->assertSame(7, (int) $movement->qty_change);
        $this->assertSame(9, (int) $movement->balance_after);
    }
}
