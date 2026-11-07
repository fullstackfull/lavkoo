<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Http\Controllers\Admin\OperationalCosts\CostFormat;
use App\Http\Controllers\Admin\OperationalCosts\CostLabels;
use App\Http\Controllers\Admin\OperationalCosts\ProfitabilityController;
use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Services\OperationalCosts\CostLedger;
use App\Services\OperationalCosts\Profit\ProductProfitability;
use App\Services\OperationalCosts\Profit\ProfitAggregator;
use App\Services\OperationalCosts\Profit\ProfitabilityService;
use App\Services\OperationalCosts\Profit\ProfitRow;
use App\Services\OperationalCosts\Reports\ProductNames;
use App\Services\OperationalCosts\Reports\ProfitReportBuilder;
use App\Services\OperationalCosts\Sales\SaleDocument;
use App\Services\OperationalCosts\Sales\SaleDocumentReader;
use App\Services\OperationalCosts\Support\Money;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesOperationalCostData;

/** Profitability: the screen lays out what the profit services return, bounded, with estimated and actual apart and a word on every gap. */
class AdminOperationalCostsProfitTest extends AdminPagesTestCase
{
    use CreatesOperationalCostData;

    private const NOVEMBER = ['from' => '2026-11-01', 'to' => '2026-11-30'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['fulfillment.currency' => 'SAR']);
        foreach ([[50, 'Vitamin C 1000'], [51, 'Panadol Extra']] as [$id, $name]) {
            DB::table('products')->insert(['id' => $id, 'name' => $name, 'added_by' => 'admin', 'product_type' => 'physical']);
        }
    }

    /**
     * Two channel sales and their costs: a Trendyol one (revenue 200, goods 60) whose picking is still an estimate, and an Amazon one (revenue 100, goods 40) with a commission nobody could determine.
     *
     * @return array{trendyol: ExternalOrderMapping, amazon: ExternalOrderMapping}
     */
    private function books(): array
    {
        $trendyol = $this->channelSale('200', 'trendyol', '2026-11-05 09:00:00');
        $amazon = $this->channelSale('100', 'amazon', '2026-11-08 12:00:00', productId: 51);
        $of = static fn (ExternalOrderMapping $sale, int $product): array => ['mappingId' => $sale->id, 'channel' => $sale->sales_channel, 'accountId' => $sale->account_id, 'productId' => $product];
        $this->recordCost(CostState::Actual, '60', CostComponent::Cogs, '2026-11-05', $of($trendyol, 50));
        $this->recordCost(CostState::Actual, '40', CostComponent::Cogs, '2026-11-08', $of($amazon, 51));
        $this->recordCost(CostState::Actual, '30', CostComponent::Shipping, '2026-11-05', $of($trendyol, 50) + ['provider' => 'torod_point']);
        $this->recordCost(CostState::Estimated, '12', CostComponent::Picking, '2026-11-05', $of($trendyol, 50) + ['provider' => 'torod_point']);
        $this->recordCost(CostState::Actual, '20', CostComponent::Shipping, '2026-11-08', $of($amazon, 51) + ['provider' => 'torod_point']);
        $this->recordCost(CostState::Missing, null, CostComponent::MarketplaceCommission, '2026-11-08', $of($amazon, 51) + ['provider' => 'amazon', 'reason' => 'no_contract']);

        return ['trendyol' => $trendyol, 'amazon' => $amazon];
    }

    /** @return array<string, ProfitRow> what the services say, computed here without the screen's help */
    private function expectedRows(array $sales, string $dimension): array
    {
        $reader = app(SaleDocumentReader::class);
        $documents = array_map(static fn (ExternalOrderMapping $sale): SaleDocument => $reader->forChannelOrder($sale->id), array_values($sales));

        return $dimension === 'product'
            ? app(ProductProfitability::class)->rows($documents)
            : app(ProfitAggregator::class)->group(iterator_to_array(app(ProfitabilityService::class)->forDocuments($documents), false), $dimension);
    }

    /** How a sale is named in a list: its order number, and its package when the channel splits an order into several. */
    private function reference(ExternalOrderMapping $sale): string
    {
        return '>' . $sale->external_order_id . ($sale->external_package_id !== '' ? '/' . $sale->external_package_id : '') . '<';
    }

    private function profit(array $query = []): string
    {
        return $this->get(route('admin.operational-costs.profit.index', $query + self::NOVEMBER))->assertOk()->getContent();
    }

    private function rowWith(string $html, string $text): string
    {
        preg_match_all('#<tr[^>]*>.*?</tr>#s', $html, $rows);
        foreach ($rows[0] as $row) {
            if (str_contains($row, $text)) {
                return $row;
            }
        }
        $this->fail("No table row mentions {$text}.");
    }

    public function test_every_dimension_renders_when_there_is_nothing_to_report(): void
    {
        foreach (['product', 'order', 'channel', 'provider'] as $dimension) {
            $html = $this->profit(['dimension' => $dimension]);

            $this->assertStringContainsString(e(translate('no_sales_in_this_period')), $html, $dimension);
            $this->assertStringContainsString(e(translate('profit_dimension_' . $dimension)), $html);
        }
    }

    public function test_by_channel_each_line_shows_the_figures_the_profit_services_compute(): void
    {
        $sales = $this->books();
        $rows = $this->expectedRows($sales, 'channel');

        $html = $this->profit(['dimension' => 'channel']);

        $this->assertSame(['amazon', 'trendyol'], array_keys($rows));
        foreach ($rows as $channel => $row) {
            $line = $this->rowWith($html, e(translate('sales_channel_' . $channel)));
            foreach ([$row->revenue, $row->estimatedProfit(), $row->actualProfit(), $row->estimatedCost, $row->actualCost] as $figure) {
                $this->assertStringContainsString(CostFormat::amount($figure), $line, $channel . ' ' . $figure->toStorage());
            }
        }
        $this->assertSame('200.00', $rows['trendyol']->revenue->toDisplay());
        $this->assertSame('98.00', $rows['trendyol']->estimatedProfit()->toDisplay(), '200 - 60 goods - 30 - 12.');
        $this->assertSame('110.00', $rows['trendyol']->actualProfit()->toDisplay(), 'The estimated picking is not an actual cost.');
        $this->assertSame('40.00', $rows['amazon']->estimatedProfit()->toDisplay(), '100 - 40 goods - 20 shipping; the commission is a gap, not a zero.');
    }

    public function test_the_total_is_the_sum_of_the_services_lines_and_estimated_and_actual_stay_two_figures(): void
    {
        $rows = $this->expectedRows($this->books(), 'channel');
        $totals = [
            'revenue' => Money::sum(array_map(static fn (ProfitRow $row): Money => $row->revenue, $rows)),
            'estimated cost' => Money::sum(array_map(static fn (ProfitRow $row): Money => $row->estimatedCost, $rows)),
            'actual cost' => Money::sum(array_map(static fn (ProfitRow $row): Money => $row->actualCost, $rows)),
        ];

        $html = $this->profit(['dimension' => 'channel']);

        $this->assertSame(['300.00', '162.00', '150.00'], array_map(static fn (Money $total): string => $total->toDisplay(), array_values($totals)));
        foreach ($totals as $label => $total) {
            $this->assertStringContainsString('<div class="k-stat__value k-num">' . CostFormat::amount($total, 'SAR') . '</div>', $html, $label);
        }
        $this->assertStringContainsString('<div class="k-stat__value k-num">' . CostFormat::amount($totals['revenue']->minus($totals['estimated cost']), 'SAR') . '</div>', $html, 'Estimated profit.');
        $this->assertStringContainsString('<div class="k-stat__value k-num">' . CostFormat::amount($totals['revenue']->minus($totals['actual cost']), 'SAR') . '</div>', $html, 'Actual profit.');
    }

    public function test_every_figure_says_how_complete_it_is(): void
    {
        $this->books();

        $html = $this->profit(['dimension' => 'channel']);
        $trendyol = $this->rowWith($html, e(translate('sales_channel_trendyol')));
        $amazon = $this->rowWith($html, e(translate('sales_channel_amazon')));

        $this->assertStringContainsString(e(translate('complete')), $trendyol, 'Nothing is missing from Trendyol\'s estimated figure.');
        $this->assertStringContainsString(e(translate('incomplete')) . ': 1 ' . e(translate('sales')), $trendyol, 'Its actual figure still waits for the picking charge.');
        $this->assertStringNotContainsString(e(translate('complete')) . '<', $amazon, 'A sale with a cost nobody could determine is never complete.');
        $this->assertSame(2, substr_count($amazon, e(translate('incomplete')) . ': 1 ' . e(translate('sales')) . ' · 1 ' . e(translate('missing'))), 'Both of its figures name the missing cost.');
    }

    public function test_the_page_states_what_the_total_is_made_of(): void
    {
        $this->books();

        $html = $this->profit(['dimension' => 'channel']);

        $this->assertStringContainsString(e(translate('estimated_cost_includes_estimates')), $html);
        $this->assertStringContainsString(e(translate('actual_cost_is_settled_charges_only')), $html);
        $this->assertStringContainsString(e(translate('incomplete')) . ': 2 ' . e(translate('sales')) . ' · 1 ' . e(translate('missing')), $html);
    }

    public function test_a_disputed_cost_is_in_neither_profit_and_is_stated_on_its_own(): void
    {
        $sale = $this->channelSale('100');
        $entry = $this->recordCost(CostState::Actual, '9', CostComponent::Shipping, '2026-11-05', ['mappingId' => $sale->id, 'channel' => 'trendyol']);
        app(CostLedger::class)->dispute($entry, 'billed twice');

        $html = $this->profit(['dimension' => 'channel']);

        $this->assertStringContainsString(e(translate('disputed_costs_are_in_neither_figure')) . ': <span class="k-num">9.00 SAR</span>', $html);
        $this->assertStringContainsString('<div class="k-stat__value k-num">100.00 SAR</div>', $html, 'Actual profit is the whole revenue: the disputed charge is not subtracted.');
    }

    public function test_by_provider_the_sales_no_provider_handled_have_a_line_of_their_own(): void
    {
        $this->storefrontSale(900, 40);
        $this->channelSale('100', 'trendyol')->forceFill(['provider' => 'torod_point'])->save();

        $html = $this->profit(['dimension' => 'provider']);

        $this->assertStringContainsString(e(translate('no_provider')), $html);
        $this->assertStringContainsString('Torod Point', $html);
    }

    public function test_by_product_the_product_service_decides_and_a_charge_nobody_could_split_stays_visible(): void
    {
        $sales = $this->books();
        $this->recordCost(CostState::Actual, '4', CostComponent::Printing, '2026-11-05', ['mappingId' => $sales['trendyol']->id, 'channel' => 'trendyol']);
        $rows = $this->expectedRows($sales, 'product');

        $html = $this->profit(['dimension' => 'product']);

        $this->assertArrayHasKey('50', $rows);
        $this->assertArrayHasKey(ProductProfitability::UNALLOCATED, $rows);
        $vitamin = $this->rowWith($html, 'Vitamin C 1000');
        $this->assertStringContainsString(CostFormat::amount($rows['50']->revenue), $vitamin);
        $this->assertStringContainsString(CostFormat::amount($rows['50']->actualProfit()), $vitamin);
        $this->assertStringContainsString('#50', $vitamin);
        $this->assertStringContainsString('Panadol Extra', $html);
        $this->assertStringContainsString(e(translate('costs_that_could_not_be_split_between_products')), $this->rowWith($html, e(translate('costs_that_could_not_be_split_between_products'))));
        $this->assertStringContainsString(CostFormat::amount($rows[ProductProfitability::UNALLOCATED]->actualCost), $this->rowWith($html, e(translate('costs_that_could_not_be_split_between_products'))));
        $this->assertStringContainsString(e(translate('product_profit_counts_the_costs_the_ledger_holds_note')), $html);
        $this->assertStringContainsString(e(translate('incomplete')) . ': 1 ' . e(translate('missing')), $this->rowWith($html, 'Panadol Extra'), 'A product whose sale has a missing cost says so.');
    }

    public function test_by_order_each_sale_is_a_line_with_a_link_to_its_costs(): void
    {
        $sales = $this->books();

        $html = $this->profit(['dimension' => 'order']);

        foreach ($sales as $sale) {
            $this->assertStringContainsString(e(route('admin.operational-costs.profit.order', ['type' => 'channel_order', 'id' => $sale->id])), $html);
            $this->assertStringContainsString($this->reference($sale), $html);
        }
        $this->assertStringContainsString('2026-11-05', $html);
        $this->assertStringContainsString(e(translate('sale_state_delivered')), $html);
        $this->assertStringContainsString(e(translate('incomplete')) . ': 1 ' . e(translate('missing')), $this->rowWith($html, $this->reference($sales['amazon'])), 'A single sale names the cost that is missing.');
        $this->assertStringContainsString(e(translate('incomplete')) . ': 1 ' . e(translate('pending')), $this->rowWith($html, $this->reference($sales['trendyol'])), 'And the one that still waits for the real charge.');
    }

    public function test_the_order_lines_can_be_sorted_by_revenue_and_by_profit_either_way(): void
    {
        $small = $this->channelSale('50');
        $big = $this->channelSale('500');
        $medium = $this->channelSale('120');
        foreach ([$small, $big, $medium] as $sale) {
            $this->recordCost(CostState::Actual, '10', CostComponent::Shipping, '2026-11-05', ['mappingId' => $sale->id, 'channel' => $sale->sales_channel, 'accountId' => $sale->account_id, 'productId' => 50]);
        }
        $position = static fn (string $html, ExternalOrderMapping $sale): int => (int) strpos($html, route('admin.operational-costs.profit.order', ['type' => 'channel_order', 'id' => $sale->id]));

        $descending = $this->profit(['dimension' => 'order', 'sort' => 'revenue', 'direction' => 'desc']);
        $this->assertLessThan($position($descending, $medium), $position($descending, $big));
        $this->assertLessThan($position($descending, $small), $position($descending, $medium));

        $ascending = $this->profit(['dimension' => 'order', 'sort' => 'revenue', 'direction' => 'asc']);
        $this->assertLessThan($position($ascending, $medium), $position($ascending, $small));
        $this->assertLessThan($position($ascending, $big), $position($ascending, $medium));

        $byProfit = $this->profit(['dimension' => 'order', 'sort' => 'actual_profit', 'direction' => 'desc']);
        $this->assertLessThan($position($byProfit, $small), $position($byProfit, $big));
        preg_match('#<th class="k-table__num"><a href="([^"]*)">' . preg_quote(e(translate('revenue')), '#') . '</a>#', $descending, $sortLink);
        parse_str((string) parse_url(html_entity_decode($sortLink[1]), PHP_URL_QUERY), $sortQuery);
        $this->assertSame('asc', $sortQuery['direction'], 'The header of the column in force flips the direction of its sort.');
        $this->assertSame(['2026-11-01', '2026-11-30', 'order'], [$sortQuery['from'], $sortQuery['to'], $sortQuery['dimension']], 'And keeps the period and the dimension.');
        $this->profit(['sort' => 'nonsense', 'direction' => ['x']]);
    }

    public function test_the_filters_narrow_the_sales_read(): void
    {
        $trendyol = $this->channelSale('200', 'trendyol', '2026-11-05 09:00:00');
        $amazon = $this->channelSale('100', 'amazon', '2026-11-20 09:00:00');
        $amazon->forceFill(['provider' => 'torod_point'])->save();
        $this->storefrontSale(900, 40, '2026-11-12 10:00:00');
        $reference = fn (ExternalOrderMapping $sale): string => $this->reference($sale);
        $page = fn (array $query): string => $this->profit($query + ['dimension' => 'order']);

        $byChannel = $page(['channel' => 'amazon']);
        $this->assertStringContainsString($reference($amazon), $byChannel);
        $this->assertStringNotContainsString($reference($trendyol), $byChannel);
        $this->assertStringNotContainsString('>900<', $byChannel);

        $this->assertStringContainsString('>900<', $page(['channel' => 'storefront']));
        $this->assertStringNotContainsString($reference($amazon), $page(['channel' => 'storefront']));

        $byAccount = $page(['account' => $trendyol->account_id]);
        $this->assertStringContainsString($reference($trendyol), $byAccount);
        $this->assertStringNotContainsString($reference($amazon), $byAccount);
        $this->assertStringNotContainsString('>900<', $byAccount, 'An account is a channel account: the storefront has none.');

        $byProvider = $page(['provider' => 'torod_point']);
        $this->assertStringContainsString($reference($amazon), $byProvider);
        $this->assertStringNotContainsString($reference($trendyol), $byProvider);
        $this->assertStringContainsString($reference($trendyol), $page(['provider' => 'none']));
        $this->assertStringNotContainsString($reference($amazon), $page(['provider' => 'none']));

        $byDate = $page(['from' => '2026-11-10', 'to' => '2026-11-15']);
        $this->assertStringContainsString('>900<', $byDate);
        $this->assertStringNotContainsString($reference($trendyol), $byDate);
        $this->assertStringNotContainsString($reference($amazon), $byDate);
        $this->profit(['channel' => ['x'], 'account' => 'abc', 'provider' => ['y'], 'dimension' => ['z']]);
    }

    public function test_the_books_of_a_seller_are_not_in_the_platforms_profit(): void
    {
        $platform = $this->channelSale('100');
        $sellers = $this->channelSale('900', seller: 9);
        $page = fn (array $query = []): string => $this->profit($query + ['dimension' => 'order']);

        $this->assertStringContainsString($this->reference($platform), $page());
        $this->assertStringNotContainsString($this->reference($sellers), $page());
        $this->assertStringContainsString($this->reference($sellers), $page(['seller' => 9]));
        $this->assertStringNotContainsString($this->reference($platform), $page(['seller' => 9]));
        $both = $page(['seller' => 'all']);
        $this->assertStringContainsString($this->reference($platform), $both);
        $this->assertStringContainsString($this->reference($sellers), $both);
    }

    public function test_the_default_period_is_the_current_month_and_unusable_dates_say_so(): void
    {
        $this->travelTo('2026-11-15');
        $this->channelSale('100', 'trendyol', '2026-11-05 09:00:00');
        $this->channelSale('900', 'trendyol', '2026-10-05 09:00:00');

        $html = $this->get(route('admin.operational-costs.profit.index', ['dimension' => 'channel']))->assertOk()->getContent();
        $this->assertStringContainsString('value="2026-11-01"', $html);
        $this->assertStringContainsString('value="2026-11-30"', $html);
        $this->assertStringContainsString('<div class="k-stat__value k-num">100.00 SAR</div>', $html, 'Only November is read.');

        $bad = $this->get(route('admin.operational-costs.profit.index', ['from' => '2026-12-31', 'to' => '2026-01-01']))->assertOk()->getContent();
        $this->assertStringContainsString(e(translate('the_dates_were_not_valid_the_current_month_is_shown')), $bad);
        $this->get(route('admin.operational-costs.profit.index', ['from' => '2026-11-01']))->assertOk()->assertSee(e(translate('the_dates_were_not_valid_the_current_month_is_shown')), false);
    }

    public function test_a_report_that_hit_the_limit_on_sales_read_says_so(): void
    {
        $this->channelSale('100');
        $this->channelSale('100');
        $this->channelSale('100');
        $this->app->instance(ProfitReportBuilder::class, new ProfitReportBuilder(
            app(SaleDocumentReader::class), app(ProfitabilityService::class), app(ProfitAggregator::class), app(ProductProfitability::class), app(ProductNames::class),
            documentLimit: 2, productDocumentLimit: 1,
        ));

        $channel = $this->profit(['dimension' => 'channel']);
        $this->assertStringContainsString(e(translate('this_report_was_cut_short')), $channel);
        $this->assertStringContainsString('<div class="k-stat__value k-num">200.00 SAR</div>', $channel, 'Only the two sales that were read are added up.');

        $product = $this->profit(['dimension' => 'product']);
        $this->assertStringContainsString(e(translate('this_report_was_cut_short')), $product);
        $this->assertStringContainsString(e(CostLabels::filled(translate('only_the_first_sales_of_the_period_were_read'), ['limit' => 1])), $product, 'The by-product report reads fewer sales.');
    }

    public function test_a_long_list_stops_at_its_limit_and_the_totals_still_count_every_line(): void
    {
        $total = ProfitabilityController::LINE_LIMIT + 1;
        $account = $this->channelSale('10');
        for ($index = 1; $index < $total; $index++) {
            $mapping = ExternalOrderMapping::create([
                'account_id' => $account->account_id, 'sales_channel' => 'trendyol', 'seller_id' => 0, 'external_order_id' => 'BULK-' . $index, 'external_package_id' => 'P' . $index, 'status' => 'delivered',
                'currency' => 'SAR', 'prices_include_tax' => false, 'purchased_at' => '2026-11-06 09:00:00',
            ]);
            ExternalOrderLine::create(['mapping_id' => $mapping->id, 'external_line_id' => 'BL' . $index, 'product_id' => 50, 'quantity' => 1, 'quantity_cancelled' => 0, 'line_total' => '10', 'discount_total' => '0', 'tax_total' => '0', 'currency' => 'SAR', 'status' => 'open']);
        }

        $html = $this->profit(['dimension' => 'order']);

        $this->assertSame(ProfitabilityController::LINE_LIMIT, substr_count($html, '/profit/orders/channel_order/'));
        $this->assertStringContainsString(e(CostLabels::filled(translate('only_the_first_lines_are_listed_the_totals_count_all_of_them'), ['shown' => ProfitabilityController::LINE_LIMIT, 'total' => $total])), $html);
        $this->assertStringContainsString('<div class="k-stat__value k-num">' . CostFormat::amount(Money::of($total * 10), 'SAR') . '</div>', $html);
    }

    public function test_the_drill_down_lists_every_cost_entry_behind_the_result_with_its_state(): void
    {
        $sales = $this->books();
        $trendyol = $sales['trendyol'];
        $expected = app(ProfitabilityService::class)->forDocument(app(SaleDocumentReader::class)->forChannelOrder($trendyol->id));

        $html = $this->get(route('admin.operational-costs.profit.order', ['type' => 'channel_order', 'id' => $trendyol->id]))->assertOk()->getContent();

        foreach ([$expected->revenue, $expected->estimatedCost, $expected->actualCost, $expected->estimatedProfit(), $expected->actualProfit()] as $figure) {
            $this->assertStringContainsString('<div class="k-stat__value k-num">' . CostFormat::amount($figure, 'SAR') . '</div>', $html);
        }
        $this->assertStringContainsString(e(translate('a_marketplace_payout_is_not_revenue_note')), $html);
        $this->assertStringContainsString(e(translate('cost_component_shipping')), $html);
        $this->assertStringContainsString(e(translate('cost_component_picking')), $html);
        $this->assertStringContainsString(e(translate('cost_state_actual')), $html);
        $this->assertStringContainsString(e(translate('cost_state_estimated')), $html);
        $this->assertStringContainsString('30.00 SAR', $html);
        $this->assertStringContainsString('12.00 SAR', $html);
        $this->assertStringContainsString(e(translate('incomplete')) . ': 1 ' . e(translate('pending')), $html);
        $this->assertSame(3, substr_count($html, '/admin/operational-costs/entries/'), 'All three entries of the sale (goods, shipping, picking) are linked.');
    }

    public function test_a_sale_nobody_recorded_a_cost_for_shows_no_cost_and_no_profit_instead_of_a_perfect_margin(): void
    {
        $sale = $this->channelSale('80');

        $html = $this->get(route('admin.operational-costs.profit.order', ['type' => 'channel_order', 'id' => $sale->id]))->assertOk()->getContent();

        preg_match_all('#<div class="k-stat__value k-num">([^<]*)</div>#', $html, $tiles);
        $this->assertSame(['80.00 SAR', '—', '—', '—', '—'], $tiles[1], 'Revenue is known; the costs and so the profits are not.');
        $this->assertSame(2, substr_count($html, e(translate('nothing_recorded'))));
        $this->assertStringContainsString(e(translate('this_figure_is_less_than_certain')), $html);
        $this->assertStringContainsString(e(translate('no_costs_recorded_for_this_sale')), $html);
        $this->assertStringNotContainsString(e(translate('complete')) . '</span>', $html, 'Nothing is complete when nothing was recorded.');
        $this->assertStringNotContainsString('100.00%', $html);
    }

    public function test_the_sale_by_sale_table_gives_a_sale_nobody_recorded_a_cost_for_dashes_and_not_a_perfect_margin(): void
    {
        $sales = $this->books();
        $uncosted = $this->channelSale('80', 'trendyol', '2026-11-12 10:00:00');

        $html = $this->profit(['dimension' => 'order']);

        $bare = $this->rowWith($html, $this->reference($uncosted));
        $this->assertStringContainsString(e(translate('nothing_recorded')), $bare);
        $this->assertStringContainsString('80.00', $bare, 'Its revenue is known.');
        preg_match_all('#<td class="k-table__num[^"]*">(.*?)</td>#s', $bare, $cells);
        $this->assertSame(['1', '80.00', '—', '—'], array_map(static fn (string $cell): string => trim(strip_tags($cell)), array_slice($cells[1], 0, 4)));
        $this->assertStringNotContainsString('100.00%', $bare);
        $this->assertStringNotContainsString(e(translate('complete')) . '</span>', $bare);

        $costed = $this->rowWith($html, $this->reference($sales['trendyol']));
        $this->assertStringNotContainsString(e(translate('nothing_recorded')), $costed);
    }

    public function test_a_sale_with_costs_shows_its_profit_and_no_warning_about_missing_records(): void
    {
        $sale = $this->books()['trendyol'];

        $html = $this->get(route('admin.operational-costs.profit.order', ['type' => 'channel_order', 'id' => $sale->id]))->assertOk()->getContent();

        preg_match_all('#<div class="k-stat__value k-num">([^<]*)</div>#', $html, $tiles);
        $this->assertSame(['200.00 SAR', '102.00 SAR', '90.00 SAR', '98.00 SAR', '110.00 SAR'], $tiles[1]);
        $this->assertStringNotContainsString(e(translate('nothing_recorded')), $html);
    }

    public function test_the_report_counts_the_sales_nobody_recorded_a_cost_for_and_warns_about_them(): void
    {
        $this->books();
        $this->channelSale('80', 'trendyol', '2026-11-12 10:00:00');
        $this->channelSale('60', 'amazon', '2026-11-13 10:00:00');

        $html = $this->get(route('admin.operational-costs.profit.index', self::NOVEMBER))->assertOk()->getContent();

        $this->assertStringContainsString(e(CostLabels::filled(translate('sales_have_no_cost_recorded_at_all'), ['count' => 2])), $html);
        $this->assertStringContainsString(e(translate('their_profit_is_only_their_revenue_until_their_costs_are_recorded')), $html);
    }

    public function test_a_report_whose_sales_all_have_costs_does_not_raise_that_warning(): void
    {
        $this->books();

        $html = $this->get(route('admin.operational-costs.profit.index', self::NOVEMBER))->assertOk()->getContent();

        $this->assertStringNotContainsString(e(translate('their_profit_is_only_their_revenue_until_their_costs_are_recorded')), $html);
    }

    public function test_the_overview_warns_about_uncosted_sales_in_the_month_too(): void
    {
        $this->channelSale('80', 'trendyol', '2026-11-12 10:00:00');

        $html = $this->get(route('admin.operational-costs.index', self::NOVEMBER))->assertOk()->getContent();

        $this->assertStringContainsString(e(CostLabels::filled(translate('sales_have_no_cost_recorded_at_all'), ['count' => 1])), $html);
    }

    public function test_a_cost_nobody_could_determine_is_missing_in_the_drill_down_too_and_never_a_zero(): void
    {
        $amazon = $this->books()['amazon'];

        $html = $this->get(route('admin.operational-costs.profit.order', ['type' => 'channel_order', 'id' => $amazon->id]))->assertOk()->getContent();

        $row = $this->rowWith($html, e(translate('cost_component_marketplace_commission')) . '</td>');
        $this->assertStringContainsString(e(translate('cost_state_missing')), $row);
        $this->assertStringContainsString(e(translate('missing_reason_no_contract')), $row);
        $this->assertStringNotContainsString('0.00', $row);
        $this->assertStringContainsString(e(translate('incomplete')) . ': 1 ' . e(translate('missing')), $html);
    }

    public function test_a_storefront_order_can_be_drilled_into_and_a_cancelled_sale_has_no_revenue(): void
    {
        $this->storefrontSale(900, 40);
        DB::table('orders')->insert(['id' => 901, 'order_status' => 'canceled', 'order_amount' => 10, 'shipping_cost' => 0, 'seller_is' => 'admin', 'created_at' => '2026-11-06 10:00:00', 'updated_at' => '2026-11-06 10:00:00']);
        DB::table('order_details')->insert(['order_id' => 901, 'product_id' => 50, 'qty' => 1, 'price' => 10, 'discount' => 0, 'tax' => 0]);
        $expected = app(ProfitabilityService::class)->forDocument(app(SaleDocumentReader::class)->forOrder(900));

        $html = $this->get(route('admin.operational-costs.profit.order', ['type' => 'order', 'id' => 900]))->assertOk()->getContent();
        $cancelled = $this->get(route('admin.operational-costs.profit.order', ['type' => 'order', 'id' => 901]))->assertOk()->getContent();

        $this->assertSame('150.00', $expected->revenue->toDisplay(), '40 of the stored unit at the store\'s own rate.');
        $this->assertStringContainsString('<div class="k-stat__value k-num">150.00 SAR</div>', $html);
        $this->assertStringContainsString(e(translate('a_cancelled_sale_has_no_revenue')), $cancelled);
        $this->assertStringContainsString('<div class="k-stat__value k-num">0.00 SAR</div>', $cancelled);
    }

    public function test_an_unknown_sale_or_kind_of_sale_is_not_found(): void
    {
        $this->get(route('admin.operational-costs.profit.order', ['type' => 'channel_order', 'id' => 999]))->assertNotFound();
        $this->get(route('admin.operational-costs.profit.order', ['type' => 'order', 'id' => 999]))->assertNotFound();
        $this->get('/admin/operational-costs/profit/orders/payout/1')->assertNotFound();
        $this->get('/admin/operational-costs/profit/orders/order/abc')->assertNotFound();
    }

    public function test_a_marketplace_payout_is_not_a_sale_and_is_listed_nowhere(): void
    {
        $sale = $this->channelSale('100');
        $this->recordCost(CostState::Actual, '15', CostComponent::MarketplaceCommission, '2026-11-05', ['mappingId' => $sale->id, 'channel' => 'trendyol']);
        $expected = app(ProfitabilityService::class)->forDocument(app(SaleDocumentReader::class)->forChannelOrder($sale->id));

        $html = $this->profit(['dimension' => 'order']);

        $this->assertSame('100.00', $expected->revenue->toDisplay(), 'What the marketplace later pays out is not added to the revenue of the sale it pays for.');
        $this->assertSame(1, substr_count($html, '/profit/orders/channel_order/'));
    }
}
