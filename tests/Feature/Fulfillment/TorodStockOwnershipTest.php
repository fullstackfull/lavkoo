<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Enums\Fulfillment\StockSource;
use App\Models\ProviderCatalogItem;
use App\Models\ProviderFulfillmentItem;
use App\Models\ProviderProductMapping;
use App\Models\ProviderSyncState;
use App\Services\Fulfillment\Data\CapabilityState;
use App\Services\Fulfillment\Data\ReadinessReport;
use App\Services\Fulfillment\FieldMapStore;
use App\Services\Fulfillment\InventorySyncService;
use App\Services\Fulfillment\ProductFulfillmentPanel;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\ProviderMappingService;
use App\Services\Fulfillment\ProviderStockOwnership;
use App\Services\Fulfillment\Providers\TorodPoint\TorodFieldMaps;
use App\Services\Fulfillment\Providers\TorodPoint\TorodPointAdapter;
use App\Services\Fulfillment\Providers\TorodPoint\TorodStockConfirmation;
use App\Services\Fulfillment\Support\ProviderConfig;
use App\Services\Marketplace\InventoryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeM5aznAdapter;

/**
 * Whose stock the storefront shows: its own (typed by a person) until the warehouse's figure has been verified, confirmed for the
 * exact field it is read from, and deliberately switched on; then, for the products ASSIGNED to the warehouse and only those, the
 * warehouse's figure minus what customers bought and the warehouse has not taken on yet.
 */
class TorodStockOwnershipTest extends TorodTestCase
{
    private const PATH = 'quantity';

    protected function setUp(): void
    {
        parent::setUp();

        // The stock ledger belongs to the platform's inventory module; the fulfillment test schema does not build it.
        Schema::create('stock_movements', static function (Blueprint $table): void {
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
    }

    // ---- helpers -------------------------------------------------------------------------------------------------------------

    private function config(): ProviderConfig
    {
        return app(ProviderConnectionManager::class)->config(ProviderCode::TorodPoint);
    }

    /** The warehouse's product list, every figure under the assumed `quantity` field: id => figure. */
    private function warehouse(array $figures): array
    {
        return array_map(static fn (int $id, mixed $figure): array => ['id' => $id, 'name' => 'P' . $id, 'sku' => 'S' . $id, 'quantity' => $figure], array_keys($figures), $figures);
    }

    private function fakeProductPages(array ...$pages): void
    {
        $call = 0;
        Http::fake([self::V5 . '/public/fulfillment/product*' => function () use (&$call, $pages) {
            return Http::response(['data' => $pages[min($call++, count($pages) - 1)]]);
        }]);
    }

    /** The map is verified for `$path` and the administrator has confirmed that field; the switch is whatever is given. */
    private function stockSync(string $path = self::PATH, bool $switchedOn = true, bool $confirmed = true): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['inventory_sync_enabled' => $switchedOn, 'company_id' => '714', 'dry_run' => false]);
        $this->verifyProductsMap(['id' => 'id', 'name' => 'name', 'sku' => 'sku', 'stock' => $path], [['id' => 1, 'name' => 'A', 'sku' => 'S1']]);
        if ($confirmed) {
            $this->confirmStockField($path);
        }
    }

    /** What ticking the box beside the path does. */
    private function confirmStockField(string $path): void
    {
        $settings = app(TorodStockConfirmation::class)->settingsFor(ticked: true, seenPath: $path, current: $this->config());
        app(ProviderConnectionManager::class)->save(ProviderCode::TorodPoint, null, null, $settings);
    }

    private function mappedProduct(int $id, int $stock = 50, ?ProviderCode $assignedTo = ProviderCode::TorodPoint, ?string $externalId = null): ProviderProductMapping
    {
        $this->makeProduct($id, $assignedTo, ['current_stock' => $stock]);

        return ProviderProductMapping::create([
            'provider' => 'torod_point', 'product_id' => $id, 'variant_key' => '', 'external_id' => $externalId ?? (string) $id,
            'external_variant_id' => '', 'external_sku' => 'S' . $id, 'status' => MappingStatus::Approved,
        ]);
    }

    private function stockOf(int $productId): int
    {
        return (int) DB::table('products')->where('id', $productId)->value('current_stock');
    }

    private function movements(): int
    {
        return DB::table('stock_movements')->count();
    }

    // ---- manual or warehouse: who sets the stock -------------------------------------------------------------------------------

    /** @return iterable<string, array{bool, bool, bool, bool, bool, bool}> */
    public static function modes(): iterable
    {
        //                                           assigned, mapped, switched on, verified, confirmed => the warehouse sets the stock
        yield 'everything in place'                => [true, true, true, true, true, true];
        yield 'the sync is switched off'           => [true, true, false, true, true, false];
        yield 'the field is not confirmed'         => [true, true, true, true, false, false];
        yield 'the map is not verified'            => [true, true, true, false, true, false];
        yield 'the product is not assigned'        => [false, true, true, true, true, false];
        yield 'the product has no approved link'   => [true, false, true, true, true, false];
    }

    #[DataProvider('modes')]
    public function test_the_warehouse_sets_the_stock_only_when_every_condition_holds(bool $assigned, bool $mapped, bool $switchedOn, bool $verified, bool $confirmed, bool $warehouseSets): void
    {
        $this->stockSync(switchedOn: $switchedOn, confirmed: $confirmed);
        if (!$verified) {
            app(FieldMapStore::class)->save(ProviderCode::TorodPoint, TorodFieldMaps::PRODUCTS, ['id' => 'id', 'name' => 'name', 'stock' => self::PATH], null);
        }
        $this->makeProduct(1, $assigned ? ProviderCode::TorodPoint : null, ['current_stock' => 7]);
        if ($mapped) {
            ProviderProductMapping::create(['provider' => 'torod_point', 'product_id' => 1, 'variant_key' => '', 'external_id' => '1', 'external_variant_id' => '', 'status' => MappingStatus::Approved]);
        }
        $this->fakeProductPages($this->warehouse([1 => 40]));

        $result = $this->adapter()->syncInventory();

        $this->assertSame($warehouseSets ? ProviderCode::TorodPoint : null, app(ProviderStockOwnership::class)->controllingProvider(1));
        $this->assertSame($warehouseSets ? 40 : 7, $this->stockOf(1), 'otherwise the storefront keeps the figure a person typed');
        $this->assertSame($warehouseSets, $result['ok'] && ($result['stats']['updated'] ?? 0) === 1);
    }

