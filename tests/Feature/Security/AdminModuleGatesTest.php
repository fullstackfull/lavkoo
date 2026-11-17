<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Admin;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The role editor of the admin panel switches whole modules on and off per employee, and a route without the `module:` middleware answers every
 * employee, whatever their role holds. The routes of the AI and tax modules sat in the `admin` group alone: an employee with an empty role could
 * read and change the AI provider settings (the API key included) and the VAT and tax rates, and read the tax reports. Each group now asks for
 * the permission of the menu that lists it.
 */
class AdminModuleGatesTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class, ActivationCheckMiddleware::class,
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function gatedRoutes(): array
    {
        $routes = [];
        foreach (['index', 'store', 'vendors-usage-limits', 'vendors-usage-limits-update', 'customers-usage-limits', 'customers-usage-limits-update'] as $name) {
            $routes['AI setting ' . $name] = ['admin.third-party.ai-setting.' . $name, 'module:3rd_party_setup'];
        }
        foreach (['index', 'store', 'update', 'status', 'export', 'systemVatTax', 'systemTaxVatStore', 'systemTaxVatVendorStatus'] as $name) {
            $routes['VAT and tax ' . $name] = ['admin.vat-tax.' . $name, 'module:business_settings'];
        }
        foreach (['get-tax-report', 'getTaxDetails', 'getTaxDetailsExport', 'adminTaxReportExport', 'vendor-wise-taxes', 'vendorWiseTaxExport', 'vendorTax', 'vendorTaxExport'] as $name) {
            $routes['tax report ' . $name] = ['admin.report.' . $name, 'module:reports'];
        }

        return $routes;
    }

    #[DataProvider('gatedRoutes')]
    public function test_the_route_asks_for_the_permission_of_its_menu(string $routeName, string $middleware): void
    {
        $route = Route::getRoutes()->getByName($routeName);

        $this->assertInstanceOf(RoutingRoute::class, $route, $routeName . ' is not a route');
        $this->assertContains('admin', $route->middleware(), $routeName);
        $this->assertContains($middleware, $route->middleware(), $routeName);
    }

    public function test_no_route_of_the_two_modules_answers_without_a_module_permission(): void
    {
        $open = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $isTheirs = str_starts_with($route->getName() ?? '', 'admin.third-party.ai-setting.')
                || str_starts_with($route->getName() ?? '', 'admin.vat-tax.')
                || (str_starts_with($route->getName() ?? '', 'admin.report.') && preg_match('/tax/i', $route->getActionName()) === 1);
            $hasPermission = count(array_filter($route->middleware(), static fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'module:'))) > 0;
            if ($isTheirs && !$hasPermission) {
                $open[] = implode('|', $route->methods()) . ' ' . $route->uri();
            }
        }

        $this->assertSame([], $open);
    }

    /** @return array<string, array{0: string, 1: int, 2: bool}> route, the id of the employee (role 2 holds only the catalog, role 3 only the third-party setup, role 1 is the master admin), whether the request may reach the controller */
    public static function employees(): array
    {
        $routes = ['/admin/third-party/ai-setting' => 3, '/admin/vat-tax/list' => 0, '/admin/report/get-tax-report' => 0];
        $cases = [];
        foreach ($routes as $path => $roleWithThePermission) {
            $cases[$path . ' as the catalog employee'] = [$path, 2, false];
            $cases[$path . ' as the master admin'] = [$path, 1, true];
            if ($roleWithThePermission !== 0) {
                $cases[$path . ' as the employee who holds its permission'] = [$path, $roleWithThePermission, true];
            } else {
                $cases[$path . ' as the third-party employee'] = [$path, 3, false];
            }
        }

        return $cases;
    }

    #[DataProvider('employees')]
    public function test_an_employee_without_the_permission_is_sent_back_to_the_dashboard(string $path, int $adminId, bool $mayReachTheController): void
    {
        $response = $this->actingAs(Admin::query()->findOrFail($adminId), 'admin')->get($path);

        $sentBack = $response->isRedirection() && $response->headers->get('Location') === route('admin.dashboard.index');
        $this->assertSame(!$mayReachTheController, $sentBack, $path . ' answered ' . $response->getStatusCode());
    }
}
