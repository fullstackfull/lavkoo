<?php

namespace Tests\Feature\OperationalCosts;

use App\Enums\OperationalCosts\CostState;
use App\Models\InventoryCostLayer;
use App\Models\OperationalCostEntry;
use App\Models\ProviderFulfillmentItem;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\OperationalCosts\Data\LayerReceipt;
use App\Services\OperationalCosts\InventoryCostLayerService;
use App\Services\OperationalCosts\Sales\SaleCostService;
use App\Services\OperationalCosts\Sales\SaleDocument;
use App\Services\OperationalCosts\Sales\SaleLine;
use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\FakeCurrencyRates;
use Tests\TestCase;

/** Costs and prices in these tests are arbitrary numbers made up for the arithmetic. */
class SaleCostServiceTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private const TOROD = 1;
    private const FBA = 2;
    private const PRODUCT = 50;

    private SaleCostService $sales;

    private InventoryCostLayerService $layers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'fulfillment.currency' => 'SAR']);
        $this->sales = app(SaleCostService::class);
        $this->layers = app(InventoryCostLayerService::class);
    }

    private function stock(int $quantity, string $unitCost, string $identity, int $location = self::TOROD): void
    {
        $this->layers->receive(new LayerReceipt(
            locationId: $location, productId: self::PRODUCT, quantity: $quantity, unitCost: Money::of($unitCost), currency: 'SAR',
            receivedAt: CarbonImmutable::parse('2026-01-01'), sourceType: 'purchase_invoice', identity: $identity,
        ));
    }

    private function line(int $id, int $quantity, int $location = self::TOROD, string $basis = SaleLine::BASIS_OWNED_STOCK, string $targetType = 'order_line'): SaleLine
    {
        return new SaleLine($targetType, $id, self::PRODUCT, '', null, $quantity, Money::of('100'), Money::zero(), $location, $basis);
    }

    private function document(string $channel, string $state, array $lines, int $id = 1): SaleDocument
    {
        return new SaleDocument(
            subjectType: $channel === 'storefront' ? 'order' : 'channel_order', subjectId: $id, salesChannel: $channel, accountId: $channel === 'storefront' ? null : 1,
            sellerId: 0, currency: 'SAR', occurredAt: CarbonImmutable::parse('2026-11-10'), state: $state, fulfillmentMode: null, provider: null,
            orderId: $channel === 'storefront' ? $id : null, mappingId: $channel === 'storefront' ? null : $id, shippingCharged: Money::zero(),
            orderDiscounts: Money::zero(), lines: $lines,
        );
    }

    private function cogs(int $lineId, string $type = 'order_line'): ?OperationalCostEntry
    {
        return OperationalCostEntry::where('subject_type', $type)->where('subject_id', $lineId)->where('cost_component', 'cogs')->where('is_effective', true)->first();
    }

    public function test_an_open_order_gets_an_estimate_from_the_average_cost_of_the_stock(): void
    {
        $this->stock(2, '10', 'inv-1');
        $this->stock(2, '20', 'inv-2');

        $this->sales->refresh($this->document('storefront', SaleDocument::STATE_OPEN, [$this->line(11, 3)]));

        $entry = $this->cogs(11);
        $this->assertSame(CostState::Estimated, $entry->state);
        $this->assertSame('45.000000', $entry->amount, '3 units at the 15.00 average.');
        $this->assertSame(4, (int) InventoryCostLayer::sum('quantity_remaining'), 'An estimate takes nothing from the stock.');
    }

    public function test_delivery_turns_the_estimate_into_the_actual_cost_taken_first_in_first_out(): void
    {
        $this->stock(2, '10', 'inv-1');
        $this->stock(2, '20', 'inv-2');
        $open = $this->document('storefront', SaleDocument::STATE_OPEN, [$this->line(11, 3)]);
        $this->sales->refresh($open);
        $estimate = $this->cogs(11);

        $this->sales->refresh($this->document('storefront', SaleDocument::STATE_DELIVERED, [$this->line(11, 3)]));

        $actual = $this->cogs(11);
        $this->assertSame(CostState::Actual, $actual->state);
        $this->assertSame('40.000000', $actual->amount, '2 x 10 then 1 x 20.');
        $this->assertFalse($estimate->fresh()->is_effective, 'The estimate and the real cost are one cost, not two.');
        $this->assertSame(1, (int) InventoryCostLayer::sum('quantity_remaining'));
    }

    public function test_a_line_whose_units_are_all_cancelled_stops_costing_and_gives_its_units_back_while_the_rest_of_the_order_stands(): void
    {
        $this->stock(10, '10', 'inv-1');
        $kept = $this->line(11, 1);
        $cancelled = $this->line(12, 2);
        $this->sales->refresh($this->document('amazon', SaleDocument::STATE_OPEN, [$kept, $cancelled], 5));
        $this->assertSame('20.000000', $this->cogs(12)->amount);

        $this->sales->refresh($this->document('amazon', SaleDocument::STATE_SHIPPED, [$kept, $cancelled], 5));
        $this->assertSame(7, (int) InventoryCostLayer::sum('quantity_remaining'), 'Both lines were recognised: 3 units left the shelf.');

        $this->sales->refresh($this->document('amazon', SaleDocument::STATE_SHIPPED, [$kept, $this->line(12, 0)], 5));

        $this->assertSame(9, (int) InventoryCostLayer::sum('quantity_remaining'), 'The cancelled line gave its 2 units back.');
        $this->assertSame([CostState::Actual, '0.000000'], [$this->cogs(12)->state, $this->cogs(12)->amount], 'It now costs nothing.');
        $this->assertSame('10.000000', $this->cogs(11)->amount, 'The line that stands is untouched.');
        $before = [OperationalCostEntry::count(), (int) InventoryCostLayer::sum('quantity_remaining')];
        $this->sales->refresh($this->document('amazon', SaleDocument::STATE_SHIPPED, [$kept, $this->line(12, 0)], 5));
        $this->assertSame($before, [OperationalCostEntry::count(), (int) InventoryCostLayer::sum('quantity_remaining')], 'Repeating the refresh changes nothing.');
    }

    public function test_a_cancelled_line_that_never_had_a_cost_records_nothing(): void
    {
        $this->stock(10, '10', 'inv-1');

        $outcomes = $this->sales->refresh($this->document('amazon', SaleDocument::STATE_SHIPPED, [$this->line(12, 0)], 6));

        $this->assertSame([], $outcomes);
        $this->assertSame(0, OperationalCostEntry::where('subject_id', 12)->count());
    }

    public function test_a_line_that_is_not_linked_to_a_product_has_a_missing_cost_not_a_free_one_until_it_is_linked(): void
    {
        $this->stock(10, '10', 'inv-1');
        $unlinked = new SaleLine('channel_order_line', 31, null, '', 'SKU-X', 2, Money::of('100'), Money::zero(), self::TOROD, SaleLine::BASIS_OWNED_STOCK);
        $document = $this->document('amazon', SaleDocument::STATE_SHIPPED, [$unlinked], 3);

        $this->sales->refresh($document);
        $this->sales->refresh($document);

        $missing = $this->cogs(31, 'channel_order_line');
        $this->assertSame([CostState::Missing, null, 'product_not_linked'], [$missing->state, $missing->amount, $missing->missing_reason]);
        $this->assertSame(1, OperationalCostEntry::where('subject_id', 31)->count(), 'Repeating the refresh adds nothing.');

        $linked = new SaleLine('channel_order_line', 31, self::PRODUCT, '', 'SKU-X', 2, Money::of('100'), Money::zero(), self::TOROD, SaleLine::BASIS_OWNED_STOCK);
        $this->sales->refresh($this->document('amazon', SaleDocument::STATE_SHIPPED, [$linked], 3));

        $this->assertSame([CostState::Actual, '20.000000'], [$this->cogs(31, 'channel_order_line')->state, $this->cogs(31, 'channel_order_line')->amount]);
        $this->assertFalse($missing->fresh()->is_effective);
    }

    public function test_the_storefront_waits_for_delivery_and_a_marketplace_for_shipping(): void
    {
        $this->stock(10, '10', 'inv-1');

        $this->sales->refresh($this->document('storefront', SaleDocument::STATE_SHIPPED, [$this->line(11, 1)], 1));
        $this->sales->refresh($this->document('amazon', SaleDocument::STATE_SHIPPED, [$this->line(21, 1, targetType: 'channel_order_line')], 2));

        $this->assertSame(CostState::Estimated, $this->cogs(11)->state, 'A storefront order that has shipped is not yet sold.');
        $this->assertSame(CostState::Actual, $this->cogs(21, 'channel_order_line')->state);
    }

    public function test_repeating_the_recognition_takes_nothing_twice(): void
    {
        $this->stock(10, '10', 'inv-1');
        $delivered = $this->document('storefront', SaleDocument::STATE_DELIVERED, [$this->line(11, 3)]);

        $this->sales->refresh($delivered);
        $this->sales->refresh($delivered);
        $this->sales->refresh($delivered);

        $this->assertSame(7, (int) InventoryCostLayer::sum('quantity_remaining'));
        $this->assertSame(1, OperationalCostEntry::where('cost_component', 'cogs')->where('state', 'actual')->count());
    }

    public function test_stock_the_layers_cannot_cover_is_missing_and_a_later_invoice_completes_it(): void
    {
        $this->stock(2, '10', 'inv-1');
        $delivered = $this->document('storefront', SaleDocument::STATE_DELIVERED, [$this->line(11, 3)]);

        $this->sales->refresh($delivered);
        $short = $this->cogs(11);
        $this->assertSame('30.000000', $short->amount);
        $this->assertSame(CostState::Estimated, $short->state, 'The two covered units are costed; the third is estimated at the cost of those two until its invoice arrives.');

        $this->stock(5, '12', 'inv-late');
        $this->sales->refresh($delivered);

        $complete = $this->cogs(11);
        $this->assertSame(CostState::Actual, $complete->state);
        $this->assertSame('32.000000', $complete->amount, '2 x 10 and the late invoice\'s 1 x 12.');
    }

    public function test_with_no_cost_layer_and_no_estimate_the_cost_is_missing_never_zero(): void
    {
        $this->sales->refresh($this->document('storefront', SaleDocument::STATE_DELIVERED, [$this->line(11, 3)]));

        $entry = $this->cogs(11);
        $this->assertSame(CostState::Missing, $entry->state);
        $this->assertNull($entry->amount);
        $this->assertSame('cost_layers_short', $entry->missing_reason);
    }

    public function test_a_line_with_no_known_stock_location_is_missing(): void
    {
        $line = new SaleLine('order_line', 11, self::PRODUCT, '', null, 1, Money::of('100'), Money::zero(), null, SaleLine::BASIS_OWNED_STOCK);

        $this->sales->refresh($this->document('storefront', SaleDocument::STATE_DELIVERED, [$line]));

        $this->assertSame('inventory_location_unknown', $this->cogs(11)->missing_reason);
    }

    public function test_a_cancelled_order_gives_its_units_back(): void
    {
        $this->stock(10, '10', 'inv-1');
        $this->sales->refresh($this->document('storefront', SaleDocument::STATE_DELIVERED, [$this->line(11, 3)]));
        $this->assertSame(7, (int) InventoryCostLayer::sum('quantity_remaining'));

        $this->sales->refresh($this->document('storefront', SaleDocument::STATE_CANCELLED, [$this->line(11, 3)]));

        $this->assertSame(10, (int) InventoryCostLayer::sum('quantity_remaining'));
    }

    public function test_a_line_that_shrinks_takes_the_smaller_quantity(): void
    {
        $this->stock(10, '10', 'inv-1');
        $this->sales->refresh($this->document('storefront', SaleDocument::STATE_DELIVERED, [$this->line(11, 4)]));
        $this->sales->refresh($this->document('storefront', SaleDocument::STATE_DELIVERED, [$this->line(11, 3)]));

        $this->assertSame(7, (int) InventoryCostLayer::sum('quantity_remaining'));
        $this->assertSame('30.000000', $this->cogs(11)->amount);
    }

    public function test_a_partial_return_put_back_on_the_shelf_reverses_only_its_share_of_the_cost(): void
    {
        $this->stock(10, '10', 'inv-1');
        $delivered = $this->document('storefront', SaleDocument::STATE_DELIVERED, [$this->line(11, 4)]);
        $this->sales->refresh($delivered);

        $this->sales->restock($delivered, $delivered->lines[0], 1, 'return-77');
        $this->sales->restock($delivered, $delivered->lines[0], 1, 'return-77');

        $net = OperationalCostEntry::where('subject_id', 11)->where('cost_component', 'cogs')->where('is_effective', true)->get()
            ->reduce(fn (Money $sum, OperationalCostEntry $entry): Money => $sum->plus($entry->amount), Money::zero());
        $this->assertSame('30.000000', $net->toStorage(), '4 units sold, 1 back: 3 units of cost.');
        $this->assertSame(7, (int) InventoryCostLayer::where('status', 'open')->sum('quantity_remaining'), 'The returned unit is on the shelf again, once.');
    }

    public function test_fba_sales_use_fba_stock_and_never_torod_stock(): void
    {
        $this->stock(10, '10', 'inv-torod', self::TOROD);
        $this->layers->transfer('to-fba-1', self::TOROD, self::FBA, self::PRODUCT, '', 3, CarbonImmutable::parse('2026-11-01'), extraCostPerUnit: Money::of('1'));

        $this->sales->refresh($this->document('amazon', SaleDocument::STATE_SHIPPED, [$this->line(21, 2, self::FBA, targetType: 'channel_order_line')], 2));

        $this->assertSame('22.000000', $this->cogs(21, 'channel_order_line')->amount, '2 units at 10 plus the 1.00 freight of getting them to FBA.');
        $this->assertSame(7, (int) InventoryCostLayer::where('inventory_location_id', self::TOROD)->sum('quantity_remaining'));
        $this->assertSame(1, (int) InventoryCostLayer::where('inventory_location_id', self::FBA)->sum('quantity_remaining'));
    }

    public function test_a_dropshipped_line_costs_what_the_supplier_charged_when_the_order_was_placed(): void
    {
        ProviderFulfillmentItem::create([
            'order_id' => 900, 'order_detail_id' => 9001, 'product_id' => self::PRODUCT, 'provider' => 'm5azn', 'name' => 'Item', 'quantity' => 2,
            'line_total' => 80, 'unit_cost' => '18.5', 'status' => 'routed', 'snapshot' => ['mapping' => ['supplier_cost_currency' => 'SAR']],
        ]);

        $this->sales->refresh($this->document('storefront', SaleDocument::STATE_OPEN, [$this->line(9001, 2, 3, SaleLine::BASIS_SUPPLIER)], 900));

        $entry = OperationalCostEntry::where('subject_id', 9001)->where('cost_component', 'supplier_cost')->first();
        $this->assertSame('37.000000', $entry->amount);
        $this->assertSame(CostState::Estimated, $entry->state, 'Until the supplier\'s invoice is matched it is an estimate.');
    }

    public function test_a_dropshipped_line_with_no_recorded_supplier_cost_is_missing(): void
    {
        $this->sales->refresh($this->document('storefront', SaleDocument::STATE_OPEN, [$this->line(9002, 2, 3, SaleLine::BASIS_SUPPLIER)], 901));

        $entry = OperationalCostEntry::where('subject_id', 9002)->where('cost_component', 'supplier_cost')->first();
        $this->assertSame(CostState::Missing, $entry->state);
        $this->assertSame('supplier_cost_unknown', $entry->missing_reason);
    }
}
