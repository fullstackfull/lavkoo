<?php

namespace Tests\Feature\OperationalCosts;

use App\Models\InventoryCostLayer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsPurchaseOrderWorld;
use Tests\TestCase;

/**
 * The units of a purchase-order line that the layers account for are worked out from the layers that exist, not from two columns alone: the migration that adds the columns can be
 * rolled back and applied again (a failed release followed by a second attempt), and that empties them for every layer made so far. The platform's tables are INFERRED.
 */
class PurchaseOrderLayersAfterRollbackTest extends TestCase
{
    use BuildsPurchaseOrderWorld;

    private const MIGRATION = 'migrations/2026_11_11_100000_add_purchase_order_sources_to_cost_tables.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00:00'));
        $this->setUpPurchaseOrderWorld();
    }

    private function rollBackAndApplyTheMigrationAgain(): void
    {
        $migration = require database_path(self::MIGRATION);
        $migration->down();
        $migration->up();
    }

    public function test_a_line_received_in_parts_is_not_layered_a_second_time_after_the_migration_was_rolled_back_and_applied_again(): void
    {
        [$product, $line] = $this->placedLine(ordered: 10, cost: 12.5);
        $line = $this->receiveOnPlatform($line, 3);
        $this->applyLayers();
        $this->receiveOnPlatform($line, 7);
        $this->applyLayers();
        $this->assertSame(10, (int) InventoryCostLayer::where('product_id', $product)->sum('quantity_received'), 'Two layers, ten units.');

        $this->rollBackAndApplyTheMigrationAgain();
        $this->assertSame([null, null], [InventoryCostLayer::orderBy('id')->first()->source_from, InventoryCostLayer::orderBy('id')->first()->source_to], 'The bookkeeping is gone.');
        $run = $this->applyLayers();

        $this->assertSame(10, (int) InventoryCostLayer::where('product_id', $product)->sum('quantity_received'));
        $this->assertSame([0, 1, 0], [$run->created, $run->upToDate, $run->receivedBelowLayered]);
    }

    public function test_what_is_received_after_the_rollback_is_layered_from_where_the_layers_end(): void
    {
        [$product, $line] = $this->placedLine(ordered: 15, cost: 12.5);
        $line = $this->receiveOnPlatform($line, 3);
        $this->applyLayers();
        $line = $this->receiveOnPlatform($line, 7);
        $this->applyLayers();
        $this->rollBackAndApplyTheMigrationAgain();
        $this->receiveOnPlatform($line, 5);

        $run = $this->applyLayers();

        $this->assertSame([1, 5], [$run->created, $run->createdUnits]);
        $this->assertSame([[null, null, 3], [null, null, 7], [10, 15, 5]], InventoryCostLayer::orderBy('id')->get(['source_from', 'source_to', 'quantity_received'])->map(static fn (InventoryCostLayer $layer): array => [$layer->source_from, $layer->source_to, (int) $layer->quantity_received])->all());
    }

    public function test_a_line_received_in_one_go_keeps_its_layer_after_the_rollback(): void
    {
        [$product, $line] = $this->placedLine(ordered: 10, cost: 12.5);
        $this->receiveOnPlatform($line, 10);
        $this->applyLayers();

        $this->rollBackAndApplyTheMigrationAgain();
        $run = $this->applyLayers();

        $this->assertSame([10, 0], [(int) InventoryCostLayer::where('product_id', $product)->sum('quantity_received'), $run->created]);
    }

    public function test_the_units_before_a_cutover_inside_a_receipt_stay_out_after_the_rollback_too(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00'));
        [$product, $line] = $this->placedLine(ordered: 10, cost: 12.5);
        $this->travelTo(CarbonImmutable::parse('2026-10-25 09:00:00'));
        $line = $this->receiveOnPlatform($line, 4);
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00:00'));
        $this->receiveOnPlatform($line, 6);
        $this->applyLayers(since: '2026-11-03');
        $this->assertSame([4, 10], [InventoryCostLayer::sole()->source_from, InventoryCostLayer::sole()->source_to], 'Six units: the first four are the opening balance\'s.');

        $this->rollBackAndApplyTheMigrationAgain();
        $run = $this->applyLayers(since: '2026-11-03');

        $this->assertSame([6, 0], [(int) InventoryCostLayer::where('product_id', $product)->sum('quantity_received'), $run->created]);
    }

    public function test_a_layer_whose_name_cannot_be_found_again_still_counts_for_the_units_it_holds(): void
    {
        [$product, $line] = $this->placedLine(ordered: 10, cost: 12.5);
        $line = $this->receiveOnPlatform($line, 3);
        $this->applyLayers();
        $this->receiveOnPlatform($line, 7);
        $this->applyLayers();
        $this->rollBackAndApplyTheMigrationAgain();
        foreach (InventoryCostLayer::where('product_id', $product)->pluck('id') as $id) {
            DB::table('inventory_cost_layers')->where('id', $id)->update(['dedupe_key' => 'renamed-' . $id]);
        }

        $run = $this->applyLayers();

        $this->assertSame([10, 0], [(int) InventoryCostLayer::where('product_id', $product)->sum('quantity_received'), $run->created]);
    }
}
