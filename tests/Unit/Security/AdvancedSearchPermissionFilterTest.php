<?php

namespace Tests\Unit\Security;

use App\Enums\GlobalConstant;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Services\AdvancedSearchPermissionFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\TestCase;

/**
 * What the header search keeps of its hits for the signed-in employee, without the routes, the database or the view. A hit is dropped when the role does not
 * hold the permission of its group (the type the search gives it) or, for a type the filter does not know, of the page its address names in the sidebar. The
 * master admin (the first admin role) passes every permission check.
 */
class AdvancedSearchPermissionFilterTest extends TestCase
{
    /** The groups the search comes back in, each with the permission whose holders may see it and the address of a page of it. */
    private const GROUPS = [
        'products' => ['catalog', 'admin/products/list/in-house'],
        'categories' => ['catalog', 'admin/category/view'],
        'brands' => ['catalog', 'admin/brand/list'],
        'orders' => ['orders', 'admin/orders/list/all'],
        'refund_requests' => ['orders', 'admin/refund-section/refund/list/pending'],
        'users' => ['people', 'admin/customer/list'],
        'sellers' => ['people', 'admin/vendors/list'],
        'delivery_men' => ['people', 'admin/delivery-man/list'],
        'subscriptions' => ['people', 'admin/customer/subscriber-list'],
        'coupons' => ['marketing', 'admin/coupon/add'],
        'blogs' => ['marketing', 'admin/blog/view'],
        'business_pages' => ['business_settings', 'admin/pages-and-media/list'],
        'contacts' => ['help_and_support', 'admin/contact/list'],
    ];

    /** @return array<string, array{0: string, 1: string, 2: string}> the group, the permission of it, the address a hit of it carries */
    public static function hitsOfEachGroup(): array
    {
        $cases = [];
        foreach (self::GROUPS as $group => [$permission, $page]) {
            $cases[$group . ' with the address of its own page'] = [$group, $permission, $page];
            $cases[$group . ' with no address'] = [$group, $permission, ''];
            $cases[$group . ' with the address of a page of another permission'] = [$group, $permission, $permission === 'orders' ? 'admin/products/list/in-house' : 'admin/orders/list/all'];
        }

        return $cases;
    }

    /** @return array<string, array{0: string, 1: string}> the address a hit of no known type carries and the permission of the sidebar page it names */
    public static function addressesOfSidebarPages(): array
    {
        return [
            'a page of the menu' => ['admin/orders/list/all', 'orders'],
            'a page of the menu, with a slash in front' => ['/admin/orders/list/all', 'orders'],
            'a detail page that shares the path root of a page of the menu' => ['admin/orders/details/15', 'orders'],
            'a detail page of the catalog' => ['admin/products/update/15', 'catalog'],
            'a detail page of the customers' => ['admin/customer/view/15', 'people'],
            'a page of a menu entry whose permission no role can hold' => ['admin/vat-tax/list', 'Business_Settings'],
            'an address of no menu entry under a path root that entries of two permissions share, which takes the permission of the first of them' => ['admin/business-settings/zz-detail/1', 'marketing'],
        ];
    }

    #[DataProvider('hitsOfEachGroup')]
    public function test_a_hit_is_kept_for_the_holder_of_the_permission_of_its_group_and_for_the_master_admin_only(string $group, string $permission, string $uri): void
    {
        $hits = [$group => [['uri' => $uri, 'type' => $group, 'priority' => 3]]];

        $this->assertKeptOnlyBy(hits: $hits, permission: $permission);
    }

    #[DataProvider('addressesOfSidebarPages')]
    public function test_a_hit_of_no_known_type_follows_the_permission_of_the_sidebar_page_it_names(string $uri, string $permission): void
    {
        $hits = ['menu' => [['uri' => $uri, 'type' => 'menu', 'priority' => 1]]];

        $this->assertKeptOnlyBy(hits: $hits, permission: $permission);
    }

    public function test_a_hit_of_no_known_type_and_no_known_page_is_kept_so_that_the_pages_of_an_add_on_stay_searchable(): void
    {
        $hits = [
            'page' => [
                ['uri' => 'admin/zz-addon/page', 'type' => 'page', 'priority' => 2],
                ['uri' => 'admin/orders-archive/1', 'type' => 'page', 'priority' => 2],
                ['uri' => '', 'type' => 'page', 'priority' => 2],
            ],
            'zz_group' => [['type' => 'zz_group', 'priority' => 3]],
        ];

        $this->signInHolding(permissions: []);

        $this->assertSame($hits, $this->filter(grouped: $hits));
    }

