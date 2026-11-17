<?php

namespace Tests\Feature\StaffScope;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Providers\AppServiceProvider;
use App\Services\Staff\StaffCountryScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use ReflectionMethod;
use Tests\Support\BuildsStaffOrderWorld;
use Tests\Support\StaffProbe\LayoutProbeController;
use Tests\TestCase;

/**
 * The layout every admin page shares (GCC-14c, slice 3): the counters of its sidebar and the latest pending orders of its header are narrowed to the countries of a restricted
 * employee on EVERY admin screen, not only on the screens whose controller registers the middleware of the scope.
 *
 * The probe screen below is one that no scope covers (most of the panel is): it reads orders and products in its own content, and then renders the layout. The layout is rendered
 * after the screen, so it binds the request itself, once, when no middleware has; what the screen read is not touched. Employee 7 works with Kuwait only, employee 1 is the
 * master admin. Products: 1 SA, 2 KW, 3 KW, 4 AE. Orders: 101 SA, 102 KW, 103 KW (delivered), 104 SA and KW, 105 without a line, 109 AE (confirmed). Refund requests: 1 about
 * a Saudi line, 2 about a Kuwaiti line, 3 about a Saudi line of the mixed order.
 */
class StaffLayoutTest extends TestCase
{
    use BuildsStaffOrderWorld;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Event::fake();
        View::getFinder()->prependLocation(base_path('tests/Support/staff-layout-views'));
        $this->withoutMiddleware([
            ActivationCheckMiddleware::class, Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);

        $this->buildStaffOrderTables();
        Schema::dropIfExists('contacts');
        Schema::create('contacts', function ($table) {
            $table->id();
            $table->boolean('seen')->default(false);
        });
        $this->insertStaffProduct(id: 1, country: 'SA');
        $this->insertStaffProduct(id: 2, country: 'KW');
        $this->insertStaffProduct(id: 3, country: 'KW');
        $this->insertStaffProduct(id: 4, country: 'AE');
        $this->insertStaffOrder(id: 101, products: [1]);
        $this->insertStaffOrder(id: 102, products: [2]);
        $this->insertStaffOrder(id: 103, products: [2, 3], extra: ['order_status' => 'delivered']);
        $this->insertStaffOrder(id: 104, products: [1, 2]);
        $this->insertStaffOrder(id: 105, products: []);
        $this->insertStaffOrder(id: 109, products: [4], extra: ['order_status' => 'confirmed']);
        $this->insertStaffRefund(id: 1, orderId: 101, aboutProduct: 1);
        $this->insertStaffRefund(id: 2, orderId: 102, aboutProduct: 2);
        $this->insertStaffRefund(id: 3, orderId: 104, aboutProduct: 1);

        $provider = $this->app->getProvider(AppServiceProvider::class);
        (new ReflectionMethod($provider, 'adminSidebarLayoutCount'))->invoke($provider);
        Route::get('admin/zz-layout-probe', [LayoutProbeController::class, 'show'])->middleware(['web', 'admin']);
    }