    public function test_while_the_sync_is_off_nothing_is_even_asked_of_the_warehouse(): void
    {
        $this->stockSync(switchedOn: false);
        $this->mappedProduct(1, stock: 7);
        Http::fake();

        $result = $this->adapter()->syncInventory();

        $this->assertFalse($result['ok']);
        $this->assertSame(7, $this->stockOf(1));
        Http::assertNothingSent();
    }

    public function test_an_unconfirmed_stock_field_blocks_the_sync_and_says_why(): void
    {
        $this->stockSync(confirmed: false);
        $this->mappedProduct(1, stock: 7);
        Http::fake();

        $result = $this->adapter()->syncInventory();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no inventory endpoint is documented', $result['error']);
        $this->assertSame(CapabilityState::UNVERIFIED, $this->adapter()->readiness()->capability('inventory_sync')->state);
        $this->assertSame(7, $this->stockOf(1));
        Http::assertNothingSent();
    }

    public function test_a_confirmation_made_for_one_field_lapses_when_the_map_reads_another(): void
    {
        $this->stockSync(path: 'sellableQuantity');
        $this->assertSame(CapabilityState::ENABLED, $this->adapter()->readiness()->capability('inventory_sync')->state, 'confirmed for sellableQuantity');

        $this->verifyProductsMap(['id' => 'id', 'name' => 'name', 'sku' => 'sku', 'stock' => 'onHandQuantity'], [['id' => 1, 'name' => 'A', 'sku' => 'S1']]);
        $this->mappedProduct(1, stock: 7);
        Http::fake();

        $this->assertSame(CapabilityState::UNVERIFIED, $this->adapter()->readiness()->capability('inventory_sync')->state, 'the statement was about another field');
        $this->assertFalse($this->adapter()->syncInventory()['ok']);
        $this->assertSame(7, $this->stockOf(1));
        Http::assertNothingSent();
    }

    public function test_a_confirmation_from_before_it_named_a_field_does_not_count(): void
    {
        $this->stockSync(confirmed: false);
        app(ProviderConnectionManager::class)->save(ProviderCode::TorodPoint, null, null, ['stock_field_confirmed_at' => now()->toDateTimeString()]);

        $this->assertFalse(app(TorodStockConfirmation::class)->isConfirmed($this->config()));
        $this->assertFalse($this->adapter()->syncInventory()['ok']);
    }

    public function test_ticking_the_box_confirms_the_field_that_was_shown_and_only_while_it_is_still_the_one_read(): void
    {
        $this->stockSync(confirmed: false);
        $confirmation = app(TorodStockConfirmation::class);

        $this->assertSame(['stock_field_confirmed_at' => null, 'stock_field_confirmed_path' => null], $confirmation->settingsFor(ticked: false, seenPath: self::PATH, current: $this->config()));
        $this->assertSame(['stock_field_confirmed_at' => null, 'stock_field_confirmed_path' => null], $confirmation->settingsFor(ticked: true, seenPath: 'sellableQuantity', current: $this->config()), 'a page left open across a change of the field confirms nothing');
        $this->assertSame(['stock_field_confirmed_at' => null, 'stock_field_confirmed_path' => null], $confirmation->settingsFor(ticked: true, seenPath: null, current: $this->config()));

        $ticked = $confirmation->settingsFor(ticked: true, seenPath: self::PATH, current: $this->config());
        $this->assertSame(self::PATH, $ticked['stock_field_confirmed_path']);
        $this->assertNotNull($ticked['stock_field_confirmed_at']);
    }

    public function test_saving_the_form_again_keeps_the_moment_of_the_original_confirmation(): void
    {
        $this->stockSync();
        $first = $this->config()->get('stock_field_confirmed_at');
        $this->travel(2)->hours();

        $again = app(TorodStockConfirmation::class)->settingsFor(ticked: true, seenPath: self::PATH, current: $this->config());

        $this->assertSame($first, $again['stock_field_confirmed_at']);
    }

    public function test_no_quantity_is_proposed_as_the_stock_and_the_staged_catalogue_carries_none(): void
    {
        $products = [['id' => 1, 'name' => 'A', 'sku' => 'S1', 'quantity' => 0, 'originalQuantity' => 0, 'committedQuantity' => 0, 'allocatedQuantity' => 0, 'onHandQuantity' => 15, 'sellableQuantity' => 0]];
        $this->fakeProductPages($products);

        $sample = $this->adapter()->sample(TorodFieldMaps::PRODUCTS);
        $this->adapter()->syncCatalog();

        $this->assertArrayNotHasKey('stock', $sample['proposal']);
        $this->assertNull(ProviderCatalogItem::where('external_id', '1')->value('stock'), 'no figure is staged as the stock either');
    }

    // ---- only what is assigned ----------------------------------------------------------------------------------------------------

    public function test_only_the_products_assigned_to_the_warehouse_are_written(): void
    {
        $this->stockSync();
        $this->mappedProduct(1, stock: 50);
        $unassigned = $this->mappedProduct(2, stock: 20, assignedTo: null);
        $otherProvider = $this->mappedProduct(3, stock: 30, assignedTo: ProviderCode::M5azn);
        $this->fakeProductPages($this->warehouse([1 => 40, 2 => 11, 3 => 9, 4 => 5]));

        $result = $this->adapter()->syncInventory();

        $this->assertTrue($result['ok']);
        $this->assertSame(['updated' => 1, 'unchanged' => 0, 'unmapped' => 1, 'not_assigned' => 2, 'invalid' => 0, 'unsupported' => 0, 'absent' => 0], $result['stats']);
        $this->assertSame([40, 20, 30], [$this->stockOf(1), $this->stockOf(2), $this->stockOf(3)]);
        $this->assertNull($unassigned->fresh()->provider_stock, 'no figure is recorded for a product the warehouse does not hold');
        $this->assertSame(StockSource::None, $unassigned->fresh()->stock_source);
        $this->assertSame(StockSource::None, $otherProvider->fresh()->stock_source);
        $this->assertSame(StockSource::ProviderSync, ProviderProductMapping::where('product_id', 1)->first()->stock_source);
    }

