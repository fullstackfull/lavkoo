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
use App\Http\Controllers\Admin\Telemetry\AnalyticsController;
use App\Models\Admin;
use App\Services\Staff\StaffCountryScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\TaxModule\app\Http\Controllers\Admin\Reports\VendorTaxReportController;
use Tests\Support\BuildsStaffReportWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Two things slice 3 does that no page shows (GCC-14c): the sum over the table of orders that the vendor tax report makes outside the model is narrowed by the same rule as the model
 * (the one raw read of orders among the covered screens), and the analytics screens, whose orders and revenue come from rollups that carry no country of the shop, are unavailable to a
 * restricted employee. Orders: 101 SA, 102 KW (a vendor's shop 7), 103 KW (shop 7), 104 SA and KW, 105 without a line, 109 AE.
 */
class StaffTaxAndAnalyticsTest extends TestCase
{
    use BuildsStaffReportWorld;
    use RestoresLanguageFiles;

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();

        $this->withoutMiddleware([
            ActivationCheckMiddleware::class, Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
        $this->buildStaffReportTables();
        $this->insertStaffProduct(id: 1, country: 'SA');
        $this->insertStaffProduct(id: 2, country: 'KW');
        $this->insertStaffProduct(id: 3, country: 'KW');
        $this->insertStaffProduct(id: 4, country: 'AE');
        DB::statement('alter table orders add column shop_id integer null');
        foreach ([101 => [1], 102 => [2], 103 => [2, 3], 104 => [1, 2], 105 => [], 109 => [4]] as $order => $products) {
            $this->insertStaffOrder(id: $order, products: $products, extra: ['order_status' => 'delivered', 'order_amount' => $order, 'total_tax_amount' => 1]);
            DB::table('orders')->where('id', $order)->update(['shop_id' => 7]);
        }
    }

    private function narrowTo(array $countries, int $adminId = 7): void
    {
        $this->switchStaffScopesOn();
        DB::table('admin_country_scopes')->where('admin_id', $adminId)->delete();
        $this->giveCountries(adminId: $adminId, countries: $countries);
        $request = Request::create('/admin/report/vendor-tax-report');
        $this->app->instance('request', $request);
        $scope = app(StaffCountryScope::class);
        $scope->bind(request: $request, admin: Admin::query()->findOrFail($adminId));
        $scope->narrowTo(request: $request, subjects: [StaffCountryScope::SUBJECT_ORDERS]);
    }

    private function sumOfTheShop(): object
    {
        return app(StaffCountryScope::class)->narrowRawOrders(query: DB::table('orders'), orderIdColumn: 'orders.id')
            ->where('shop_id', 7)
            ->selectRaw('COUNT(*) as total_orders, SUM(order_amount) as total_order_amount')
            ->first();
    }

    // ---- the raw sum of the vendor tax report ------------------------------------------------------------------------------------------

    public function test_the_sum_over_the_table_of_orders_counts_the_employees_orders_only(): void
    {
        $this->narrowTo(['KW']);

        $sum = $this->sumOfTheShop();

        $this->assertSame([2, 205.0], [(int) $sum->total_orders, (float) $sum->total_order_amount], '102 and 103: the mixed order, the one with no line and the others are not theirs');
    }

    public function test_the_same_sum_for_who_is_not_restricted_is_over_every_order_and_the_same_statement_as_before(): void
    {
        $unbound = DB::table('orders')->where('shop_id', 7)->toSql();
        $everyone = $this->sumOfTheShop();

        $this->assertSame([6, 624.0], [(int) $everyone->total_orders, (float) $everyone->total_order_amount]);
        $this->assertSame($unbound, app(StaffCountryScope::class)->narrowRawOrders(query: DB::table('orders'), orderIdColumn: 'orders.id')->where('shop_id', 7)->toSql());
    }

    public function test_an_employee_restricted_to_nothing_sums_no_order(): void
    {
        $this->narrowTo(['zz']);

        $this->assertSame(0, (int) $this->sumOfTheShop()->total_orders);
    }

    public function test_the_vendor_tax_report_narrows_its_raw_sum_by_the_rule_of_the_model_and_builds_without_a_statement_sqlite_does_not_know(): void
    {
        $source = (string) file_get_contents((string) (new \ReflectionClass(VendorTaxReportController::class))->getFileName());

        $this->assertStringContainsString("app(StaffCountryScope::class)->narrowRawOrders(query: DB::table('orders'), orderIdColumn: 'orders.id')", $source);
        $this->assertStringContainsString("in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)", $source);
        $this->assertInstanceOf(VendorTaxReportController::class, app(VendorTaxReportController::class), 'the controller is built before the sign-in check of its routes, and the statement that is MySQL\'s is not run elsewhere');
    }

    // ---- analytics ----------------------------------------------------------------------------------------------------------------------

    /** @return list<array{0: string, 1: string}> */
    private function analyticsRoutes(): array
    {
        $routes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (ltrim((string) $route->getControllerClass(), '\\') === AnalyticsController::class) {
                $routes[] = [array_values(array_diff($route->methods(), ['HEAD']))[0], '/' . preg_replace('/\{[^}]+\}/', '1', $route->uri())];
            }
        }

        return $routes;
    }

    public function test_every_analytics_screen_is_unavailable_to_a_restricted_employee(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 9, countries: ['KW']);
        $this->signInAsStaff(adminId: 9);

        $this->assertCount(7, $this->analyticsRoutes());
        foreach ($this->analyticsRoutes() as [$method, $uri]) {
            $this->call($method, $uri)->assertNotFound();
        }
    }

    public function test_the_analytics_answer_a_restricted_employee_like_an_unknown_page_and_are_not_refused_to_who_is_not_restricted(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 9, countries: ['KW']);
        $this->signInAsStaff(adminId: 9);
        $known = $this->getJson('/admin/analytics/live-feed');
        $unknown = $this->getJson('/admin/zz-nothing-here');

        $this->assertSame([404, $unknown->getStatusCode()], [$known->getStatusCode(), 404]);
        $this->assertSame('', $known->json('message'), 'no message: nothing that tells why');

        DB::table('admin_country_scopes')->where('admin_id', 9)->delete();
        $this->assertNotSame(404, $this->getJson('/admin/analytics/live-feed')->getStatusCode(), 'no country, so no restriction: the screen answers what it always did');
    }
}
