<?php

namespace Tests\Feature\StaffScope;

use App\Services\Staff\StaffProductRoutes;
use Illuminate\Support\Facades\Route;
use Tests\Support\StaffProductInventory;
use Tests\Support\StaffProductRouteScanner;
use Tests\TestCase;

/**
 * docs/gcc/GCC-14C-STAFF-COUNTRY-SCOPES.md holds the survey of the admin routes that read products and the rules of the covered screens. The tables are written from the
 * same data as the guard, and this test keeps them from drifting: a controller, a route or a rule the doc does not carry, or a count it states wrongly, fails.
 */
class StaffScopeDocTest extends TestCase
{
    private const DOC = 'docs/gcc/GCC-14C-STAFF-COUNTRY-SCOPES.md';

    private string $doc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doc = (string) file_get_contents(base_path(self::DOC));
    }

    private function displayOf(string $controller): string
    {
        return str_replace(['App\Http\Controllers\\', 'Modules\AI\app\Http\Controllers\\'], ['', 'Modules\AI\\'], $controller);
    }

    private function statusOf(array $entry): string
    {
        return match ($entry['class']) {
            StaffProductInventory::SCOPED => 'scoped in slice 1',
            StaffProductInventory::NOT_NEEDED => 'not needed',
            default => 'out of slice 1 (' . $entry['slice'] . ')',
        };
    }

    public function test_the_doc_exists_and_names_the_switch_the_table_the_migration_and_the_commands(): void
    {
        foreach ([
            'gcc.staff.country_scopes', 'GCC_STAFF_COUNTRY_SCOPES', 'admin_country_scopes', '2026_11_28_100000_create_admin_country_scopes_table',
            'gcc:staff-country:assign', 'gcc:staff-country:revoke', 'gcc:staff-country:list', 'ScopeProductsToStaffCountries', 'StaffCountryProductScope',
            'StaffProductRoutes', 'NewProductCountryInStaffScope', 'StaffCountryScope', 'GCC-14', 'GCC-20',
        ] as $name) {
            $this->assertStringContainsString($name, $this->doc, $name);
        }

        $this->assertFileExists(database_path('migrations/2026_11_28_100000_create_admin_country_scopes_table.php'));
    }

    public function test_the_doc_records_the_owner_decision_about_the_platform_admin_grants(): void
    {
        $this->assertStringContainsString('Owner decision recorded: a platform admin\'s grant country list is to be intersected with the staff scope once both are merged', $this->doc);
    }

    public function test_every_controller_of_the_table_is_in_the_doc_with_its_counts_and_its_status(): void
    {
        $found = (new StaffProductRouteScanner())->controllers();

        foreach (StaffProductInventory::CONTROLLERS as $controller => $entry) {
            $row = sprintf('| `%s` | %d | %d | %s |', $this->displayOf($controller), count($found[$controller]['routes']), count($found[$controller]['productRoutes']), $this->statusOf($entry));

            $this->assertStringContainsString($row, $this->doc, $controller);
        }
    }

    public function test_the_doc_states_the_counts_the_table_and_the_routes_add_up_to(): void
    {
        $found = (new StaffProductRouteScanner())->controllers();
        $counts = StaffProductInventory::countsByClass();
        $routes = ['scoped' => 0, 'not_needed' => 0, 'out' => 0];
        foreach (StaffProductInventory::CONTROLLERS as $controller => $entry) {
            $routes[$entry['class']] += count($found[$controller]['productRoutes']);
        }

        $this->assertStringContainsString(sprintf('**%d controllers: %d scoped in slice 1, %d not needed, %d out of slice 1.**', array_sum($counts), $counts['scoped'], $counts['not_needed'], $counts['out']), $this->doc);
        $this->assertStringContainsString(sprintf('**%d routes read or name a product: %d scoped in slice 1, %d not needed, %d out of slice 1.**', array_sum($routes), $routes['scoped'], $routes['not_needed'], $routes['out']), $this->doc);
    }

    public function test_every_route_that_reads_products_or_names_one_is_in_the_doc(): void
    {
        $missing = [];
        foreach ((new StaffProductRouteScanner())->controllers() as $controller => $info) {
            foreach ($info['productRoutes'] as $key) {
                if (!str_contains($this->doc, '`' . str_replace('|', '\|', $key) . '`')) {
                    $missing[] = $key . ' (' . $controller . ')';
                }
            }
        }

        $this->assertSame([], $missing, "Routes the survey finds that the doc does not list:\n" . implode("\n", $missing));
    }

    public function test_every_rule_of_the_covered_screens_is_in_the_doc(): void
    {
        $scanner = new StaffProductRouteScanner();
        $missing = [];
        foreach (StaffProductRoutes::controllers() as $controller) {
            foreach (Route::getRoutes()->getRoutes() as $route) {
                if (ltrim((string) $route->getControllerClass(), '\\') !== $controller) {
                    continue;
                }
                $short = substr($controller, (int) strrpos($controller, '\\') + 1);
                $row = sprintf('| `%s` | `%s::%s` | `%s` |', str_replace('|', '\|', $scanner->keyOf($route)), $short, $route->getActionMethod(), StaffProductRoutes::ruleOf($controller, $route->getActionMethod()));
                if (!str_contains($this->doc, $row)) {
                    $missing[] = $row;
                }
            }
        }

        $this->assertSame([], $missing);
        $this->assertStringContainsString('The rules of the 42 routes', $this->doc);
    }

    public function test_the_doc_names_every_slice_and_every_controller_found_by_hand(): void
    {
        foreach (StaffProductInventory::SLICES as $slice => $what) {
            $this->assertStringContainsString("| {$slice} | {$what} |", $this->doc, $slice);
        }
        foreach (array_keys(StaffProductInventory::READ_THROUGH_SERVICES) as $controller) {
            $this->assertStringContainsString('`' . $this->displayOf($controller) . '`', $this->doc, $controller);
        }
    }

    public function test_the_doc_carries_no_placeholder_of_its_generator(): void
    {
        $this->assertStringNotContainsString('{{', $this->doc);
    }
}
