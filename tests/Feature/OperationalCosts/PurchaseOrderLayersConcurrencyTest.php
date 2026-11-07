<?php

namespace Tests\Feature\OperationalCosts;

use App\Models\InventoryCostLayer;
use App\Services\OperationalCosts\PurchaseOrders\PurchaseOrderLayerService;
use App\Services\OperationalCosts\PurchaseOrders\TrancheOutcome;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsPlatformProcurementSchema;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\TestCase;

/**
 * Several processes at the same instant against a real MySQL or MariaDB: the layer table's unique key on a purchase-order line and where its units start is what keeps a unit from
 * being layered twice, whatever each process saw. Skipped on SQLite, which is private to one process.
 */
class PurchaseOrderLayersConcurrencyTest extends TestCase
{
    use BuildsPlatformProcurementSchema;
    use BuildsSalesChannelSchema;

    protected function setUp(): void
    {
        parent::setUp();
        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }
        $this->buildSalesChannelSchema();
        $this->buildPlatformProcurementSchema();
        config(['operational_costs.base_currency' => 'SAR', 'fulfillment.currency' => 'SAR', 'operational_costs.enabled' => true, 'operational_costs.sources.purchase_orders' => 'on']);
        app(InventoryLocationService::class)->ensureDefaults();
    }

    /** @return int the id of a line that has received `$quantity` units on the platform */
    private function receivedLine(int $quantity, float $cost = 12.5): int
    {
        $product = $this->platformProduct();
        $order = $this->purchaseOrder($this->supplier(), [[$product, $quantity, $cost]]);

        return $this->receiveOnPlatform($order->items->first(), $quantity)->id;
    }

    /** @param list<array<string, mixed>> $reports @return array<string, int> */
    private function outcomes(array $reports): array
    {
        $this->assertSame(array_fill(0, count($reports), true), array_column($reports, 'ok'), 'No process failed: ' . json_encode(array_column($reports, 'message')));

        return array_count_values(array_column($reports, 'result'));
    }

    public function test_eight_processes_running_the_command_at_once_make_exactly_one_layer_for_each_line(): void
    {
        $lines = [$this->receivedLine(10), $this->receivedLine(6)];

        $reports = ConcurrentRunner::run('layers_from_purchase_orders', array_fill(0, 8, ['since' => '2000-01-01']));

        $this->assertSame(array_fill(0, 8, true), array_column($reports, 'ok'), json_encode(array_column($reports, 'message')));
        $this->assertSame(2, array_sum(array_column(array_column($reports, 'result'), 'created')), 'Between the eight of them: one layer per line.');
        $this->assertSame(2, InventoryCostLayer::count());
        $this->assertSame([$lines[0] => 10, $lines[1] => 6], InventoryCostLayer::orderBy('source_id')->pluck('quantity_received', 'source_id')->all());
        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the processes really ran at the same time');
        $this->assertSame(0, $this->service()->apply(since: CarbonImmutable::parse('2000-01-01'))->created, 'And a run afterwards finds nothing left to do.');
    }

    /** Each writes its layer in a transaction held open for 0.4 seconds, so they overlap for certain: only the key on the line and where its units start can decide between them. */
    public function test_two_runs_that_saw_the_line_at_different_moments_make_one_layer_for_the_units_both_start_at_and_the_rest_follows(): void
    {
        $line = $this->receivedLine(10);
        $first = ['line_id' => $line, 'from' => 0, 'to' => 3, 'received_at' => '2026-11-03 09:00:00', 'hold_microseconds' => 400000];
        $second = ['line_id' => $line, 'from' => 0, 'to' => 10, 'received_at' => '2026-11-09 11:00:00', 'hold_microseconds' => 400000];

        $reports = ConcurrentRunner::run('layer_purchase_order_tranche', [$first, $second]);

        $outcomes = $this->outcomes($reports);
        $this->assertEquals([TrancheOutcome::CREATED => 1, TrancheOutcome::OVERTAKEN => 1], $outcomes, 'The one that got there second was told the units were taken: ' . json_encode($outcomes));
        $this->assertSame(1, InventoryCostLayer::count(), 'Whichever tranche got there first, the units from 0 are in one layer, not two.');
        $this->assertTrue(ConcurrentRunner::overlapped($reports));

        $this->service()->apply(since: CarbonImmutable::parse('2000-01-01'));
        $layers = InventoryCostLayer::orderBy('source_from')->get();
        $this->assertSame(10, (int) $layers->sum('quantity_received'), 'The units of the line, once, whichever one won.');
        $this->assertSame($layers->pluck('source_from')->all(), $layers->map(fn ($layer, $index): int => $index === 0 ? 0 : (int) $layers[$index - 1]->source_to)->all(), 'No two layers overlap and none leaves a gap.');
    }

    public function test_many_lines_layered_by_many_processes_at_once_lose_no_layer_to_a_deadlock_or_a_duplicate_key(): void
    {
        $lines = array_map(fn (int $number): int => $this->receivedLine($number + 1), range(1, 12));

        $reports = ConcurrentRunner::run('layers_from_purchase_orders', array_fill(0, 6, ['since' => '2000-01-01']));

        $this->assertSame(array_fill(0, 6, true), array_column($reports, 'ok'), json_encode(array_column($reports, 'message')));
        $this->assertSame(12, array_sum(array_column(array_column($reports, 'result'), 'created')));
        $this->assertSame($lines, InventoryCostLayer::orderBy('source_id')->pluck('source_id')->all());
    }

    private function service(): PurchaseOrderLayerService
    {
        return app(PurchaseOrderLayerService::class);
    }
}