    public function test_nothing_but_the_stock_of_an_assigned_product_changes(): void
    {
        $this->stockSync();
        $this->mappedProduct(1, stock: 50);
        $before = (array) DB::table('products')->where('id', 1)->first();
        $this->fakeProductPages([['id' => 1, 'name' => 'A different title', 'sku' => 'OTHER', 'quantity' => 40, 'price' => 999, 'barcode' => 'OTHER', 'description' => 'changed']]);

        $this->adapter()->syncInventory();

        $after = (array) DB::table('products')->where('id', 1)->first();
        $this->assertSame(40, (int) $after['current_stock']);
        $this->assertSame(array_diff_key($before, ['current_stock' => 0, 'updated_at' => 0]), array_diff_key($after, ['current_stock' => 0, 'updated_at' => 0]), 'price, title, code, barcode, variations, tax and every other column are untouched');
        $this->assertSame(1, DB::table('products')->count(), 'no product is created from the warehouse list');
    }

    // ---- figures that cannot be trusted ---------------------------------------------------------------------------------------------

    /** @return iterable<string, array{mixed}> */
    public static function unusableFigures(): iterable
    {
        yield 'negative' => [-5];
        yield 'fractional' => [2.5];
        yield 'text' => ['many'];
        yield 'nothing' => [null];
        yield 'a list' => [['a' => 1]];
        yield 'true' => [true];
        yield 'beyond any warehouse' => [1_000_000_000_000];
        yield 'beyond a 64-bit integer' => [1.8446744073709552E+19];
        yield 'an unlimited sentinel' => [1e300];
        yield 'hugely negative' => [-1e300];
        yield 'the largest float' => [1.7976931348623157e308];
    }

    #[DataProvider('unusableFigures')]
    public function test_an_unusable_figure_leaves_the_stock_exactly_as_it_was_and_is_never_turned_into_zero(mixed $figure): void
    {
        $this->stockSync();
        $mapping = $this->mappedProduct(1, stock: 50);
        $this->mappedProduct(2, stock: 50);
        $this->fakeProductPages($this->warehouse([1 => $figure, 2 => 12]));

        $result = $this->adapter()->syncInventory();

        $this->assertTrue($result['ok'], 'the product with a usable figure is synced');
        $this->assertSame(1, $result['stats']['invalid']);
        $this->assertSame(1, $result['stats']['updated']);
        $this->assertSame([50, 12], [$this->stockOf(1), $this->stockOf(2)]);
        $this->assertNull($mapping->fresh()->provider_stock);
        $this->assertSame(StockSource::None, $mapping->fresh()->stock_source);
        $this->assertSame(1, $this->movements(), 'only the product that changed has a movement');
    }

    public function test_a_missing_quantity_field_is_not_a_zero(): void
    {
        $this->stockSync();
        $this->mappedProduct(1, stock: 50);
        $this->mappedProduct(2, stock: 50);
        $this->fakeProductPages([['id' => 1, 'name' => 'P1', 'sku' => 'S1'], ['id' => 2, 'name' => 'P2', 'sku' => 'S2', 'quantity' => 12]]);

        $result = $this->adapter()->syncInventory();

        $this->assertSame(1, $result['stats']['invalid']);
        $this->assertSame([50, 12], [$this->stockOf(1), $this->stockOf(2)]);
    }

    public function test_zero_is_a_real_figure_when_the_warehouse_says_so(): void
    {
        $this->stockSync();
        $this->mappedProduct(1, stock: 50);
        $this->fakeProductPages($this->warehouse([1 => 0]));

        $result = $this->adapter()->syncInventory();

        $this->assertSame(1, $result['stats']['updated']);
        $this->assertSame(0, $this->stockOf(1));
        $this->assertSame(-50, (int) DB::table('stock_movements')->value('qty_change'));
    }

    public function test_a_product_listed_twice_with_different_figures_is_not_written_but_with_the_same_figure_it_is(): void
    {
        $this->stockSync();
        $this->mappedProduct(1, stock: 50);
        $this->mappedProduct(2, stock: 50);
        $this->fakeProductPages([
            ['id' => 1, 'name' => 'P1', 'sku' => 'S1', 'quantity' => 10], ['id' => 1, 'name' => 'P1', 'sku' => 'S1', 'quantity' => 30],
            ['id' => 2, 'name' => 'P2', 'sku' => 'S2', 'quantity' => 20], ['id' => 2, 'name' => 'P2', 'sku' => 'S2', 'quantity' => 20],
        ]);

        $result = $this->adapter()->syncInventory();

        $this->assertSame([50, 20], [$this->stockOf(1), $this->stockOf(2)]);
        $this->assertSame(1, $result['stats']['invalid']);
    }

    public function test_a_product_the_response_leaves_out_keeps_its_stock_and_is_counted(): void
    {
        $this->stockSync();
        $this->mappedProduct(1, stock: 50);
        $this->mappedProduct(2, stock: 50);
        $this->fakeProductPages($this->warehouse([2 => 12]));

        $result = $this->adapter()->syncInventory();

        $this->assertSame([50, 12], [$this->stockOf(1), $this->stockOf(2)]);
        $this->assertSame(1, $result['stats']['absent']);
    }

    public function test_an_empty_list_changes_nothing_and_is_not_reported_as_a_success(): void
    {
        $this->stockSync();
        $this->mappedProduct(1, stock: 50);
        $this->fakeProductPages([]);

        $result = $this->adapter()->syncInventory();

        $this->assertFalse($result['ok'], 'no assigned product received a figure');
        $this->assertSame(50, $this->stockOf(1));
        $this->assertSame(0, $this->movements());
    }

    public function test_an_empty_list_is_fine_when_the_warehouse_holds_nothing_of_ours(): void
    {
        $this->stockSync();
        $this->makeProduct(1, null, ['current_stock' => 50]);
        $this->fakeProductPages([]);

        $result = $this->adapter()->syncInventory();

        $this->assertTrue($result['ok'], 'no product is assigned to the warehouse, so there is nothing to expect');
        $this->assertSame(50, $this->stockOf(1));
    }

