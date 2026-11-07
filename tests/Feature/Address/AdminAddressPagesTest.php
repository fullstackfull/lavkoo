<?php

namespace Tests\Feature\Address;

use App\Http\Controllers\Admin\Address\AddressCityController;
use App\Http\Controllers\Admin\Address\AddressImportController;
use App\Http\Controllers\Admin\Address\AddressOverviewController;
use App\Http\Controllers\Admin\Address\AddressRegionController;
use App\Http\Controllers\Admin\Address\LegacyAddressController;
use App\Http\Middleware\EnsureAddressMigrated;
use App\Services\Address\AddressCatalogEditor;
use App\Services\Address\AddressSchema;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/** The address administration pages: who can open them, how they are guarded, and that there is no way to delete a place. */
class AdminAddressPagesTest extends AdminAddressTestCase
{
    /** @return array<string, array{0: string}> */
    public static function pages(): array
    {
        return [
            'overview' => ['admin.address-system.index'],
            'regions' => ['admin.address-system.regions.index'],
            'cities' => ['admin.address-system.cities.index'],
            'add a city' => ['admin.address-system.cities.create'],
            'old addresses' => ['admin.address-system.legacy.index'],
            'import' => ['admin.address-system.import.index'],
        ];
    }

    /** @return list<RoutingRoute> */
    private function addressRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'admin/address-system'),
        ));
    }

    private function urlOf(RoutingRoute $route): string
    {
        return '/' . preg_replace('/\{[^}]+\}/', '1', $route->uri());
    }

    #[DataProvider('pages')]
    public function test_every_page_renders_for_an_administrator_and_links_the_others(string $routeName): void
    {
        $this->signInWithTheCatalog();

        $html = $this->get(route($routeName))->assertOk()->getContent();

        foreach (['index', 'regions.index', 'cities.index', 'legacy.index', 'import.index'] as $other) {
            $this->assertStringContainsString(route("admin.address-system.{$other}"), $html, "{$routeName} links {$other}");
        }
    }

    public function test_the_city_edit_page_renders_with_the_city_it_is_about(): void
    {
        $this->signInWithTheCatalog();

        $html = $this->get(route('admin.address-system.cities.edit', $this->ids['jeddah']))->assertOk()->getContent();

        $this->assertStringContainsString('جدة', $html);
        $this->assertStringContainsString('Jeddah', $html);
        $this->assertStringContainsString(route('admin.address-system.cities.update', $this->ids['jeddah']), $html);
    }

    public function test_an_unknown_city_has_no_edit_page(): void
    {
        $this->signInWithTheCatalog();

        $this->get(route('admin.address-system.cities.edit', 987654))->assertNotFound();
    }

    public function test_a_guest_is_refused_everywhere_and_nothing_changes(): void
    {
        $this->seedAddressCatalog();
        $routes = $this->addressRoutes();
        $this->assertGreaterThanOrEqual(15, count($routes));

        foreach ($routes as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->call($method, $this->urlOf($route), ['mode' => 'soft', 'active' => 0, 'name_ar' => 'x', 'name_en' => 'y'])->assertNotFound();
            }
        }

        $this->assertSame(AddressSchema::MODE_OFF, AddressSchema::configuredMode());
        $this->assertSame([], $this->auditActions());
    }

    public function test_an_administrator_without_the_business_settings_module_is_turned_away_and_one_with_it_gets_in(): void
    {
        $this->seedAddressCatalog();

        foreach ([2, 3] as $adminId) {
            $this->signIn($adminId);
            $this->get(route('admin.address-system.index'))->assertRedirect(route('admin.dashboard.index'));
            $this->post(route('admin.address-system.mode.update'), ['mode' => 'soft'])->assertRedirect(route('admin.dashboard.index'));
        }
        $this->assertSame(AddressSchema::MODE_OFF, AddressSchema::configuredMode(), 'a role without the module cannot change the mode');

        $this->signIn(self::BUSINESS_SETTINGS_ADMIN);
        $this->get(route('admin.address-system.index'))->assertOk();
        $this->post(route('admin.address-system.mode.update'), ['mode' => 'soft'])->assertRedirect(route('admin.address-system.index'));
        $this->assertSame(AddressSchema::MODE_SOFT, AddressSchema::configuredMode());
    }

    public function test_every_route_has_the_guard_chain_and_every_post_is_throttled(): void
    {
        foreach ($this->addressRoutes() as $route) {
            $declared = array_map(static fn ($item): string => is_string($item) ? $item : 'closure', (array) $route->middleware());
            $middleware = array_values(array_diff($declared, array_map('strval', $route->excludedMiddleware())));
            $label = implode('|', $route->methods()) . ' /' . $route->uri();

            foreach (['admin', 'actch:admin_panel', 'module:business_settings', EnsureAddressMigrated::class] as $required) {
                $this->assertContains($required, $middleware, "{$label} lacks {$required}");
            }
            if (in_array('POST', $route->methods(), true)) {
                $this->assertNotEmpty(array_filter($middleware, static fn (string $name): bool => str_starts_with($name, 'throttle:')), "{$label} is not throttled");
            }
        }
    }

    public function test_the_routes_are_named_and_mapped_after_the_fulfillment_ones(): void
    {
        $names = array_map(static fn ($route): ?string => $route->getName(), Route::getRoutes()->getRoutes());
        $firstAddress = min(array_keys(array_filter($names, static fn (?string $name): bool => str_starts_with((string) $name, 'admin.address-system.'))));
        $lastFulfillment = max(array_keys(array_filter($names, static fn (?string $name): bool => str_starts_with((string) $name, 'admin.fulfillment-providers.'))));

        $this->assertGreaterThan($lastFulfillment, $firstAddress);
    }

    public function test_there_is_no_way_to_delete_a_region_or_a_city(): void
    {
        foreach ($this->addressRoutes() as $route) {
            $this->assertNotContains('DELETE', $route->methods(), $route->uri());
            $this->assertNotContains('PUT', $route->methods(), $route->uri());
            $this->assertDoesNotMatchRegularExpression('/delete|destroy|remove/i', $route->uri() . ' ' . $route->getName(), $route->uri());
        }

        foreach ([AddressOverviewController::class, AddressRegionController::class, AddressCityController::class, LegacyAddressController::class, AddressImportController::class, AddressCatalogEditor::class] as $class) {
            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                $this->assertDoesNotMatchRegularExpression('/delete|destroy|remove|forget/i', $method->getName(), $class . '::' . $method->getName());
            }
        }
    }

    public function test_the_sidebar_entry_sits_in_the_business_settings_group_and_is_guarded_by_the_route(): void
    {
        $sidebar = (string) file_get_contents(base_path('resources/views/layouts/admin/partials/v2/_side-bar.blade.php'));

        $group = strpos($sidebar, "module_permission_check('business_settings')");
        $entry = strpos($sidebar, "Route::has('admin.address-system.index')");

        $this->assertNotFalse($group);
        $this->assertNotFalse($entry);
        $this->assertGreaterThan($group, $entry);
        $this->assertStringContainsString('data-item="address-system"', $sidebar);
        $this->assertStringContainsString("route('admin.address-system.index')", $sidebar);
    }
}
