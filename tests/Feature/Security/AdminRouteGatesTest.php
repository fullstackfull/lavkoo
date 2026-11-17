<?php

namespace Tests\Feature\Security;

use App\Enums\GlobalConstant;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Admin;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\ReadsRouteMiddleware;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * A route of the admin panel without the `module:` middleware answers every signed-in employee, whatever their role holds, and an employee whose role
 * holds no module at all can use it. Each route listed here belongs to one menu entry of the panel and asks for the permission of that entry, as the
 * other routes of its screen do. The master admin (the first admin role) passes every permission check.
 *
 * The routes and their middleware are the real ones. Only the action is replaced by a stub that answers "reached", because the controllers need
 * tables that these tests do not build.
 */
class AdminRouteGatesTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use ReadsRouteMiddleware;
    use RestoresLanguageFiles;

    /** The routes that carry a permission, as "METHOD /path" => the permission of the menu entry that owns them. */
    private const GATES = [
        'GET /admin/messages/index/customer' => 'people',
        'GET /admin/messages/message' => 'people',
        'POST /admin/messages/message' => 'people',
    ];

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

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function gatedRoutes(): array
    {
        $cases = [];
        foreach (self::GATES as $route => $permission) {
            [$method, $uri] = explode(' ', $route);
            $cases[$route] = [$method, $uri, $permission];
        }

        return $cases;
    }

    #[DataProvider('gatedRoutes')]
    public function test_the_route_asks_for_the_permission_of_its_menu(string $method, string $uri, string $permission): void
    {
        $this->assertSame([$permission], $this->modulePermissionsOf(route: $this->routeFor(method: $method, uri: $uri)));
    }

    #[DataProvider('gatedRoutes')]
    public function test_an_employee_who_does_not_hold_the_permission_is_sent_back_to_the_dashboard(string $method, string $uri, string $permission): void
    {
        $this->replaceTheActionWithAStub(method: $method, uri: $uri);
        $everyOtherPermission = array_values(array_diff(array_keys(GlobalConstant::EMPLOYEE_ROLE_MODULE_PERMISSION), [$permission]));

        $this->assertSentBackToTheDashboard(
            response: $this->requestAs(employee: $this->employeeHolding(modules: []), method: $method, uri: $uri),
            who: 'an employee whose role holds no module',
        );
        $this->assertSentBackToTheDashboard(
            response: $this->requestAs(employee: $this->employeeHolding(modules: $everyOtherPermission), method: $method, uri: $uri),
            who: 'an employee whose role holds every other permission',
        );
    }

    #[DataProvider('gatedRoutes')]
    public function test_the_holder_of_the_permission_and_the_master_admin_reach_the_route(string $method, string $uri, string $permission): void
    {
        $this->replaceTheActionWithAStub(method: $method, uri: $uri);

        $this->assertReachedTheAction(
            response: $this->requestAs(employee: $this->employeeHolding(modules: [$permission]), method: $method, uri: $uri),
            who: 'an employee whose role holds only ' . $permission,
        );
        $this->assertReachedTheAction(
            response: $this->requestAs(employee: Admin::query()->findOrFail(1), method: $method, uri: $uri),
            who: 'the master admin',
        );
    }

    private function replaceTheActionWithAStub(string $method, string $uri): void
    {
        $route = $this->routeFor(method: $method, uri: $uri);
        $action = $route->getAction();
        $action['uses'] = static fn (): string => 'reached';
        unset($action['controller']);
        $route->setAction($action);
    }

    /** @param list<string> $modules */
    private function employeeHolding(array $modules): Admin
    {
        $roleId = DB::table('admin_roles')->insertGetId(['name' => 'Role of ' . count($modules) . ' modules', 'module_access' => json_encode($modules), 'status' => 1]);
        $adminId = DB::table('admins')->insertGetId(['name' => 'Employee', 'email' => 'employee' . $roleId . '@example.test', 'admin_role_id' => $roleId, 'status' => 1]);

        return Admin::query()->findOrFail($adminId);
    }

    private function requestAs(Admin $employee, string $method, string $uri): TestResponse
    {
        return $this->actingAs($employee, 'admin')->json($method, $uri);
    }

    private function assertSentBackToTheDashboard(TestResponse $response, string $who): void
    {
        $sentBack = $response->isRedirection() && $response->headers->get('Location') === route('admin.dashboard.index');

        $this->assertTrue($sentBack, $who . ' answered ' . $response->getStatusCode() . ', not the redirect to the dashboard');
    }

    private function assertReachedTheAction(TestResponse $response, string $who): void
    {
        $this->assertSame('reached', $response->getContent(), $who . ' answered ' . $response->getStatusCode());
    }
}