    public function test_a_failing_warehouse_changes_nothing_and_a_page_that_fails_midway_applies_nothing(): void
    {
        $this->stockSync();
        $this->mappedProduct(1, stock: 50);
        $call = 0;
        $firstPage = array_map(static fn (int $id): array => ['id' => $id, 'name' => 'P' . $id, 'sku' => 'S' . $id, 'quantity' => 1], range(1, 100));
        Http::fake([self::V5 . '/public/fulfillment/product*' => function () use (&$call, $firstPage) {
            return $call++ === 0 ? Http::response(['data' => $firstPage]) : Http::response(['message' => 'down'], 500);
        }]);

        $result = $this->adapter()->syncInventory();

        $this->assertFalse($result['ok']);
        $this->assertSame(50, $this->stockOf(1), 'the first page was readable, but a sync that could not finish applies none of it');
        $this->assertNotNull(ProviderSyncState::where(['provider' => 'torod_point', 'capability' => 'inventory'])->value('last_failure_at'));
    }

    public function test_when_not_one_figure_is_usable_the_sync_fails_loudly_instead_of_reporting_success(): void
    {
        $this->stockSync();
        $this->mappedProduct(1, stock: 50);
        $this->fakeProductPages([['id' => 1, 'name' => 'P1', 'sku' => 'S1', 'quantity' => 'n/a']]);

        $result = $this->adapter()->syncInventory();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('usable quantity', $result['error']);
        $this->assertSame(50, $this->stockOf(1));
    }

    public function test_a_mapping_that_cannot_be_written_is_counted_and_never_labelled_as_synced(): void
    {
        $this->stockSync();
        $variants = json_encode([['type' => 'Blue', 'price' => 10, 'qty' => 7], ['type' => 'Red', 'price' => 10, 'qty' => 3]]);
        $this->makeProduct(1, ProviderCode::TorodPoint, ['current_stock' => 10, 'variation' => $variants]);
        $wholeProduct = ProviderProductMapping::create(['provider' => 'torod_point', 'product_id' => 1, 'variant_key' => '', 'external_id' => '1', 'external_variant_id' => '', 'status' => MappingStatus::Approved]);
        $this->makeProduct(2, ProviderCode::TorodPoint, ['current_stock' => 10, 'variation' => $variants]);
        $renamed = ProviderProductMapping::create(['provider' => 'torod_point', 'product_id' => 2, 'variant_key' => 'Navy', 'external_id' => '2', 'external_variant_id' => '', 'status' => MappingStatus::Approved]);
        $this->mappedProduct(3, stock: 20);
        $this->fakeProductPages($this->warehouse([1 => 40, 2 => 40, 3 => 12]));

        $result = $this->adapter()->syncInventory();

        $this->assertTrue($result['ok'], 'the product that could be written was');
        $this->assertSame(12, $this->stockOf(3));
        $this->assertSame(2, $result['stats']['unsupported'], 'one figure for a product sold per variant cannot be spread over the variants, and a variant that is no longer there has nothing to write');
        $this->assertSame([10, 10], [$this->stockOf(1), $this->stockOf(2)]);
        $this->assertSame($variants, DB::table('products')->where('id', 1)->value('variation'), 'the variants were not touched');
        $this->assertNull($wholeProduct->fresh()->provider_stock);
        $this->assertSame(StockSource::None, $wholeProduct->fresh()->stock_source);
        $this->assertNull($renamed->fresh()->provider_stock, 'a variant that is no longer there is not labelled as synced either');
    }

    public function test_a_mapped_variant_that_was_renamed_is_unsupported_not_unchanged(): void
    {
        $this->stockSync();
        $this->makeProduct(1, ProviderCode::TorodPoint, ['current_stock' => 10, 'variation' => json_encode([['type' => 'Azure', 'price' => 10, 'qty' => 10]])]);
        $mapping = ProviderProductMapping::create(['provider' => 'torod_point', 'product_id' => 1, 'variant_key' => 'Blue', 'external_id' => '1', 'external_variant_id' => '', 'status' => MappingStatus::Approved]);
        $this->fakeProductPages($this->warehouse([1 => 0]));

        $result = $this->adapter()->syncInventory();

        $this->assertFalse($result['ok'], 'nothing could be written, which is a failure, not a success');
        $this->assertSame(10, $this->stockOf(1));
        $this->assertNull($mapping->fresh()->provider_stock);
        $this->assertSame(1, ProviderSyncState::where(['provider' => 'torod_point', 'capability' => 'inventory'])->count());
    }

    public function test_a_second_run_with_the_same_figure_does_not_rewrite_the_product_of_a_variant(): void
    {
        $this->stockSync();
        $this->makeProduct(1, ProviderCode::TorodPoint, ['current_stock' => 10, 'variation' => json_encode([['type' => 'Blue', 'price' => 10, 'qty' => 10]])]);
        ProviderProductMapping::create(['provider' => 'torod_point', 'product_id' => 1, 'variant_key' => 'Blue', 'external_id' => '1', 'external_variant_id' => '', 'status' => MappingStatus::Approved]);
        $this->fakeProductPages($this->warehouse([1 => 7]));
        $this->adapter()->syncInventory();
        $this->assertSame(7, $this->stockOf(1));
        DB::table('products')->where('id', 1)->update(['updated_at' => '2020-01-01 00:00:00']);

        $second = $this->adapter()->syncInventory();

        $this->assertSame(1, $second['stats']['unchanged']);
        $this->assertSame('2020-01-01 00:00:00', (string) DB::table('products')->where('id', 1)->value('updated_at'), 'nothing changed, so the row was left alone');
        $this->assertSame(1, $this->movements());
    }

    public function test_a_variant_that_differs_is_rewritten_even_when_the_total_happens_to_match(): void
    {
        $this->stockSync();
        $this->makeProduct(1, ProviderCode::TorodPoint, ['current_stock' => 7, 'variation' => json_encode([['type' => 'Blue', 'price' => 10, 'qty' => 10]])]);
        ProviderProductMapping::create(['provider' => 'torod_point', 'product_id' => 1, 'variant_key' => 'Blue', 'external_id' => '1', 'external_variant_id' => '', 'status' => MappingStatus::Approved]);
        $this->fakeProductPages($this->warehouse([1 => 7]));

        $this->adapter()->syncInventory();

        $this->assertSame(7, json_decode((string) DB::table('products')->where('id', 1)->value('variation'), true)[0]['qty'], 'the figure on the variant is the warehouse\'s, whatever the stored total said');
    }

