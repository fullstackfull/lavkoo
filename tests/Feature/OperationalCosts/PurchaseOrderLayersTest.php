<?php

namespace Tests\Feature\OperationalCosts;

use App\Console\ScheduleDefinition;
use App\Models\InventoryCostLayer;
use App\Models\InventoryLocation;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\Marketplace\PurchaseOrderService;
use App\Services\OperationalCosts\Data\LayerReceipt;
use App\Services\OperationalCosts\InventoryCostLayerService;
use App\Services\OperationalCosts\PurchaseOrders\LayerRun;
use App\Services\OperationalCosts\PurchaseOrders\LinePlan;
use App\Services\OperationalCosts\PurchaseOrders\LineVerdict;
use App\Services\OperationalCosts\PurchaseOrders\PlatformProcurement;
use App\Services\OperationalCosts\PurchaseOrders\PlatformReconciliation;
use App\Services\OperationalCosts\PurchaseOrders\PurchaseOrderLayerService;
use App\Services\OperationalCosts\PurchaseOrders\PurchaseOrderSourceMode;
use App\Services\OperationalCosts\PurchaseOrders\Tranche;
use App\Services\OperationalCosts\PurchaseOrders\TrancheOutcome;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsAuditLogTable;
use Tests\Support\BuildsPlatformProcurementSchema;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\FakeCurrencyRates;
use Tests\TestCase;

/**
 * FIFO layers made from the goods the platform received on its purchase orders. Receipts go through the platform's own PurchaseOrderService; the platform's tables are INFERRED
 * (tests/Support/BuildsPlatformProcurementSchema.php). Every figure is made up.
 */
class PurchaseOrderLayersTest extends TestCase
{
    use BuildsAuditLogTable;
    use BuildsPlatformProcurementSchema;
    use BuildsSalesChannelSchema;

    private InventoryLocation $own;