    public function test_a_hit_that_is_an_object_or_names_its_page_by_route_uri_is_read_like_the_others(): void
    {
        $object = new stdClass();
        $object->uri = 'admin/orders/list/all';
        $hits = ['menu' => [$object, ['route_uri' => 'admin/orders/list/all'], ['route_uri' => 'admin/customer/list']]];

        $this->signInHolding(permissions: ['orders']);
        $filtered = $this->filter(grouped: $hits);

        $this->assertSame([$object, ['route_uri' => 'admin/orders/list/all']], $filtered['menu']);
        $this->assertSame($object, $filtered['menu'][0]);
    }

    public function test_a_group_with_nothing_left_is_removed_and_the_others_keep_their_order_and_are_numbered_again(): void
    {
        $orders = ['uri' => 'admin/orders/list/all', 'type' => 'orders'];
        $customer = ['uri' => 'admin/customer/list', 'type' => 'users'];
        $hits = [
            'users' => [$customer, $customer],
            'menu' => [['uri' => 'admin/customer/list', 'type' => 'menu'], ['uri' => 'admin/orders/list/all', 'type' => 'menu'], ['uri' => 'admin/orders/list/pending', 'type' => 'menu']],
            'orders' => [$orders],
        ];

        $this->signInHolding(permissions: ['orders']);

        $this->assertSame(
            ['menu' => [['uri' => 'admin/orders/list/all', 'type' => 'menu'], ['uri' => 'admin/orders/list/pending', 'type' => 'menu']], 'orders' => [$orders]],
            $this->filter(grouped: $hits),
        );
    }

    public function test_nothing_in_means_nothing_out(): void
    {
        $this->signInHolding(permissions: []);

        $this->assertSame([], $this->filter(grouped: []));
    }

    public function test_a_role_that_is_switched_off_holds_nothing_and_neither_does_a_visitor(): void
    {
        $hits = ['orders' => [['uri' => 'admin/orders/list/all', 'type' => 'orders']]];

        $this->signInHolding(permissions: ['orders'], roleIsActive: false);
        $this->assertSame([], $this->filter(grouped: $hits), 'A role that holds orders but is switched off');

        $this->app['auth']->guard('admin')->logout();
        $this->assertSame([], $this->filter(grouped: $hits), 'Nobody signed in');
    }

    /**
     * @param array<string, list<mixed>> $hits
     * The hits pass for a role that holds the permission and for the master admin, and not for a role that holds no permission or every other one. A role holds
     * only what the role form offers, which is why the hits of a permission that no form offers pass for the master admin alone.
     */
    private function assertKeptOnlyBy(array $hits, string $permission): void
    {
        $offered = array_keys(GlobalConstant::EMPLOYEE_ROLE_MODULE_PERMISSION);

        $this->signInHolding(permissions: [$permission]);
        $this->assertSame($hits, $this->filter(grouped: $hits), 'A role that holds ' . $permission);
        $this->signInHolding(permissions: array_values(array_diff($offered, [$permission])));
        $this->assertSame([], $this->filter(grouped: $hits), 'A role that holds every other permission');
        $this->signInHolding(permissions: []);
        $this->assertSame([], $this->filter(grouped: $hits), 'A role that holds no permission');
        $this->signInHolding(permissions: $offered);
        $this->assertSame(in_array($permission, $offered, true) ? $hits : [], $this->filter(grouped: $hits), 'A role that holds every permission the role form offers');
        $this->signInHolding(permissions: null);
        $this->assertSame($hits, $this->filter(grouped: $hits), 'The master admin');
    }

    /** @param list<string>|null $permissions null is the master admin */
    private function signInHolding(?array $permissions, bool $roleIsActive = true): void
    {
        $employee = new Admin(['admin_role_id' => $permissions === null ? 1 : 2]);
        $employee->setRelation('role', new AdminRole(['module_access' => json_encode($permissions ?? []), 'status' => $roleIsActive]));

        $this->actingAs($employee, 'admin');
    }

    /**
     * @param array<string, list<mixed>> $grouped
     * @return array<string, list<mixed>>
     */
    private function filter(array $grouped): array
    {
        return app(AdvancedSearchPermissionFilter::class)->filterByPermission(grouped: $grouped);
    }
}