    public function test_ownership_is_checked_again_under_the_product_lock(): void
    {
        $this->stockSync();
        $mapping = $this->mappedProduct(1, stock: 50);
        $this->mappedProduct(2, stock: 50);
        $moved = false;
        DB::beforeExecuting(function (string $query) use (&$moved): void {
            if (!$moved && preg_match('/from [`"]products[`"] where [`"]id[`"] = \?/', $query) === 1) {
                $moved = true;
                DB::table('products')->where('id', 1)->update(['fulfillment_provider' => null]);
            }
        });
        $this->fakeProductPages($this->warehouse([1 => 40, 2 => 12]));

        $result = $this->adapter()->syncInventory();

        $this->assertSame(50, $this->stockOf(1), 'moved off the warehouse after the run began: not written');
        $this->assertNull($mapping->fresh()->provider_stock);
        $this->assertSame(1, $result['stats']['not_assigned']);
        $this->assertSame(12, $this->stockOf(2));
    }

    public function test_a_run_that_was_switched_off_while_it_read_the_warehouse_writes_nothing(): void
    {
        $this->stockSync();
        $this->mappedProduct(1, stock: 50);
        Http::fake([self::V5 . '/public/fulfillment/product*' => function () {
            app(ProviderConnectionManager::class)->save(ProviderCode::TorodPoint, null, null, ['inventory_sync_enabled' => false]);

            return Http::response(['data' => $this->warehouse([1 => 40])]);
        }]);

        $result = $this->adapter()->syncInventory();

        $this->assertFalse($result['ok']);
        $this->assertSame(50, $this->stockOf(1));
    }

    public function test_a_run_whose_map_was_changed_while_it_read_the_warehouse_writes_nothing(): void
    {
        $this->stockSync();
        $this->mappedProduct(1, stock: 50);
        Http::fake([self::V5 . '/public/fulfillment/product*' => function () {
            $this->verifyProductsMap(['id' => 'id', 'name' => 'name', 'sku' => 'sku', 'stock' => 'sellableQuantity'], [['id' => 1, 'name' => 'A', 'sku' => 'S1', 'sellableQuantity' => 3]]);
            $this->confirmStockField('sellableQuantity');

            return Http::response(['data' => $this->warehouse([1 => 40])]);
        }]);

        $result = $this->adapter()->syncInventory();

        $this->assertFalse($result['ok']);
        $this->assertSame(50, $this->stockOf(1), 'figures read at the old path are not written under a confirmation made for another one');
    }

    public function test_the_stock_confirmation_is_explained_when_only_the_map_lost_its_verification(): void
    {
        $this->stockSync();
        $this->mappedProduct(1, stock: 50);
        app(FieldMapStore::class)->save(ProviderCode::TorodPoint, TorodFieldMaps::PRODUCTS, ['id' => 'id', 'name' => 'title', 'stock' => self::PATH], null);
        Http::fake();

        $capability = $this->adapter()->readiness()->capability('inventory_sync');
        $result = $this->adapter()->syncInventory();

        $this->assertSame('torod_stock_map_unverified', $capability->reason, 'the confirmation stands; the map has to be verified again');
        $this->assertStringContainsString('verified again', $result['error']);
        Http::assertNothingSent();
    }

    // ---- accepted just now ----------------------------------------------------------------------------------------------------------

    public function test_an_order_accepted_after_the_figures_began_to_be_read_is_still_held_back(): void
    {
        $this->stockSync();
        $fulfillment = $this->torodFulfillment();
        $fulfillment->forceFill(['status' => FulfillmentStatus::Submitted, 'submitted_at' => now()])->save();
        $service = app(InventorySyncService::class);

        $service->applyToAssignedProducts(ProviderCode::TorodPoint, [1421521 => 10.0], readStartedAt: now()->subMinute());
        $this->assertSame(8, $this->stockOf(1), 'accepted after the read began: the figure may not count its 2 units yet');

        $service->applyToAssignedProducts(ProviderCode::TorodPoint, [1421521 => 10.0], readStartedAt: now()->addMinute());
        $this->assertSame(10, $this->stockOf(1), 'accepted before the read began: the figure counted them');
    }

    public function test_an_accepted_order_with_no_recorded_acceptance_time_counts_from_its_last_event(): void
    {
        $this->stockSync();
        $fulfillment = $this->torodFulfillment();
        $service = app(InventorySyncService::class);
        $fulfillment->forceFill(['status' => FulfillmentStatus::Processing, 'submitted_at' => null, 'last_received_at' => now()])->save();

        $service->applyToAssignedProducts(ProviderCode::TorodPoint, [1421521 => 10.0], readStartedAt: now()->subMinute());
        $this->assertSame(8, $this->stockOf(1), 'its first word came after the read began: the figure may not count its 2 units');

        $fulfillment->forceFill(['last_received_at' => now()->subHours(2)])->save();
        $service->applyToAssignedProducts(ProviderCode::TorodPoint, [1421521 => 10.0], readStartedAt: now()->subMinute());
        $this->assertSame(10, $this->stockOf(1), 'its last word came before the read began');

        $fulfillment->forceFill(['last_received_at' => null])->save();
        $service->applyToAssignedProducts(ProviderCode::TorodPoint, [1421521 => 10.0], readStartedAt: now()->subMinute());
        $this->assertSame(8, $this->stockOf(1), 'nothing says when: it counts from its last change');
    }

    public function test_an_order_that_was_accepted_and_then_cancelled_or_finished_holds_nothing_back(): void
    {
        $this->stockSync();
        $fulfillment = $this->torodFulfillment();
        $service = app(InventorySyncService::class);

        foreach ([FulfillmentStatus::Cancelled, FulfillmentStatus::Delivered, FulfillmentStatus::Returned] as $status) {
            $fulfillment->forceFill(['status' => $status, 'submitted_at' => now()])->save();
            $service->applyToAssignedProducts(ProviderCode::TorodPoint, [1421521 => 10.0], readStartedAt: now()->subMinute());
            $this->assertSame(10, $this->stockOf(1), $status->value);
        }
    }

