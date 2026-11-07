<?php

namespace Tests\Feature\OperationalCosts;

use App\Models\ExternalOrderMapping;
use App\Models\InventoryCostLayer;
use App\Models\InventoryLocation;
use App\Models\InventoryReservation;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\OperationalCosts\Data\LayerReceipt;
use App\Services\OperationalCosts\InventoryCostLayerService;
use App\Services\OperationalCosts\PurchaseOrders\PlatformReconciliation;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsPlatformProcurementSchema;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\FakeCurrencyRates;
use Tests\TestCase;

/**
 * The read-only reconciliation of the cost layers with the platform's purchase orders, stock and store orders. The platform's tables here are INFERRED
 * (tests/Support/BuildsPlatformProcurementSchema.php); every figure is made up.
 */
class PlatformReconciliationTest extends TestCase
{
    use BuildsPlatformProcurementSchema;
    use BuildsSalesChannelSchema;

    private InventoryLocation $own;

    private InventoryLocation $torod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->buildPlatformProcurementSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'fulfillment.currency' => 'SAR']);
        [$this->torod, , $this->own] = app(InventoryLocationService::class)->ensureDefaults();
    }

    /** @return array<string, mixed> */
    private function report(int $limit = 50, ?int $product = null): array
    {
        return app(PlatformReconciliation::class)->report(limit: $limit, productId: $product);
    }

    private function openLayer(int $product, int $units, string $reference = 'inv-1', ?InventoryLocation $at = null): InventoryCostLayer
    {
        return app(InventoryCostLayerService::class)->receive(new LayerReceipt(
            locationId: ($at ?? $this->own)->id, productId: $product, quantity: $units, unitCost: Money::of('10'), currency: 'SAR', receivedAt: CarbonImmutable::parse('2026-11-01'),
            sourceType: 'purchase_invoice', identity: $reference . ':' . $product, supplierName: 'Layer Supplier',
        ));
    }

    private function openStoreOrder(int $orderId, int $product, int $quantity, string $status = 'confirmed'): void
    {
        DB::table('orders')->insert(['id' => $orderId, 'order_status' => $status, 'payment_status' => 'paid', 'order_amount' => 0, 'seller_is' => 'admin', 'created_at' => '2026-11-05 10:00:00', 'updated_at' => '2026-11-05 10:00:00']);
        DB::table('order_details')->insert(['order_id' => $orderId, 'product_id' => $product, 'qty' => $quantity, 'price' => 10, 'discount' => 0, 'tax' => 0]);
    }

    /** @return array<string, int> the number of rows of every table of the database */
    private function rowCounts(): array
    {
        $counts = [];
        foreach (Schema::getTables(schema: Schema::getCurrentSchemaListing()) as $table) {
            $counts[$table['name']] = DB::table($table['name'])->count();
        }
        ksort($counts);

        return $counts;
    }

    /** @return list<string> the statements run while `$work` ran */
    private function statementsOf(callable $work): array
    {
        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });
        $work();

        return $statements;
    }

    private function assertOnlyReads(array $statements): void
    {
        $this->assertNotEmpty($statements);
        foreach ($statements as $sql) {
            $this->assertMatchesRegularExpression('/^\s*\(?\s*(select|pragma|show)\b/i', $sql, 'A read-only report ran: ' . $sql);
            $this->assertDoesNotMatchRegularExpression('/\bfor\s+update\b|\block\s+in\s+share\s+mode\b/i', $sql, 'A read-only report must not lock platform rows: ' . $sql);
        }
    }

    public function test_the_layers_are_compared_with_the_platform_stock_plus_the_store_units_that_left_it_and_are_not_delivered(): void
    {
        $balanced = $this->platformProduct(['name' => 'Balanced', 'current_stock' => 7]);
        $this->openLayer($balanced, 10);
        $this->openStoreOrder(900, $balanced, 3);
        $this->openStoreOrder(901, $balanced, 4, status: 'delivered');
        $this->openStoreOrder(902, $balanced, 5, status: 'canceled');

        $above = $this->platformProduct(['name' => 'Layers above', 'current_stock' => 5]);
        $this->openLayer($above, 8);
        $below = $this->platformProduct(['name' => 'Layers below', 'current_stock' => 4]);
        $this->openLayer($below, 1);

        $report = $this->report()['products'];

        $this->assertSame(3, $report['examined']);
        $this->assertSame([1, 1, 1], [$report['balanced'], $report['layers_above_platform'], $report['layers_below_platform']]);
        $rows = array_column($report['rows'], null, 'product_id');
        $this->assertArrayNotHasKey($balanced, $rows, 'A product whose layers equal its stock plus the units still on the road is balanced: 7 + 3 = 10.');
        $this->assertSame(['layers_above_platform', 5, 8, 3], [$rows[$above]['status'], $rows[$above]['platform_stock'], $rows[$above]['layer_units'], $rows[$above]['difference']]);
        $this->assertSame(['layers_below_platform', 4, 1, -3], [$rows[$below]['status'], $rows[$below]['platform_stock'], $rows[$below]['layer_units'], $rows[$below]['difference']]);
        $this->assertSame([$above, $below], array_column($report['rows'], 'product_id'), 'The biggest difference first; the two are equal here, so by id.');
    }

    public function test_a_store_order_made_for_a_channel_order_is_not_a_store_unit_and_the_units_channels_took_are_shown_beside_the_difference(): void
    {
        $product = $this->platformProduct(['current_stock' => 8]);
        $this->openLayer($product, 8);
        $this->openStoreOrder(910, $product, 2);
        ExternalOrderMapping::create(['account_id' => 1, 'sales_channel' => 'amazon', 'seller_id' => 0, 'external_order_id' => 'ORD-1', 'external_package_id' => '', 'status' => 'shipped', 'currency' => 'SAR', 'order_id' => 910]);
        app(InventoryCostLayerService::class)->consume('channel_order_line', 55, $this->own->id, $product, '', 2, CarbonImmutable::parse('2026-11-10'));

        $row = $this->report()['products']['rows'][0];

        $this->assertSame(0, $row['undelivered_store_units'], 'The internal order only carries the channel order: the channel order is the sale.');
        $this->assertSame([6, 8, -2, 2], [$row['layer_units'], $row['expected_layer_units'], $row['difference'], $row['channel_units_taken']], 'The platform never saw the 2 units the channel took: the difference is theirs.');
    }

    public function test_units_an_open_channel_order_holds_of_a_provider_shelf_are_not_a_difference_between_the_layers_and_the_stock(): void
    {
        // The provider sync takes a channel order's hold off the storefront's mirror of the provider's count; the provider still holds the goods and no layer has been consumed for them.
        $product = $this->platformProduct(['current_stock' => 8, 'fulfillment_provider' => 'torod_point']);
        $this->openLayer($product, 10, at: $this->torod);
        InventoryReservation::create([
            'reservation_key' => 'channel-order:1:1', 'location_id' => $this->torod->id, 'product_id' => $product, 'variant_key' => '', 'quantity' => 2,
            'state' => InventoryReservation::RESERVED, 'reserved_at' => CarbonImmutable::parse('2026-11-10'),
        ]);

        $report = $this->report()['products'];

        $this->assertSame([1, 0], [$report['balanced'], $report['layers_above_platform']], '8 shown + 2 the channel holds = the 10 the layers hold');
        $this->assertSame([], $report['rows']);
    }

    public function test_a_hold_at_a_place_no_provider_owns_is_not_added_back(): void
    {
        $product = $this->platformProduct(['current_stock' => 8]);
        $this->openLayer($product, 10);
        InventoryReservation::create([
            'reservation_key' => 'channel-order:2:1', 'location_id' => $this->own->id, 'product_id' => $product, 'variant_key' => '', 'quantity' => 2,
            'state' => InventoryReservation::RESERVED, 'reserved_at' => CarbonImmutable::parse('2026-11-10'),
        ]);

        $row = $this->report()['products']['rows'][0];

        $this->assertSame([0, 8, 2], [$row['channel_units_held'], $row['expected_layer_units'], $row['difference']], 'the store\'s own stock is not lowered for a channel hold, so nothing is added back');
    }

    public function test_platform_stock_with_no_layer_and_negative_platform_stock_are_listed_and_a_product_assigned_to_m5azn_is_left_out(): void
    {
        $covered = $this->platformProduct(['current_stock' => 5]);
        $this->openLayer($covered, 5);
        $bare = $this->platformProduct(['name' => 'Bare', 'current_stock' => 12]);
        $this->platformProduct(['name' => 'Sold out', 'current_stock' => 0]);
        $this->platformProduct(['name' => 'Supplier stock', 'current_stock' => 40, 'fulfillment_provider' => 'm5azn']);
        $this->platformProduct(['name' => 'Digital', 'current_stock' => 999999999, 'product_type' => 'digital']);
        $this->platformProduct(['name' => 'Vendor product', 'current_stock' => 9, 'added_by' => 'seller']);
        $negative = $this->platformProduct(['name' => 'Oversold', 'current_stock' => -3]);

        $report = $this->report();

        $this->assertSame(['products' => 1, 'units' => 12], array_intersect_key($report['stock_without_layers'], ['products' => 1, 'units' => 1]));
        $this->assertSame([$bare], array_column($report['stock_without_layers']['rows'], 'product_id'));
        $this->assertSame(['products' => 1, 'units' => -3], array_intersect_key($report['negative_stock'], ['products' => 1, 'units' => 1]));
        $this->assertSame([$negative], array_column($report['negative_stock']['rows'], 'product_id'));
    }

    public function test_every_received_line_that_cannot_be_layered_says_why_and_never_becomes_a_zero_cost_layer(): void
    {
        $plain = $this->platformProduct(['name' => 'Plain']);
        $torod = $this->platformProduct(['name' => 'At Torod', 'fulfillment_provider' => 'torod_point']);
        $m5azn = $this->platformProduct(['name' => 'M5AZN', 'fulfillment_provider' => 'm5azn']);
        $variants = $this->platformProduct(['name' => 'Variants', 'variation' => [['type' => 'Red', 'qty' => 5]]]);
        $vendorProduct = $this->platformProduct(['name' => 'Vendor product', 'added_by' => 'seller']);
        $digital = $this->platformProduct(['name' => 'Digital', 'product_type' => 'digital']);
        $supplier = $this->supplier();

        $order = $this->purchaseOrder($supplier, [
            [$plain, 10, 12.5], [$torod, 6, 20], [$m5azn, 4, 9], [$variants, 3, 9], [$vendorProduct, 2, 9], [$digital, 2, 9],
            [$plain, 5, 0], [$plain, 5, null], [null, 4, 7.0, 'Delivery of gloves'], [9999, 1, 5],
        ]);
        $vendorOrder = $this->purchaseOrder($this->supplier('Vendor supplier', sellerId: 7), [[$plain, 3, 5]], sellerId: 7);
        $euro = $this->purchaseOrder($supplier, [[$plain, 2, 11]], currency: 'EUR');
        $unknownCurrency = $this->purchaseOrder($supplier, [[$plain, 2, 11]], currency: null);
        foreach ([$order, $vendorOrder, $euro, $unknownCurrency] as $each) {
            foreach ($each->items as $line) {
                $this->receiveOnPlatform($line, $line->qty_ordered);
            }
        }

        $orders = $this->report()['purchase_orders'];

        $reasons = array_map(static fn (array $figures): array => [$figures['lines'], $figures['units']], $orders['never_layered']['by_reason']);
        ksort($reasons);
        $this->assertSame([
            'cost_missing' => [2, 10], 'currency_unknown' => [1, 2], 'm5azn_assigned' => [1, 4], 'no_exchange_rate' => [1, 2], 'no_product' => [2, 5], 'not_physical' => [1, 2],
            'variant_product' => [1, 3], 'vendor_order' => [1, 3], 'vendor_product' => [1, 2],
        ], $reasons);
        $this->assertSame(16, $orders['units_without_layer'], 'Only the product of the platform that is physical, has no variant, a cost, a currency with a rate and a place: 10 here and the 6 at Torod Point.');
        $this->assertSame(2, $orders['lines_without_layer']);
        $this->assertSame(0, $orders['units_layered']);
        $this->assertSame(0, InventoryCostLayer::count(), 'A report writes nothing.');
        $wherePut = array_column($orders['without_layer']['rows'], 'units_without_layer', 'product_id');
        $this->assertSame([$plain => 10, $torod => 6], $wherePut);
    }

    public function test_a_received_line_an_invoice_or_a_typed_cost_may_already_hold_is_reported_as_covered_and_not_as_a_layer_still_to_make(): void
    {
        $product = $this->platformProduct();
        $this->openLayer($product, 5);
        $order = $this->purchaseOrder($this->supplier('Layer Supplier'), [[$product, 5, 5]]);
        $this->receiveOnPlatform($order->items->first(), 5);

        $orders = $this->report()['purchase_orders'];

        $this->assertSame(['covered_by_other_layers' => [1, 5]], array_map(static fn (array $figures): array => [$figures['lines'], $figures['units']], $orders['never_layered']['by_reason']));
        $this->assertSame(0, $orders['units_without_layer'], 'Nothing is left to layer unless a person names the line.');
    }

    public function test_a_receipt_is_dated_from_the_stock_ledger_when_it_adds_up_and_from_the_line_when_it_does_not(): void
    {
        $product = $this->platformProduct();
        $order = $this->purchaseOrder($this->supplier(), [[$product, 10, 5]]);
        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00:00'));
        $line = $this->receiveOnPlatform($order->items->first(), 4);
        $this->travelTo(CarbonImmutable::parse('2026-11-08 15:30:00'));
        $this->receiveOnPlatform($line, 6);

        $row = $this->report()['purchase_orders']['without_layer']['rows'][0];
        $this->assertSame([10, '2026-11-08 15:30:00', false], [$row['units_without_layer'], $row['received_at'], $row['date_is_approximate']], 'The date of the last receipt the ledger recorded, since 4 + 6 is what the line received.');

        DB::table('stock_movements')->where('qty_change', 4)->delete();
        $row = $this->report()['purchase_orders']['without_layer']['rows'][0];
        $this->assertSame([true, '2026-11-08 15:30:00'], [$row['date_is_approximate'], $row['received_at']], 'A receipt the ledger missed: the ledger no longer adds up, so the date is the line\'s own last change, said to be approximate.');
    }

    public function test_a_report_runs_nothing_but_selects_and_every_table_keeps_its_rows(): void
    {
        $product = $this->platformProduct(['current_stock' => 4]);
        $this->openLayer($product, 6);
        $this->openStoreOrder(920, $product, 1);
        $order = $this->purchaseOrder($this->supplier(), [[$product, 5, 5], [null, 1, 1, 'Freight']]);
        $this->receiveOnPlatform($order->items->first(), 5);
        $before = $this->rowCounts();
        $stock = DB::table('products')->where('id', $product)->value('current_stock');

        $statements = $this->statementsOf(function (): void {
            $this->report();
            Artisan::call('costs:reconcile-platform', ['--json' => true]);
            Artisan::call('costs:reconcile-platform');
        });

        $this->assertOnlyReads($statements);
        $this->assertSame($before, $this->rowCounts());
        $this->assertSame(9, (int) $stock, 'The receipt raised the platform\'s 4 units by 5.');
        $this->assertSame($stock, DB::table('products')->where('id', $product)->value('current_stock'), 'And the report left them as they were.');
    }

    public function test_the_tables_that_come_from_the_dump_being_absent_makes_the_report_say_so_and_do_nothing(): void
    {
        Schema::drop('purchase_order_items');
        Schema::drop('suppliers');

        $report = $this->report();

        $this->assertFalse($report['available']);
        $this->assertSame(['purchase_order_items'], $report['missing']);
        $this->assertStringContainsString('purchase_order_items', $report['message']);

        $this->artisan('costs:reconcile-platform')->expectsOutputToContain('purchase_order_items')->assertExitCode(0);
        $this->assertFalse(json_decode($this->jsonOutput(), true)['available']);
    }

    public function test_a_missing_column_is_named_and_a_missing_optional_table_only_costs_the_names_and_dates(): void
    {
        Schema::table('purchase_order_items', static fn ($table) => $table->dropColumn('qty_received'));
        $this->assertSame(['purchase_order_items.qty_received'], $this->report()['missing']);

        Schema::drop('purchase_order_items');
        Schema::drop('stock_movements');
        Schema::create('purchase_order_items', static function ($table): void {
            $table->id();
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedInteger('qty_received')->default(0);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->timestamps();
        });
        Schema::drop('suppliers');
        $product = $this->platformProduct();
        DB::table('purchase_orders')->insert(['id' => 1, 'reference' => 'PO-1', 'supplier_id' => 3, 'status' => 'ordered', 'currency' => 'SAR']);
        DB::table('purchase_order_items')->insert(['purchase_order_id' => 1, 'product_id' => $product, 'qty_received' => 4, 'unit_cost' => '5.00', 'updated_at' => '2026-11-04 10:00:00']);

        $orders = $this->report()['purchase_orders'];

        $this->assertSame(4, $orders['units_without_layer']);
        $this->assertNull($orders['without_layer']['rows'][0]['supplier']);
        $this->assertTrue($orders['without_layer']['rows'][0]['date_is_approximate'], 'With no stock ledger there is no receipt date to take.');
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> the core columns the report reads, and whether losing one leaves the store orders out of the comparison */
    public static function coreColumnsTheReportReads(): array
    {
        return [
            'products.name' => ['products', 'name', false],
            'orders.order_status' => ['orders', 'order_status', true],
            'orders.seller_is' => ['orders', 'seller_is', true],
            'order_details.qty' => ['order_details', 'qty', true],
            'order_details.product_id' => ['order_details', 'product_id', true],
        ];
    }

    #[DataProvider('coreColumnsTheReportReads')]
    public function test_a_core_column_of_the_platform_that_is_not_there_is_skipped_and_named_and_never_an_exception(string $table, string $column, bool $leavesStoreOrdersOut): void
    {
        $product = $this->platformProduct(['name' => 'Gauze', 'current_stock' => 4]);
        $this->openLayer($product, 6);
        $order = $this->purchaseOrder($this->supplier(), [[$product, 10, 12.5]]);
        $this->receiveOnPlatform($order->items->first(), 10);
        Schema::table($table, static fn ($blueprint) => $blueprint->dropColumn($column));

        $report = $this->report();

        $this->assertTrue($report['available']);
        $this->assertSame($leavesStoreOrdersOut ? ['store_orders' => ["{$table}.{$column}"]] : [], $report['skipped']);
        $this->assertSame(10, $report['purchase_orders']['units_received'], 'Everything the columns that are there say is still reported.');
        $this->artisan('costs:reconcile-platform')->expectsOutputToContain($leavesStoreOrdersOut ? "{$table}.{$column} is not there" : 'Nothing was written')->assertExitCode(0);
        $this->artisan('costs:reconcile-platform', ['--json' => true])->assertExitCode(0);
    }

    public function test_a_report_over_the_whole_platform_skips_nothing(): void
    {
        $this->assertSame([], $this->report()['skipped']);
    }

    public function test_the_command_obeys_the_module_switch_and_the_migrations(): void
    {
        config(['operational_costs.enabled' => false]);
        $this->artisan('costs:reconcile-platform')->expectsOutputToContain('Operational costs are switched off')->assertExitCode(0);

        config(['operational_costs.enabled' => true]);
        Schema::drop('provider_fulfillments');
        $this->artisan('costs:reconcile-platform')->expectsOutputToContain('migrations have not been run')->assertExitCode(0);
    }

    public function test_the_json_report_carries_the_same_figures_as_the_service_and_the_command_prints_a_table_of_them(): void
    {
        $product = $this->platformProduct(['name' => 'Gauze', 'current_stock' => 2]);
        $this->openLayer($product, 5);
        $order = $this->purchaseOrder($this->supplier(), [[$product, 5, 5]]);
        $this->receiveOnPlatform($order->items->first(), 5);

        $decoded = json_decode($this->jsonOutput(), true);

        $this->assertTrue($decoded['available']);
        $this->assertSame($this->report()['products']['rows'], $decoded['products']['rows']);
        $this->assertSame(5, $decoded['purchase_orders']['units_without_layer']);
        $this->artisan('costs:reconcile-platform')->expectsOutputToContain('Gauze')->expectsOutputToContain('Nothing was written')->assertExitCode(0);
    }

    public function test_a_single_product_and_a_row_limit_narrow_the_listings_but_the_totals_stay_whole(): void
    {
        $products = [$this->platformProduct(['current_stock' => 1]), $this->platformProduct(['current_stock' => 2]), $this->platformProduct(['current_stock' => 3])];
        foreach ($products as $product) {
            $this->openLayer($product, 10);
        }

        $limited = $this->report(limit: 2)['products'];
        $this->assertSame([3, 2, true], [$limited['layers_above_platform'], count($limited['rows']), $limited['truncated']]);
        $this->assertSame($products[0], $limited['rows'][0]['product_id'], 'The product that is furthest off first.');

        $one = $this->report(product: $products[1])['products'];
        $this->assertSame([1, [$products[1]]], [$one['examined'], array_column($one['rows'], 'product_id')]);
        $this->artisan('costs:reconcile-platform', ['--product' => 'abc'])->expectsOutputToContain('must be an id')->assertExitCode(1);
    }

    private function jsonOutput(): string
    {
        Artisan::call('costs:reconcile-platform', ['--json' => true]);

        return Artisan::output();
    }
}
