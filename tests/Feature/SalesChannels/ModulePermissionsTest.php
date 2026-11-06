<?php

namespace Tests\Feature\SalesChannels;

use App\Http\Middleware\EnsureSalesChannelsMigrated;
use App\Http\Middleware\ModulePermissionMiddleware;
use App\Models\Admin;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/** The two modules are separate permissions: every screen sits behind its own, and holding one does not open the other. */
class ModulePermissionsTest extends AdminPagesTestCase
{
    protected function middlewareToDisable(): array
    {
        return array_values(array_filter(parent::middlewareToDisable(), static fn (string $class): bool => $class !== ModulePermissionMiddleware::class));
    }

    /** @return list<LaravelRoute> */
    private function routesNamed(string $prefix): array
    {
        return array_values(array_filter(Route::getRoutes()->getRoutes(), static fn (LaravelRoute $route): bool => str_starts_with((string) $route->getName(), $prefix)));
    }

    public function test_every_route_of_each_module_sits_behind_that_modules_permission_and_the_migration_guard(): void
    {
        foreach (['admin.sales-channels.' => 'sales_channels', 'admin.operational-costs.' => 'operational_costs'] as $prefix => $module) {
            $routes = $this->routesNamed($prefix);
            $this->assertNotEmpty($routes, "No route is named {$prefix}*.");
            foreach ($routes as $route) {
                $middleware = $route->gatherMiddleware();
                $this->assertContains("module:{$module}", $middleware, $route->getName() . ' is not behind the ' . $module . ' permission.');
                $this->assertContains('admin', $middleware, $route->getName() . ' does not require an admin.');
                $this->assertContains(EnsureSalesChannelsMigrated::class, $middleware, $route->getName() . ' has no migration guard.');
            }
        }
    }

    public function test_every_post_route_is_state_changing_and_none_is_reachable_by_get(): void
    {
        foreach (array_merge($this->routesNamed('admin.sales-channels.'), $this->routesNamed('admin.operational-costs.')) as $route) {
            $isRead = in_array('GET', $route->methods(), true);
            $isWrite = array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods()) !== [];
            $this->assertFalse($isRead && $isWrite, $route->getName() . ' answers both reads and writes.');
        }
    }

    public function test_a_role_with_only_sales_channels_cannot_open_the_cost_screens_and_the_reverse(): void
    {
        $this->actingAs(Admin::findOrFail(4), 'admin');
        $this->get(route('admin.sales-channels.index'))->assertOk();
        $this->get(route('admin.operational-costs.index'))->assertRedirect(route('admin.dashboard.index'));

        $this->actingAs(Admin::findOrFail(5), 'admin');
        $this->get(route('admin.operational-costs.index'))->assertOk();
        $this->get(route('admin.sales-channels.index'))->assertRedirect(route('admin.dashboard.index'));
    }

    public function test_the_master_admin_holds_both(): void
    {
        $this->get(route('admin.sales-channels.index'))->assertOk();
        $this->get(route('admin.operational-costs.index'))->assertOk();
    }
}