    public function test_the_adapter_hands_the_sync_the_moment_it_began_reading(): void
    {
        $this->stockSync();
        $fulfillment = $this->torodFulfillment();
        $fulfillment->forceFill(['status' => FulfillmentStatus::Submitted, 'submitted_at' => now()->addSecond()])->save();
        $this->fakeProductPages($this->warehouse([1421521 => 10]));

        $this->adapter()->syncInventory();

        $this->assertSame(8, $this->stockOf(1), 'accepted while the list was being read');
    }

    // ---- orders the warehouse has not taken on yet ------------------------------------------------------------------------------------

    /** @return iterable<string, array{FulfillmentStatus}> */
    public static function everyStatus(): iterable
    {
        foreach (FulfillmentStatus::cases() as $status) {
            yield $status->value => [$status];
        }
    }

    #[DataProvider('everyStatus')]
    public function test_units_on_orders_the_warehouse_has_not_taken_on_are_held_back_and_the_others_are_not(FulfillmentStatus $status): void
    {
        $this->stockSync();
        $fulfillment = $this->torodFulfillment();
        $fulfillment->forceFill(['status' => $status, 'submitted_at' => now()->subHour()])->save();
        $this->fakeProductPages($this->warehouse([1421521 => 40]));

        $this->adapter()->syncInventory();

        $this->assertSame($status->isPreSubmission() ? 38 : 40, $this->stockOf(1), '40 in the warehouse, minus the 2 units of an order that is not with it yet');
    }

    public function test_a_cancelled_line_or_a_finished_order_holds_nothing_back(): void
    {
        $this->stockSync();
        $this->torodFulfillment();
        $this->fakeProductPages($this->warehouse([1421521 => 40]));

        ProviderFulfillmentItem::where('order_id', 100)->where('provider', 'torod_point')->update(['status' => ProviderFulfillmentItem::STATUS_CANCELLED]);
        $this->adapter()->syncInventory();
        $this->assertSame(40, $this->stockOf(1), 'a cancelled line');

        ProviderFulfillmentItem::where('order_id', 100)->where('provider', 'torod_point')->update(['status' => ProviderFulfillmentItem::STATUS_ROUTED]);
        $this->adapter()->syncInventory();
        $this->assertSame(38, $this->stockOf(1));

        DB::table('orders')->where('id', 100)->update(['order_status' => 'delivered']);
        $this->adapter()->syncInventory();
        $this->assertSame(40, $this->stockOf(1), 'a finished order');
    }

    public function test_units_the_checkout_already_took_are_not_taken_again(): void
    {
        $this->stockSync();
        $this->torodFulfillment();
        DB::table('products')->where('id', 1)->update(['current_stock' => 38]);
        $this->fakeProductPages($this->warehouse([1421521 => 40]));

        $first = $this->adapter()->syncInventory();
        $second = $this->adapter()->syncInventory();

        $this->assertSame(38, $this->stockOf(1), 'the sync SETS the figure; it does not subtract from what the checkout already subtracted');
        $this->assertSame([0, 1], [$first['stats']['updated'], $first['stats']['unchanged']]);
        $this->assertSame([0, 1], [$second['stats']['updated'], $second['stats']['unchanged']]);
        $this->assertSame(0, $this->movements());
    }

    public function test_syncing_twice_changes_it_once_and_leaves_one_movement_with_its_balance(): void
    {
        $this->stockSync();
        $this->mappedProduct(1, stock: 50);
        $this->fakeProductPages($this->warehouse([1 => 40]));

        $first = $this->adapter()->syncInventory();
        $second = $this->adapter()->syncInventory();

        $this->assertSame(1, $first['stats']['updated']);
        $this->assertSame(1, $second['stats']['unchanged']);
        $this->assertSame(1, $this->movements());
        $movement = DB::table('stock_movements')->first();
        $this->assertSame([-10, 40, 'provider_sync'], [(int) $movement->qty_change, (int) $movement->balance_after, $movement->reason]);
    }

    public function test_cancelling_an_order_the_warehouse_never_took_gives_its_units_back_on_the_next_sync(): void
    {
        $this->stockSync();
        $this->torodFulfillment();
        $this->fakeProductPages($this->warehouse([1421521 => 40]));
        $this->adapter()->syncInventory();
        $this->assertSame(38, $this->stockOf(1));

        DB::table('orders')->where('id', 100)->update(['order_status' => 'canceled']);
        $this->adapter()->syncInventory();

        $this->assertSame(40, $this->stockOf(1));
    }

    // ---- the product form and the manual entry -------------------------------------------------------------------------------------------

    /** The warehouse has spoken for every mapped product: its figure is recent. */
    private function freshlySynced(): void
    {
        ProviderProductMapping::query()->update(['provider_stock' => 40, 'provider_stock_synced_at' => now(), 'stock_source' => StockSource::ProviderSync->value]);
    }

    private function controlled(int $stock = 37): void
    {
        $this->stockSync();
        $this->mappedProduct(1, stock: $stock);
        $this->freshlySynced();
    }

    public function test_the_product_form_cannot_replace_the_stock_the_warehouse_sets(): void
    {
        $this->controlled();
        $ownership = app(ProviderStockOwnership::class);

        $kept = $ownership->keepProviderStock(['current_stock' => 999, 'name' => 'Renamed', 'variation' => '[]'], DB::table('products')->where('id', 1)->first());

        $this->assertArrayNotHasKey('current_stock', $kept, 'the stock is not written at all, so a checkout that happened meanwhile is not undone');
        $this->assertSame('Renamed', $kept['name'], 'everything else the form says is still saved');
    }

    public function test_while_the_warehouse_does_not_control_the_stock_the_typed_figure_is_kept(): void
    {
        $this->stockSync(switchedOn: false);
        $this->mappedProduct(1, stock: 37);

        $kept = app(ProviderStockOwnership::class)->keepProviderStock(['current_stock' => 999, 'variation' => '[]'], DB::table('products')->where('id', 1)->first());

        $this->assertSame(999, $kept['current_stock']);
    }

    public function test_a_product_that_is_not_assigned_keeps_whatever_the_form_says(): void
    {
        $this->stockSync();
        $this->mappedProduct(1, stock: 37, assignedTo: null);

        $kept = app(ProviderStockOwnership::class)->keepProviderStock(['current_stock' => 999, 'variation' => '[]'], DB::table('products')->where('id', 1)->first());

        $this->assertSame(999, $kept['current_stock']);
    }

