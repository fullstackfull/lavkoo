<?php

namespace Tests\Feature\StaffScope;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\EnsureMasterAdmin;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Services\AuditLogger;
use App\Services\Staff\StaffGovernanceRoutes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Tests\Support\BuildsStaffScopeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\Support\StaffGovernanceInventory;
use Tests\Support\StaffGovernanceRouteScanner;
use Tests\TestCase;

/**
 * The screens of the panel's own GOVERNANCE: the employees, their roles, the audit trail and the credentials of the integrations (GCC-14c;
 * G1-08, G1-09, G1-13). They were in neither of the two surveys, which is what this closes.
 *
 * The guard is the third scan beside the products' and the orders'. The behaviour it pins has two halves. The employee and role screens are
 * behind `EnsureMasterAdmin`, so a restricted employee does not reach them at all -- a real answer, and only true while the middleware is
 * there, which is why it is asserted per route. And the audit centre narrows its lines by the market each one names, which it has always
 * said it does and, until this, did not: nothing on its route bound the employee's countries, so the narrowing was dead code and the trail
 * of every market was readable by anyone with the system-settings module.
 */
class StaffGovernanceScreensTest extends TestCase
{
    use BuildsStaffScopeWorld;
    use RestoresLanguageFiles;

    private StaffGovernanceRouteScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scanner = new StaffGovernanceRouteScanner();
        $this->rememberLanguageFiles();
        Http::preventStrayRequests();
        View::getFinder()->prependLocation(base_path('tests/Support/staff-views'));
        $this->withoutMiddleware([
            ActivationCheckMiddleware::class, Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);

        $this->buildStaffAdminTables();
        $this->buildStaffProductTables();
        $this->runStaffScopeMigration();
        $this->primeTaxSystemCache();

        // An employee who holds the system settings (the audit centre) and the people module (the employee screens), so that what refuses
        // them is the staff gate and never a missing permission.
        DB::table('admin_roles')->insert(['id' => 62, 'name' => 'System and people', 'module_access' => json_encode(['system_settings', 'people']), 'status' => 1]);
        DB::table('admins')->insert(['id' => 91, 'name' => 'System hand', 'email' => 'ninetyone@example.test', 'admin_role_id' => 62]);

        app(AuditLogger::class)->record(action: 'coupon.updated', subject: ['type' => 'coupon', 'id' => 1], country: 'KW');
        app(AuditLogger::class)->record(action: 'theme.published', subject: ['type' => 'theme', 'id' => 1], country: 'SA');
        app(AuditLogger::class)->record(action: 'settings.changed', subject: ['type' => 'setting', 'id' => 1]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('audit_logs');
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function asKuwaitSystemHand(): static
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 91, countries: ['KW']);

        return $this->signInAsStaff(adminId: 91);
    }

    // ---- the audit centre -----------------------------------------------------------------------------------------------------------

    public function test_the_audit_centre_shows_a_restricted_employee_the_lines_of_their_own_countries(): void
    {
        $page = $this->asKuwaitSystemHand()->get('/admin/marketplace/audit-log');

        $page->assertOk();
        $this->assertStringContainsString('[audit:1 KW]', (string) $page->getContent());
        $this->assertStringNotContainsString('[audit:2 ', (string) $page->getContent(), 'the Saudi line');
        $this->assertStringNotContainsString('[audit:3 ', (string) $page->getContent(), 'a line that names no market is nobody\'s');
    }

    /**
     * The second read of the same screen. The module filter is built from the actions actually recorded, so an unnarrowed one would tell a
     * Kuwait employee that something happened to a THEME -- in a market they cannot see a single line of.
     */
    public function test_the_module_filter_of_the_audit_centre_names_no_other_markets_area(): void
    {
        $page = $this->asKuwaitSystemHand()->get('/admin/marketplace/audit-log');

        $this->assertStringContainsString('[module:coupon]', (string) $page->getContent());
        $this->assertStringNotContainsString('[module:theme]', (string) $page->getContent());
        $this->assertStringNotContainsString('[module:settings]', (string) $page->getContent());
    }

    public function test_an_employee_restricted_to_nothing_reads_no_line_at_all(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 91, countries: ['ZZ']);

        $page = $this->signInAsStaff(adminId: 91)->get('/admin/marketplace/audit-log');

