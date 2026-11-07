<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\OperationalCosts\CostState;
use App\Models\InventoryCostLayer;
use App\Models\OperationalCostContract;
use App\Models\OperationalCostEntry;
use App\Models\OperationalCostInvoice;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\CreatesOperationalCostData;

/** The cost screens say, in the same place, whether the module calculates by itself (OPERATIONAL_COSTS_ENABLED) and whether the scheduler runs it (OPERATIONAL_COSTS_SCHEDULE_ENABLED). */
class AdminOperationalCostsModuleStateTest extends AdminPagesTestCase
{
    use CreatesOperationalCostData;

    private const MONTH = ['from' => '2026-11-01', 'to' => '2026-11-30'];

    private const PAGES = ['index', 'contracts.index', 'contracts.create', 'product-costs.index', 'entries.index', 'invoices.index', 'invoices.create', 'profit.index', 'reports.index'];

    private function banner(string $html): ?string
    {
        return preg_match('/data-cost-module-state="([a-z-]+)"/', $html, $found) === 1 ? $found[1] : null;
    }

    /** @return iterable<string, array{string}> */
    public static function pages(): iterable
    {
        foreach (self::PAGES as $page) {
            yield $page => [$page];
        }
    }

    #[DataProvider('pages')]
    public function test_a_page_says_the_module_is_off_and_nothing_is_calculated_automatically(string $page): void
    {
        config(['operational_costs.enabled' => false, 'operational_costs.schedule_enabled' => true]);

        $html = $this->get(route('admin.operational-costs.' . $page, self::MONTH))->assertOk()->getContent();

        $this->assertSame('off', $this->banner($html));
        $this->assertStringContainsString(e(translate('operational_costs_are_switched_off_banner')), $html);
        $this->assertStringContainsString('OPERATIONAL_COSTS_ENABLED', $html);
        $this->assertStringNotContainsString(e(translate('operational_costs_schedule_is_switched_off_banner')), $html, 'One banner: the schedule is moot while the module is off.');
    }

    #[DataProvider('pages')]
    public function test_a_page_says_when_only_the_schedule_is_off(string $page): void
    {
        config(['operational_costs.enabled' => true, 'operational_costs.schedule_enabled' => false]);

        $html = $this->get(route('admin.operational-costs.' . $page, self::MONTH))->assertOk()->getContent();

        $this->assertSame('schedule-off', $this->banner($html));
        $this->assertStringContainsString(e(translate('operational_costs_schedule_is_switched_off_banner')), $html);
        $this->assertStringContainsString('OPERATIONAL_COSTS_SCHEDULE_ENABLED', $html);
        $this->assertStringNotContainsString(e(translate('operational_costs_are_switched_off_banner')), $html);
    }

    #[DataProvider('pages')]
    public function test_a_page_shows_no_banner_when_both_switches_are_on(string $page): void
    {
        config(['operational_costs.enabled' => true, 'operational_costs.schedule_enabled' => true]);

        $html = $this->get(route('admin.operational-costs.' . $page, self::MONTH))->assertOk()->getContent();

        $this->assertNull($this->banner($html));
    }

    public function test_the_banner_is_on_the_detail_pages_too(): void
    {
        config(['operational_costs.enabled' => false]);
        $contract = $this->draftContract();
        $entry = $this->recordCost(CostState::Actual, '12');
        $sale = $this->channelSale('100');
        $layer = app(\App\Services\OperationalCosts\InventoryCostLayerService::class)->receive(new \App\Services\OperationalCosts\Data\LayerReceipt(
            locationId: $this->stockLocation()->id, productId: 50, quantity: 2, unitCost: \App\Services\OperationalCosts\Support\Money::of('3'), currency: 'SAR',
            receivedAt: \Carbon\CarbonImmutable::parse('2026-11-01'), sourceType: 'opening_balance', identity: 'opening',
        ));

        foreach ([
            route('admin.operational-costs.contracts.show', $contract->id),
            route('admin.operational-costs.contracts.edit', $contract->id),
            route('admin.operational-costs.contracts.rules.create', $contract->id),
            route('admin.operational-costs.entries.show', $entry->id),
            route('admin.operational-costs.product-costs.show', $layer->id),
            route('admin.operational-costs.profit.order', ['type' => 'channel_order', 'id' => $sale->id]),
        ] as $url) {
            $this->assertSame('off', $this->banner($this->get($url)->assertOk()->getContent()), $url);
        }
    }

    public function test_every_page_of_the_module_includes_the_banner(): void
    {
        $root = base_path('resources/views/admin-views/operational-costs');
        $missing = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (str_starts_with($relative, 'partials/') || !str_ends_with($relative, '.blade.php')) {
                continue;
            }
            if (!str_contains((string) file_get_contents($file->getPathname()), "@include('admin-views.operational-costs.partials._module-state')")) {
                $missing[] = $relative;
            }
        }

        $this->assertSame([], $missing, 'A cost screen that does not show the state of the module.');
    }

    /** @return iterable<string, array{bool, bool, string}> */
    public static function hubStates(): iterable
    {
        yield 'both off' => [false, false, 'calculation_off'];
        yield 'module on, schedule off' => [true, false, 'schedule_off'];
        yield 'both on' => [true, true, 'both_on'];
        yield 'schedule on, module off' => [false, true, 'schedule_needs_module'];
    }

    #[DataProvider('hubStates')]
    public function test_the_channels_hub_lists_the_state_of_the_cost_module_with_the_other_switches(bool $enabled, bool $schedule, string $expected): void
    {
        config(['operational_costs.enabled' => $enabled, 'operational_costs.schedule_enabled' => $schedule]);

        $html = $this->get(route('admin.sales-channels.index'))->assertOk()->getContent();

        $this->assertStringContainsString('OPERATIONAL_COSTS_ENABLED', $html);
        $this->assertStringContainsString('OPERATIONAL_COSTS_SCHEDULE_ENABLED', $html);
        $this->assertStringContainsString(e(translate($enabled ? 'operational_costs_calculation_on_help' : 'operational_costs_calculation_off_help')), $html);
        $this->assertStringContainsString(e(translate(match ($expected) {
            'schedule_needs_module' => 'operational_costs_schedule_needs_the_module_help',
            'schedule_off' => 'operational_costs_schedule_off_help',
            'calculation_off' => 'operational_costs_schedule_needs_the_module_help',
            default => 'operational_costs_schedule_on_help',
        })), $html);
    }

    public function test_what_a_person_records_in_the_product_costs_screen_is_saved_while_the_module_is_off(): void
    {
        config(['operational_costs.enabled' => false]);
        DB::table('products')->insert(['id' => 50, 'name' => 'Vitamin C 1000', 'code' => 'VC-1', 'added_by' => 'admin', 'product_type' => 'physical']);
        $warehouse = $this->stockLocation();

        $this->post(route('admin.operational-costs.product-costs.store'), [
            'location_id' => $warehouse->id, 'product_id' => 50, 'quantity' => '10', 'unit_cost' => '4.5', 'currency' => 'SAR',
            'received_at' => '2026-11-01', 'source_type' => 'opening_balance', 'reference' => 'OPEN-1', 'supplier_name' => 'Supplier A',
        ])->assertRedirect();

        $this->assertSame(1, InventoryCostLayer::count());
        $this->assertSame(0, OperationalCostEntry::count(), 'Stock a person recorded is a layer, not a cost.');
        $this->assertSame(0, OperationalCostInvoice::count());
        $this->assertSame(0, OperationalCostContract::count());
    }
}