    public function test_variants_keep_the_warehouse_figures_and_a_new_variant_takes_the_form(): void
    {
        $this->stockSync();
        $this->makeProduct(1, ProviderCode::TorodPoint, ['current_stock' => 10, 'variation' => json_encode([
            ['type' => 'Blue', 'price' => 10, 'qty' => 7], ['type' => 'Red', 'price' => 10, 'qty' => 3],
        ])]);
        foreach (['Blue', 'Red'] as $type) {
            ProviderProductMapping::create(['provider' => 'torod_point', 'product_id' => 1, 'variant_key' => $type, 'external_id' => '1', 'external_variant_id' => 'V-' . $type, 'status' => MappingStatus::Approved]);
        }
        $this->freshlySynced();
        $submitted = json_encode([
            ['type' => 'Blue', 'price' => 12, 'qty' => 99], ['type' => 'Red', 'price' => 10, 'qty' => 98], ['type' => 'Green', 'price' => 10, 'qty' => 5],
        ]);

        $kept = app(ProviderStockOwnership::class)->keepProviderStock(['current_stock' => 202, 'variation' => $submitted], DB::table('products')->where('id', 1)->first());

        $variations = json_decode($kept['variation'], true);
        $this->assertSame([7, 3, 5], array_column($variations, 'qty'));
        $this->assertSame(12, $variations[0]['price'], 'a price typed in the form is still saved');
        $this->assertSame(15, $kept['current_stock']);
    }

    public function test_a_product_whose_variants_were_switched_off_takes_the_typed_stock(): void
    {
        $this->stockSync();
        $this->makeProduct(1, ProviderCode::TorodPoint, ['current_stock' => 30, 'variation' => '[]']);
        ProviderProductMapping::create(['provider' => 'torod_point', 'product_id' => 1, 'variant_key' => 'Blue', 'external_id' => '1', 'external_variant_id' => 'V', 'status' => MappingStatus::Approved]);
        $this->freshlySynced();

        $kept = app(ProviderStockOwnership::class)->keepProviderStock(['current_stock' => 12, 'variation' => '[]'], DB::table('products')->where('id', 1)->first());

        $this->assertSame(12, $kept['current_stock'], 'the mapped variants are gone: nothing here is the warehouse\'s to set');
        $this->assertNull(app(ProviderStockOwnership::class)->controllingProvider(1));
    }

    public function test_a_whole_product_mapping_on_a_product_with_variants_is_not_controlled(): void
    {
        $this->stockSync();
        $this->makeProduct(1, ProviderCode::TorodPoint, ['current_stock' => 10, 'variation' => json_encode([['type' => 'Blue', 'price' => 10, 'qty' => 7], ['type' => 'Red', 'price' => 10, 'qty' => 3]])]);
        ProviderProductMapping::create(['provider' => 'torod_point', 'product_id' => 1, 'variant_key' => '', 'external_id' => '1', 'external_variant_id' => '', 'status' => MappingStatus::Approved]);
        $this->freshlySynced();
        $submitted = ['current_stock' => 99, 'variation' => json_encode([['type' => 'Blue', 'price' => 10, 'qty' => 50], ['type' => 'Red', 'price' => 10, 'qty' => 49]])];

        $this->assertNull(app(ProviderStockOwnership::class)->controllingProvider(1), 'one figure cannot be spread over variants, so the sync does not write it either');
        $this->assertSame($submitted, app(ProviderStockOwnership::class)->keepProviderStock($submitted, DB::table('products')->where('id', 1)->first()));
    }

    public function test_stock_goes_back_to_manual_when_the_warehouse_stops_answering_for_it(): void
    {
        $this->controlled();
        $ownership = app(ProviderStockOwnership::class);
        $this->assertSame(ProviderCode::TorodPoint, $ownership->controllingProvider(1));

        $this->travel(TorodPointAdapter::STOCK_FRESH_HOURS + 1)->hours();
        $this->assertNull($ownership->controllingProvider(1), 'a figure nobody has refreshed for hours is not the word on the stock');
        $this->assertTrue(app(ProviderMappingService::class)->setManualStock(ProviderProductMapping::first()->id, 3));
        $this->freshlySynced();
        $this->assertSame(ProviderCode::TorodPoint, $ownership->controllingProvider(1));
    }

    public function test_a_figure_a_person_typed_does_not_hand_the_product_back_to_the_warehouse(): void
    {
        $this->controlled();
        $ownership = app(ProviderStockOwnership::class);
        $this->travel(TorodPointAdapter::STOCK_FRESH_HOURS + 1)->hours();
        $this->assertNull($ownership->controllingProvider(1));

        $this->assertTrue(app(ProviderMappingService::class)->setManualStock(ProviderProductMapping::first()->id, 3));

        $this->assertNull($ownership->controllingProvider(1), 'the warehouse has not spoken: the typed figure is the only stock there is');
        $this->assertSame(999, $ownership->keepProviderStock(['current_stock' => 999, 'variation' => '[]'], DB::table('products')->where('id', 1)->first())['current_stock']);
        $this->assertTrue(app(InventoryService::class)->adjust(productId: 1, delta: 5, reason: 'count_correction')['ok']);
    }

    public function test_a_long_sync_interval_keeps_a_figure_in_force_until_the_run_that_follows_it(): void
    {
        $this->controlled();
        $ownership = app(ProviderStockOwnership::class);
        $this->enableProvider(ProviderCode::TorodPoint, ['inventory_sync_interval_minutes' => 720, 'inventory_sync_enabled' => true, 'company_id' => '714', 'dry_run' => false]);

        $this->travel(11)->hours();
        $this->assertSame(ProviderCode::TorodPoint, $ownership->controllingProvider(1), 'a run every twelve hours has not missed anything after eleven');

        $this->travel(9)->hours();
        $this->assertSame(ProviderCode::TorodPoint, $ownership->controllingProvider(1), 'nor a run that is hours late');

        $this->travel(17)->hours();
        $this->assertNull($ownership->controllingProvider(1), 'three whole intervals without a figure: the warehouse stopped answering');

        $this->enableProvider(ProviderCode::TorodPoint, ['inventory_sync_interval_minutes' => 30, 'inventory_sync_enabled' => true, 'company_id' => '714', 'dry_run' => false]);
        $this->freshlySynced();
        $this->travel(TorodPointAdapter::STOCK_FRESH_HOURS * 60 + 1)->minutes();
        $this->assertNull($ownership->controllingProvider(1), 'with the default interval six hours is the limit');
    }

