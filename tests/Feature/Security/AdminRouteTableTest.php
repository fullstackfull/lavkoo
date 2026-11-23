<?php

namespace Tests\Feature\Security;

use App\Enums\GlobalConstant;
use App\Http\Middleware\EnsureStorefrontPermission;
use App\Services\Storefront\StorefrontPermissionService;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Support\ReadsRouteMiddleware;
use Tests\TestCase;

/**
 * A route of the admin panel without a `module:` permission answers every signed-in employee, whatever their role holds. This test walks the route
 * table and fails for a new one: a route of the admin group either asks for the permission of its menu or is listed here, with the reason why it
 * is open to every employee. A reason that stops being true is a reason to gate the route.
 *
 * A route that the screens of several permissions call is gated with a list: `module:a,b` passes an employee who holds `a` or `b`. Every name of a list
 * has to be a permission the role form offers.
 */
class AdminRouteTableTest extends TestCase
{
    use ReadsRouteMiddleware;

    private const STATIC_GALLERY = 'A static gallery of the interface components: no data.';

    private const OWN_SIDEBAR_PINS = 'The menu shortcuts the signed-in employee pinned: it reads and writes their own list only.';

    private const OWN_PROFILE = 'The profile of the signed-in employee needs no module. Which admin the id in the path names is the question of draft #57, which makes the handlers act on the signed-in employee.';

    private const AUCTION_ADDON = 'No admin page of this repository calls it: it serves the admin pages of the Auction add-on, which are not part of this repository. The header search notes that the auction routes carry no `module:auction`, and `auction` is not a permission the role form offers, so there is no menu entry to take a permission from.';

    private const ADDON_SCREENS = 'Draft #63 asks for `themes_and_addons`, the permission of the System Addons entry of the sidebar.';

    /** The routes that ask for no permission on purpose, as "METHOD path" => why. */
    private const OPEN_ON_PURPOSE = [
        'GET admin/dashboard' => 'The landing page: every refusal of the role middleware redirects to it, so a permission on it would send an employee round in a circle. The view shows its numbers only to the master admin and to a role that holds `dashboard`.',
        'GET admin/dashboard/real-time-activities' => 'The poller in the layout of every admin page. It answers every signed-in employee and reports only the sections whose permission their role holds: new orders (orders), restock requests (catalog) and unread chat messages (people).',
        'GET admin/logout' => 'Every signed-in employee must be able to sign out.',
        'GET admin/settings' => 'The searchable index of the settings pages. It lists only the groups whose module the employee holds (SettingsIndexService) and holds no data of its own.',
        'GET admin/design-system' => self::STATIC_GALLERY,
        'GET admin/component' => self::STATIC_GALLERY,
        'GET admin/component-snippets' => self::STATIC_GALLERY,
        'GET admin/v2/sidebar-pins' => self::OWN_SIDEBAR_PINS,
        'POST admin/v2/sidebar-pins/toggle' => self::OWN_SIDEBAR_PINS,
        'POST admin/v2/sidebar-pins/replace' => self::OWN_SIDEBAR_PINS,
        'GET admin/v2/advanced-search' => 'The search box in the header of every page. It drops the results of the modules the employee does not hold (V2AdvancedSearchController).',
        'GET admin/advanced-search' => 'The search box in the header of the classic layout, on every page, so no one permission fits. Like the v2 search it drops the hits of the modules the employee does not hold, the customers, vendors, orders, contacts and coupons it finds included (AdvancedSearchPermissionFilter).',
        'POST admin/advanced-search-recent' => 'Records the recent search of the signed-in employee and redirects to it: their own list only.',
        'GET admin/profile/update/{id}' => self::OWN_PROFILE,
        'POST admin/profile/update/{id}' => self::OWN_PROFILE,
        'PATCH admin/profile/update/{id}' => self::OWN_PROFILE,
        'GET admin/auction/product/title-auto-fill' => self::AUCTION_ADDON,
        'GET admin/auction/product/description-auto-fill' => self::AUCTION_ADDON,
        'GET admin/auction/product/general-setup-auto-fill' => self::AUCTION_ADDON,
        'GET admin/auction/product/shipping-policy-auto-fill' => self::AUCTION_ADDON,
        'GET admin/auction/product/auction-info-auto-fill' => self::AUCTION_ADDON,
        'GET admin/auction/product/seo-section-auto-fill' => self::AUCTION_ADDON,
        'POST admin/auction/product/analyze-image-auto-fill' => self::AUCTION_ADDON,
        'POST admin/auction/product/generate-title-suggestions' => self::AUCTION_ADDON,
    ];

