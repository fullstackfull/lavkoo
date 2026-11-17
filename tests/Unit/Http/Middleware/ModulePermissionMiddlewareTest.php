<?php

namespace Tests\Unit\Http\Middleware;

use App\Enums\GlobalConstant;
use App\Http\Middleware\ModulePermissionMiddleware;
use App\Models\Admin;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
use Tests\Support\BuildsAdminEmployees;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * `module:a,b,c` passes an employee whose role holds at least one of the listed permissions. Each permission is decided by Helpers::module_permission_check
 * (the master admin passes every one, a role that is inactive, missing or without `module_access` holds none), so a list never decides differently from a
 * single permission. A single permission answers as it always did: the next handler for a holder, the dashboard and the access-denied toast for an
 * employee who does not hold it, the previous page for someone who is not signed in as an admin.
 *
 * The middleware is called directly, apart from one probe route that shows the router hands every name of a list to it. The routes that carry a list
 * are tested in AdminRouteGatesTest.
 */
class ModulePermissionMiddlewareTest extends TestCase
{
    use BuildsAdminEmployees;
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    private const PREVIOUS_PAGE = 'http://localhost/the-page-before';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** @return array<string, array{0: list<string>, 1: list<string>}> the permissions the role holds, the permissions the route lists */
    public static function holdersOfAListedPermission(): array
    {
        return [
            'the one permission listed' => [['catalog'], ['catalog']],
            'the first of several' => [['catalog'], ['catalog', 'orders', 'people']],
            'the middle one of several' => [['orders'], ['catalog', 'orders', 'people']],
            'the last of several' => [['people'], ['catalog', 'orders', 'people']],
            'every one of several' => [['catalog', 'orders', 'people'], ['catalog', 'orders', 'people']],
            'one of several, among permissions the route does not list' => [['reports', 'people', 'marketing'], ['catalog', 'orders', 'people']],
            'a name nobody can hold, then a permission the role holds' => [['catalog'], ['no_such_permission', 'catalog']],
            'a permission the role holds, then a name nobody can hold' => [['catalog'], ['catalog', 'no_such_permission']],
        ];
    }

    /** @return array<string, array{0: list<string>, 1: list<string>}> the permissions the role holds, the permissions the route lists */
    public static function employeesWhoHoldNoneOfThePermissionsListed(): array
    {
        $everyPermission = array_keys(GlobalConstant::EMPLOYEE_ROLE_MODULE_PERMISSION);

        return [
            'the one permission listed, which the role does not hold' => [['orders'], ['catalog']],
            'none of several' => [['marketing'], ['catalog', 'orders', 'people']],
            'a role that holds no module, one permission listed' => [[], ['catalog']],
            'a role that holds no module, several listed' => [[], ['catalog', 'orders', 'people']],
            'every permission the route does not list' => [array_values(array_diff($everyPermission, ['catalog', 'orders', 'people'])), ['catalog', 'orders', 'people']],
            'a name nobody can hold' => [['catalog'], ['no_such_permission']],
            'several names nobody can hold' => [['catalog', 'orders'], ['no_such_permission', 'another_no_such_permission']],
            'the empty name that `module:` leaves' => [['catalog'], ['']],
        ];
    }

    #[DataProvider('holdersOfAListedPermission')]
    public function test_an_employee_whose_role_holds_any_listed_permission_reaches_the_next_handler(array $held, array $listed): void
    {
        $this->assertReachedTheNextHandler(response: $this->askFor(permissions: $listed, employee: $this->employeeHolding(modules: $held)));
    }

    #[DataProvider('employeesWhoHoldNoneOfThePermissionsListed')]
    public function test_an_employee_whose_role_holds_none_of_the_listed_permissions_is_sent_to_the_dashboard(array $held, array $listed): void
    {
        $this->assertSentToTheDashboard(response: $this->askFor(permissions: $listed, employee: $this->employeeHolding(modules: $held)));
    }

    public function test_the_master_admin_reaches_the_next_handler_whatever_the_list_names(): void
    {
        foreach ([['catalog'], ['catalog', 'orders', 'people'], ['no_such_permission']] as $listed) {
            $this->assertReachedTheNextHandler(response: $this->askFor(permissions: $listed, employee: Admin::query()->findOrFail(1)));
        }
    }

    public function test_the_master_admin_passes_although_the_role_is_inactive_or_gone(): void
    {
        DB::table('admin_roles')->where('id', 1)->update(['status' => 0]);
        $this->assertReachedTheNextHandler(response: $this->askFor(permissions: ['catalog', 'orders'], employee: Admin::query()->findOrFail(1)));

        DB::table('admin_roles')->where('id', 1)->delete();
        $this->assertReachedTheNextHandler(response: $this->askFor(permissions: ['catalog', 'orders'], employee: Admin::query()->findOrFail(1)));
    }