    public function test_a_product_form_that_dropped_the_typed_figure_says_so(): void
    {
        $controller = (string) file_get_contents(app_path('Http/Controllers/Admin/Product/ProductController.php'));

        $this->assertStringContainsString("ToastMagic::warning(translate('stock_is_set_by_the_warehouse_sync'));", $controller);
    }

    public function test_a_product_switched_to_digital_is_left_alone_by_the_form_guard(): void
    {
        $this->controlled();
        $submitted = ['product_type' => 'digital', 'current_stock' => 999999999, 'variation' => '[]'];

        $this->assertSame($submitted, app(ProviderStockOwnership::class)->keepProviderStock($submitted, DB::table('products')->where('id', 1)->first()));
    }

    public function test_the_form_guard_does_not_write_back_a_stock_that_changed_while_the_form_was_being_processed(): void
    {
        $this->controlled(stock: 10);
        DB::table('products')->where('id', 1)->update(['current_stock' => 8]);

        $kept = app(ProviderStockOwnership::class)->keepProviderStock(['current_stock' => 999, 'variation' => '[]'], (object) ['id' => 1, 'current_stock' => 10]);

        $this->assertArrayNotHasKey('current_stock', $kept);
        $this->assertSame(8, $this->stockOf(1), 'what a checkout took meanwhile stays taken');
    }

    public function test_an_inventory_adjustment_stands_aside_for_a_product_the_warehouse_controls(): void
    {
        $this->controlled(stock: 40);
        $this->makeProduct(2, null, ['current_stock' => 40]);

        $refused = app(InventoryService::class)->adjust(productId: 1, delta: 25, reason: 'count_correction');
        $accepted = app(InventoryService::class)->adjust(productId: 2, delta: 25, reason: 'count_correction');

        $this->assertSame(['ok' => false, 'reason' => 'stock_is_set_by_the_warehouse_sync'], $refused);
        $this->assertSame(40, $this->stockOf(1));
        $this->assertTrue($accepted['ok']);
        $this->assertSame(65, $this->stockOf(2));
    }

    public function test_a_typed_stock_figure_is_refused_for_a_mapping_the_warehouse_controls_and_accepted_otherwise(): void
    {
        $this->controlled();
        $mapping = ProviderProductMapping::first();

        $this->assertFalse(app(ProviderMappingService::class)->setManualStock($mapping->id, 5));
        $this->assertSame(40, $mapping->fresh()->provider_stock, 'the warehouse figure is still the one on the mapping');

        $this->enableProvider(ProviderCode::TorodPoint, ['inventory_sync_enabled' => false, 'company_id' => '714', 'dry_run' => false]);

        $this->assertTrue(app(ProviderMappingService::class)->setManualStock($mapping->id, 5));
        $this->assertSame(5, $mapping->fresh()->provider_stock);
        $this->assertSame(StockSource::Manual, $mapping->fresh()->stock_source);
    }

    public function test_the_product_panel_says_when_the_warehouse_controls_the_stock(): void
    {
        $this->controlled();

        $this->assertSame(ProviderCode::TorodPoint, app(ProductFulfillmentPanel::class)->for(1)['stockControlledBy']);
        $this->assertNull(app(ProductFulfillmentPanel::class)->for(null)['stockControlledBy']);

        $this->enableProvider(ProviderCode::TorodPoint, ['inventory_sync_enabled' => false, 'company_id' => '714', 'dry_run' => false]);
        $this->assertNull(app(ProductFulfillmentPanel::class)->for(1)['stockControlledBy']);
    }

    public function test_a_product_owned_by_m5azn_is_never_taken_over_by_this_rule(): void
    {
        $this->app->instance(FakeM5aznAdapter::class, new class extends FakeM5aznAdapter {
            public function readiness(): ReadinessReport
            {
                return new ReadinessReport(true, [], [
                    'dispatch' => new CapabilityState('dispatch', CapabilityState::ENABLED),
                    'inventory_sync' => new CapabilityState('inventory_sync', CapabilityState::ENABLED),
                ]);
            }
        });
        $this->makeProduct(5, ProviderCode::M5azn, ['current_stock' => 30]);
        $mapping = ProviderProductMapping::create(['provider' => 'm5azn', 'product_id' => 5, 'variant_key' => '', 'external_id' => '5', 'external_variant_id' => '', 'status' => MappingStatus::Approved]);
        $ownership = app(ProviderStockOwnership::class);

        $this->assertNull($ownership->controllingProvider(5), 'M5AZN\'s stock has its own flow, which this change leaves alone');
        $this->assertSame(99, $ownership->keepProviderStock(['current_stock' => 99, 'variation' => '[]'], DB::table('products')->where('id', 5)->first())['current_stock']);
        $this->assertNull(app(ProductFulfillmentPanel::class)->for(5)['stockControlledBy']);
        $this->assertTrue(app(ProviderMappingService::class)->setManualStock($mapping->id, 4));
    }

    public function test_the_admin_product_update_goes_through_the_stock_guard(): void
    {
        $controller = (string) file_get_contents(app_path('Http/Controllers/Admin/Product/ProductController.php'));

        $this->assertStringContainsString('$dataArray = app(ProviderStockOwnership::class)->keepProviderStock(update: $dataArray, product: $product);', $controller);
        $this->assertStringContainsString('if ($dataArray !== $submitted) {', $controller, 'and the page says that the typed figure was ignored');
        $this->assertStringContainsString('if (app(ProviderStockOwnership::class)->controllingProvider((int) $request[\'product_id\']) !== null) {', $controller, 'and so does the Update stock action');
    }

    public function test_nothing_in_this_flow_sends_a_write_to_the_provider(): void
    {
        $this->controlled();
        $this->fakeProductPages($this->warehouse([1 => 40]));

        $this->adapter()->syncInventory();

        foreach (Http::recorded() as [$request]) {
            $this->assertSame('GET', $request->method(), 'reading the product list is all a stock sync does');
        }
    }
}
