<?php

namespace Tests\Feature\StaffScope;

use App\Services\Staff\StaffOrderRoutes;
use App\Services\Staff\StaffProductRoutes;
use Illuminate\Support\Facades\Route;
use Tests\Support\StaffProductInventory;
use Tests\Support\StaffProductRouteScanner;
use Tests\TestCase;

/**
 * docs/gcc/GCC-14C-STAFF-COUNTRY-SCOPES.md holds the survey of the admin routes that read products and the rules of the covered screens (slice 1: the product screens, slice 2: the
 * order, POS and refund screens). The tables are written from the same data as the guard, and this test keeps them from drifting: a controller, a route or a rule the doc does
 * not carry, or a count it states wrongly, fails.
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
            StaffProductInventory::SCOPED => 'scoped in ' . ($entry['slice'] ?? 'slice 1'),
            StaffProductInventory::NOT_NEEDED => 'not needed',
            default => 'out of slice 2 (' . $entry['slice'] . ')',
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
        $counts = StaffProductInventory::countsByStatus();
        $routes = ['slice 1' => 0, 'slice 2' => 0, StaffProductInventory::NOT_NEEDED => 0, StaffProductInventory::OUT => 0];
        foreach (StaffProductInventory::CONTROLLERS as $controller => $entry) {
            $routes[StaffProductInventory::statusKeyOf($entry)] += count($found[$controller]['productRoutes']);
        }

        $this->assertStringContainsString(sprintf('**%d controllers: %d scoped in slice 1, %d scoped in slice 2, %d not needed, %d out of slice 2.**', array_sum($counts), $counts['slice 1'], $counts['slice 2'], $counts['not_needed'], $counts['out']), $this->doc);
        $this->assertStringContainsString(sprintf('**%d routes read or name a product: %d scoped in slice 1, %d scoped in slice 2, %d not needed, %d out of slice 2.**', array_sum($routes), $routes['slice 1'], $routes['slice 2'], $routes['not_needed'], $routes['out']), $this->doc);
        $this->assertSame([2, 5], [$counts['slice 1'], $counts['slice 2']], 'the product screens and the five order and POS controllers the scan finds');
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
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $controller = ltrim((string) $route->getControllerClass(), '\\');
            $method = $route->getActionMethod();
            $rule = match (true) {
                in_array($controller, StaffProductRoutes::controllers(), true) => '`' . StaffProductRoutes::ruleOf($controller, $method) . '`',
                in_array($controller, StaffOrderRoutes::controllers(), true) => implode(' + ', array_map(static fn (string $part): string => '`' . $part . '`', StaffOrderRoutes::ruleOf($controller, $method) ?? [])),
                default => null,
            };
            if ($rule === null) {
                continue;
            }
            $short = substr($controller, (int) strrpos($controller, '\\') + 1);
            $row = sprintf('| `%s` | `%s::%s` | %s |', str_replace('|', '\|', $scanner->keyOf($route)), $short, $method, $rule);
            if (!str_contains($this->doc, $row)) {
                $missing[] = $row;
            }
        }

        $this->assertSame([], $missing);
        $this->assertStringContainsString('The rules of the 42 routes', $this->doc);
        $this->assertStringContainsString('The rules of the 48 routes', $this->doc);
        $this->assertSame(48, array_sum(array_map(static fn (string $controller): int => count(StaffOrderRoutes::rulesOf($controller)), StaffOrderRoutes::controllers())));
    }

    public function test_the_doc_carries_the_permission_each_covered_route_asks_for(): void
    {
        $scanner = new StaffProductRouteScanner();
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $controller = ltrim((string) $route->getControllerClass(), '\\');
            if (!in_array($controller, StaffOrderRoutes::controllers(), true)) {
                continue;
            }
            $modules = array_values(array_diff(array_values(array_filter(array_map(static fn ($middleware): string => is_string($middleware) ? $middleware : '', $route->gatherMiddleware()), static fn (string $middleware): bool => str_starts_with($middleware, 'module:'))), array_map('strval', $route->excludedMiddleware())));
            $row = sprintf('| `%s` | `%s::%s` | %s | %s |', str_replace('|', '\|', $scanner->keyOf($route)), substr($controller, (int) strrpos($controller, '\\') + 1), $route->getActionMethod(), implode(' + ', array_map(static fn (string $part): string => '`' . $part . '`', StaffOrderRoutes::ruleOf($controller, $route->getActionMethod()) ?? [])), $modules === [] ? 'none' : implode(', ', $modules));

            $this->assertStringContainsString($row, $this->doc);
        }
    }

    public function test_the_doc_names_every_slice_and_every_controller_found_by_hand(): void
    {
        foreach (StaffProductInventory::SLICES as $slice => $what) {
            $this->assertStringContainsString("| {$slice} | {$what} |", $this->doc, $slice);
        }
        foreach (StaffProductInventory::READ_THROUGH_SERVICES as $controller => $entry) {
            $status = ($entry['class'] ?? null) === StaffProductInventory::SCOPED ? 'scoped in ' . $entry['slice'] : 'out of slice 2 (' . $entry['slice'] . ')';

            $this->assertStringContainsString(sprintf('| `%s` | %s | %s |', $this->displayOf($controller), $status, $entry['reason']), $this->doc, $controller);
        }
        foreach (StaffProductInventory::SCOPED_WITHOUT_PRODUCTS as $controller => $entry) {
            $this->assertStringContainsString(sprintf('| `%s` | scoped in %s | %s |', $this->displayOf($controller), $entry['slice'], $entry['reason']), $this->doc, $controller);
        }
    }

    public function test_the_doc_records_the_owner_decisions_of_slice_2(): void
    {
        foreach (['Unattributed orders', 'Mixed-country orders', 'Refund requests about a line of a mixed order', 'The screens of a customer and of a delivery man list their orders'] as $decision) {
            $this->assertStringContainsString($decision, $this->doc, $decision);
        }
    }

    public function test_the_doc_carries_no_placeholder_of_its_generator(): void
    {
        $this->assertStringNotContainsString('{{', $this->doc);
    }
}
