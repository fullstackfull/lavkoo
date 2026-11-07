<?php

namespace Tests\Feature\OperationalCosts;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\ExternalOrderMapping;
use App\Models\OperationalCostEntry;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\OperationalCosts\CostLedger;
use App\Services\OperationalCosts\Profit\ConversionGaps;
use App\Services\OperationalCosts\Profit\ProductProfitability;
use App\Services\OperationalCosts\Profit\ProfitAggregator;
use App\Services\OperationalCosts\Profit\ProfitabilityService;
use App\Services\OperationalCosts\Reports\ProfitLine;
use App\Services\OperationalCosts\Reports\ProfitTotals;
use App\Services\OperationalCosts\Sales\SaleDocument;
use App\Services\OperationalCosts\Sales\SaleDocumentReader;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\Support\CreatesOperationalCostData;
use Tests\Support\FakeCurrencyRates;
use Tests\TestCase;

/**
 * Profit when a currency has no recorded exchange rate: whatever cannot be converted is left out and said so, in the by-product view exactly as in the by-sale view, and
 * no figure built on it is a finished number. The store's rates here are SAR (the reporting currency), USD and TRY; EUR has none until a test records one.
 */
class ProfitWithoutExchangeRateTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;
    use CreatesOperationalCostData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->rates(['USD' => 1.0, 'SAR' => 3.75, 'TRY' => 40.0]);
        config(['operational_costs.base_currency' => 'SAR', 'fulfillment.currency' => 'SAR']);
    }

    /** @param array<string, float> $rates */
    private function rates(array $rates): void
    {
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates($rates));
    }

    /** A delivered channel sale of one line, in the given currency, for the given product. */
    private function sale(string $currency, string $lineTotal = '100', int $productId = 50, string $channel = 'trendyol'): ExternalOrderMapping
    {
        $sale = $this->channelSale($lineTotal, $channel, productId: $productId);
        $sale->forceFill(['currency' => $currency])->save();
        $sale->lines()->update(['currency' => $currency]);

        return $sale->fresh();
    }

    /** @param array<string, mixed> $with */
    private function cost(ExternalOrderMapping $sale, string $amount, string $currency = 'SAR', int $productId = 50, CostState $state = CostState::Actual, CostComponent $component = CostComponent::Shipping, array $with = []): OperationalCostEntry
    {
        return $this->recordCost($state, $amount, $component, '2026-11-05', $with + ['mappingId' => $sale->id, 'channel' => $sale->sales_channel, 'accountId' => $sale->account_id, 'productId' => $productId, 'currency' => $currency]);
    }

    /** @param list<ExternalOrderMapping> $sales @return list<SaleDocument> */
    private function documents(array $sales): array
    {
        return array_map(static fn (ExternalOrderMapping $sale): SaleDocument => app(SaleDocumentReader::class)->forChannelOrder($sale->id), $sales);
    }

    /** @param list<ExternalOrderMapping> $sales @return array<string, \App\Services\OperationalCosts\Profit\ProfitRow> */
    private function productRows(array $sales): array
    {
        return app(ProductProfitability::class)->rows($this->documents($sales));
    }

    public function test_a_product_whose_revenue_is_all_in_a_currency_with_no_rate_has_an_unknown_revenue_and_not_a_revenue_of_zero(): void
    {
        $euro = $this->sale('EUR', '100');
        $this->cost($euro, '10');

        $row = $this->productRows([$euro])['50'];

        $this->assertFalse($row->revenueKnown());
        $this->assertSame(['EUR' => 1], $row->gaps->revenue);
        $this->assertSame(['EUR'], $row->gaps->currencies());
        $this->assertNull($row->estimatedProfit(), 'A profit cannot be told from a revenue nobody could convert.');
        $this->assertNull($row->actualProfit());
        $this->assertNull($row->estimatedMarginPercent());
        $this->assertNull(ProfitLine::ofRow($row, 'Vitamin C', groupsSales: false)->revenue(), 'A line shows it as unknown (a dash, an empty cell), not 0.00.');
        $this->assertSame('10.000000', $row->actualCost->toStorage(), 'What could be converted is still counted.');
    }

    public function test_a_cost_in_a_currency_with_no_rate_is_left_out_and_flagged_and_the_profit_is_the_figure_that_could_be_computed(): void
    {
        $sale = $this->sale('SAR', '100');
        $this->cost($sale, '10');
        $this->cost($sale, '20', 'EUR', component: CostComponent::MarketplaceCommission);

        $row = $this->productRows([$sale])['50'];

        $this->assertTrue($row->revenueKnown());
        $this->assertSame('100.000000', $row->revenue->toStorage());
        $this->assertSame('10.000000', $row->actualCost->toStorage(), 'The 20 EUR is not counted as zero and not guessed at: it is left out.');
        $this->assertSame(['EUR' => 1], $row->gaps->costs);
        $this->assertSame([], $row->gaps->revenue);
        $this->assertSame('90.000000', $row->actualProfit()->toStorage(), 'The figure that could be computed, which the flag says is incomplete.');
        $this->assertFalse($row->gaps->isEmpty());
        $this->assertSame(0, $row->missingCosts, 'A cost with no rate is not a cost nobody determined.');
    }

    public function test_a_product_with_revenue_in_two_currencies_shows_what_could_be_converted_flagged_incomplete(): void
    {
        $riyals = $this->sale('SAR', '100', productId: 50);
        $euros = $this->sale('EUR', '80', productId: 50);
        $liras = $this->sale('TRY', '400', productId: 50);

        $row = $this->productRows([$riyals, $euros, $liras])['50'];

        $this->assertTrue($row->revenueKnown(), 'Part of the revenue could be converted.');
        $this->assertSame('137.500000', $row->revenue->toStorage(), '100 SAR + 400 TRY at 3.75/40 = 37.50; the 80 EUR is left out.');
        $this->assertSame(['EUR' => 1], $row->gaps->revenue);
        $this->assertNotNull($row->estimatedProfit());
        $this->assertFalse($row->gaps->isEmpty());
    }

    public function test_the_other_product_of_the_same_report_is_unaffected(): void
    {
        $riyals = $this->sale('SAR', '100', productId: 50);
        $euros = $this->sale('EUR', '80', productId: 51);

        $rows = $this->productRows([$riyals, $euros]);

        $this->assertTrue($rows['50']->gaps->isEmpty());
        $this->assertSame('100.000000', $rows['50']->estimatedProfit()->toStorage());
        $this->assertFalse($rows['51']->revenueKnown());
    }

    public function test_once_a_rate_is_recorded_the_same_books_are_complete_again(): void
    {
        $euro = $this->sale('EUR', '100');
        $this->cost($euro, '20', 'EUR');
        $this->assertFalse($this->productRows([$euro])['50']->gaps->isEmpty());

        $this->rates(['USD' => 1.0, 'SAR' => 3.75, 'TRY' => 40.0, 'EUR' => 0.9]);
        $row = $this->productRows([$euro])['50'];

        $this->assertTrue($row->gaps->isEmpty());
        $this->assertTrue($row->revenueKnown());
        $this->assertSame('416.666667', $row->revenue->toStorage(), '100 EUR at 3.75 / 0.9 SAR per EUR.');
        $this->assertSame('83.333333', $row->estimatedCost->toStorage());
        $this->assertNotNull($row->actualProfit());
    }

    public function test_a_charge_in_dispute_is_counted_as_disputed_and_never_as_an_amount_with_no_rate_or_a_missing_cost(): void
    {
        $sale = $this->sale('SAR', '100');
        $disputed = $this->cost($sale, '12', component: CostComponent::Shipping);
        app(CostLedger::class)->dispute($disputed, 'overcharged');
        $this->recordCost(CostState::Missing, null, CostComponent::Picking, '2026-11-05', ['mappingId' => $sale->id, 'channel' => 'trendyol', 'accountId' => $sale->account_id, 'productId' => 50, 'reason' => 'no_contract']);

        $row = $this->productRows([$sale])['50'];

        $this->assertSame(1, $row->disputedCosts);
        $this->assertSame('12.000000', $row->disputedCost->toStorage(), 'In neither cost figure, and shown on its own.');
        $this->assertSame('0.000000', $row->estimatedCost->toStorage());
        $this->assertSame(1, $row->missingCosts);
        $this->assertTrue($row->gaps->isEmpty(), 'Neither of them is a conversion gap.');
    }

    public function test_the_gap_a_sale_shows_and_the_gap_its_product_shows_are_the_same(): void
    {
        $riyals = $this->sale('SAR', '100', productId: 50);
        $euros = $this->sale('EUR', '80', productId: 51);
        $this->cost($riyals, '10', productId: 50);
        $this->cost($riyals, '4', 'USD', productId: 50, component: CostComponent::Picking);
        $this->cost($euros, '6', 'EUR', productId: 51);
        $documents = $this->documents([$riyals, $euros]);

        $results = iterator_to_array(app(ProfitabilityService::class)->forDocuments($documents), false);
        $byChannel = app(ProfitAggregator::class)->group($results, ProfitAggregator::BY_CHANNEL);
        $byProduct = app(ProductProfitability::class)->rows($documents);
        $orderTotals = ProfitTotals::of($byChannel);
        $productTotals = ProfitTotals::of($byProduct);

        $this->assertTrue($results[0]->revenueKnown());
        $this->assertFalse($results[1]->revenueKnown(), 'The EUR sale has no revenue in riyals to report: it is unknown, not zero.');
        $this->assertSame($orderTotals->gaps->revenue, $productTotals->gaps->revenue, 'The same revenue is left out by both views.');
        $this->assertSame($orderTotals->gaps->costs, $productTotals->gaps->costs, 'The same costs are left out by both views.');
        $this->assertSame(['EUR'], $orderTotals->gaps->currencies());
        $this->assertSame($orderTotals->revenue->toStorage(), $productTotals->revenue->toStorage());
        $this->assertSame($orderTotals->revenueKnown(), $productTotals->revenueKnown());
        $this->assertSame($orderTotals->estimatedProfit()->toStorage(), $productTotals->estimatedProfit()->toStorage());
    }

    public function test_a_sale_in_a_currency_with_no_rate_has_no_revenue_to_show_and_no_profit_in_the_order_view_too(): void
    {
        $euro = $this->sale('EUR', '100');
        $this->cost($euro, '10');

        $result = app(ProfitabilityService::class)->forDocument($this->documents([$euro])[0]);
        $line = ProfitLine::ofResult($result);

        $this->assertFalse($result->revenueKnown());
        $this->assertNull($result->estimatedProfit());
        $this->assertNull($line->revenue());
        $this->assertNull($line->estimatedProfit());
        $this->assertContains('revenue_not_convertible', $result->notes);
        $this->assertSame(['EUR' => 1], $result->gaps->revenue);
        $this->assertFalse($result->estimatedComplete());
    }

    public function test_a_group_of_sales_inherits_the_gap_and_shows_unknown_revenue_only_when_all_of_it_is_unknown(): void
    {
        $riyals = $this->sale('SAR', '100');
        $euros = $this->sale('EUR', '80');
        $allEuro = $this->sale('EUR', '60', channel: 'amazon');

        $results = iterator_to_array(app(ProfitabilityService::class)->forDocuments($this->documents([$riyals, $euros, $allEuro])), false);
        $rows = app(ProfitAggregator::class)->group($results, ProfitAggregator::BY_CHANNEL);

        $this->assertTrue($rows['trendyol']->revenueKnown(), 'One of its two sales could be converted.');
        $this->assertSame('100.000000', $rows['trendyol']->revenue->toStorage());
        $this->assertSame(['EUR' => 1], $rows['trendyol']->gaps->revenue);
        $this->assertFalse($rows['amazon']->revenueKnown(), 'All of its revenue is in a currency with no rate.');
        $this->assertNull($rows['amazon']->estimatedProfit());
        $this->assertSame(['EUR'], ProfitTotals::of($rows)->gaps->currencies());
        $this->assertTrue(ProfitTotals::of($rows)->revenueKnown(), 'The total has a revenue: Trendyol\'s.');
        $this->assertTrue(ProfitTotals::of([$rows['amazon']])->revenueKnown() === false);
    }

    public function test_a_total_whose_every_revenue_is_unknown_has_no_profit_and_a_total_with_a_gap_is_never_complete(): void
    {
        $totals = ProfitTotals::of($this->productRows([$this->sale('EUR', '100')]));

        $this->assertFalse($totals->revenueKnown());
        $this->assertNull($totals->estimatedProfit());
        $this->assertNull($totals->actualMarginPercent());
        $this->assertFalse($totals->gaps->isEmpty());
        $this->assertSame(1, $totals->gaps->revenueCount());
        $this->assertSame(0, $totals->gaps->costCount());
    }

    public function test_a_sale_that_has_nothing_to_convert_leaves_no_gap(): void
    {
        $zero = $this->sale('EUR', '0');
        $cancelled = $this->sale('EUR', '100');
        $cancelled->forceFill(['status' => 'cancelled'])->save();

        $rows = $this->productRows([$zero, $cancelled]);

        $this->assertTrue($rows['50']->gaps->isEmpty(), 'A revenue of zero needs no rate, and a cancelled sale has no revenue.');
        $this->assertTrue($rows['50']->revenueKnown());
    }

    public function test_conversion_gaps_add_up_by_currency_and_name_every_currency_once_in_order(): void
    {
        $gaps = (new ConversionGaps())->withRevenue('EUR')->withRevenue('EUR')->withCost('GBP')->plus((new ConversionGaps())->withCost('EUR'));

        $this->assertSame(['EUR' => 2], $gaps->revenue);
        $this->assertSame(['GBP' => 1, 'EUR' => 1], $gaps->costs);
        $this->assertSame(2, $gaps->revenueCount());
        $this->assertSame(2, $gaps->costCount());
        $this->assertSame(['EUR', 'GBP'], $gaps->currencies());
        $this->assertTrue((new ConversionGaps())->isEmpty());
    }
}