    private InventoryLocation $torod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->buildPlatformProcurementSchema();
        $this->buildAuditLogTable();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'fulfillment.currency' => 'SAR', 'operational_costs.enabled' => true, 'operational_costs.sources.purchase_orders' => 'on']);
        [$this->torod, , $this->own] = app(InventoryLocationService::class)->ensureDefaults();
    }

    private function service(): PurchaseOrderLayerService
    {
        return app(PurchaseOrderLayerService::class);
    }

    /** The cutover far in the past: every unit ever received counts. */
    private function apply(?string $since = '2000-01-01'): LayerRun
    {
        return $this->service()->apply(since: CarbonImmutable::parse($since));
    }

    /** @return array{0: int, 1: PurchaseOrderItem} a product and the line of a new order for it, nothing received yet */
    private function line(int $ordered = 10, float|null $cost = 12.5, array $product = [], ?string $currency = 'SAR', ?Supplier $supplier = null): array
    {
        $productId = $this->platformProduct($product);
        $order = $this->purchaseOrder($supplier ?? $this->supplier('Acme Pharma Supply'), [[$productId, $ordered, $cost]], currency: $currency);

        return [$productId, $order->items->first()];
    }

    private function receivedLine(int $quantity = 10, float|null $cost = 12.5, array $product = [], ?string $currency = 'SAR'): array
    {
        [$productId, $line] = $this->line($quantity, $cost, $product, $currency);

        return [$productId, $this->receiveOnPlatform($line, $quantity)];
    }

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        $counts = [];
        foreach (Schema::getTables(schema: Schema::getCurrentSchemaListing()) as $table) {
            $counts[$table['name']] = DB::table($table['name'])->count();
        }
        ksort($counts);

        return $counts;
    }

    /** @return list<string> */
    private function statementsOf(callable $work): array
    {
        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });
        $work();

        return $statements;
    }

    public function test_one_receipt_is_one_layer_with_the_orders_reference_the_supplier_the_cost_the_place_and_the_date_of_the_receipt(): void
    {
        $supplier = $this->supplier('Acme Pharma Supply');
        $product = $this->platformProduct();
        $order = $this->purchaseOrder($supplier, [[$product, 10, 12.5]]);
        $this->travelTo(CarbonImmutable::parse('2026-11-08 15:30:00'));
        $line = $this->receiveOnPlatform($order->items->first(), 10);

        $run = $this->apply();

        $layer = InventoryCostLayer::sole();
        $this->assertSame([1, 10, 0, 0], [$run->created, $run->createdUnits, $run->alreadyMade, $run->upToDate]);
        $this->assertSame(
            ['purchase_order', $line->id, $order->reference, 0, 10, 10, 10, '12.500000', 'SAR', $this->own->id, 0, $supplier->id, 'Acme Pharma Supply', 'stock_movement', 'open'],
            [$layer->source_type, $layer->source_id, $layer->source_ref, $layer->source_from, $layer->source_to, $layer->quantity_received, $layer->quantity_remaining, $layer->unit_cost, $layer->currency, $layer->inventory_location_id, $layer->seller_id, $layer->supplier_id, $layer->supplier_name, $layer->received_at_basis, $layer->status],
        );
        $this->assertSame('2026-11-08 15:30:00', $layer->received_at->toDateTimeString(), 'The day the platform logged the receipt.');
        $this->assertSame(10, (int) DB::table('products')->where('id', $product)->value('current_stock'), 'The platform\'s stock is as the receipt left it.');
    }

    public function test_running_it_again_makes_nothing(): void
    {
        $this->receivedLine();
        $this->apply();
        $before = $this->rowCounts();

        $again = $this->apply();

        $this->assertSame([0, 0, 1], [$again->created, $again->alreadyMade, $again->upToDate]);
        $this->assertSame(1, InventoryCostLayer::count());
        $this->assertSame(array_diff_key($before, ['audit_logs' => 1]), array_diff_key($this->rowCounts(), ['audit_logs' => 1]));
    }

    public function test_a_line_received_three_then_seven_is_two_layers_each_dated_by_its_receipt_and_never_a_second_layer_for_the_same_units(): void
    {
        [$product, $line] = $this->line(10);
        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00:00'));
        $line = $this->receiveOnPlatform($line, 3);
        $this->assertSame(1, $this->apply()->created);

        $this->travelTo(CarbonImmutable::parse('2026-11-09 11:15:00'));
        $this->receiveOnPlatform($line, 7);
        $second = $this->apply();
        $third = $this->apply();

        $layers = InventoryCostLayer::orderBy('source_from')->get();
        $this->assertSame([[0, 3, 3], [3, 10, 7]], $layers->map(fn ($layer): array => [$layer->source_from, $layer->source_to, $layer->quantity_received])->all());
        $this->assertSame(['2026-11-03 09:00:00', '2026-11-09 11:15:00'], $layers->map(fn ($layer): string => $layer->received_at->toDateTimeString())->all());
        $this->assertSame([1, 7, 0], [$second->created, $second->createdUnits, $third->created]);
        $this->assertSame(10, (int) $layers->sum('quantity_received'), 'The units of the line, once.');
    }

    public function test_a_line_received_in_two_steps_before_the_first_run_is_one_layer_of_all_its_units(): void
    {
        [, $line] = $this->line(10);
        $line = $this->receiveOnPlatform($line, 4);
        $this->receiveOnPlatform($line, 6);

        $run = $this->apply();

        $this->assertSame([1, 10], [$run->created, InventoryCostLayer::sole()->quantity_received]);
    }

    public function test_cancelling_an_order_after_a_partial_receipt_keeps_the_layer_of_what_came_in_and_makes_nothing_more(): void
    {
        [, $line] = $this->line(10);
        $line = $this->receiveOnPlatform($line, 4);
        $this->apply();
        app(PurchaseOrderService::class)->cancel($line->purchaseOrder);

        $run = $this->apply();

        $this->assertSame([0, 1], [$run->created, $run->upToDate]);
        $layer = InventoryCostLayer::sole();
        $this->assertSame([4, 4, 'open'], [$layer->quantity_received, $layer->quantity_remaining, $layer->status], 'The platform does not take received stock back when an order is cancelled, so neither does the cost.');
    }

    public function test_an_order_with_nothing_received_makes_nothing_whatever_its_state(): void
    {
        [, $line] = $this->line(10);
        $this->apply();
        app(PurchaseOrderService::class)->cancel($line->purchaseOrder);

        $run = $this->apply();

        $this->assertSame([0, 0], [$run->lines, InventoryCostLayer::count()]);
    }

    public function test_a_quantity_that_falls_below_what_was_layered_is_reported_and_the_layer_is_left_alone(): void
    {
        [, $line] = $this->receivedLine(10);
        $this->apply();
        DB::table('purchase_order_items')->where('id', $line->id)->update(['qty_received' => 6]);

        $run = $this->apply();

        $this->assertSame([1, 0], [$run->receivedBelowLayered, $run->created]);
        $this->assertSame(10, InventoryCostLayer::sole()->quantity_remaining, 'Nothing is reversed on a guess.');
    }

    public function test_a_line_with_no_cost_a_currency_nobody_can_convert_or_no_currency_is_refused_and_never_a_layer_at_zero(): void
    {
        $this->receivedLine(cost: 0);
        $this->receivedLine(cost: null);
        $this->receivedLine(currency: 'EUR');
        $this->receivedLine(currency: null);
        $this->receivedLine(currency: 'KSA');

        $run = $this->apply();

        $refused = $run->refused;
        ksort($refused);
        $this->assertSame(['cost_missing' => 2, 'currency_unknown' => 1, 'no_exchange_rate' => 1], $refused);
        $this->assertSame(1, InventoryCostLayer::count(), 'Only the line in the store\'s own riyal (spelt KSA by this store) is a layer.');
        $this->assertSame('12.500000', InventoryCostLayer::sole()->unit_cost);
    }

    public function test_a_cost_in_another_currency_with_a_rate_is_converted_and_the_original_is_kept(): void
    {
        $this->receivedLine(quantity: 4, cost: 10, currency: 'USD');

        $this->apply();

        $layer = InventoryCostLayer::sole();
        $this->assertSame(['37.500000', 'SAR', 'USD', '10.000000'], [$layer->unit_cost, $layer->currency, $layer->original_currency, $layer->original_unit_cost]);
    }

    public function test_a_description_only_line_a_missing_product_and_a_vendors_order_or_product_are_not_layers(): void
    {
        $supplier = $this->supplier();
        $vendorProduct = $this->platformProduct(['added_by' => 'seller']);
        $order = $this->purchaseOrder($supplier, [[null, 3, 5.0, 'Delivery of gloves'], [9999, 3, 5.0], [$vendorProduct, 3, 5.0]]);
        $vendorsOwn = $this->purchaseOrder($this->supplier('Vendor supplier', sellerId: 4), [[$this->platformProduct(), 3, 5.0]], sellerId: 4);
        foreach ([...$order->items, ...$vendorsOwn->items] as $line) {
            $this->receiveOnPlatform($line, 3);
        }

        $run = $this->apply();

        $this->assertSame(['no_product' => 2, 'vendor_product' => 1, 'vendor_order' => 1], $run->refused);
        $this->assertSame(0, InventoryCostLayer::count());
    }

    public function test_a_product_with_variants_is_refused_because_an_order_line_names_no_variant(): void
    {
        $this->receivedLine(product: ['variation' => [['type' => 'Red', 'qty' => 3, 'sku' => 'R']]]);

        $run = $this->apply();

        $this->assertSame(['variant_product' => 1], $run->refused);
        $this->assertSame(0, InventoryCostLayer::count());
    }

    public function test_a_product_assigned_to_m5azn_is_refused_and_one_assigned_to_torod_point_is_layered_at_its_warehouse_while_the_rest_go_to_the_own_warehouse(): void
    {
        $this->receivedLine(product: ['fulfillment_provider' => 'm5azn']);
        [$atTorod] = $this->receivedLine(product: ['fulfillment_provider' => 'torod_point']);
        [$inHouse] = $this->receivedLine();

        $run = $this->apply();

        $this->assertSame(['m5azn_assigned' => 1], $run->refused);
        $this->assertSame($this->torod->id, InventoryCostLayer::where('product_id', $atTorod)->sole()->inventory_location_id, 'Where a sale of it would take the units from.');
        $this->assertSame($this->own->id, InventoryCostLayer::where('product_id', $inHouse)->sole()->inventory_location_id);
    }

    public function test_a_line_with_no_location_to_put_the_goods_at_is_refused(): void
    {
        $this->receivedLine();
        $this->own->forceFill(['is_active' => false])->save();

        $this->assertSame(['no_location' => 1], $this->apply()->refused);
        $this->assertSame(0, InventoryCostLayer::count());
    }

    public function test_the_same_units_asked_for_at_another_location_find_the_layer_that_is_there_and_make_no_second_one(): void
    {
        [$product, $line] = $this->receivedLine(4);
        $this->apply();
        $plan = new LinePlan(
            line: app(PlatformProcurement::class)->receivedLines(0, 1, lineId: $line->id)[0],
            verdict: LineVerdict::eligible($this->torod->id, Money::of('12.5'), 'SAR'),
            accountedUnits: 0,
            tranche: new Tranche(from: 0, to: 4, receivedAt: CarbonImmutable::now(), dateBasis: Tranche::BASIS_MOVEMENTS),
            reducedBy: 0,
        );

        $outcome = $this->service()->layerTranche($plan);

        $this->assertSame(TrancheOutcome::ALREADY_MADE, $outcome->state);
        $this->assertSame(1, InventoryCostLayer::count());
        $this->assertSame($this->own->id, InventoryCostLayer::sole()->inventory_location_id, 'The layer stays where it was made.');
        $this->assertSame($product, InventoryCostLayer::sole()->product_id);
    }

    public function test_units_asked_for_that_start_at_the_same_place_but_end_elsewhere_are_reported_as_overtaken_not_made_twice(): void
    {
        [, $line] = $this->receivedLine(10);
        $this->apply();
        $plan = new LinePlan(
            line: app(PlatformProcurement::class)->receivedLines(0, 1, lineId: $line->id)[0],
            verdict: LineVerdict::eligible($this->own->id, Money::of('12.5'), 'SAR'),
            accountedUnits: 0,
            tranche: new Tranche(from: 0, to: 3, receivedAt: CarbonImmutable::now(), dateBasis: Tranche::BASIS_MOVEMENTS),
            reducedBy: 0,
        );

        $this->assertSame(TrancheOutcome::OVERTAKEN, $this->service()->layerTranche($plan)->state);
        $this->assertSame(1, InventoryCostLayer::count());
    }

    public function test_a_currency_that_lost_its_rate_between_looking_and_writing_is_refused_and_not_made_at_a_guess(): void
    {
        [, $line] = $this->receivedLine(4);
        $plan = new LinePlan(
            line: app(PlatformProcurement::class)->receivedLines(0, 1, lineId: $line->id)[0],
            verdict: LineVerdict::eligible($this->own->id, Money::of('10'), 'EUR'),
            accountedUnits: 0,
            tranche: new Tranche(from: 0, to: 4, receivedAt: CarbonImmutable::now(), dateBasis: Tranche::BASIS_MOVEMENTS),
            reducedBy: 0,
        );

        $outcome = $this->service()->layerTranche($plan);

        $this->assertSame([TrancheOutcome::REFUSED, 'no_exchange_rate', 0], [$outcome->state, $outcome->refusal->value, InventoryCostLayer::count()]);
    }

    public function test_a_layer_made_from_a_purchase_order_is_used_like_any_other_by_a_sale_first_in_first_out(): void
    {
        [$product, $line] = $this->line(10);
        $line = $this->receiveOnPlatform($line, 4);
        $this->apply();
        $this->receiveOnPlatform($line, 6);
        $this->apply();

        $taken = app(InventoryCostLayerService::class)->consume('order_line', 1, $this->own->id, $product, '', 5, CarbonImmutable::parse('2026-11-12'));

        $this->assertTrue($taken->isComplete());
        $this->assertSame('62.500000', $taken->totalCost->toStorage());
        $this->assertSame([4, 1], array_column($taken->pieces, 'quantity'), 'The first receipt is used up before the second.');
    }

    public function test_the_receipt_the_stock_ledger_does_not_account_for_is_dated_by_the_line_and_marked_approximate(): void
    {
        [, $line] = $this->receivedLine(10);
        DB::table('stock_movements')->delete();

        $run = $this->apply();

        $layer = InventoryCostLayer::sole();
        $this->assertSame(['line_updated_at', 1], [$layer->received_at_basis, $run->approximateDates]);
        $this->assertSame($line->updated_at->toDateTimeString(), $layer->received_at->toDateTimeString());
    }

    public function test_a_cutover_leaves_the_units_received_before_it_to_the_opening_balance(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00'));
        [, $split] = $this->line(10);
        [, $before] = $this->line(5);
        [, $unknown] = $this->line(10);
        $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00:00'));
        $split = $this->receiveOnPlatform($split, 4);
        $before = $this->receiveOnPlatform($before, 5);
        $unknown = $this->receiveOnPlatform($unknown, 4);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00'));
        $this->receiveOnPlatform($split, 6);
        $this->receiveOnPlatform($unknown, 6);
        DB::table('stock_movements')->where('product_id', $unknown->product_id)->where('qty_change', 4)->delete();
        [, $after] = $this->line(8);
        $after = $this->receiveOnPlatform($after, 8);
        DB::table('stock_movements')->where('product_id', $after->product_id)->delete();

        $run = $this->service()->apply(since: CarbonImmutable::parse('2026-11-01'));

        $layers = InventoryCostLayer::orderBy('source_id')->get()->keyBy('source_id');
        $this->assertSame([[4, 10, 6]], [[$layers[$split->id]->source_from, $layers[$split->id]->source_to, $layers[$split->id]->quantity_received]], 'Four units came in on the 25th (before the cutover): the opening balance has them. Six came in on the 5th.');
        $this->assertSame('2026-11-05 10:00:00', $layers[$split->id]->received_at->toDateTimeString());
        $this->assertFalse($layers->has($before->id), 'Everything the line received came in before the cutover.');
        $this->assertFalse($layers->has($unknown->id), 'Part of it came in before, and nothing can say how much.');
        $this->assertSame([0, 8, 'line_updated_at'], [$layers[$after->id]->source_from, $layers[$after->id]->source_to, $layers[$after->id]->received_at_basis], 'A line made after the cutover received nothing before it, ledger or no ledger.');
        $this->assertSame(['date_uncertain' => 1], $run->refused);
        $this->assertSame([2, 1], [$run->created, $run->upToDate]);
    }

    public function test_a_line_whose_layers_already_account_for_everything_is_up_to_date_whatever_the_cutover_date_says_about_its_receipts(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00'));
        [, $line] = $this->line(10);
        $line = $this->receiveOnPlatform($line, 4);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00'));
        $this->receiveOnPlatform($line, 6);
        DB::table('stock_movements')->delete();
        $this->service()->apply(since: CarbonImmutable::parse('2000-01-01'));

        $run = $this->service()->apply(since: CarbonImmutable::parse('2026-11-01'));

        $this->assertSame([0, 1, []], [$run->created, $run->upToDate, $run->refused], 'Nothing is left to layer, so there is nothing to be unsure about.');
    }

    public function test_nothing_is_cut_over_in_a_report_without_a_date_and_every_unit_counts(): void
    {
        $this->receivedLine(10);

        $run = $this->service()->preview();

        $this->assertSame([1, 10], [$run->planned, $run->plannedUnits]);
    }

    public function test_the_reconciliation_follows_the_layers_the_source_makes(): void
    {
        [$product, $line] = $this->line(10);
        $line = $this->receiveOnPlatform($line, 6);
        $before = app(PlatformReconciliation::class)->report();
        $this->apply();
        $line = $this->receiveOnPlatform($line, 4);
        DB::table('purchase_order_items')->where('id', $line->id)->update(['qty_received' => 9]);
        $report = app(PlatformReconciliation::class)->report();

        $this->assertSame([6, 0], [$before['purchase_orders']['units_without_layer'], $before['purchase_orders']['units_layered']]);
        $this->assertSame([9, 6, 3], [$report['purchase_orders']['units_received'], $report['purchase_orders']['units_layered'], $report['purchase_orders']['units_without_layer']]);
        $row = collect($report['products']['rows'])->firstWhere('product_id', $product);
        $this->assertSame([9, 6, 3, 6, 10], [$row['purchase_order_units_received'], $row['purchase_order_units_layered'], $row['purchase_order_units_without_layer'], $row['layer_units'], $row['platform_stock']]);

        DB::table('purchase_order_items')->where('id', $line->id)->update(['qty_received' => 5]);
        $below = app(PlatformReconciliation::class)->report()['purchase_orders']['received_below_layered']['rows'];
        $this->assertSame([[$line->id, 5, 6]], array_map(static fn (array $row): array => [$row['line_id'], $row['units_received'], $row['layered_up_to']], $below));
    }

    public function test_a_supplier_name_and_an_order_reference_longer_than_their_columns_are_cut_to_fit_not_refused_by_a_strict_server(): void
    {
        $supplier = $this->supplier(str_repeat('ش', 200));
        [, $line] = $this->line(2, 5.0, supplier: $supplier);
        $line = $this->receiveOnPlatform($line, 2);
        DB::table('purchase_orders')->where('id', $line->purchase_order_id)->update(['reference' => str_repeat('R', 150)]);

        $this->apply();

        $layer = InventoryCostLayer::sole();
        $this->assertSame([160, 120], [mb_strlen($layer->supplier_name), mb_strlen($layer->source_ref)]);
    }

    public function test_a_run_is_one_audit_line_and_a_preview_is_none(): void
    {
        $this->receivedLine();
        $this->service()->preview();
        $runs = static fn (): int => DB::table('audit_logs')->where('action', 'operational_cost.layers_from_purchase_orders')->count();
        $this->assertSame(0, $runs());

        $this->apply('2026-11-01');

        $this->assertSame(1, $runs());
        $context = json_decode((string) DB::table('audit_logs')->where('action', 'operational_cost.layers_from_purchase_orders')->value('context'), true);
        $this->assertSame([1, 10, '2026-11-01'], [$context['created_layers'], $context['created_units'], $context['since']]);
    }

    // ---- the source's switch ---------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function modes(): array
    {
        return [
            'nothing set' => [null, 'off'], 'off' => ['off', 'off'], 'shadow' => ['shadow', 'shadow'], 'on' => ['on', 'on'], 'on in capitals with spaces' => [' ON ', 'on'],
            'true is not on' => [true, 'off'], 'one is not on' => ['1', 'off'], 'yes is not on' => ['yes', 'off'], 'a typo is off' => ['onn', 'off'], 'enabled is off' => ['enabled', 'off'],
        ];
    }

    #[DataProvider('modes')]
    public function test_only_the_exact_words_shadow_and_on_switch_the_source_on(mixed $configured, string $expected): void
    {
        config(['operational_costs.sources.purchase_orders' => $configured]);

        $this->assertSame($expected, PurchaseOrderSourceMode::current());
        $this->assertSame($expected !== 'off', PurchaseOrderSourceMode::reports());
        $this->assertSame($expected === 'on', PurchaseOrderSourceMode::active());
    }

    public function test_the_source_is_off_unless_the_deployment_file_says_otherwise(): void
    {
        $this->assertSame('off', (require base_path('config/operational_costs.php'))['sources']['purchase_orders']);
    }

    public function test_with_the_source_on_the_cost_modules_master_switch_still_has_to_be_on(): void
    {
        config(['operational_costs.enabled' => false]);

        $this->assertFalse(PurchaseOrderSourceMode::active());
        $this->expectException(LogicException::class);
        $this->apply();
    }

    public function test_with_the_source_off_nothing_is_read_or_written_and_the_command_says_so(): void
    {
        $this->receivedLine();
        config(['operational_costs.sources.purchase_orders' => 'off']);
        $before = $this->rowCounts();

        $statements = $this->statementsOf(function (): void {
            $this->artisan('costs:layers-from-purchase-orders')->expectsOutputToContain('purchase-order source is off')->assertExitCode(0);
            $this->artisan('costs:layers-from-purchase-orders', ['--apply' => true, '--since' => '2026-11-01'])->expectsOutputToContain('purchase-order source is off')->assertExitCode(1);
        });

        $this->assertSame([], $statements, 'Not one statement: the switch is read from the configuration before anything else is touched.');
        $this->assertSame($before, $this->rowCounts());
        $this->expectException(LogicException::class);
        $this->apply();
    }

    public function test_with_the_cost_module_off_the_command_does_nothing_and_a_dry_run_is_not_a_failure(): void
    {
        $this->receivedLine();
        config(['operational_costs.enabled' => false]);

        $this->artisan('costs:layers-from-purchase-orders')->expectsOutputToContain('Operational costs are switched off')->assertExitCode(0);
        $this->artisan('costs:layers-from-purchase-orders', ['--apply' => true, '--since' => '2026-11-01'])->expectsOutputToContain('Operational costs are switched off')->assertExitCode(1);
        $this->assertSame(0, InventoryCostLayer::count());
    }

    public function test_shadow_mode_writes_nothing_anywhere_not_even_to_the_modules_own_tables_and_only_reports(): void
    {
        $this->receivedLine();
        $this->receivedLine(product: ['variation' => [['type' => 'Red', 'qty' => 1]]]);
        $before = $this->rowCounts();
        $stock = DB::table('products')->orderBy('id')->pluck('current_stock', 'id')->all();
        config(['operational_costs.sources.purchase_orders' => 'shadow']);

        $statements = $this->statementsOf(function (): void {
            $this->service()->preview();
            $this->artisan('costs:layers-from-purchase-orders')->expectsOutputToContain('1 with 10 units to layer')->expectsOutputToContain('variant_product')->expectsOutputToContain('nothing was written anywhere')->assertExitCode(0);
            $this->artisan('costs:layers-from-purchase-orders', ['--json' => true, '--since' => '2026-11-01'])->assertExitCode(0);
            $this->artisan('costs:reconcile-platform')->assertExitCode(0);
        });

        $this->assertNotEmpty($statements);
        foreach ($statements as $sql) {
            $this->assertMatchesRegularExpression('/^\s*\(?\s*(select|pragma|show)\b/i', $sql, 'Shadow mode ran: ' . $sql);
            $this->assertDoesNotMatchRegularExpression('/\bfor\s+update\b|\block\s+in\s+share\s+mode\b/i', $sql);
        }
        $this->assertSame($before, $this->rowCounts(), 'Every table of the database has the rows it had.');
        $this->assertSame($stock, DB::table('products')->orderBy('id')->pluck('current_stock', 'id')->all());
    }

    public function test_shadow_mode_refuses_to_make_layers_by_the_command_and_by_the_service(): void
    {
        $this->receivedLine();
        config(['operational_costs.sources.purchase_orders' => 'shadow']);

        $this->artisan('costs:layers-from-purchase-orders', ['--apply' => true, '--since' => '2026-11-01'])->expectsOutputToContain('shadow mode')->assertExitCode(1);

        $this->assertSame(0, InventoryCostLayer::count());
        $this->expectException(LogicException::class);
        $this->apply();
    }

    public function test_the_command_dry_runs_by_default_applies_only_with_a_cutover_date_and_checks_what_it_is_given(): void
    {
        $this->receivedLine();

        $this->artisan('costs:layers-from-purchase-orders')->expectsOutputToContain('Dry run')->assertExitCode(0);
        $this->assertSame(0, InventoryCostLayer::count());

        $this->artisan('costs:layers-from-purchase-orders', ['--apply' => true])->expectsOutputToContain('needs a cutover date')->assertExitCode(1);
        $this->artisan('costs:layers-from-purchase-orders', ['--apply' => true, '--dry-run' => true, '--since' => '2026-11-01'])->expectsOutputToContain('not both')->assertExitCode(1);
        $this->artisan('costs:layers-from-purchase-orders', ['--since' => '1 November'])->expectsOutputToContain('YYYY-MM-DD')->assertExitCode(1);
        $this->artisan('costs:layers-from-purchase-orders', ['--product' => 'x'])->expectsOutputToContain('must be an id')->assertExitCode(1);
        $this->assertSame(0, InventoryCostLayer::count());

        $this->artisan('costs:layers-from-purchase-orders', ['--apply' => true, '--since' => '2026-11-01'])->expectsOutputToContain('1 layers made (10 units)')->assertExitCode(0);
        $this->assertSame(1, InventoryCostLayer::count());
        $this->artisan('costs:layers-from-purchase-orders', ['--apply' => true, '--since' => '2026-11-01'])->expectsOutputToContain('0 layers made')->assertExitCode(0);
        $this->assertSame(1, InventoryCostLayer::count());
    }

    public function test_the_json_result_says_what_was_planned_what_was_made_and_why_a_line_was_left(): void
    {
        $this->receivedLine();
        $this->receivedLine(cost: 0);

        Artisan::call('costs:layers-from-purchase-orders', ['--json' => true]);

        $result = json_decode(Artisan::output(), true);
        $this->assertSame(['dry-run', 2, 1, 10, 0, ['cost_missing' => 1]], [$result['mode'], $result['lines'], $result['planned_layers'], $result['planned_units'], $result['created_layers'], $result['refused']]);
        $this->assertSame(0, InventoryCostLayer::count());
    }

    public function test_a_command_limited_to_one_product_or_one_line_looks_at_nothing_else(): void
    {
        [$first] = $this->receivedLine();
        [, $second] = $this->receivedLine();

        Artisan::call('costs:layers-from-purchase-orders', ['--json' => true, '--product' => (string) $first]);
        $this->assertSame(1, json_decode(Artisan::output(), true)['lines']);
        Artisan::call('costs:layers-from-purchase-orders', ['--apply' => true, '--since' => '2026-11-01', '--line' => (string) $second->id]);

        $this->assertSame([$second->id], InventoryCostLayer::pluck('source_id')->all());
    }

    public function test_a_platform_table_or_column_that_is_not_there_means_nothing_is_read_or_written_and_the_command_says_which(): void
    {
        $this->receivedLine();
        Schema::drop('purchase_orders');
        $before = $this->rowCounts();

        $this->assertSame(0, Artisan::call('costs:layers-from-purchase-orders'));
        $output = Artisan::output();
        $this->assertStringContainsString('(purchase_orders)', $output);
        $this->assertStringContainsString('installation dump', $output);
        $this->artisan('costs:layers-from-purchase-orders', ['--apply' => true, '--since' => '2026-11-01'])->expectsOutputToContain('purchase_orders')->assertExitCode(1);

        $this->assertSame($before, $this->rowCounts());
        $this->assertSame(0, InventoryCostLayer::count());
    }

    public function test_the_migration_of_the_modules_own_columns_not_having_run_means_nothing_is_made_and_the_command_says_which(): void
    {
        $this->receivedLine();
        Schema::table('inventory_cost_layers', static function ($table): void {
            $table->dropUnique('icl_source_tranche_unique');
        });
        Schema::table('inventory_cost_layers', static fn ($table) => $table->dropColumn('source_to'));

        $this->assertSame(0, Artisan::call('costs:layers-from-purchase-orders'));
        $output = Artisan::output();
        $this->assertStringContainsString('inventory_cost_layers.source_to', $output);
        $this->assertStringContainsString('migration 2026_11_11_100000', $output);
        $this->artisan('costs:layers-from-purchase-orders', ['--apply' => true, '--since' => '2026-11-01'])->assertExitCode(1);
        $this->assertSame(0, InventoryCostLayer::count());
    }

    public function test_neither_command_is_on_the_schedule_whatever_the_switches_say_because_history_needs_a_cutover_date_a_person_chooses(): void
    {
        config(['operational_costs.enabled' => true, 'operational_costs.schedule_enabled' => true, 'operational_costs.sources.purchase_orders' => 'on']);
        $schedule = new Schedule();
        ScheduleDefinition::define($schedule);

        $commands = implode("\n", array_map(static fn ($event): string => (string) $event->command, $schedule->events()));

        $this->assertStringContainsString('costs:refresh', $commands, 'The scheduled cost commands are there, so this looks at the real schedule.');
        $this->assertStringNotContainsString('layers-from-purchase-orders', $commands);
        $this->assertStringNotContainsString('reconcile-platform', $commands);
    }

    // ---- the estimate ----------------------------------------------------------------------------------------------------------------------

    private function estimate(int $product, string $variantKey = ''): ?array
    {
        return app(InventoryCostLayerService::class)->estimatedUnitCost($this->own->id, $product, $variantKey);
    }

    public function test_the_latest_purchase_order_cost_is_an_estimate_ahead_of_the_products_own_purchase_price_only_with_the_source_on(): void
    {
        $product = $this->platformProduct(['purchase_price' => 7]);
        $order = $this->purchaseOrder($this->supplier(), [[$product, 5, 9.0]]);
        $this->receiveOnPlatform($order->items->first(), 5);

        config(['operational_costs.sources.purchase_orders' => 'off']);
        $this->assertSame('product_purchase_price', $this->estimate($product)['basis'] ?? null, 'As before: the platform\'s own purchase price, whatever the orders say.');
        config(['operational_costs.sources.purchase_orders' => 'shadow']);
        $this->assertSame('product_purchase_price', $this->estimate($product)['basis'] ?? null);

        config(['operational_costs.sources.purchase_orders' => 'on']);
        $estimate = $this->estimate($product);
        $this->assertSame(['last_purchase_order', '9.000000', 'SAR'], [$estimate['basis'], $estimate['cost']->toStorage(), $estimate['currency']]);
    }

    public function test_the_estimate_skips_a_newer_line_nobody_can_use_converts_the_currency_and_never_guesses_for_a_variant_or_a_vendor(): void
    {
        $product = $this->platformProduct();
        $old = $this->purchaseOrder($this->supplier(), [[$product, 2, 4.0]], currency: 'USD');
        $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00:00'));
        $this->receiveOnPlatform($old->items->first(), 2);
        $newer = $this->purchaseOrder($this->supplier('Other'), [[$product, 2, 0]]);
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00'));
        $this->receiveOnPlatform($newer->items->first(), 2);

        $estimate = $this->estimate($product);

        $this->assertSame(['last_purchase_order', '15.000000'], [$estimate['basis'], $estimate['cost']->toStorage()], 'The newer line has no cost; the one before it, 4 USD, is 15 riyals.');
        $this->assertNull($this->estimate($product, 'Red'), 'An order line names no variant.');
        $this->assertNull(app(InventoryCostLayerService::class)->estimatedUnitCost($this->own->id, $product, '', 3), 'And a vendor\'s stock is the vendor\'s.');
        $this->assertNull($this->estimate($this->platformProduct()), 'A product no order mentions has no estimate from one.');
    }

    public function test_open_layers_are_still_the_best_estimate_and_ahead_of_a_purchase_order_cost(): void
    {
        $product = $this->platformProduct();
        $order = $this->purchaseOrder($this->supplier(), [[$product, 5, 9.0]]);
        $this->receiveOnPlatform($order->items->first(), 5);
        app(InventoryCostLayerService::class)->receive(new LayerReceipt(
            locationId: $this->own->id, productId: $product, quantity: 2, unitCost: Money::of('20'), currency: 'SAR', receivedAt: CarbonImmutable::parse('2026-11-01'), sourceType: 'manual', identity: 'opening',
        ));

        $this->assertSame(['open_layers_average', '20.000000'], [$this->estimate($product)['basis'], $this->estimate($product)['cost']->toStorage()]);
    }
}
