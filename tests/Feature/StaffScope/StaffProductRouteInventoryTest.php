<?php

namespace Tests\Feature\StaffScope;

use App\Http\Controllers\Admin\Product\ReviewController;
use App\Http\Controllers\Admin\Promotion\BannerController;
use App\Services\Staff\StaffOrderRoutes;
use App\Services\Staff\StaffProductRoutes;
use App\Services\Staff\StaffPromotionRoutes;
use Illuminate\Support\Facades\Route;
use Tests\Support\StaffProbe\NewPathOnlyController;
use Tests\Support\StaffProbe\NewReportController;
use Tests\Support\StaffProductInventory;
use Tests\Support\StaffProductRouteScanner;
use Tests\TestCase;

/**
 * The guard of the staff country scope (GCC-14c): it walks the real route table of the admin panel and fails for a controller that reads products or names one and is
 * neither scoped nor listed, with the slice that takes it, or the reason it needs none. It is how a screen added after slice 2 cannot show a restricted employee another
 * country's products without somebody having decided so. The table is the one of docs/gcc/GCC-14C-STAFF-COUNTRY-SCOPES.md, which a second test keeps in step.
 *
 * The scan is a pattern check over source text and has blind spots (a controller that reaches products only through a service it does not name here). The ones found by
 * hand are in StaffProductInventory::READ_THROUGH_SERVICES, and the doc says so.
 */