    public function test_a_role_without_module_access_holds_no_permission(): void
    {
        $this->assertSentToTheDashboard(response: $this->askFor(permissions: ['catalog', 'orders'], employee: $this->employeeHolding(modules: null)));
    }

    public function test_an_inactive_role_holds_no_permission_although_its_module_access_lists_them(): void
    {
        $inactive = $this->employeeHolding(modules: ['catalog', 'orders'], roleIsActive: false);

        $this->assertSentToTheDashboard(response: $this->askFor(permissions: ['catalog'], employee: $inactive));
        $this->assertSentToTheDashboard(response: $this->askFor(permissions: ['people', 'orders'], employee: $inactive));
    }

    public function test_an_employee_whose_role_is_gone_holds_no_permission(): void
    {
        $this->assertSentToTheDashboard(response: $this->askFor(permissions: ['catalog', 'orders'], employee: $this->employeeOfRole(roleId: 999)));
    }

    public function test_a_route_that_lists_no_permission_refuses_everyone_the_master_admin_included(): void
    {
        $holdsEverything = $this->employeeHolding(modules: array_keys(GlobalConstant::EMPLOYEE_ROLE_MODULE_PERMISSION));

        $this->assertSentToTheDashboard(response: $this->askFor(permissions: [], employee: $holdsEverything));
        $this->assertSentToTheDashboard(response: $this->askFor(permissions: [], employee: Admin::query()->findOrFail(1)));
    }

    public function test_someone_who_is_not_signed_in_as_an_admin_is_sent_back_to_the_page_before(): void
    {
        $this->assertSentBack(response: $this->askFor(permissions: ['catalog'], employee: null));
        $this->assertSentBack(response: $this->askFor(permissions: ['catalog', 'orders', 'people'], employee: null));
    }

    public function test_someone_signed_in_on_another_guard_is_sent_back_the_same_way(): void
    {
        $this->actingAs(new GenericUser(['id' => 1]), 'web');

        $this->assertSentBack(response: $this->askFor(permissions: ['catalog', 'orders'], employee: null));
    }

    public function test_the_router_hands_every_name_of_the_list_to_the_middleware(): void
    {
        Route::get('zz-probe-any-of', static fn (): string => 'reached')->middleware('module:catalog,orders,people');

        foreach (['catalog', 'orders', 'people'] as $held) {
            $this->actingAs($this->employeeHolding(modules: [$held]), 'admin')->get('zz-probe-any-of')->assertOk()->assertSee('reached');
        }
        $this->actingAs($this->employeeHolding(modules: ['marketing']), 'admin')->get('zz-probe-any-of')->assertRedirect(route('admin.dashboard.index'));
    }

    /** @param list<string> $permissions what `module:` lists on the route */
    private function askFor(array $permissions, ?Admin $employee): BaseResponse
    {
        ToastMagic::clear();
        if ($employee !== null) {
            $this->actingAs($employee, 'admin');
        }
        $request = Request::create(uri: '/admin/anywhere', method: 'GET', server: ['HTTP_REFERER' => self::PREVIOUS_PAGE]);
        $this->app->instance('request', $request);

        return $this->app->make(ModulePermissionMiddleware::class)->handle($request, static fn (): Response => new Response('reached'), ...$permissions);
    }

    private function assertReachedTheNextHandler(BaseResponse $response): void
    {
        $this->assertSame('reached', $response->getContent(), 'answered ' . $response->getStatusCode() . ' instead of the next handler');
        $this->assertSame([], $this->flashedToasts());
    }

    private function assertSentToTheDashboard(BaseResponse $response): void
    {
        $this->assertSentTo(url: route('admin.dashboard.index'), response: $response);
    }

    private function assertSentBack(BaseResponse $response): void
    {
        $this->assertSentTo(url: self::PREVIOUS_PAGE, response: $response);
    }

    private function assertSentTo(string $url, BaseResponse $response): void
    {
        $this->assertInstanceOf(RedirectResponse::class, $response, 'reached the next handler');
        $this->assertSame($url, $response->getTargetUrl());
        $this->assertSame(['error: ' . translate('access_Denied') . '!'], $this->flashedToasts());
    }

    /** @return list<string> the toasts the middleware flashed, as "type: message" */
    private function flashedToasts(): array
    {
        return array_map(static fn (array $toast): string => $toast['type'] . ': ' . $toast['message'], array_values((array) session('laravel-toaster-magic::messages', [])));
    }
}