    /** Open on this branch and gated by a draft pull request that has not landed: the entry may go when that one does. */
    private const GATED_BY_OPEN_PULL_REQUESTS = [
        'GET admin/addon' => self::ADDON_SCREENS,
        'POST admin/addon/publish' => self::ADDON_SCREENS,
        'POST admin/addon/activation' => self::ADDON_SCREENS,
        'POST admin/addon/upload' => self::ADDON_SCREENS,
        'POST admin/addon/delete' => self::ADDON_SCREENS,
    ];

    public function test_the_walk_sees_a_new_route_without_a_permission_and_one_that_opts_out_of_its_permission(): void
    {
        Route::get('admin/zz-walk-probe-open', static fn () => 'x')->middleware(['admin']);
        Route::get('admin/zz-walk-probe-gated', static fn () => 'x')->middleware(['admin', 'module:catalog']);
        Route::get('admin/zz-walk-probe-opted-out', static fn () => 'x')->middleware(['admin', 'module:catalog'])->withoutMiddleware('module:catalog');
        Route::get('admin/zz-walk-probe-gated-by-a-list', static fn () => 'x')->middleware(['admin', 'module:catalog,people']);
        Route::get('admin/zz-walk-probe-gated-by-a-storefront-permission', static fn () => 'x')
            ->middleware(['admin', EnsureStorefrontPermission::for(StorefrontPermissionService::LIFECYCLE)]);

        $open = array_keys($this->adminRoutesThatAskForNoPermission());

        $this->assertContains('GET admin/zz-walk-probe-open', $open);
        $this->assertContains('GET admin/zz-walk-probe-opted-out', $open);
        $this->assertNotContains('GET admin/zz-walk-probe-gated', $open);
        $this->assertNotContains('GET admin/zz-walk-probe-gated-by-a-list', $open);
        $this->assertNotContains('GET admin/zz-walk-probe-gated-by-a-storefront-permission', $open);
    }

    public function test_every_admin_route_asks_for_a_permission_or_is_listed_as_open(): void
    {
        $unlisted = array_diff(
            array_keys($this->adminRoutesThatAskForNoPermission()),
            array_keys(self::OPEN_ON_PURPOSE),
            array_keys(self::GATED_BY_OPEN_PULL_REQUESTS),
        );

        $this->assertSame([], array_values($unlisted), "Admin routes that ask for no `module:` permission and are not listed as open to every employee:\n" . implode("\n", $unlisted));
    }

    public function test_every_route_listed_as_open_is_an_admin_route_that_still_asks_for_no_permission(): void
    {
        $gone = array_diff(array_keys(self::OPEN_ON_PURPOSE), array_keys($this->adminRoutesThatAskForNoPermission()));

        $this->assertSame([], array_values($gone), "Listed as open, but no longer an admin route without a permission: take it off the list.\n" . implode("\n", $gone));
    }

    public function test_every_route_listed_as_gated_by_a_pull_request_exists(): void
    {
        $gone = array_diff(array_keys(self::GATED_BY_OPEN_PULL_REQUESTS), array_keys($this->adminRoutes()));

        $this->assertSame([], array_values($gone), "Listed as gated by a pull request, but not an admin route: take it off the list.\n" . implode("\n", $gone));
    }

    public function test_every_listed_route_has_its_reason(): void
    {
        foreach ([...self::OPEN_ON_PURPOSE, ...self::GATED_BY_OPEN_PULL_REQUESTS] as $route => $reason) {
            $this->assertNotSame('', trim($reason), $route . ' is listed without a reason');
        }
    }

