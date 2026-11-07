<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Http\Controllers\Admin\OperationalCosts\CostLabels;
use App\Models\ExternalOrderMapping;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\OperationalCosts\Reports\CsvExporter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesOperationalCostData;
use Tests\Support\FakeCurrencyRates;

/**
 * What an administrator sees, and what the file says, when a currency has no recorded exchange rate: a figure built on it is flagged incomplete with the currency named,
 * a revenue nobody could convert is a dash and not a 0.00, the total says so too, and the note says how to put it right.
 */
class AdminOperationalCostsExchangeRateGapsTest extends AdminPagesTestCase
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

    private function sale(string $currency, string $lineTotal, int $productId, string $channel = 'trendyol'): ExternalOrderMapping
    {
        $sale = $this->channelSale($lineTotal, $channel, '2026-11-05 09:00:00', productId: $productId);
        $sale->forceFill(['currency' => $currency])->save();
        $sale->lines()->update(['currency' => $currency]);

        return $sale->fresh();
    }

    private function cost(ExternalOrderMapping $sale, string $amount, int $productId, string $currency = 'SAR', CostComponent $component = CostComponent::Shipping): void
    {
        $this->recordCost(CostState::Actual, $amount, $component, '2026-11-05', ['mappingId' => $sale->id, 'channel' => $sale->sales_channel, 'accountId' => $sale->account_id, 'productId' => $productId, 'currency' => $currency]);
    }

    /** Product 50: a sale in riyals with a euro cost. Product 51: a sale in euros only. */
    private function books(): void
    {
        $riyals = $this->sale('SAR', '100', 50);
        $this->cost($riyals, '10', 50);
        $this->cost($riyals, '20', 50, 'EUR', CostComponent::MarketplaceCommission);
        $euros = $this->sale('EUR', '80', 51, 'amazon');
        $this->cost($euros, '6', 51);
    }

    private function page(array $query = []): string
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

    /** The markup of one tile of the totals: from its label to the start of the next tile. */
    private function statBlock(string $html, string $labelKey): string
    {
        foreach (explode('<div class="k-stat__head">', $html) as $segment) {
            if (str_starts_with(ltrim($segment), '<span>' . e(translate($labelKey)) . '</span>')) {
                return $segment;
            }
        }
        $this->fail("No tile {$labelKey}.");
    }

    private function statValue(string $html, string $labelKey): string
    {
        $this->assertSame(1, preg_match('#<span>' . preg_quote(e(translate($labelKey)), '#') . '</span>.*?<div class="k-stat__value k-num">(.*?)</div>#s', $html, $found), "No tile {$labelKey}.");

        return trim($found[1]);
    }

    private function sentence(string $currencies = 'EUR'): string
    {
        return e(CostLabels::filled(translate('no_exchange_rate_for_currencies'), ['currencies' => $currencies]));
    }

    public function test_by_product_a_row_with_a_cost_that_has_no_rate_is_flagged_and_still_shows_the_figures_that_could_be_computed(): void
    {
        $this->books();

        $row = $this->rowWith($this->page(['dimension' => 'product']), '#50');

        $this->assertStringContainsString($this->sentence(), $row);
        $this->assertStringContainsString(e(translate('incomplete')), $row);
        $this->assertStringContainsString('100.00', $row, 'The revenue that could be converted.');
        $this->assertStringContainsString('90.00', $row, 'The figure that could be computed (100 - 10); the flag says it is not final.');
    }

    public function test_by_product_a_row_whose_revenue_is_all_unconvertible_shows_the_revenue_and_the_profit_as_unknown_not_as_zero(): void
    {
        $this->books();

        $row = $this->rowWith($this->page(['dimension' => 'product']), '#51');

        $this->assertStringContainsString($this->sentence(), $row);
        $this->assertStringNotContainsString('0.00', $row, 'No revenue of 0.00 and no profit of -6.00 is invented for a sale nobody could convert.');
        $this->assertGreaterThanOrEqual(3, substr_count($row, '—'), 'The revenue and the two profits are dashes.');
    }

    public function test_the_totals_inherit_the_flag_and_the_note_says_how_to_put_it_right(): void
    {
        $this->books();

        $html = $this->page(['dimension' => 'product']);

        $this->assertStringContainsString('data-profit-gap="exchange-rate"', $html);
        $this->assertStringContainsString(e(CostLabels::filled(translate('profit_no_exchange_rate_note'), ['currencies' => 'EUR'])), $html);
        $this->assertStringContainsString($this->sentence(), $html, 'The tiles of the total carry the flag too.');
        $this->assertStringContainsString(e(translate('this_figure_is_less_than_certain')), $html);
    }

    public function test_a_total_whose_every_revenue_is_unconvertible_shows_a_dash_for_revenue_and_profit(): void
    {
        $this->cost($this->sale('EUR', '80', 51), '6', 51);

        $html = $this->page(['dimension' => 'product']);

        foreach (['recognised_revenue', 'estimated_profit', 'actual_profit'] as $tile) {
            $this->assertSame('—', $this->statValue($html, $tile), "{$tile} is unknown, not 0.00 SAR or -6.00 SAR.");
        }
        $this->assertSame('6.00 SAR', $this->statValue($html, 'actual_cost'), 'What could be converted is still counted.');
    }

    public function test_a_total_with_part_of_its_revenue_converted_shows_it_flagged_and_not_as_a_clean_number(): void
    {
        $this->books();

        $html = $this->page(['dimension' => 'product']);

        $this->assertSame('100.00 SAR', $this->statValue($html, 'recognised_revenue'));
        $this->assertStringContainsString($this->sentence(), $this->statBlock($html, 'estimated_profit'));
        $this->assertStringContainsString($this->sentence(), $this->statBlock($html, 'recognised_revenue'));
    }

    /** @return iterable<string, array{string}> */
    public static function groupedViews(): iterable
    {
        yield 'by channel' => ['channel'];
        yield 'by provider' => ['provider'];
        yield 'by order' => ['order'];
    }

    #[DataProvider('groupedViews')]
    public function test_the_other_grouped_views_flag_a_gap_the_same_way(string $dimension): void
    {
        $this->books();

        $html = $this->page(['dimension' => $dimension]);

        $this->assertStringContainsString($this->sentence(), $html, $dimension);
        $this->assertStringContainsString('data-profit-gap="exchange-rate"', $html, $dimension);
    }

    public function test_by_channel_a_channel_whose_revenue_is_all_unconvertible_shows_it_as_unknown(): void
    {
        $this->books();

        $row = $this->rowWith($this->page(['dimension' => 'channel']), e(translate('sales_channel_amazon')));

        $this->assertStringNotContainsString('0.00', $row);
        $this->assertStringContainsString($this->sentence(), $row);
    }

    public function test_the_overview_flags_the_same_gap(): void
    {
        $this->books();

        $html = $this->get(route('admin.operational-costs.index', self::NOVEMBER))->assertOk()->getContent();

        $this->assertStringContainsString($this->sentence(), $html);
        $this->assertStringContainsString('data-profit-gap="exchange-rate"', $html);
    }

    public function test_the_sale_page_of_a_sale_in_a_currency_with_no_rate_says_so(): void
    {
        $euros = $this->sale('EUR', '80', 51, 'amazon');
        $this->cost($euros, '6', 51);

        $html = $this->get(route('admin.operational-costs.profit.order', ['type' => 'channel_order', 'id' => $euros->id]))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('profit_note_revenue_not_convertible')), $html);
        $this->assertStringContainsString($this->sentence(), $html);
    }

    public function test_once_a_rate_is_recorded_the_flag_is_gone_and_the_figures_are_whole(): void
    {
        $this->books();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates(['USD' => 1.0, 'SAR' => 3.75, 'EUR' => 0.9]));

        $html = $this->page(['dimension' => 'product']);

        $this->assertStringNotContainsString('data-profit-gap', $html);
        $this->assertStringNotContainsString(e(translate('no_exchange_rate_for_currencies')), $html);
        $this->assertStringNotContainsString($this->sentence(), $html);
    }

    /** @return array{0: string, 1: list<list<string>>} */
    private function download(string $dimension): array
    {
        Cache::flush();
        $csv = $this->get(route('admin.operational-costs.profit.export', ['dimension' => $dimension] + self::NOVEMBER))->assertOk()->streamedContent();

        return [$csv, array_map('str_getcsv', explode("\r\n", rtrim(substr($csv, strlen(CsvExporter::BYTE_ORDER_MARK)), "\r\n")))];
    }

    /** @param list<list<string>> $rows */
    private function cell(array $rows, string $key, string $heading): string
    {
        $index = array_search(translate($heading), $rows[0], true);
        $this->assertNotFalse($index, "No column {$heading}.");
        foreach (array_slice($rows, 1) as $row) {
            if ($row[0] === $key) {
                return $row[$index];
            }
        }
        $this->fail("No row {$key}.");
    }

    public function test_the_by_product_file_carries_the_flag_the_counts_and_the_currencies_and_leaves_an_unknown_revenue_empty(): void
    {
        $this->books();

        [$csv, $rows] = $this->download('product');

        $this->assertSame('1', $this->cell($rows, '50', 'incomplete_no_exchange_rate'));
        $this->assertSame('0', $this->cell($rows, '50', 'unconverted_revenue_amounts'));
        $this->assertSame('1', $this->cell($rows, '50', 'unconverted_cost_entries'));
        $this->assertSame('EUR', $this->cell($rows, '50', 'currencies_without_a_rate'));
        $this->assertSame('100.000000', $this->cell($rows, '50', 'revenue'));
        $this->assertSame('1', $this->cell($rows, '51', 'unconverted_revenue_amounts'));
        $this->assertSame('', $this->cell($rows, '51', 'revenue'), 'Unknown, not 0.');
        $this->assertSame('', $this->cell($rows, '51', 'estimated_profit'));
        $this->assertSame('', $this->cell($rows, '51', 'actual_margin'));
        $this->assertStringContainsString(CostLabels::filled(translate('profit_report_has_amounts_without_an_exchange_rate'), ['currencies' => 'EUR']), $csv, 'The note says how to put it right.');
    }

    public function test_the_other_files_carry_the_flag_too(): void
    {
        $this->books();
        [, $byChannel] = $this->download('channel');
        [, $byOrder] = $this->download('order');

        $this->assertSame('1', $this->cell($byChannel, 'amazon', 'incomplete_no_exchange_rate'));
        $this->assertSame('', $this->cell($byChannel, 'amazon', 'revenue'));
        $this->assertSame('0', $this->cell($byChannel, 'trendyol', 'unconverted_revenue_amounts'));
        $this->assertContains('EUR', array_map(fn (array $row): string => $row[array_search(translate('currencies_without_a_rate'), $byOrder[0], true)] ?? '', array_slice($byOrder, 1)));
    }

    public function test_a_file_of_books_with_a_rate_for_every_currency_carries_no_flag_and_no_note(): void
    {
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates(['USD' => 1.0, 'SAR' => 3.75, 'EUR' => 0.9]));
        $this->books();

        [$csv, $rows] = $this->download('product');

        $this->assertSame('0', $this->cell($rows, '50', 'incomplete_no_exchange_rate'));
        $this->assertSame('', $this->cell($rows, '50', 'currencies_without_a_rate'));
        $this->assertNotSame('', $this->cell($rows, '51', 'revenue'));
        $this->assertStringNotContainsString('no recorded exchange rate', $csv);
    }

    public function test_the_disputed_count_is_a_column_of_its_own(): void
    {
        $sale = $this->sale('SAR', '100', 50);
        $this->cost($sale, '10', 50);
        app(\App\Services\OperationalCosts\CostLedger::class)->dispute(\App\Models\OperationalCostEntry::firstOrFail(), 'overcharged');

        [, $rows] = $this->download('product');

        $this->assertSame('1', $this->cell($rows, '50', 'disputed_costs_count'));
        $this->assertSame('0', $this->cell($rows, '50', 'incomplete_no_exchange_rate'), 'A charge in dispute is not an amount with no rate.');
        $this->assertSame('10.000000', $this->cell($rows, '50', 'disputed_cost'));
    }
}
