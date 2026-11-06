<?php

namespace Tests\Feature\OperationalCosts;

use App\Models\InventoryCostConsumption;
use App\Models\InventoryCostLayer;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\OperationalCosts\Data\LayerReceipt;
use App\Services\OperationalCosts\Exceptions\CostInputMissing;
use App\Services\OperationalCosts\InventoryCostLayerService;
use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\FakeCurrencyRates;
use Tests\TestCase;

/** The costs in these tests are arbitrary numbers made up for the arithmetic. */
class InventoryCostLayerServiceTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private const TOROD = 1;
    private const FBA = 2;
    private const PRODUCT = 50;

    private InventoryCostLayerService $layers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'fulfillment.currency' => 'SAR']);
        $this->layers = app(InventoryCostLayerService::class);
    }

    private function receive(int $quantity, string $unitCost, string $day, string $identity, string $currency = 'SAR', ?string $landed = null, int $location = self::TOROD): InventoryCostLayer
    {
        return $this->layers->receive(new LayerReceipt(
            locationId: $location, productId: self::PRODUCT, quantity: $quantity, unitCost: Money::of($unitCost), currency: $currency,
            receivedAt: CarbonImmutable::parse($day), sourceType: 'purchase_invoice', identity: $identity, landedUnitCost: $landed === null ? null : Money::of($landed),
        ));
    }

    private function sell(string $line, int $quantity, int $location = self::TOROD): \App\Services\OperationalCosts\Data\ConsumptionResult
    {
        return $this->layers->consume('order_line', (int) $line, $location, self::PRODUCT, '', $quantity, CarbonImmutable::parse('2026-11-10'));
    }

    public function test_a_sale_takes_the_oldest_layer_first_and_freezes_the_cost(): void
    {
        $this->receive(5, '10', '2026-01-01', 'inv-1');
        $this->receive(5, '12', '2026-02-01', 'inv-2');

        $result = $this->sell('1', 7);

        $this->assertTrue($result->isComplete());
        $this->assertSame('74.000000', $result->totalCost->toStorage(), '5 x 10 from the first layer and 2 x 12 from the second.');
    }

    public function test_landed_cost_is_part_of_what_a_unit_cost(): void
    {
        $this->receive(4, '10', '2026-01-01', 'inv-1', landed: '1.5');

        $this->assertSame('46.000000', $this->sell('1', 4)->totalCost->toStorage());
    }

    public function test_what_the_layers_cannot_cover_is_a_shortfall_never_a_free_unit(): void
    {
        $this->receive(3, '10', '2026-01-01', 'inv-1');

        $result = $this->sell('1', 5);

        $this->assertFalse($result->isComplete());
        $this->assertSame(2, $result->shortfall());
        $this->assertSame('30.000000', $result->totalCost->toStorage(), 'Only the three units with a known cost are costed.');
    }

    public function test_selling_the_same_line_twice_takes_nothing_the_second_time(): void
    {
        $this->receive(10, '10', '2026-01-01', 'inv-1');

        $this->sell('1', 4);
        $again = $this->sell('1', 4);

        $this->assertSame(6, (int) InventoryCostLayer::first()->quantity_remaining);
        $this->assertSame('40.000000', $again->totalCost->toStorage());
        $this->assertSame(1, InventoryCostConsumption::count());
    }

    public function test_a_purchase_invoice_that_arrives_after_the_sale_completes_it(): void
    {
        $this->receive(3, '10', '2026-01-01', 'inv-1');
        $short = $this->sell('1', 5);
        $this->assertSame(2, $short->shortfall());

        $this->receive(10, '11', '2026-11-12', 'inv-2');
        $complete = $this->sell('1', 5);

        $this->assertTrue($complete->isComplete());
        $this->assertSame('52.000000', $complete->totalCost->toStorage(), '3 x 10 from the first layer and 2 x 11 from the late one.');
    }

    public function test_reversing_a_sale_puts_the_units_back_and_taking_again_starts_a_new_generation(): void
    {
        $layer = $this->receive(10, '10', '2026-01-01', 'inv-1');
        $this->sell('1', 4);

        $this->assertSame(4, $this->layers->reverse('order_line', 1));
        $this->assertSame(10, (int) $layer->fresh()->quantity_remaining);

        $retaken = $this->sell('1', 6);
        $this->assertSame('60.000000', $retaken->totalCost->toStorage());
        $this->assertSame(4, (int) $layer->fresh()->quantity_remaining);
    }

    public function test_the_same_receipt_is_one_layer(): void
    {
        $this->receive(10, '10', '2026-01-01', 'inv-1');
        $this->receive(10, '10', '2026-01-01', 'inv-1');

        $this->assertSame(1, InventoryCostLayer::count());
    }

    public function test_goods_bought_in_another_currency_are_converted_with_the_recorded_rate(): void
    {
        $layer = $this->receive(2, '400', '2026-01-01', 'inv-try', currency: 'TRY');

        $this->assertSame('SAR', $layer->currency);
        $this->assertSame('37.500000', $layer->unit_cost, '400 TRY x 3.75 / 40.');
        $this->assertSame('TRY', $layer->original_currency);
        $this->assertSame('400.000000', $layer->original_unit_cost);
    }

    public function test_goods_bought_in_a_currency_with_no_rate_are_refused_not_guessed(): void
    {
        $this->expectException(CostInputMissing::class);
        $this->receive(2, '5', '2026-01-01', 'inv-eur', currency: 'EUR');
    }

    public function test_moving_stock_to_fba_is_not_a_sale_and_the_cost_travels_with_it(): void
    {
        $this->receive(10, '10', '2026-01-01', 'inv-1');

        $moved = $this->layers->transfer('transfer-1', self::TOROD, self::FBA, self::PRODUCT, '', 4, CarbonImmutable::parse('2026-11-10'), extraCostPerUnit: Money::of('2'));

        $this->assertTrue($moved->isComplete());
        $torod = InventoryCostLayer::where('inventory_location_id', self::TOROD)->first();
        $fba = InventoryCostLayer::where('inventory_location_id', self::FBA)->first();
        $this->assertSame(6, (int) $torod->quantity_remaining);
        $this->assertSame(4, (int) $fba->quantity_remaining);
        $this->assertSame('10.000000', $fba->unit_cost);
        $this->assertSame('2.000000', $fba->landed_unit_cost, 'The freight of the move is added to the units it moved.');
        $this->assertSame(0, DB::table('operational_cost_entries')->count(), 'A transfer records no cost of goods sold.');
    }

    public function test_a_transfer_reference_used_twice_moves_nothing_twice(): void
    {
        $this->receive(10, '10', '2026-01-01', 'inv-1');
        $this->layers->transfer('transfer-1', self::TOROD, self::FBA, self::PRODUCT, '', 4, CarbonImmutable::parse('2026-11-10'));
        $this->layers->transfer('transfer-1', self::TOROD, self::FBA, self::PRODUCT, '', 4, CarbonImmutable::parse('2026-11-10'));

        $this->assertSame(6, (int) InventoryCostLayer::where('inventory_location_id', self::TOROD)->first()->quantity_remaining);
        $this->assertSame(4, (int) InventoryCostLayer::where('inventory_location_id', self::FBA)->sum('quantity_remaining'));
    }

    public function test_a_sale_from_fba_uses_only_fba_layers(): void
    {
        $this->receive(10, '10', '2026-01-01', 'inv-1');

        $result = $this->sell('1', 2, location: self::FBA);

        $this->assertSame(2, $result->shortfall(), 'Torod stock is not FBA stock, and its cost layers are not FBA cost layers.');
    }

    public function test_the_estimate_for_a_unit_is_the_average_of_the_open_layers(): void
    {
        $this->receive(2, '10', '2026-01-01', 'inv-1');
        $this->receive(2, '20', '2026-02-01', 'inv-2');

        $estimate = $this->layers->estimatedUnitCost(self::TOROD, self::PRODUCT);

        $this->assertSame('15.000000', $estimate['cost']->toStorage());
        $this->assertSame('open_layers_average', $estimate['basis']);
        $this->assertNull($this->layers->estimatedUnitCost(self::TOROD, 999), 'No layer and no product price: no estimate, not zero.');
    }
}
