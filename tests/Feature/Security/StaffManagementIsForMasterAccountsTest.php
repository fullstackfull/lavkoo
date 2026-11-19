<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Admin\Employee\CustomRoleController;
use App\Http\Controllers\Admin\Employee\EmployeeController;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Admin;
use Illuminate\Routing\Route as PanelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\TestCase;

/**
 * The employees and the roles of the panel are managed by master accounts, as the menus of the panel say.
 *
 * Both menus show "employees" and "roles" only to an account that holds the master role. The routes asked for the people
 * permission instead, which the customer, vendor and support screens are filed under as well. An employee holding that
 * permission was offered none of the staff screens and was served every one of them at its address: any role could be given
 * any permission, the asker's own role included, and with it the add-on screens, which install code on the server.
 *
 * Every route of the two controllers is read from the router and asked for as three people. The controllers are stood in for
 * by one that only says it was reached, so that nothing here creates, rewrites or deletes an account or a role.
 */
class StaffManagementIsForMasterAccountsTest extends TestCase
{
    use BuildsFulfillmentSchema;

    private const FIRST_ADMIN = 1;

    private const MASTER_ROLE = 1;

    private const ANOTHER_MASTER = 7;

    private const PEOPLE_EMPLOYEE = 9;

    private const STAFF_CONTROLLERS = [EmployeeController::class, CustomRoleController::class];

    private const REACHED = 'a staff management controller was reached';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCoreSchema();
        DB::table('admin_roles')->insert(['id' => 9, 'name' => 'Everything but the master role', 'module_access' => json_encode($this->everyPermissionTheRoutesAskFor()), 'status' => 1]);
        DB::table('admins')->insert([
            ['id' => self::PEOPLE_EMPLOYEE, 'name' => 'People employee', 'email' => 'people@example.test', 'admin_role_id' => 9],
            ['id' => self::ANOTHER_MASTER, 'name' => 'Another master', 'email' => 'another-master@example.test', 'admin_role_id' => self::MASTER_ROLE],
        ]);
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class, ActivationCheckMiddleware::class,
        ]);
        Http::preventStrayRequests();
        $this->standInForTheStaffControllers();
    }

    public function test_the_scan_sees_the_staff_screens(): void
    {
        $addresses = array_column($this->everyStaffRequest(), 1);

        foreach (['/admin/employee/list', '/admin/employee/add', '/admin/employee/update/1', '/admin/employee/status', '/admin/custom-role/add', '/admin/custom-role/update/1', '/admin/custom-role/delete'] as $known) {
            $this->assertContains($known, $addresses);
        }
    }

    /** Holding every permission a route of the panel asks for, the people permission among them, is not holding the master role. */
    public function test_an_employee_reaches_no_staff_screen_whatever_permissions_the_role_holds(): void
    {
        $this->actingAs(Admin::findOrFail(self::PEOPLE_EMPLOYEE), 'admin');

        foreach ($this->everyStaffRequest() as [$method, $address]) {
            $response = $this->call($method, $address);

            $this->assertNotSame(self::REACHED, $response->getContent(), "{$method} {$address} reached its controller");
            $this->assertTrue($response->isRedirect(route('admin.dashboard.index')), "{$method} {$address} answered {$response->getStatusCode()}");
        }
    }

    public function test_a_request_made_by_script_is_refused_with_an_answer_a_script_can_read(): void
    {
        $this->actingAs(Admin::findOrFail(self::PEOPLE_EMPLOYEE), 'admin')
            ->post('/admin/employee/status', ['id' => 2, 'status' => 0], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertForbidden()
            ->assertJsonPath('status', 'error');
    }

    /** @return array<string, array{int}> */
    public static function aMasterAccount(): array
    {
        return ['the first admin' => [self::FIRST_ADMIN], 'another account holding the master role' => [self::ANOTHER_MASTER]];
    }

    #[DataProvider('aMasterAccount')]
    public function test_a_master_account_reaches_every_staff_screen(int $account): void
    {
        $this->actingAs(Admin::findOrFail($account), 'admin');

        foreach ($this->everyStaffRequest() as [$method, $address]) {
            $response = $this->call($method, $address);

            $this->assertSame(self::REACHED, $response->getContent(), "{$method} {$address} was refused to a master account");
        }
    }

    /** @return list<array{string, string}> the method and the address, with a value in the place of every parameter */
    private function everyStaffRequest(): array
    {
        $requests = [];
        foreach ($this->staffRoutes() as $route) {
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $requests[] = [$method, '/' . preg_replace('/\{[^}]+\}/', '1', $route->uri())];
            }
        }

        return $requests;
    }

    /** @return list<PanelRoute> */
    private function staffRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (PanelRoute $route): bool => in_array(strstr($route->getActionName(), '@', true), self::STAFF_CONTROLLERS, true),
        ));
    }

    /** @return list<string> */
    private function everyPermissionTheRoutesAskFor(): array
    {
        $asked = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($route->middleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'module:')) {
                    $asked[] = substr($middleware, strlen('module:'));
                }
            }
        }

        return array_values(array_unique($asked));
    }

    private function standInForTheStaffControllers(): void
    {
        $standIn = new class(self::REACHED) {
            public function __construct(private readonly string $answer)
            {
            }

            /** @return list<never> */
            public function getMiddleware(): array
            {
                return [];
            }

            /** @param array<string, mixed> $parameters */
            public function callAction(string $method, array $parameters): string
            {
                return $this->answer;
            }
        };
        foreach (self::STAFF_CONTROLLERS as $controller) {
            $this->app->instance($controller, $standIn);
        }
    }
}