class StaffProductRouteInventoryTest extends TestCase
{
    private StaffProductRouteScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scanner = new StaffProductRouteScanner();
    }

    /** @return list<string> */
    private function unclassified(): array
    {
        return array_values(array_diff(array_keys($this->scanner->controllers()), array_keys(StaffProductInventory::CONTROLLERS), array_keys(StaffProductInventory::READ_THROUGH_SERVICES)));
    }

    public function test_every_admin_controller_that_reads_products_or_names_one_is_classified(): void
    {
        $unclassified = $this->unclassified();

        $this->assertSame([], $unclassified, "Admin controllers that read products or name one and are not in StaffProductInventory (scope them, or say why they need none and which slice takes them):\n" . implode("\n", $unclassified));
    }

    public function test_every_classified_controller_still_reads_products_or_names_one(): void
    {
        $gone = array_values(array_diff(array_keys(StaffProductInventory::CONTROLLERS), array_keys($this->scanner->controllers())));

        $this->assertSame([], $gone, "Listed, but no longer an admin controller that reads products: take it off the list.\n" . implode("\n", $gone));
    }

    public function test_every_controller_listed_as_read_through_a_service_is_still_an_admin_controller(): void
    {
        $adminControllers = [];
        foreach ($this->scanner->adminRoutes() as $route) {
            $adminControllers[ltrim((string) $route->getControllerClass(), '\\')] = true;
        }

        $this->assertSame([], array_values(array_diff(array_keys(StaffProductInventory::READ_THROUGH_SERVICES), array_keys($adminControllers))));
        $this->assertSame([], array_values(array_intersect(array_keys(StaffProductInventory::READ_THROUGH_SERVICES), array_keys(StaffProductInventory::CONTROLLERS))), 'a controller the scan sees belongs in the main table');
    }

    public function test_every_entry_has_a_reason_and_a_class_and_a_slice_somebody_named(): void
    {
        foreach (StaffProductInventory::CONTROLLERS as $controller => $entry) {
            $this->assertNotSame('', trim($entry['reason']), $controller . ' is listed without a reason');
            $this->assertContains($entry['class'], [StaffProductInventory::SCOPED, StaffProductInventory::NOT_NEEDED, StaffProductInventory::OUT], $controller);
            $this->assertSame($entry['class'] === StaffProductInventory::NOT_NEEDED, $entry['slice'] === null && $entry['class'] !== StaffProductInventory::SCOPED, $controller . ': a controller that needs no scope names no slice');
            if ($entry['class'] === StaffProductInventory::OUT) {
                $this->assertArrayHasKey($entry['slice'], StaffProductInventory::SLICES, $controller . ': the slice that takes it is one that follows slice 2');
            }
            if ($entry['class'] === StaffProductInventory::SCOPED) {
                $this->assertContains($entry['slice'], [null, 'slice 2', 'slice 3', 'slice 4'], $controller . ': scoped in slice 1 (no slice named), in slice 2, 3 or 4');
            }
        }
        foreach (StaffProductInventory::READ_THROUGH_SERVICES as $controller => $entry) {
            $this->assertNotSame('', trim($entry['reason']), $controller);
            $this->assertArrayHasKey($entry['slice'], ($entry['class'] ?? null) === StaffProductInventory::SCOPED ? ['slice 2' => true, 'slice 3' => true, 'slice 4' => true] : StaffProductInventory::SLICES, $controller);
        }
        foreach (StaffProductInventory::SCOPED_WITHOUT_PRODUCTS as $controller => $entry) {
            $this->assertNotSame('', trim($entry['reason']), $controller);
            $this->assertContains($entry['slice'], ['slice 2', 'slice 3', 'slice 4'], $controller);
        }
    }

    /** @return array<string, list<class-string>> the controllers the survey says a slice scoped, by slice */
    private function scopedBySurvey(): array
    {
        $scoped = ['slice 1' => [], 'slice 2' => [], 'slice 3' => [], 'slice 4' => []];
        foreach (StaffProductInventory::CONTROLLERS as $controller => $entry) {
            if ($entry['class'] === StaffProductInventory::SCOPED) {
                $scoped[$entry['slice'] ?? 'slice 1'][] = $controller;
            }
        }
        foreach (StaffProductInventory::READ_THROUGH_SERVICES as $controller => $entry) {
            if (($entry['class'] ?? null) === StaffProductInventory::SCOPED && !isset($entry['by'])) {
                $scoped[$entry['slice']][] = $controller;
            }
        }
        foreach (StaffProductInventory::SCOPED_WITHOUT_PRODUCTS as $controller => $entry) {
            $scoped[$entry['slice']][] = $controller;
        }
        foreach ($scoped as &$controllers) {
            sort($controllers);
        }

        return $scoped;
    }

    public function test_the_controllers_listed_as_scoped_are_exactly_the_ones_that_have_rules_in_the_registry_of_their_slice(): void
    {
        $slice1 = StaffProductRoutes::controllers();
        $slice2 = StaffOrderRoutes::orderScreenControllers();
        $slice3 = StaffOrderRoutes::readScreenControllers();
        $slice4 = StaffPromotionRoutes::controllers();
        sort($slice1);
        sort($slice2);
        sort($slice3);
        sort($slice4);

        $this->assertSame(['slice 1' => $slice1, 'slice 2' => $slice2, 'slice 3' => $slice3, 'slice 4' => $slice4], $this->scopedBySurvey());
        // One controller, one registry: a screen whose rules were in two of them would be judged by whichever middleware ran first.
        foreach ([[$slice1, $slice2], [$slice1, $slice3], [$slice1, $slice4], [$slice2, $slice3], [$slice2, $slice4], [$slice3, $slice4]] as [$left, $right]) {
            $this->assertSame([], array_values(array_intersect($left, $right)), 'a controller is covered by one slice');
        }
    }

    public function test_every_route_of_a_scoped_controller_has_a_rule_and_every_rule_names_a_route(): void
    {
        foreach (StaffProductRoutes::controllers() as $controller) {
            $actions = $this->actionsOf(controller: $controller);

            $rules = StaffProductRoutes::rulesOf(controller: $controller);
            $this->assertSame([], array_values(array_diff_key($actions, $rules)), $controller . ' has routes without a rule in StaffProductRoutes');
            $this->assertSame([], array_values(array_diff(array_keys($rules), array_keys($actions))), $controller . ' has rules for actions that no route reaches');
            foreach ($rules as $action => $rule) {
                $this->assertMatchesRegularExpression('/^(list|none|new|(route|input|inputs|restock|variation):[A-Za-z_]+\??)$/', $rule, $controller . '@' . $action);
            }
        }
        foreach (StaffOrderRoutes::controllers() as $controller) {
            $actions = $this->actionsOf(controller: $controller);

            $rules = StaffOrderRoutes::rulesOf(controller: $controller);
            $this->assertSame([], array_values(array_diff_key($actions, $rules)), $controller . ' has routes without a rule in StaffOrderRoutes');
            $this->assertSame([], array_values(array_diff(array_keys($rules), array_keys($actions))), $controller . ' has rules for actions that no route reaches');
        }
        foreach (StaffPromotionRoutes::controllers() as $controller) {
            $actions = $this->actionsOf(controller: $controller);

            $rules = StaffPromotionRoutes::rulesOf(controller: $controller);
            $this->assertSame([], array_values(array_diff_key($actions, $rules)), $controller . ' has routes without a rule in StaffPromotionRoutes');
            $this->assertSame([], array_values(array_diff(array_keys($rules), array_keys($actions))), $controller . ' has rules for actions that no route reaches');
            foreach ($rules as $action => $rule) {
                $this->assertNotSame([], $rule, $controller . '@' . $action . ': an empty rule refuses the request, so it is never what a screen wants');
                foreach ($rule as $part) {
                    $this->assertMatchesRegularExpression('/^(none|list|list:orders|banner_form|(product|deal_of_the_day|most_demanded)\.(route|input|inputs):[A-Za-z_]+\??)$/', $part, $controller . '@' . $action);
                }
            }
        }
    }

    /** @return array<string, string> the routes of a controller by the name of the action they reach */
    private function actionsOf(string $controller): array
    {
        $actions = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (ltrim((string) $route->getControllerClass(), '\\') === $controller) {
                $actions[$route->getActionMethod()] = $this->scanner->keyOf(route: $route);
            }
        }

        return $actions;
    }

    public function test_the_unscoped_controllers_have_no_rules_and_so_no_middleware(): void
    {
        $this->assertSame([], StaffProductRoutes::rulesOf(controller: StaffProductInventory::class));
        $this->assertSame([], StaffProductRoutes::rulesOf(controller: ReviewController::class));
        $this->assertSame([], StaffOrderRoutes::rulesOf(controller: ReviewController::class));
        $this->assertSame([], StaffPromotionRoutes::rulesOf(controller: ReviewController::class));
        // Slice 4 took the banners, so their rules live in ONE registry and in neither of the other two.
        $this->assertNotSame([], StaffPromotionRoutes::rulesOf(controller: BannerController::class));
        $this->assertSame([], StaffProductRoutes::rulesOf(controller: BannerController::class));
        $this->assertSame([], StaffOrderRoutes::rulesOf(controller: BannerController::class));
        $this->assertSame([], StaffOrderRoutes::rulesOf(controller: 'App\Http\Controllers\Admin\Product\ProductController'), 'the order screens and the product screens are covered by their own registry');
        $this->assertSame([], StaffProductRoutes::rulesOf(controller: 'App\Http\Controllers\Admin\Order\OrderController'));
    }

    public function test_a_controller_is_found_with_or_without_the_backslash_a_route_may_carry_in_front_of_its_class(): void
    {
        $this->assertSame('list', StaffProductRoutes::ruleOf(controller: '\\App\\Http\\Controllers\\Admin\\Product\\ProductController', method: 'index'));
        $this->assertSame('list', StaffProductRoutes::ruleOf(controller: 'App\\Http\\Controllers\\Admin\\Product\\ProductController', method: 'index'));
        $this->assertSame(StaffProductRoutes::rulesOf(controller: 'App\\Http\\Controllers\\Admin\\Product\\ProductController'), StaffProductRoutes::rulesOf(controller: '\\App\\Http\\Controllers\\Admin\\Product\\ProductController'));
    }

    public function test_a_rule_of_a_kind_nobody_knows_names_nothing_that_can_be_checked(): void
    {
        $request = \Illuminate\Http\Request::create('/admin/products/delete/10', 'DELETE');

        $this->assertNull(StaffProductRoutes::productIdsOf(request: $request, rule: 'bogus:id'));
        $this->assertNull(StaffProductRoutes::productIdsOf(request: $request, rule: ''));
        $this->assertSame([], StaffProductRoutes::productIdsOf(request: $request, rule: 'list'));
    }

    // ---- the scan is not blind -------------------------------------------------------------------------------------------------------

    public function test_a_new_controller_that_reads_products_is_found_and_fails_the_guard(): void
    {
        Route::get('admin/zz-new-report', [NewReportController::class, 'index'])->middleware(['web', 'admin']);

        $this->assertContains(NewReportController::class, $this->unclassified());
    }

    public function test_a_route_that_names_a_product_only_in_its_path_or_parameter_is_found(): void
    {
        Route::get('admin/zz-new/{productId}', [NewPathOnlyController::class, 'show'])->middleware(['web', 'admin']);

        $found = $this->scanner->controllers()[NewPathOnlyController::class];

        $this->assertSame(['GET admin/zz-new/{productId}'], $found['productRoutes']);
    }

    public function test_a_route_that_reads_no_product_and_names_none_is_not_a_product_route(): void
    {
        Route::get('admin/zz-harmless', [NewPathOnlyController::class, 'harmless'])->middleware(['web', 'admin']);

        $this->assertArrayNotHasKey(NewPathOnlyController::class, $this->scanner->controllers());
    }

    public function test_a_route_outside_the_admin_group_is_not_part_of_this_survey(): void
    {
        Route::get('zz-outside', [NewReportController::class, 'index'])->middleware(['web']);

        $this->assertNotContains(NewReportController::class, $this->unclassified());
    }

    public function test_a_route_that_opts_out_of_the_admin_group_is_not_counted_as_in_it(): void
    {
        Route::get('admin/zz-opted-out', [NewReportController::class, 'index'])->middleware(['web', 'admin'])->withoutMiddleware('admin');

        $this->assertNotContains(NewReportController::class, $this->unclassified());
    }

    public function test_the_survey_finds_the_slice_one_controllers_and_a_good_many_more(): void
    {
        $found = $this->scanner->controllers();

        $this->assertArrayHasKey('App\Http\Controllers\Admin\Product\ProductController', $found);
        // 41 since the product-identity lane added POST admin/products/copy-to-country/{id} to this controller.
        $this->assertCount(41, $found['App\Http\Controllers\Admin\Product\ProductController']['productRoutes']);
        $this->assertGreaterThan(40, count($found));
        $this->assertGreaterThan(140, array_sum(array_map(static fn (array $controller): int => count($controller['productRoutes']), $found)));
    }
}
