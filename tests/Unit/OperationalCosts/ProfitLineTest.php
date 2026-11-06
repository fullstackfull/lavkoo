<?php

namespace Tests\Unit\OperationalCosts;

use App\Services\OperationalCosts\Profit\CostBucket;
use App\Services\OperationalCosts\Profit\ProfitResult;
use App\Services\OperationalCosts\Profit\ProfitRow;
use App\Services\OperationalCosts\Reports\ProfitLine;
use App\Services\OperationalCosts\Support\Money;
use PHPUnit\Framework\TestCase;

class ProfitLineTest extends TestCase
{
    /** @param array<string, CostBucket> $buckets */
    private function sale(array $buckets, string $estimatedCost = '0', string $actualCost = '0', array $missing = []): ProfitResult
    {
        return new ProfitResult(
            subjectType: 'channel_order',
            subjectId: 7,
            salesChannel: 'trendyol',
            accountId: 3,
            provider: null,
            fulfillmentMode: null,
            state: 'delivered',
            currency: 'SAR',
            occurredOn: '2026-11-05',
            reference: 'TY-7',
            revenue: Money::of('200'),
            buckets: $buckets,
            estimatedCost: Money::of($estimatedCost),
            actualCost: Money::of($actualCost),
            disputedCost: Money::zero(),
            missingComponents: $missing,
            pendingActualComponents: [],
            revenueTrusted: true,
            notes: [],
        );
    }

    public function test_a_sale_with_costs_gives_its_cost_profit_and_margin(): void
    {
        $line = ProfitLine::ofResult($this->sale(['shipping' => new CostBucket('shipping', 'fulfillment')], estimatedCost: '50', actualCost: '30'));

        $this->assertTrue($line->costed);
        $this->assertSame('50.00', $line->estimatedCost()->toDisplay());
        $this->assertSame('30.00', $line->actualCost()->toDisplay());
        $this->assertSame('150.00', $line->estimatedProfit()->toDisplay());
        $this->assertSame('170.00', $line->actualProfit()->toDisplay());
        $this->assertSame('75.00', $line->estimatedMarginPercent()->toDisplay());
        $this->assertSame(0, $line->incompleteEstimated);
        $this->assertSame(0, $line->incompleteActual);
    }

    public function test_a_sale_no_cost_was_recorded_for_gives_no_cost_and_no_profit_and_counts_as_a_gap(): void
    {
        $line = ProfitLine::ofResult($this->sale([]));

        $this->assertFalse($line->costed);
        $this->assertSame('200.00', $line->revenue()->toDisplay(), 'Its revenue is known.');
        foreach ([$line->estimatedCost(), $line->actualCost(), $line->estimatedProfit(), $line->actualProfit(), $line->estimatedMarginPercent(), $line->actualMarginPercent()] as $unknown) {
            $this->assertNull($unknown, 'A cost nobody recorded is not a zero, and a profit built on it is not the revenue.');
        }
        $this->assertSame(1, $line->incompleteEstimated);
        $this->assertSame(1, $line->incompleteActual);
    }

    public function test_a_group_of_sales_is_always_costed_because_its_figures_are_the_aggregates(): void
    {
        $row = new ProfitRow('trendyol', 'trendyol');
        $row->revenue = Money::of('300');
        $row->orders = 2;

        $line = ProfitLine::ofRow($row, 'Trendyol', groupsSales: true);

        $this->assertTrue($line->costed);
        $this->assertSame('0.00', $line->estimatedCost()->toDisplay());
        $this->assertSame('300.00', $line->estimatedProfit()->toDisplay());
    }
}
