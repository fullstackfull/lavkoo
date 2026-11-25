<?php

namespace Tests\Feature\StaffScope;

use App\Services\Staff\StaffOrderRoutes;
use App\Services\Staff\StaffProductRoutes;
use App\Services\Staff\StaffPromotionRoutes;
use Illuminate\Support\Facades\Route;
use Tests\Support\StaffOrderInventory;
use Tests\Support\StaffOrderRouteScanner;
use Tests\Support\StaffProductInventory;
use Tests\Support\StaffProductRouteScanner;
use Tests\TestCase;

/**
 * docs/gcc/GCC-14C-STAFF-COUNTRY-SCOPES.md holds the surveys of the admin routes that read products and of the ones that read orders, and the rules of the covered screens (slice 1:
 * the product screens, slice 2: the order, POS and refund screens, slice 3: the screens that read orders). The tables are written from the same data as the guards, and this test
 * keeps them from drifting: a controller, a route or a rule the doc does not carry, or a count it states wrongly, fails. Slice 4 (the promotion screens) is in it too, with
 * its own registry, so a promotion route that gains or loses a rule shows up here as well.
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
        return str_replace(['App\Http\Controllers\\', 'Modules\AI\app\Http\Controllers\\', 'Modules\TaxModule\app\Http\Controllers\\'], ['', 'Modules\AI\\', 'Modules\TaxModule\\'], $controller);
    }

    private function statusOf(array $entry): string
    {
        return match ($entry['class']) {
            StaffProductInventory::SCOPED => 'scoped in ' . ($entry['slice'] ?? 'slice 1'),
            StaffProductInventory::NOT_NEEDED => 'not needed',
            default => 'out of slice 4 (' . $entry['slice'] . ')',
        };
    }

    /** @param array{slice: string, class?: string} $entry */
    private function statusOfAControllerFoundByHand(array $entry): string
    {
        return ($entry['class'] ?? null) === StaffProductInventory::SCOPED ? 'scoped in ' . $entry['slice'] : 'out of slice 4 (' . $entry['slice'] . ')';
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
        $this->assertStringContainsString('Owner decision recorded and built: a platform admin\'s grant country list is intersected with the staff scope', $this->doc);
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
        $routes = ['slice 1' => 0, 'slice 2' => 0, 'slice 3' => 0, 'slice 4' => 0, StaffProductInventory::NOT_NEEDED => 0, StaffProductInventory::OUT => 0];
        foreach (StaffProductInventory::CONTROLLERS as $controller => $entry) {
            $routes[StaffProductInventory::statusKeyOf($entry)] += count($found[$controller]['productRoutes']);
        }

        $this->assertStringContainsString(sprintf('**%d controllers: %d scoped in slice 1, %d scoped in slice 2, %d scoped in slice 3, %d scoped in slice 4, %d not needed, %d out of slice 4.**', array_sum($counts), $counts['slice 1'], $counts['slice 2'], $counts['slice 3'], $counts['slice 4'], $counts['not_needed'], $counts['out']), $this->doc);
        $this->assertStringContainsString(sprintf('**%d routes read or name a product: %d scoped in slice 1, %d scoped in slice 2, %d scoped in slice 3, %d scoped in slice 4, %d not needed, %d out of slice 4.**', array_sum($routes), $routes['slice 1'], $routes['slice 2'], $routes['slice 3'], $routes['slice 4'], $routes['not_needed'], $routes['out']), $this->doc);
        // Slice 1 is three: the product screens, the moderation screen and the shipping destination permissions (H1-03, H1-04), whose
        // two actions take a product id in the request and so are judged by it. Slice 2 is the five order and POS controllers.
        $this->assertSame([3, 5], [$counts['slice 1'], $counts['slice 2']], 'the product screens and the five order and POS controllers the scan finds');
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
                in_array($controller, StaffPromotionRoutes::controllers(), true) => implode(' + ', array_map(static fn (string $part): string => '`' . $part . '`', StaffPromotionRoutes::ruleOf($controller, $method) ?? [])),
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
        // 45 since the product-identity lane added POST admin/products/copy-to-country/{id}.
        $this->assertStringContainsString('The rules of the 45 routes', $this->doc);
        $this->assertStringContainsString('The rules of the 49 routes', $this->doc);
        $this->assertStringContainsString('The rules of the 87 routes', $this->doc);
        $this->assertSame(87, array_sum(array_map(static fn (string $controller): int => count(StaffPromotionRoutes::rulesOf($controller)), StaffPromotionRoutes::controllers())));
        $this->assertSame(49, array_sum(array_map(static fn (string $controller): int => count(StaffOrderRoutes::rulesOf($controller)), StaffOrderRoutes::orderScreenControllers())));
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
            if (!in_array($slice, ['slice 3', 'slice 4'], true)) {
                $this->assertStringContainsString("| {$slice} | {$what} |", $this->doc, $slice);
            }
        }
        foreach (StaffProductInventory::READ_THROUGH_SERVICES as $controller => $entry) {
            $this->assertStringContainsString(sprintf('| `%s` | %s | %s |', $this->displayOf($controller), $this->statusOfAControllerFoundByHand($entry), $entry['reason']), $this->doc, $controller);
        }
        foreach (StaffProductInventory::SCOPED_WITHOUT_PRODUCTS as $controller => $entry) {
            $this->assertStringContainsString(sprintf('| `%s` | scoped in %s | %s |', $this->displayOf($controller), $entry['slice'], $entry['reason']), $this->doc, $controller);
        }
    }

    // ---- the survey of the orders ---------------------------------------------------------------------------------------------------

    public function test_every_controller_of_the_order_survey_is_in_the_doc_with_its_counts_and_its_status(): void
    {
        $found = (new StaffOrderRouteScanner())->controllers();

        foreach (StaffOrderInventory::CONTROLLERS as $controller => $entry) {
            $row = sprintf('| `%s` | %d | %d | %s | %s |', $this->displayOf($controller), count($found[$controller]['routes']), count($found[$controller]['orderRoutes']), $this->statusOf($entry), $entry['reason']);

            $this->assertStringContainsString($row, $this->doc, $controller);
        }
    }

    public function test_the_doc_states_the_counts_the_order_survey_and_the_routes_add_up_to(): void
    {
        $found = (new StaffOrderRouteScanner())->controllers();
        $counts = StaffOrderInventory::countsByStatus();
        $routes = ['slice 2' => 0, 'slice 3' => 0, 'slice 4' => 0, StaffOrderInventory::NOT_NEEDED => 0, StaffOrderInventory::OUT => 0];
        foreach (StaffOrderInventory::CONTROLLERS as $controller => $entry) {
            $routes[StaffOrderInventory::statusKeyOf($entry)] += count($found[$controller]['orderRoutes']);
        }

        $this->assertStringContainsString(sprintf('**%d controllers: %d scoped in slice 2, %d scoped in slice 3, %d scoped in slice 4, %d not needed, %d out of slice 4.**', array_sum($counts), $counts['slice 2'], $counts['slice 3'], $counts['slice 4'], $counts['not_needed'], $counts['out']), $this->doc);
        $this->assertStringContainsString(sprintf('**%d routes read or name an order: %d scoped in slice 2, %d scoped in slice 3, %d scoped in slice 4, %d not needed, %d out of slice 4.**', array_sum($routes), $routes['slice 2'], $routes['slice 3'], $routes['slice 4'], $routes['not_needed'], $routes['out']), $this->doc);
    }

    public function test_every_route_that_reads_orders_or_names_one_is_in_the_doc(): void
    {
        $missing = [];
        foreach ((new StaffOrderRouteScanner())->controllers() as $controller => $info) {
            foreach ($info['orderRoutes'] as $key) {
                if (!str_contains($this->doc, '`' . str_replace('|', '\|', $key) . '`')) {
                    $missing[] = $key . ' (' . $controller . ')';
                }
            }
        }

        $this->assertSame([], $missing, "Routes the order survey finds that the doc does not list:\n" . implode("\n", $missing));
    }

    public function test_the_doc_names_every_slice_and_every_controller_of_the_order_survey_found_by_hand(): void
    {
        foreach (StaffOrderInventory::SLICES as $slice => $what) {
            $this->assertStringContainsString("| {$slice} | {$what} |", $this->doc, $slice);
        }
        foreach (StaffOrderInventory::READ_THROUGH_SERVICES as $controller => $entry) {
            $this->assertStringContainsString(sprintf('| `%s` | %s | %s |', $this->displayOf($controller), $this->statusOfAControllerFoundByHand($entry), $entry['reason']), $this->doc, $controller);
        }
        foreach (StaffOrderInventory::SCOPED_WITHOUT_ORDER_READS as $controller => $entry) {
            $this->assertStringContainsString(sprintf('| `%s` | scoped in %s | %s |', $this->displayOf($controller), $entry['slice'], $entry['reason']), $this->doc, $controller);
        }
    }

    public function test_the_doc_records_the_owner_decisions_of_slice_2(): void
    {
        foreach (['Unattributed orders', 'Mixed-country orders', 'Refund requests about a line of a mixed order', 'The screens of a customer and of a delivery man list their orders'] as $decision) {
            $this->assertStringContainsString($decision, $this->doc, $decision);
        }
    }

    public function test_the_doc_describes_what_slice_3_enforces_and_records_its_decisions_and_its_measure(): void
    {
        foreach ([
            'What slice 3 enforces', 'StaffCountryScope::narrowLayout', 'StaffCountryScope::narrowRawOrders', 'StaffCountryScope::countriesOf', 'StaffSearchHits', '`unavailable`',
            '`order_lines`', '`order_transactions`', '`refund_transactions`', 'StaffOrderRouteInventoryTest', 'StaffSixCountryReportMatrixTest', 'Measured once on MySQL 8.0.46',
            'What a restricted employee may see of the money that cannot be told apart by country', 'The sales of a product in a mixed order', 'The ratings of a delivery man',
            'The recent searches', 'The wallet and loyalty reports and the wallet bonus tab', 'App\\Http\\Controllers\\Admin\\TransactionController',
        ] as $text) {
            $this->assertStringContainsString($text, $this->doc, $text);
        }
        $this->assertStringContainsString('The screens of a customer and of a delivery man list their orders', $this->doc, 'the decision of slice 2 that slice 3 took');
    }

    public function test_the_doc_carries_the_rules_of_every_screen_of_slice_3_in_one_table_with_a_row_for_each_route(): void
    {
        $routes = 0;
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $routes += in_array(ltrim((string) $route->getControllerClass(), '\\'), StaffOrderRoutes::readScreenControllers(), true) ? 1 : 0;
        }
        $start = (int) strpos($this->doc, '<!-- slice-3-rules-start -->');
        $end = (int) strpos($this->doc, '<!-- slice-3-rules-end -->');

        $this->assertGreaterThan($start, $end);
        $this->assertSame($routes, substr_count(substr($this->doc, $start, $end - $start), "\n| `"));
    }

    public function test_the_doc_carries_no_placeholder_of_its_generator(): void
    {
        $this->assertStringNotContainsString('{{', $this->doc);
        $this->assertStringNotContainsString('PLACEHOLDER', $this->doc);
    }
}
