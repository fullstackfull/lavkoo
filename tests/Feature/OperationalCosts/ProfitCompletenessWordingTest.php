<?php

namespace Tests\Feature\OperationalCosts;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Http\Controllers\Admin\OperationalCosts\CostLabels;
use App\Models\ExternalOrderMapping;
use App\Services\OperationalCosts\Profit\ConversionGaps;
use Illuminate\Support\Facades\DB;
use Tests\Feature\SalesChannels\AdminPagesTestCase;
use Tests\Support\CreatesOperationalCostData;

/**
 * What the profit page says about how complete a figure is. A figure that left an amount out for want of an exchange rate, or that has a cost nobody determined, is never "Complete":
 * not on a row, not in a total, and not when the grouping is by product (a product adds up sale lines, so its own count of incomplete sales is zero).
 */
class ProfitCompletenessWordingTest extends AdminPagesTestCase
{
    use CreatesOperationalCostData;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fulfillment.currency' => 'SAR']);
        foreach ([[50, 'Vitamin C 1000'], [51, 'Panadol Extra'], [52, 'Aspirin']] as [$id, $name]) {
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

    private function byProduct(): string
    {
        return $this->get(route('admin.operational-costs.profit.index', ['dimension' => 'product', 'from' => '2026-11-01', 'to' => '2026-11-30']))->assertOk()->getContent();
    }

    /** The table row of the product named on the page. */
    private function rowOf(string $html, string $name): string
    {
        preg_match('#<tr>(?:(?!</tr>).)*' . preg_quote($name, '#') . '.*?</tr>#s', $html, $found);

        return (string) ($found[0] ?? '');
    }

    private function cost(ExternalOrderMapping $sale, string $amount, int $productId): void
    {
        $this->recordCost(CostState::Actual, $amount, CostComponent::Shipping, '2026-11-05', ['mappingId' => $sale->id, 'channel' => $sale->sales_channel, 'accountId' => $sale->account_id, 'productId' => $productId]);
    }

    public function test_the_totals_by_product_do_not_say_complete_when_a_rate_is_missing(): void
    {
        $riyals = $this->sale('SAR', '100', 50);
        $this->cost($riyals, '10', 50);
        $euros = $this->sale('EUR', '80', 51, 'amazon');
        $this->cost($euros, '6', 51);

        $html = $this->byProduct();

        $this->assertStringNotContainsString(e(translate('complete')) . ' ·', $html, 'The tiles read "Complete · Incomplete: No exchange rate for EUR" (a contradiction).');
        $this->assertStringContainsString(e(translate('incomplete')) . ': ' . e(CostLabels::noExchangeRate(new ConversionGaps(revenue: ['EUR' => 1], costs: ['EUR' => 1]))), $html);
    }

    public function test_a_product_row_with_an_amount_left_out_says_so_and_a_product_without_any_does_not(): void
    {
        $riyals = $this->sale('SAR', '100', 50);
        $this->cost($riyals, '10', 50);
        $euros = $this->sale('EUR', '80', 51, 'amazon');
        $this->cost($euros, '6', 51);

        $html = $this->byProduct();

        $this->assertStringNotContainsString(e(translate('incomplete')), $this->rowOf($html, 'Vitamin C 1000'));
        $incomplete = $this->rowOf($html, 'Panadol Extra');
        $this->assertStringContainsString(e(translate('incomplete')), $incomplete);
        $this->assertStringNotContainsString(e(translate('complete')) . '<', $incomplete, 'No "Complete" on a row that left an amount out.');
    }

    public function test_a_product_with_a_cost_nobody_determined_is_not_complete_and_says_how_many(): void
    {
        $sale = $this->sale('SAR', '100', 52);
        $this->recordCost(CostState::Missing, null, CostComponent::Shipping, '2026-11-05', ['mappingId' => $sale->id, 'channel' => 'trendyol', 'accountId' => $sale->account_id, 'productId' => 52]);

        $html = $this->byProduct();

        $row = $this->rowOf($html, 'Aspirin');
        $this->assertStringContainsString(e(translate('incomplete')) . ': 1 ' . e(translate('missing')), $row);
        $this->assertStringNotContainsString('>' . e(translate('complete')) . '<', $row);
        $this->assertStringContainsString(e(translate('incomplete')) . ': 1 ' . e(translate('missing')), $html, 'And so do the totals.');
    }

    public function test_books_with_nothing_missing_and_nothing_left_out_still_read_complete(): void
    {
        $sale = $this->sale('SAR', '100', 50);
        $this->cost($sale, '10', 50);

        $html = $this->byProduct();

        $this->assertStringNotContainsString(e(translate('incomplete')), $html);
        $this->assertStringContainsString(e(translate('estimated_cost_includes_estimates')) . ' · ' . e(translate('complete')), $html);
        $this->assertStringContainsString(e(translate('actual_cost_is_settled_charges_only')) . ' · ' . e(translate('complete')), $html);
    }
}
