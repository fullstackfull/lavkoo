<?php

namespace Tests\Feature\StaffScope;

use App\Http\Controllers\Admin\Report\RefundTransactionController;
use App\Http\Controllers\Admin\TransactionController;
use App\Services\Staff\StaffOrderRoutes;
use Illuminate\Support\Facades\Route;
use Tests\Support\StaffOrderInventory;
use Tests\Support\StaffOrderRouteScanner;
use Tests\Support\StaffProbe\NewOrderPathOnlyController;
use Tests\Support\StaffProbe\NewOrderReaderController;
use Tests\TestCase;

/**
 * The guard of the staff country scope for orders (GCC-14c, slice 3): it walks the real route table of the admin panel and fails for a controller that reads orders, their lines,
 * their money records, refunds or the earnings made of them, or names an order, and is neither scoped nor listed, with the slice that takes it, or the reason it needs none. It is how
 * a screen added after slice 3 cannot show a restricted employee another country's orders without somebody having decided so. The table is the one of
 * docs/gcc/GCC-14C-STAFF-COUNTRY-SCOPES.md, which StaffScopeDocTest keeps in step.
 *
 * The scan is a pattern check over source text and has blind spots (a controller that reaches orders only through a service it does not name). The ones found by hand are in
 * StaffOrderInventory::READ_THROUGH_SERVICES, and the doc says so.
 */
