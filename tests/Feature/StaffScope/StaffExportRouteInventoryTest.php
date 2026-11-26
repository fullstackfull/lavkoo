<?php

namespace Tests\Feature\StaffScope;

use App\Services\Staff\StaffCostRoutes;
use App\Services\Staff\StaffIntegrationRoutes;
use App\Services\Staff\StaffMarketplaceRoutes;
use App\Services\Staff\StaffOrderRoutes;
use App\Services\Staff\StaffProductRoutes;
use App\Services\Staff\StaffPromotionRoutes;
use Illuminate\Support\Facades\Route;
use Tests\Support\StaffExportInventory;
use Tests\Support\StaffExportRouteScanner;
use Tests\TestCase;

/**
 * The guard of the staff country scope for EXPORTS (GCC-14c, G-10): it walks the real route table of the admin panel and fails for a route
 * that hands the employee a file -- a spreadsheet, a PDF, a stream, an uploaded file -- and is neither scoped nor listed with the reason its
 * file holds every market, and the slice or decision that will settle it.
 *
 * Why exports get a guard of their own, when the product and order surveys already cover most of the same controllers: a file leaves the
 * panel. A screen that shows too much is a mistake somebody can still correct; a spreadsheet that held six markets is in a mailbox and
 * cannot be taken back. The two existing surveys ask "does this controller read products / orders", which is a question about screens, and
 * an export route can hand over rows that neither scan calls a read -- a theme version, a courier verification log, a file of the storage
 * served by its name.
 *
 * It is the same shape as `StaffProductRouteInventoryTest` and `StaffOrderRouteInventoryTest`, deliberately: an export added after this is
 * written has no entry, so the build fails and somebody decides what the file holds.
 */
