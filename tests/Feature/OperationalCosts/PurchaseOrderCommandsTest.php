<?php

namespace Tests\Feature\OperationalCosts;

use App\Models\InventoryLocation;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsPurchaseOrderWorld;
use Tests\TestCase;

/**
 * What the two commands of the purchase-order source say and do at the edges: before anything exists, when something is not there, and when they are asked for JSON. They write
 * nothing they were not asked to, say what the real run will do, and answer in the format they were asked for even when they refuse. The platform's tables are INFERRED.
 */
class PurchaseOrderCommandsTest extends TestCase
{
    use BuildsPurchaseOrderWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00:00'));
        $this->setUpPurchaseOrderWorld();
    }

    public function test_apply_with_a_platform_table_missing_makes_nothing_not_even_the_default_stock_locations(): void
    {
        Schema::drop('purchase_order_items');
        DB::table('inventory_locations')->delete();
        $before = $this->rowCounts();

        $this->artisan('costs:layers-from-purchase-orders', ['--apply' => true, '--since' => '2000-01-01'])->expectsOutputToContain('not complete here')->assertExitCode(1);

        $this->assertSame($before, $this->rowCounts(), 'The command said "Nothing was read or written": it made no stock location either.');
    }

    public function test_the_dry_run_says_what_apply_will_make_before_the_default_stock_locations_exist_and_writes_nothing(): void
    {
        [, $line] = $this->placedLine(ordered: 10, cost: 12.5);
        $this->receiveOnPlatform($line, 10);
        DB::table('inventory_locations')->delete();
        Cache::forget('sales_channels.default_locations_checked');
        $before = $this->rowCounts();

        Artisan::call('costs:layers-from-purchase-orders', ['--json' => true, '--since' => '2000-01-01']);
        $dryRun = json_decode(Artisan::output(), true);
        $this->assertSame($before, $this->rowCounts(), 'A dry run writes nothing, the default places included.');
        Artisan::call('costs:layers-from-purchase-orders', ['--since' => '2000-01-01']);
        $this->assertStringContainsString('default', Artisan::output(), 'The place is the default one apply makes first.');
        Artisan::call('costs:layers-from-purchase-orders', ['--json' => true, '--apply' => true, '--since' => '2000-01-01']);
        $applied = json_decode(Artisan::output(), true);

        $this->assertSame([1, 10, []], [$dryRun['planned_layers'], $dryRun['planned_units'], $dryRun['refused']]);
        $this->assertSame([$dryRun['planned_layers'], $dryRun['planned_units']], [$applied['created_layers'], $applied['created_units']], 'What the dry run planned is what apply made.');
        $this->assertNull($dryRun['layers'][0]['location_id']);
        $this->assertSame(InventoryLocation::where('code', InventoryLocationService::OWN_CODE)->value('id'), $applied['layers'][0]['location_id']);
    }

    public function test_a_default_place_that_exists_but_is_switched_off_is_still_refused_by_the_dry_run_as_it_will_be_by_apply(): void
    {
        [, $line] = $this->placedLine(ordered: 10, cost: 12.5);
        $this->receiveOnPlatform($line, 10);
        InventoryLocation::where('code', InventoryLocationService::OWN_CODE)->update(['is_active' => false]);

        Artisan::call('costs:layers-from-purchase-orders', ['--json' => true, '--since' => '2000-01-01']);
        $dryRun = json_decode(Artisan::output(), true);

        $this->assertSame([0, ['no_location' => 1]], [$dryRun['planned_layers'], $dryRun['refused']]);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}> */
    public static function refusals(): array
    {
        return [
            'the cost module is off' => [['operational_costs.enabled' => false], []],
            'the source is off' => [['operational_costs.sources.purchase_orders' => 'off'], []],
            'apply while the source only reports' => [['operational_costs.sources.purchase_orders' => 'shadow'], ['--apply' => true, '--since' => '2000-01-01']],
            'apply without a cutover date' => [[], ['--apply' => true]],
            'apply and dry run together' => [[], ['--apply' => true, '--dry-run' => true, '--since' => '2000-01-01']],
            'a cutover date that is not one' => [[], ['--since' => '1 November']],
            'a product that is not an id' => [[], ['--product' => 'x']],
            'lines to include that are not ids' => [[], ['--include' => '1,x']],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $options
     */
    #[DataProvider('refusals')]
    public function test_asked_for_json_the_layers_command_answers_in_json_even_when_it_refuses(array $config, array $options): void
    {
        config($config);

        Artisan::call('costs:layers-from-purchase-orders', $options + ['--json' => true]);

        $answer = json_decode(Artisan::output(), true);
        $this->assertIsArray($answer, 'The output is JSON.');
        $this->assertFalse($answer['available']);
        $this->assertNotSame('', $answer['message']);
    }

    public function test_asked_for_json_the_reconcile_command_answers_in_json_even_when_it_refuses(): void
    {
        config(['operational_costs.enabled' => false]);
        Artisan::call('costs:reconcile-platform', ['--json' => true]);
        $off = json_decode(Artisan::output(), true);
        config(['operational_costs.enabled' => true]);
        Artisan::call('costs:reconcile-platform', ['--json' => true, '--product' => 'x']);
        $badProduct = json_decode(Artisan::output(), true);

        $this->assertSame([false, false], [$off['available'], $badProduct['available']]);
        $this->assertStringContainsString('switched off', $off['message']);
        $this->assertStringContainsString('must be an id', $badProduct['message']);
    }

    public function test_without_json_a_refusal_is_still_a_line_of_words(): void
    {
        config(['operational_costs.enabled' => false]);

        Artisan::call('costs:layers-from-purchase-orders');
        $output = Artisan::output();

        $this->assertNull(json_decode($output));
        $this->assertStringContainsString('switched off', $output);
    }

}