class StaffOrderRouteInventoryTest extends TestCase
{
    private StaffOrderRouteScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scanner = new StaffOrderRouteScanner();
    }

    /** @return list<string> */
    private function unclassified(): array
    {
        return array_values(array_diff(array_keys($this->scanner->controllers()), array_keys(StaffOrderInventory::CONTROLLERS), array_keys(StaffOrderInventory::READ_THROUGH_SERVICES)));
    }

    /** @return list<string> */
    private function adminControllers(): array
    {
        $controllers = [];
        foreach ($this->scanner->adminRoutes() as $route) {
            $controllers[ltrim((string) $route->getControllerClass(), '\\')] = true;
        }

        return array_keys($controllers);
    }

    public function test_every_admin_controller_that_reads_orders_or_names_one_is_classified(): void
    {
        $unclassified = $this->unclassified();

        $this->assertSame([], $unclassified, "Admin controllers that read orders or name one and are not in StaffOrderInventory (scope them, or say why they need none and which slice takes them):\n" . implode("\n", $unclassified));
    }

    public function test_every_classified_controller_still_reads_orders_or_names_one(): void
    {
        $gone = array_values(array_diff(array_keys(StaffOrderInventory::CONTROLLERS), array_keys($this->scanner->controllers())));

        $this->assertSame([], $gone, "Listed, but no longer an admin controller that reads orders: take it off the list.\n" . implode("\n", $gone));
    }

    public function test_every_controller_listed_by_hand_is_still_an_admin_controller_and_none_is_listed_twice(): void
    {
        $listed = [...array_keys(StaffOrderInventory::READ_THROUGH_SERVICES), ...array_keys(StaffOrderInventory::SCOPED_WITHOUT_ORDER_READS)];

        $this->assertSame([], array_values(array_diff($listed, $this->adminControllers())));
        $this->assertSame([], array_values(array_intersect($listed, array_keys(StaffOrderInventory::CONTROLLERS))), 'a controller the scan sees belongs in the main table');
        $this->assertSame([], array_values(array_intersect(array_keys(StaffOrderInventory::READ_THROUGH_SERVICES), array_keys(StaffOrderInventory::SCOPED_WITHOUT_ORDER_READS))));
    }

    public function test_every_entry_has_a_reason_and_a_class_and_a_slice_somebody_named(): void
    {
        foreach (StaffOrderInventory::CONTROLLERS as $controller => $entry) {
            $this->assertNotSame('', trim($entry['reason']), $controller . ' is listed without a reason');
            $this->assertContains($entry['class'], [StaffOrderInventory::SCOPED, StaffOrderInventory::NOT_NEEDED, StaffOrderInventory::OUT], $controller);
            $this->assertTrue(!isset($entry['by']) || $entry['class'] === StaffOrderInventory::SCOPED, $controller . ': only a scoped controller says what narrows it');
            $this->assertSame($entry['class'] === StaffOrderInventory::NOT_NEEDED, $entry['slice'] === null, $controller . ': a controller that needs no scope names no slice');
            if ($entry['class'] === StaffOrderInventory::OUT) {
                $this->assertArrayHasKey($entry['slice'], StaffOrderInventory::SLICES, $controller . ': the slice that takes it, or the owner');
            }
            if ($entry['class'] === StaffOrderInventory::SCOPED) {
                $this->assertContains($entry['slice'], ['slice 2', 'slice 3'], $controller);
            }
        }
        foreach (StaffOrderInventory::READ_THROUGH_SERVICES as $controller => $entry) {
            $this->assertNotSame('', trim($entry['reason']), $controller);
            $this->assertArrayHasKey($entry['slice'], ($entry['class'] ?? null) === StaffOrderInventory::SCOPED ? ['slice 2' => true, 'slice 3' => true] : StaffOrderInventory::SLICES, $controller);
        }
        foreach (StaffOrderInventory::SCOPED_WITHOUT_ORDER_READS as $controller => $entry) {
            $this->assertNotSame('', trim($entry['reason']), $controller);
            $this->assertContains($entry['slice'], ['slice 2', 'slice 3'], $controller);
        }
    }

    /** @return array<string, list<class-string>> the controllers the survey says a slice scoped, by slice */
    private function scopedBySurvey(): array
    {
        $scoped = ['slice 2' => [], 'slice 3' => []];
        foreach (StaffOrderInventory::CONTROLLERS as $controller => $entry) {
            if ($entry['class'] === StaffOrderInventory::SCOPED && !isset($entry['by'])) {
                $scoped[$entry['slice']][] = $controller;
            }
        }
        foreach (StaffOrderInventory::READ_THROUGH_SERVICES as $controller => $entry) {
            if (($entry['class'] ?? null) === StaffOrderInventory::SCOPED && !isset($entry['by'])) {
                $scoped[$entry['slice']][] = $controller;
            }
        }
        foreach (StaffOrderInventory::SCOPED_WITHOUT_ORDER_READS as $controller => $entry) {
            $scoped[$entry['slice']][] = $controller;
        }
        sort($scoped['slice 2']);
        sort($scoped['slice 3']);

        return $scoped;
    }

    public function test_the_controllers_listed_as_scoped_are_exactly_the_ones_that_have_rules_in_the_registry_of_their_slice(): void
    {
        $slice2 = StaffOrderRoutes::orderScreenControllers();
        $slice3 = StaffOrderRoutes::readScreenControllers();
        sort($slice2);
        sort($slice3);

        $this->assertSame(['slice 2' => $slice2, 'slice 3' => $slice3], $this->scopedBySurvey());
    }

    public function test_the_controller_of_the_transactions_nobody_reaches_has_no_admin_route(): void
    {
        $this->assertNotContains(TransactionController::class, $this->adminControllers(), 'App\Http\Controllers\Admin\TransactionController reads order transactions with no scope: it is dead code, and this fails the day a route reaches it');
        $this->assertNotContains(TransactionController::class, array_keys(StaffOrderInventory::CONTROLLERS));
    }

    public function test_a_controller_that_is_not_listed_as_scoped_has_no_rules_and_so_no_middleware(): void
    {
        foreach (StaffOrderInventory::CONTROLLERS as $controller => $entry) {
            $hasRules = $entry['class'] === StaffOrderInventory::SCOPED && !isset($entry['by']);

            $this->assertSame($hasRules, StaffOrderRoutes::rulesOf(controller: $controller) !== [], $controller);
        }
    }

    public function test_a_controller_scoped_by_its_search_has_no_rules_and_no_middleware_and_says_what_narrows_it(): void
    {
        $bySearch = [];
        foreach ([...StaffOrderInventory::CONTROLLERS, ...StaffOrderInventory::READ_THROUGH_SERVICES, ...\Tests\Support\StaffProductInventory::READ_THROUGH_SERVICES] as $controller => $entry) {
            if (isset($entry['by'])) {
                $bySearch[$controller] = $entry;
            }
        }

        $names = array_keys($bySearch);
        sort($names);

        $this->assertSame(['App\Http\Controllers\Admin\AdvancedSearchController', 'App\Http\Controllers\Admin\V2AdvancedSearchController'], $names);
        foreach ($bySearch as $controller => $entry) {
            $this->assertSame('the search', $entry['by'], $controller);
            $this->assertSame([], StaffOrderRoutes::rulesOf(controller: $controller), $controller . ' narrows what it hands back: it registers no middleware (it would narrow the query that fills a cache every employee shares)');
            $this->assertStringNotContainsString('ScopeOrdersToStaffCountries', (string) file_get_contents((string) (new \ReflectionClass($controller))->getFileName()), $controller);
        }
    }

    /** @return array<string, string> the subject a screen narrows a request for, and what in the source of a controller means that it reads those rows */
    private function markersOfTheRowsASubjectNarrows(): array
    {
        return [
            'orders' => '/(?<![A-Za-z])Order::|\bOrderRepositoryInterface\b|->orders\b|[\'"]orders[\'"]\s*(?:=>|,|\))|\bdeliveredOrders\b/',
            'order_lines' => '/\bOrderDetail::|\borderDetails\b|\borderDetailRepo\b|\bOrderDetailRepositoryInterface\b/',
            'order_transactions' => '/\bOrderTransaction::|\borderTransaction\b|\borderTransactionRepo\b|\bOrderTransactionRepositoryInterface\b/',
            'refunds' => '/\bRefundRequest::|\brefundRequest\b|\brefundRequestRepo\b|\bRefundRequestRepositoryInterface\b|\brefundOrders\b/',
            'refund_transactions' => '/\bRefundTransaction::|\brefundTransactionRepo\b|\bRefundTransactionRepositoryInterface\b/',
        ];
    }

    /**
     * Rows a controller reads on purpose through a row it has already narrowed, and must not narrow by themselves, with the reason.
     *
     * @var array<class-string, array<string, string>>
     */
    private const READ_THROUGH_A_NARROWED_ROW = [
        RefundTransactionController::class => ['order_lines' => 'the line a refund money record is about is reached through the record, which the scope of the refund money records narrowed: the refund follows the line, so the line of a refund about a Kuwaiti line of a mixed order must not be hidden with the order'],
    ];

    public function test_the_rules_of_a_controller_of_slice_3_name_every_kind_of_row_its_file_reads(): void
    {
        foreach (StaffOrderRoutes::readScreenControllers() as $controller) {
            if (array_values(array_unique(array_map('json_encode', StaffOrderRoutes::rulesOf(controller: $controller)))) === [json_encode(['unavailable'])]) {
                continue;
            }

            $source = (string) file_get_contents((string) (new \ReflectionClass($controller))->getFileName());
            $named = [];
            foreach (StaffOrderRoutes::rulesOf(controller: $controller) as $rule) {
                $named = [...$named, ...StaffOrderRoutes::subjectsOf(rule: $rule)];
            }
            $read = array_keys(array_filter($this->markersOfTheRowsASubjectNarrows(), static fn (string $pattern): bool => preg_match($pattern, $source) === 1));

            $read = array_values(array_diff($read, array_keys(self::READ_THROUGH_A_NARROWED_ROW[$controller] ?? [])));

            $this->assertSame([], array_values(array_diff($read, $named)), $controller . ' reads rows that no rule of it narrows a request for (name the subject in the rule of the actions that read them)');
        }
    }

    // ---- the scan is not blind -------------------------------------------------------------------------------------------------------

    public function test_a_new_controller_that_reads_orders_is_found_and_fails_the_guard(): void
    {
        Route::get('admin/zz-new-order-report', [NewOrderReaderController::class, 'index'])->middleware(['web', 'admin']);

        $this->assertContains(NewOrderReaderController::class, $this->unclassified());
    }

    public function test_a_route_that_names_an_order_only_in_its_path_or_parameter_is_found(): void
    {
        Route::get('admin/zz-new/{orderId}', [NewOrderPathOnlyController::class, 'show'])->middleware(['web', 'admin']);

        $this->assertSame(['GET admin/zz-new/{orderId}'], $this->scanner->controllers()[NewOrderPathOnlyController::class]['orderRoutes']);
    }

    public function test_a_route_that_reads_no_order_and_names_none_is_not_an_order_route(): void
    {
        Route::get('admin/zz-harmless', [NewOrderPathOnlyController::class, 'harmless'])->middleware(['web', 'admin']);

        $this->assertArrayNotHasKey(NewOrderPathOnlyController::class, $this->scanner->controllers());
    }

    public function test_a_route_outside_the_admin_group_is_not_part_of_this_survey(): void
    {
        Route::get('zz-outside', [NewOrderReaderController::class, 'index'])->middleware(['web']);

        $this->assertNotContains(NewOrderReaderController::class, $this->unclassified());
    }

    public function test_a_route_that_opts_out_of_the_admin_group_is_not_counted_as_in_it(): void
    {
        Route::get('admin/zz-opted-out', [NewOrderReaderController::class, 'index'])->middleware(['web', 'admin'])->withoutMiddleware('admin');

        $this->assertNotContains(NewOrderReaderController::class, $this->unclassified());
    }

    public function test_the_survey_finds_the_order_screens_and_a_good_many_more(): void
    {
        $found = $this->scanner->controllers();

        $this->assertArrayHasKey('App\Http\Controllers\Admin\Order\OrderController', $found);
        $this->assertCount(17, $found['App\Http\Controllers\Admin\Order\OrderController']['orderRoutes']);
        $this->assertGreaterThan(25, count($found));
        $this->assertGreaterThan(90, array_sum(array_map(static fn (array $controller): int => count($controller['orderRoutes']), $found)));
    }
}