class StaffExportRouteInventoryTest extends TestCase
{
    private StaffExportRouteScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scanner = new StaffExportRouteScanner();
    }

    /** @return array<string, array{file: string, routes: list<string>, exportRoutes: list<string>, markers: list<string>}> */
    private function exportControllers(): array
    {
        return array_filter($this->scanner->controllers(), static fn (array $info): bool => $info['exportRoutes'] !== []);
    }

    public function test_every_admin_controller_that_hands_over_a_file_is_classified(): void
    {
        $unclassified = [];
        foreach ($this->exportControllers() as $controller => $info) {
            if (!array_key_exists($controller, StaffExportInventory::CONTROLLERS)) {
                $unclassified[] = $controller . ' (' . implode(', ', $info['exportRoutes']) . ')';
            }
        }

        $this->assertSame([], $unclassified, "Admin controllers that hand the employee a file and are not in StaffExportInventory (say what the file holds, and which slice or decision settles it):\n" . implode("\n", $unclassified));
    }

    public function test_every_classified_controller_still_has_an_export_route(): void
    {
        $gone = array_values(array_diff(array_keys(StaffExportInventory::CONTROLLERS), array_keys($this->exportControllers())));

        $this->assertSame([], $gone, "Listed, but no longer an admin controller that hands over a file: take it off the list.\n" . implode("\n", $gone));
    }

    public function test_every_entry_has_a_reason_and_a_class_and_a_slice_somebody_named(): void
    {
        foreach (StaffExportInventory::CONTROLLERS as $controller => $entry) {
            $this->assertNotSame('', trim($entry['reason']), $controller . ' is listed without a reason');
            $this->assertGreaterThan(60, strlen($entry['reason']), $controller . ': a reason of a few words is not a decision');
            $this->assertContains($entry['class'], [StaffExportInventory::SCOPED, StaffExportInventory::NOT_NEEDED, StaffExportInventory::OUT], $controller);

            if ($entry['class'] === StaffExportInventory::OUT) {
                $this->assertArrayHasKey($entry['slice'], StaffExportInventory::SLICES, $controller . ': where an export that is still every market\'s belongs');
            }
            if ($entry['class'] === StaffExportInventory::SCOPED) {
                $this->assertContains($entry['slice'], ['slice 1', 'slice 2', 'slice 3', 'slice 4', 'slice 5', 'slice 6'], $controller . ': the slice that narrows the file');
            }
            if ($entry['class'] === StaffExportInventory::NOT_NEEDED) {
                $this->assertNull($entry['slice'], $controller . ': a file that holds nothing of a market names no slice');
            }
        }
    }

    /**
     * The claim an entry makes is checked against the registries rather than believed: a controller this table calls scoped must really have
     * rules in the slice it names. That is what stops the table from being a list of good intentions.
     */
    public function test_a_controller_this_table_calls_scoped_really_has_rules_in_the_slice_it_names(): void
    {
        // Slice 6 spans TWO registries, so its entry asks both: a controller of that slice has rules in one of them.
        $registryOf = [
            'slice 1' => StaffProductRoutes::rulesOf(...),
            'slice 2' => StaffOrderRoutes::rulesOf(...),
            'slice 3' => StaffOrderRoutes::rulesOf(...),
            'slice 4' => StaffPromotionRoutes::rulesOf(...),
            'slice 5' => StaffMarketplaceRoutes::rulesOf(...),
            'slice 6' => static fn (string $controller): array => [
                ...StaffIntegrationRoutes::rulesOf(controller: $controller),
                ...StaffCostRoutes::rulesOf(controller: $controller),
            ],
        ];

        foreach (StaffExportInventory::CONTROLLERS as $controller => $entry) {
            if ($entry['class'] !== StaffExportInventory::SCOPED) {
                continue;
            }

            $rules = $registryOf[$entry['slice']]($controller);
            $this->assertNotSame([], $rules, $controller . ' is called scoped in ' . $entry['slice'] . ', which holds no rule for it');
        }
    }

    /** And the other direction: a controller this table calls OUT or NOT NEEDED must have no rule anywhere, or it is misfiled. */
    public function test_a_controller_this_table_leaves_out_has_no_rule_in_any_registry(): void
    {
        foreach (StaffExportInventory::CONTROLLERS as $controller => $entry) {
            if ($entry['class'] === StaffExportInventory::SCOPED) {
                continue;
            }

            // The two slice-6 registries were MISSING from this list, and that is how a stale `out` survived a green guard: slice 6 settled
            // the profitability export by a REFUSAL, the entry still read "holds the orders of every country with their costs", and nothing
            // here asked. A stale `out` understates the coverage the owner is shown exactly as badly as a stale `scoped` overstates it.
            $rules = [
                ...StaffProductRoutes::rulesOf(controller: $controller),
                ...StaffOrderRoutes::rulesOf(controller: $controller),
                ...StaffPromotionRoutes::rulesOf(controller: $controller),
                ...StaffMarketplaceRoutes::rulesOf(controller: $controller),
                ...StaffIntegrationRoutes::rulesOf(controller: $controller),
                ...StaffCostRoutes::rulesOf(controller: $controller),
            ];

            $this->assertSame([], $rules, $controller . ' is listed as ' . $entry['class'] . ' and a registry holds rules for it: it is scoped, in whichever slice wrote them');
        }
    }

    /**
     * Every export route of a SCOPED controller has a rule of its own. A controller whose list is narrowed and whose export action nobody
     * gave a rule to is how an export slips through the one middleware that would have refused it.
     */
    public function test_every_export_route_of_a_scoped_controller_has_a_rule_for_its_own_action(): void
    {
        $withoutARule = [];
        foreach ($this->exportControllers() as $controller => $info) {
            $entry = StaffExportInventory::CONTROLLERS[$controller] ?? null;
            if (($entry['class'] ?? null) !== StaffExportInventory::SCOPED) {
                continue;
            }

            foreach ($this->actionsOfTheExportRoutes(controller: $controller, exportRoutes: $info['exportRoutes']) as $action => $key) {
                $rules = [
                    ...StaffProductRoutes::rulesOf(controller: $controller),
                    ...StaffOrderRoutes::rulesOf(controller: $controller),
                    ...StaffPromotionRoutes::rulesOf(controller: $controller),
                    ...StaffMarketplaceRoutes::rulesOf(controller: $controller),
                    ...StaffIntegrationRoutes::rulesOf(controller: $controller),
                    ...StaffCostRoutes::rulesOf(controller: $controller),
                ];
                if (!array_key_exists($action, $rules)) {
                    $withoutARule[] = $controller . '@' . $action . ' (' . $key . ')';
                }
            }
        }

        $this->assertSame([], $withoutARule, "An export route of a controller this table calls scoped, whose action has no rule:\n" . implode("\n", $withoutARule));
    }

    /** @param list<string> $exportRoutes @return array<string, string> the action of each export route of a controller, by name */
    private function actionsOfTheExportRoutes(string $controller, array $exportRoutes): array
    {
        $actions = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (ltrim((string) $route->getControllerClass(), '\\') === $controller && in_array($this->scanner->keyOf($route), $exportRoutes, true)) {
                $actions[(string) $route->getActionMethod()] = $this->scanner->keyOf($route);
            }
        }

        return $actions;
    }

    /**
     * The scan finds an export that nobody has classified. Without this, a green run of the guard could mean the scanner is reading nothing
     * at all -- the failure mode every scan in this suite is one line away from.
     */
    public function test_the_scan_finds_a_new_export_and_the_counts_are_what_the_table_says(): void
    {
        $found = $this->exportControllers();

        $this->assertGreaterThan(30, count($found), 'the scan reads the real route table: a panel this size has dozens of exports');
        $this->assertArrayHasKey('App\Http\Controllers\Admin\Order\OrderController', $found, 'the order export per status is an export');
        $this->assertContains('GET admin/orders/export-excel/{status}', $found['App\Http\Controllers\Admin\Order\OrderController']['exportRoutes']);

        $counts = StaffExportInventory::countsByStatus();
        $this->assertSame(count(StaffExportInventory::CONTROLLERS), array_sum($counts));
        // What the guard is for, in one number: nine of the panel's thirty-nine export controllers still hand a restricted employee every
        // market's rows, and each of the nine says which slice or decision settles it. It was ten until the guard above gained the two
        // slice-6 registries and found the profitability export already settled.
        $this->assertSame(
            ['scoped' => 23, 'not_needed' => 7, 'out' => 9],
            $counts,
            'the figures of the export survey moved: say so in G-10 and in the handoff rather than only here',
        );
    }

    /** A file served by NAME is the one kind of export a country scope cannot reach, and exactly one route is in that state. */
    public function test_the_only_export_served_by_a_file_name_is_the_file_manager(): void
    {
        $byName = [];
        foreach (StaffExportInventory::CONTROLLERS as $controller => $entry) {
            if ($entry['slice'] === 'uploads') {
                $byName[] = $controller;
            }
        }

        $this->assertSame(['App\Http\Controllers\Admin\Settings\FileManagerController'], $byName);
    }
}