        $page->assertOk();
        $this->assertStringNotContainsString('[audit:', (string) $page->getContent());
        $this->assertStringNotContainsString('[module:', (string) $page->getContent());
    }

    public function test_the_master_admin_and_an_unrestricted_employee_read_the_whole_trail(): void
    {
        $this->switchStaffScopesOn();

        foreach ([1, 91] as $adminId) {
            $page = $this->signInAsStaff(adminId: $adminId)->get('/admin/marketplace/audit-log');

            $page->assertOk();
            foreach (['[audit:1 KW]', '[audit:2 SA]', '[audit:3 none]'] as $line) {
                $this->assertStringContainsString($line, (string) $page->getContent(), $adminId . ' ' . $line);
            }
        }
    }

    public function test_with_the_switch_off_the_whole_trail_is_read_as_it_always_was(): void
    {
        $this->giveCountries(adminId: 91, countries: ['KW']);

        $page = $this->signInAsStaff(adminId: 91)->get('/admin/marketplace/audit-log');

        $page->assertOk();
        $this->assertStringContainsString('[audit:2 SA]', (string) $page->getContent());
    }

    // ---- the employee and role screens ----------------------------------------------------------------------------------------------

    public function test_no_employee_but_a_master_account_reaches_the_staff_or_role_screens(): void
    {
        $this->asKuwaitSystemHand();

        foreach ([
            '/admin/employee/list', '/admin/employee/add', '/admin/employee/export', '/admin/employee/view/1',
            '/admin/employee/update/1', '/admin/custom-role/add', '/admin/custom-role/update/2', '/admin/custom-role/export',
        ] as $url) {
            $this->get($url)->assertRedirect(route('admin.dashboard.index'));
        }
        foreach (['/admin/employee/add', '/admin/employee/update/1', '/admin/employee/status', '/admin/custom-role/add', '/admin/custom-role/delete', '/admin/custom-role/employee-role-status'] as $url) {
            $this->post($url, ['id' => 1, 'status' => 0])->assertRedirect(route('admin.dashboard.index'));
        }

        $this->assertSame(2, (int) DB::table('admins')->where('id', 2)->value('admin_role_id'), 'no role was changed');
        $this->assertSame(8, DB::table('admins')->count(), 'no employee was created: the seven of the shared world and this test\'s own');
    }

    /**
     * The gate is the whole answer for these screens, so the inventory may only say `master_only` while it is really there. A route of such
     * a controller that lost it would hand a restricted employee the one screen that can widen their own reach.
     */
    public function test_every_route_of_a_master_only_screen_really_carries_the_gate(): void
    {
        $masterOnly = array_keys(array_filter(StaffGovernanceInventory::CONTROLLERS, static fn (array $entry): bool => $entry['class'] === StaffGovernanceInventory::MASTER_ONLY));
        $this->assertNotSame([], $masterOnly);

        $checked = 0;
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (!in_array(ltrim((string) $route->getControllerClass(), '\\'), $masterOnly, true)) {
                continue;
            }

            $middleware = array_values(array_diff(array_map(static fn ($one): string => is_string($one) ? $one : 'closure', $route->gatherMiddleware()), array_map('strval', $route->excludedMiddleware())));
            $this->assertContains(EnsureMasterAdmin::class, $middleware, $this->scanner->keyOf(route: $route) . ' is listed as master-only and does not carry the gate');
            $checked++;
        }

        $this->assertSame(15, $checked, 'the eight employee routes and the seven role routes');
    }

    // ---- the survey -----------------------------------------------------------------------------------------------------------------

    public function test_every_admin_controller_that_reads_the_panels_governance_is_classified(): void
    {
        $unclassified = array_values(array_diff(array_keys($this->scanner->controllers()), array_keys(StaffGovernanceInventory::CONTROLLERS)));

        $this->assertSame([], $unclassified, "Admin controllers that read the employees, the roles, the staff countries, the audit trail or an integration credential and are not in StaffGovernanceInventory:\n" . implode("\n", $unclassified));
    }

    public function test_every_classified_controller_still_reads_the_panels_governance(): void
    {
        $gone = array_values(array_diff(array_keys(StaffGovernanceInventory::CONTROLLERS), array_keys($this->scanner->controllers())));

        $this->assertSame([], $gone, "Listed, but no longer an admin controller that reads it: take it off the list.\n" . implode("\n", $gone));
    }

    public function test_every_entry_has_a_reason_and_a_class_and_a_slice_somebody_named(): void
    {
        foreach (StaffGovernanceInventory::CONTROLLERS as $controller => $entry) {
            $this->assertNotSame('', trim($entry['reason']), $controller . ' is listed without a reason');
            $this->assertContains($entry['class'], [StaffGovernanceInventory::MASTER_ONLY, StaffGovernanceInventory::SCOPED, StaffGovernanceInventory::NOT_NEEDED, StaffGovernanceInventory::OUT], $controller);
            $this->assertSame($entry['class'] === StaffGovernanceInventory::OUT, $entry['slice'] !== null, $controller . ': only what is still out names the slice or the question that takes it');
            if ($entry['class'] === StaffGovernanceInventory::OUT) {
                $this->assertArrayHasKey($entry['slice'], StaffGovernanceInventory::SLICES, $controller);
            }
        }
    }

    public function test_the_controllers_listed_as_scoped_are_exactly_the_ones_with_rules_and_the_others_have_none(): void
    {
        $scoped = array_keys(array_filter(StaffGovernanceInventory::CONTROLLERS, static fn (array $entry): bool => $entry['class'] === StaffGovernanceInventory::SCOPED));
        sort($scoped);
        $registry = StaffGovernanceRoutes::controllers();
        sort($registry);

        $this->assertSame($registry, $scoped);
        foreach (StaffGovernanceInventory::CONTROLLERS as $controller => $entry) {
            $this->assertSame($entry['class'] === StaffGovernanceInventory::SCOPED, StaffGovernanceRoutes::rulesOf(controller: $controller) !== [], $controller);
        }
    }

    public function test_every_route_of_a_scoped_controller_has_a_rule_the_registry_understands(): void
    {
        foreach (StaffGovernanceRoutes::controllers() as $controller) {
            $actions = [];
            foreach (Route::getRoutes()->getRoutes() as $route) {
                if (ltrim((string) $route->getControllerClass(), '\\') === $controller) {
                    $actions[$route->getActionMethod()] = true;
                }
            }

            $rules = StaffGovernanceRoutes::rulesOf(controller: $controller);
            $this->assertSame([], array_values(array_diff_key($actions, $rules)), $controller . ' has routes without a rule in StaffGovernanceRoutes');
            $this->assertSame([], array_values(array_diff(array_keys($rules), array_keys($actions))), $controller . ' has rules for actions that no route reaches');
            foreach ($rules as $action => $rule) {
                $this->assertTrue(StaffGovernanceRoutes::isKnown($rule), $controller . '@' . $action . ': ' . $rule);
            }
        }
    }

    public function test_a_rule_nobody_understands_is_not_a_rule(): void
    {
        $this->assertFalse(StaffGovernanceRoutes::isKnown('list'));
        $this->assertFalse(StaffGovernanceRoutes::isKnown(''));
        $this->assertTrue(StaffGovernanceRoutes::isKnown('none'));
        $this->assertTrue(StaffGovernanceRoutes::isKnown('list:audit'));
    }

    public function test_the_survey_counts_what_it_found(): void
    {
        $this->assertSame(
            [StaffGovernanceInventory::MASTER_ONLY => 2, StaffGovernanceInventory::SCOPED => 1, StaffGovernanceInventory::NOT_NEEDED => 5, StaffGovernanceInventory::OUT => 2],
            StaffGovernanceInventory::countsByStatus()
        );
    }

    /** The doc carries the same survey, with the same counts and the same status for every controller. */
    public function test_the_doc_carries_the_survey_and_its_counts(): void
    {
        $doc = (string) file_get_contents(base_path('docs/gcc/GCC-14C-STAFF-COUNTRY-SCOPES.md'));
        $found = $this->scanner->controllers();
        $counts = StaffGovernanceInventory::countsByStatus();

        $this->assertStringContainsString(sprintf('**%d controllers: %d master-only, %d scoped, %d not needed, %d out.**', array_sum($counts), $counts[StaffGovernanceInventory::MASTER_ONLY], $counts[StaffGovernanceInventory::SCOPED], $counts[StaffGovernanceInventory::NOT_NEEDED], $counts[StaffGovernanceInventory::OUT]), $doc);

        foreach (StaffGovernanceInventory::CONTROLLERS as $controller => $entry) {
            $status = match ($entry['class']) {
                StaffGovernanceInventory::MASTER_ONLY => 'master only',
                StaffGovernanceInventory::SCOPED => 'scoped',
                StaffGovernanceInventory::NOT_NEEDED => 'not needed',
                default => 'out (' . $entry['slice'] . ')',
            };
            $row = sprintf('| `%s` | %d | %d | %s |', str_replace('App\\Http\\Controllers\\', '', $controller), count($found[$controller]['routes']), count($found[$controller]['governanceRoutes']), $status);

            $this->assertStringContainsString($row, $doc, $controller);
        }
    }

    public function test_the_scan_reads_the_writers_of_the_trail_as_no_part_of_this_survey(): void
    {
        $found = array_keys($this->scanner->controllers());

        $this->assertNotContains('App\Http\Controllers\Admin\Commerce\CampaignController', $found, 'recording a line is G1-08\'s business, not a screen that shows one');
        $this->assertContains('App\Http\Controllers\Admin\Marketplace\AuditLogController', $found);
    }
}