    public function test_every_permission_a_route_asks_for_is_one_the_role_form_offers(): void
    {
        $unknown = $this->permissionsTheRoleFormDoesNotOffer();

        $this->assertSame([], $unknown, "A role cannot hold a permission its form does not offer, so only the master admin passes these routes:\n" . implode("\n", $unknown));
    }

    public function test_the_check_of_the_permission_names_reads_every_name_of_a_list(): void
    {
        Route::get('admin/zz-list-probe-offered', static fn () => 'x')->middleware(['admin', 'module:catalog,people']);
        Route::get('admin/zz-list-probe-unknown', static fn () => 'x')->middleware(['admin', 'module:catalog,zz_not_offered']);

        $probes = array_filter($this->permissionsTheRoleFormDoesNotOffer(), static fn (string $unknown): bool => str_contains($unknown, 'zz-list-probe'));

        $this->assertSame(['GET admin/zz-list-probe-unknown asks for `zz_not_offered`'], array_values($probes));
    }

    public function test_every_storefront_permission_a_route_asks_for_is_one_the_role_form_offers(): void
    {
        $offered = array_keys(StorefrontPermissionService::all());

        $unknown = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach (array_diff($this->storefrontPermissionsOf(route: $route), $offered) as $permission) {
                $unknown[] = $this->keyOf(route: $route) . ' asks for `' . $permission . '`';
            }
        }

        $this->assertSame([], $unknown, "A role cannot hold a storefront permission its form does not offer, so only the master admin passes these routes:\n" . implode("\n", $unknown));
    }

    public function test_the_check_of_the_storefront_permission_names_reads_every_name_of_a_list(): void
    {
        Route::get('admin/zz-storefront-probe-offered', static fn () => 'x')
            ->middleware(['admin', EnsureStorefrontPermission::for(StorefrontPermissionService::LEGAL_WRITE, StorefrontPermissionService::LEGAL_APPROVE)]);
        Route::get('admin/zz-storefront-probe-unknown', static fn () => 'x')
            ->middleware(['admin', EnsureStorefrontPermission::for(StorefrontPermissionService::LEGAL_WRITE, 'zz_not_offered')]);

        $offered = array_keys(StorefrontPermissionService::all());

        $probes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach (array_diff($this->storefrontPermissionsOf(route: $route), $offered) as $permission) {
                $probes[] = $this->keyOf(route: $route) . ' asks for `' . $permission . '`';
            }
        }

        $this->assertSame(['GET admin/zz-storefront-probe-unknown asks for `zz_not_offered`'], array_values($probes));
    }
    /** @return list<string> "METHOD path asks for `name`" for every name of a `module:` middleware that the role form does not offer */
    private function permissionsTheRoleFormDoesNotOffer(): array
    {
        $offered = array_keys(GlobalConstant::EMPLOYEE_ROLE_MODULE_PERMISSION);

        $unknown = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach (array_diff($this->modulePermissionsOf(route: $route), $offered) as $permission) {
                $unknown[] = $this->keyOf(route: $route) . ' asks for `' . $permission . '`';
            }
        }

        return $unknown;
    }

    /** @return array<string, RoutingRoute> the routes of the admin group by "METHOD path" */
    private function adminRoutes(): array
    {
        $routes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (in_array('admin', $this->declaredMiddleware(route: $route), true)) {
                $routes[$this->keyOf(route: $route)] = $route;
            }
        }

        return $routes;
    }

    /** @return array<string, RoutingRoute> */
    private function adminRoutesThatAskForNoPermission(): array
    {
        return array_filter(
            $this->adminRoutes(),
            fn (RoutingRoute $route): bool => $this->modulePermissionsOf(route: $route) === []
                && $this->storefrontPermissionsOf(route: $route) === [],
        );
    }

    private function keyOf(RoutingRoute $route): string
    {
        return implode('|', array_diff($route->methods(), ['HEAD'])) . ' ' . $route->uri();
    }
}