    private function signInAsKuwaitEmployee(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 7, countries: ['KW']);
        $this->signInAsStaff(adminId: 7);
    }

    /** @return array{screen: string, orders: string, total: int, refunds: string, latest: string} what the probe screen and the shared layout printed */
    private function pageOf(TestResponse $page): array
    {
        $page->assertOk();
        $content = (string) $page->getContent();
        preg_match('/\[screen ([^\]]*)\]/', $content, $screen);
        preg_match('/\[layout-orders ([^\]]*)\]/', $content, $orders);
        preg_match('/\[layout-total (\d+)\]/', $content, $total);
        preg_match('/\[layout-refunds ([^\]]*)\]/', $content, $refunds);
        preg_match('/\[layout-latest ([^\]]*)\]/', $content, $latest);

        return ['screen' => $screen[1], 'orders' => $orders[1], 'total' => (int) $total[1], 'refunds' => $refunds[1], 'latest' => $latest[1]];
    }

    private function probe(): array
    {
        return $this->pageOf($this->get('/admin/zz-layout-probe'));
    }

    /** @return list<string> the statements a request ran, after one request to warm up whatever the first one loads */
    private function queriesOfTheProbe(): array
    {
        $this->get('/admin/zz-layout-probe')->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/admin/zz-layout-probe')->assertOk();

        return array_column(DB::getQueryLog(), 'query');
    }

    /** @param list<string> $queries @return list<string> */
    private function withoutTheReadsThatResolveAnEmployee(array $queries): array
    {
        return array_values(array_filter($queries, static fn (string $query): bool => !str_contains($query, 'admin_country_scopes') && !str_contains($query, 'sqlite_master') && !str_contains($query, 'information_schema') && !str_contains($query, 'pragma')));
    }

    private const EVERYTHING = ['screen' => 'orders=6 products=4', 'orders' => 'confirmed=1 delivered=1 pending=4', 'total' => 6, 'refunds' => 'pending=3', 'latest' => '105,104,102,101'];

    // ---- a screen no middleware covers ---------------------------------------------------------------------------------------------

    public function test_the_layout_of_a_screen_no_scope_covers_counts_the_orders_and_refund_requests_of_the_employees_countries_only(): void
    {
        $this->signInAsKuwaitEmployee();

        $page = $this->probe();

        $this->assertSame('delivered=1 pending=1', $page['orders']);
        $this->assertSame(2, $page['total']);
        $this->assertSame('pending=1', $page['refunds']);
        $this->assertSame('102', $page['latest'], 'no number and no thumbnail of an order of another country in the header');
    }

    public function test_what_the_screen_itself_reads_is_not_touched_only_its_layout_is_narrowed(): void
    {
        $this->signInAsKuwaitEmployee();

        $this->assertSame('orders=6 products=4', $this->probe()['screen'], 'a screen is narrowed by its own slice; the layout is the shared part');
    }

    public function test_an_employee_restricted_to_nothing_sees_no_counter_and_no_latest_order(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 7, countries: ['zz']);
        $this->signInAsStaff(adminId: 7);

        $page = $this->probe();

        $this->assertSame(['', 0, '', ''], [$page['orders'], $page['total'], $page['refunds'], $page['latest']]);
    }

    public function test_the_countries_of_two_requests_of_the_same_process_never_mix(): void
    {
        $this->signInAsKuwaitEmployee();
        $this->assertSame('102', $this->probe()['latest']);

        $this->signInAsStaff(adminId: 1);
        $this->assertSame(self::EVERYTHING, $this->probe());

        $this->signInAsStaff(adminId: 7);
        $this->assertSame('102', $this->probe()['latest']);
    }

    // ---- who is not narrowed -------------------------------------------------------------------------------------------------------

    public function test_the_master_admin_sees_every_counter_and_order_with_the_switch_on_whatever_the_table_holds(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 1, countries: ['KW']);
        $this->signInAsStaff(adminId: 1);

        $this->assertSame(self::EVERYTHING, $this->probe());
    }

    public function test_an_employee_with_no_country_sees_every_counter_so_turning_the_switch_on_changes_nothing(): void
    {
        $this->switchStaffScopesOn();
        $this->signInAsStaff(adminId: 7);

        $this->assertSame(self::EVERYTHING, $this->probe());
    }

    public function test_with_the_switch_off_an_employee_who_has_countries_sees_every_counter(): void
    {
        $this->giveCountries(adminId: 7, countries: ['KW']);
        $this->signInAsStaff(adminId: 7);

        $this->assertSame(self::EVERYTHING, $this->probe());
    }

    public function test_with_the_switch_off_the_layout_asks_the_database_what_it_always_asked_and_nothing_about_countries(): void
    {
        $this->giveCountries(adminId: 7, countries: ['KW']);
        $this->signInAsStaff(adminId: 7);
        $withCountriesButOff = $this->queriesOfTheProbe();
        $this->signInAsStaff(adminId: 1);
        $master = $this->queriesOfTheProbe();

        $this->assertSame($master, $withCountriesButOff);
        $this->assertSame([], array_filter($withCountriesButOff, static fn (string $query): bool => str_contains($query, 'admin_country_scopes') || str_contains($query, 'visible_lines')));
    }

    public function test_the_master_admin_costs_no_extra_query_even_with_the_switch_on(): void
    {
        $this->signInAsStaff(adminId: 1);
        $switchOff = $this->queriesOfTheProbe();
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 1, countries: ['KW']);

        $this->assertSame($switchOff, $this->queriesOfTheProbe(), 'no schema probe, no read of the table of countries: the same statements');
    }

    public function test_an_employee_with_no_country_asks_the_same_statements_as_the_master_admin_besides_the_reads_that_say_so(): void
    {
        $this->switchStaffScopesOn();
        $this->signInAsStaff(adminId: 1);
        $master = $this->queriesOfTheProbe();
        $this->signInAsStaff(adminId: 7);
        $employee = $this->queriesOfTheProbe();

        $this->assertSame($master, $this->withoutTheReadsThatResolveAnEmployee($employee), 'what remains besides the probe of the table and the column, and the read of the rows of the employee, is what the master admin asks');
        $this->assertGreaterThan(count($master), count($employee));
    }

    public function test_a_restricted_layout_resolves_the_employee_once_and_narrows_each_of_its_three_reads(): void
    {
        $this->signInAsKuwaitEmployee();
        $queries = $this->queriesOfTheProbe();

        $this->assertCount(1, array_filter($queries, static fn (string $query): bool => str_contains($query, 'admin_country_scopes') && !str_contains($query, 'sqlite_master')), 'the rows of the employee are read once');
        $this->assertCount(2, array_filter($queries, static fn (string $query): bool => str_contains($query, 'visible_lines')), 'the order counters and the latest orders are narrowed');
        $this->assertCount(1, array_filter($queries, static fn (string $query): bool => str_contains($query, 'refund_lines')), 'and so are the refund counters');
    }

    // ---- the header partials -------------------------------------------------------------------------------------------------------

    public function test_both_headers_of_the_real_layout_read_the_latest_pending_orders_through_the_order_model_so_the_scope_reaches_them(): void
    {
        foreach (['resources/views/layouts/admin/partials/_header.blade.php', 'resources/views/layouts/admin/partials/v2/_header.blade.php'] as $header) {
            $this->assertMatchesRegularExpression("/\\\\App\\\\Models\\\\Order::where\\('order_status', 'pending'\\)\\s*->with\\('details\\.productAllStatus'\\)/", (string) file_get_contents(base_path($header)), $header);
        }
    }

    public function test_the_layout_reads_orders_nowhere_else_than_the_composer_and_the_two_headers(): void
    {
        $readers = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views/layouts/admin'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && preg_match('/Order::|RefundRequest::|order_details|DB::table/', (string) file_get_contents($file->getPathname())) === 1) {
                $readers[] = str_replace(resource_path('views/'), '', $file->getPathname());
            }
        }
        sort($readers);

        $this->assertSame(['layouts/admin/partials/_header.blade.php', 'layouts/admin/partials/v2/_header.blade.php'], $readers);
    }

    public function test_a_request_nobody_is_signed_in_to_is_not_bound_by_the_layout(): void
    {
        $this->switchStaffScopesOn();
        $request = Request::create('/admin/zz-layout-probe');
        $this->app->instance('request', $request);

        app(StaffCountryScope::class)->narrowLayout(request: $request, admin: null);

        $this->assertFalse(app(StaffCountryScope::class)->restricts());
    }
}
