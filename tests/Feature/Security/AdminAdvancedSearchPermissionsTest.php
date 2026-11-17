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
use App\Packages\AdvanceSearch\AdvanceSearch;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsAdvancedSearchSchema;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The search box in the header of the admin panel is open to every employee, because it sits on every page. What it finds is not: it looks for the typed
 * word in the customers, vendors, delivery men, subscribers, orders, refunds, coupons, blog posts, contact messages, business pages, products, categories
 * and brands, in the columns of each (a phone number, an e-mail address, the text of a message, a coupon code), so an employee who may not open those screens
 * could still read the names it answers with and find out which phone number, address or code exists. The answer is filtered by what the role of the
 * signed-in employee holds, for the hits of a fresh search and for the recent searches the box lists before anything is typed.
 *
 * The routes and their middleware are the real ones; the tables are the ones the search reads (BuildsAdvancedSearchSchema). The master admin (the first
 * admin role) passes every permission check.
 */
class AdminAdvancedSearchPermissionsTest extends TestCase
{
    use BuildsAdvancedSearchSchema;
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    /** A word that only the rows seeded below hold. */
    private const KEYWORD = 'quasarlynx';

    private const CLASSIC_SEARCH = '/admin/advanced-search';

    private const V2_SEARCH = '/admin/v2/advanced-search';

    /** The search boxes of the panel: the one in the header of the classic layout and the one of the sidebar modal of the v2 layout. */
    private const ROUTES = [
        'the classic search' => self::CLASSIC_SEARCH,
        'the v2 search' => self::V2_SEARCH,
    ];

    /**
     * The group of hits each kind of row comes back in and the permission whose holders may see it. `help_and_support` is not a permission the role form
     * offers, so only the master admin holds it.
     */
    private const PERMISSION_OF_GROUP = [
        'users' => 'people',
        'sellers' => 'people',
        'delivery_men' => 'people',
        'subscriptions' => 'people',
        'orders' => 'orders',
        'refund_requests' => 'orders',
        'products' => 'catalog',
        'categories' => 'catalog',
        'brands' => 'catalog',
        'coupons' => 'marketing',
        'blogs' => 'marketing',
        'business_pages' => 'business_settings',
        'contacts' => 'help_and_support',
    ];

    /** A value that would run a script if the list put it into the page as markup. */
    private const MARKUP = '<img src=x onerror=alert(1)>';

    /** The groups whose hits the recent-search tests record, one hit of each. */
    private const CLICKED_GROUPS = ['users', 'sellers', 'delivery_men', 'subscriptions', 'orders', 'refund_requests', 'products', 'categories', 'brands'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildAdvancedSearchSchema();
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class, ActivationCheckMiddleware::class,
        ]);
        $this->seedTheSearchableRows();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: list<string>|null}> the route, the permissions of the role (null: the master admin) */
    public static function routesAndEmployees(): array
    {
        $cases = [];
        foreach (self::ROUTES as $routeName => $route) {
            foreach (self::employees() as $employeeName => $permissions) {
                $cases[$routeName . ', ' . $employeeName] = [$route, $permissions];
            }
        }

        return $cases;
    }

    /** @return array<string, array{0: string, 1: string}> the route, the group of the row that holds the word */
    public static function routesAndGroups(): array
    {
        $cases = [];
        foreach (self::ROUTES as $routeName => $route) {
            foreach (array_keys(self::PERMISSION_OF_GROUP) as $group) {
                $cases[$routeName . ', ' . $group] = [$route, $group];
            }
        }

        return $cases;
    }

    /** @return array<string, array{0: string}> */
    public static function routes(): array
    {
        $cases = [];
        foreach (self::ROUTES as $routeName => $route) {
            $cases[$routeName] = [$route];
        }

        return $cases;
    }

    /** @return array<string, array{0: string, 1: string}> the route, what follows the path of it */
    public static function routesWithAnEmptyKeyword(): array
    {
        $cases = [];
        foreach (self::ROUTES as $routeName => $route) {
            foreach (['no keyword' => '', 'an empty keyword' => '?keyword=', 'a keyword of spaces' => '?keyword=%20%20'] as $keywordName => $query) {
                $cases[$routeName . ' with ' . $keywordName] = [$route, $query];
            }
        }

        return $cases;
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: list<string>, 4: bool}> the route, the type and the address a stored hit carries, the permissions of the role, whether the hit is listed */
    public static function storedHits(): array
    {
        $cases = [];
        foreach (self::ROUTES as $routeName => $route) {
            $cases[$routeName . ': a hit of the customers is hidden from a role without people, whatever its address says'] = [$route, 'users', 'admin/orders/list/all', ['orders'], false];
            $cases[$routeName . ': a hit of a type nobody knows is hidden when its address is a page of a permission the role does not hold'] = [$route, 'zz_forged', 'admin/customer/view/4101', ['orders'], false];
            $cases[$routeName . ': a hit of a type nobody knows is listed when its address is a page of a permission the role holds'] = [$route, 'zz_forged', 'admin/customer/view/4101', ['people'], true];
            $cases[$routeName . ': a hit of a type nobody knows and of an address no menu knows is listed, like the pages of an add-on'] = [$route, 'zz_forged', 'admin/zz-addon/page', [], true];
        }

        return $cases;
    }

    /** @return array<string, list<string>|null> the permissions of the role of each kind of employee; null is the master admin */
    private static function employees(): array
    {
        return [
            'an employee whose role holds no module' => [],
            'an employee who holds only people' => ['people'],
            'an employee who holds only orders' => ['orders'],
            'an employee who holds only the catalog' => ['catalog'],
            'an employee who holds only marketing' => ['marketing'],
            'an employee who holds only business settings' => ['business_settings'],
            'an employee who holds every permission the role form offers' => array_keys(GlobalConstant::EMPLOYEE_ROLE_MODULE_PERMISSION),
            'the master admin' => null,
        ];
    }

    /**
     * @param list<string>|null $permissions
     * @return list<string> the groups of hits the permissions let through
     */
    private static function groupsOf(?array $permissions): array
    {
        $groups = array_keys(self::PERMISSION_OF_GROUP);
        if ($permissions === null) {
            return $groups;
        }

        return array_values(array_filter($groups, static fn (string $group): bool => in_array(self::PERMISSION_OF_GROUP[$group], $permissions, true)));
    }

    #[DataProvider('routesAndEmployees')]
    public function test_the_search_answers_with_the_groups_of_the_permissions_the_role_holds(string $route, ?array $permissions): void
    {
        $answer = $this->search(employee: $this->employeeHolding(permissions: $permissions), route: $route);

        $this->assertEqualsCanonicalizing(self::groupsOf(permissions: $permissions), $answer['groups'], 'The groups of hits in the answer');
    }

    #[DataProvider('routesAndEmployees')]
    public function test_nothing_of_a_row_the_role_does_not_hold_is_in_the_answer(string $route, ?array $permissions): void
    {
        $answer = $this->search(employee: $this->employeeHolding(permissions: $permissions), route: $route);

        $leaked = $this->valuesShownBy(answer: $answer, groups: array_diff(array_keys(self::PERMISSION_OF_GROUP), self::groupsOf(permissions: $permissions)));

        $this->assertSame([], $leaked, "The answer shows what the role does not hold:\n" . implode("\n", $leaked));
    }

    #[DataProvider('routesAndGroups')]
    public function test_a_word_that_only_a_private_column_holds_finds_the_row_for_the_holder_of_its_permission_only(string $route, string $group): void
    {
        $row = $this->searchableRows()[$group];
        $permission = self::PERMISSION_OF_GROUP[$group];
        $everyOtherPermission = array_values(array_diff(array_keys(GlobalConstant::EMPLOYEE_ROLE_MODULE_PERMISSION), [$permission]));

        foreach (['an employee whose role holds no module' => [], 'an employee whose role holds every other permission' => $everyOtherPermission] as $who => $permissions) {
            $answer = $this->search(employee: $this->employeeHolding(permissions: $permissions), route: $route, keyword: $row['probe']);

            $this->assertSame([], $answer['groups'], $who . ' typed ' . $row['probe'] . ' and found a hit of ' . $group);
            $this->assertSame([], $this->valuesShownBy(answer: $answer, groups: [$group], typed: $row['probe']), $who . ' typed ' . $row['probe'] . ' and the answer shows the row');
        }

        $this->assertSame([$group], $this->search(employee: $this->employeeHolding(permissions: null), route: $route, keyword: $row['probe'])['groups'], 'The master admin finds the row by ' . $row['probe']);
        if ($permission !== 'help_and_support') {
            $this->assertSame([$group], $this->search(employee: $this->employeeHolding(permissions: [$permission]), route: $route, keyword: $row['probe'])['groups'], 'The holder of ' . $permission . ' finds the row by ' . $row['probe']);
        }
    }

    #[DataProvider('routesAndEmployees')]
    public function test_the_menu_hits_are_the_ones_the_search_package_gives_the_employee(string $route, ?array $permissions): void
    {
        $employee = $this->employeeHolding(permissions: $permissions);

        foreach (['orders', 'dashboard'] as $keyword) {
            $answer = $this->search(employee: $employee, route: $route, keyword: $keyword);

            $this->assertEqualsCanonicalizing($this->menuUrisThePackageGives(employee: $employee, keyword: $keyword), $answer['urisByGroup']['menu'] ?? [], 'The menu hits for ' . $keyword);
        }
    }

    #[DataProvider('routesAndEmployees')]
    public function test_no_hit_leads_to_a_sidebar_page_of_a_permission_the_role_does_not_hold(string $route, ?array $permissions): void
    {
        $employee = $this->employeeHolding(permissions: $permissions);
        $permissionOfPage = $this->permissionOfEachSidebarPage();

        $unreachable = [];
        foreach (['orders', 'settings'] as $keyword) {
            foreach ($this->search(employee: $employee, route: $route, keyword: $keyword)['uris'] as $uri) {
                $needs = $permissionOfPage[$uri] ?? '';
                if ($needs !== '' && $permissions !== null && !in_array($needs, $permissions, true)) {
                    $unreachable[$uri . ' (asks for ' . $needs . ')'] = true;
                }
            }
        }

        $this->assertSame([], array_keys($unreachable), "Hits to pages the role cannot open:\n" . implode("\n", array_keys($unreachable)));
    }

    #[DataProvider('routes')]
    public function test_the_dashboard_hit_is_for_every_employee_because_its_page_asks_for_no_permission(string $route): void
    {
        $answer = $this->search(employee: $this->employeeHolding(permissions: []), route: $route, keyword: 'dashboard');

        $this->assertContains('admin/dashboard', $answer['urisByGroup']['menu'] ?? []);
    }

    #[DataProvider('routes')]
    public function test_the_order_pages_are_hits_for_the_holder_of_orders_and_for_nobody_else(string $route): void
    {
        $holder = $this->search(employee: $this->employeeHolding(permissions: ['orders']), route: $route, keyword: 'orders');
        $other = $this->search(employee: $this->employeeHolding(permissions: ['people', 'catalog']), route: $route, keyword: 'orders');

        $this->assertContains('admin/orders/list/all', $holder['urisByGroup']['menu'] ?? []);
        $this->assertNotContains('admin/orders/list/all', $other['uris']);
    }

    #[DataProvider('routes')]
    public function test_a_keyword_that_is_an_array_is_answered_like_no_keyword(string $route): void
    {
        $employee = $this->employeeHolding(permissions: ['people']);
        $this->clickOnTheHits(employee: $employee, groups: ['users']);

        $response = $this->actingAs(Admin::query()->findOrFail($employee->id), 'admin')->getJson($route . '?keyword[]=' . self::KEYWORD);

        $response->assertOk();
        $this->assertSame('', $response->json('keyword'));
        $this->assertSame(['users'], array_keys($response->json('result')));
    }

    #[DataProvider('routesWithAnEmptyKeyword')]
    public function test_an_empty_keyword_lists_the_recent_searches_the_role_still_holds(string $route, string $query): void
    {
        $employee = $this->employeeHolding(permissions: ['people', 'orders', 'catalog']);
        $this->clickOnTheHits(employee: $employee, groups: self::CLICKED_GROUPS);
        $this->reduceTheRoleOf(employee: $employee, permissions: ['orders']);

        $response = $this->actingAs(Admin::query()->findOrFail($employee->id), 'admin')->getJson($route . $query);

        $response->assertOk();
        $answer = $this->answerOf(response: $response);
        $this->assertEqualsCanonicalizing(['orders', 'refund_requests'], $answer['groups'], 'The recent searches left after the role was reduced to orders');
        $this->assertSame([], $this->valuesShownBy(answer: $answer, groups: self::groupsOf(permissions: ['people', 'catalog'])), 'What the recent searches of a permission the role lost still show');
    }

    #[DataProvider('routes')]
    public function test_the_recent_searches_are_listed_in_full_while_the_role_holds_what_they_lead_to(string $route): void
    {
        $employee = $this->employeeHolding(permissions: ['people', 'orders', 'catalog']);
        $this->clickOnTheHits(employee: $employee, groups: self::CLICKED_GROUPS);

        $answer = $this->search(employee: $employee, route: $route, keyword: '');

        $this->assertEqualsCanonicalizing(self::CLICKED_GROUPS, $answer['groups']);
    }

    #[DataProvider('routes')]
    public function test_the_master_admin_gets_every_recent_search_back(string $route): void
    {
        $master = $this->employeeHolding(permissions: null);
        $this->clickOnTheHits(employee: $master, groups: self::CLICKED_GROUPS);

        $this->assertEqualsCanonicalizing(self::CLICKED_GROUPS, $this->search(employee: $master, route: $route, keyword: '')['groups']);
    }

    #[DataProvider('routes')]
    public function test_the_recent_searches_of_one_employee_are_not_listed_for_another(string $route): void
    {
        $this->clickOnTheHits(employee: $this->employeeHolding(permissions: ['people']), groups: ['users']);

        $this->assertSame([], $this->search(employee: $this->employeeHolding(permissions: ['people']), route: $route, keyword: '')['groups']);
    }

    /** @param list<string> $permissions */
    #[DataProvider('storedHits')]
    public function test_a_stored_hit_is_filtered_by_the_type_and_the_address_it_carries(string $route, string $type, string $uri, array $permissions, bool $isListed): void
    {
        $employee = $this->employeeHolding(permissions: $permissions);
        $this->storeAHit(employee: $employee, hit: ['priority' => 3, 'type' => $type, 'uri' => $uri, 'page_title_value' => 'Stored hit']);

        $answer = $this->search(employee: $employee, route: $route, keyword: '');

        $this->assertSame($isListed ? [$type] : [], $answer['groups']);
    }

    public function test_the_v2_search_finds_nothing_for_the_word_auction_while_the_add_on_is_not_published(): void
    {
        $this->skipWhenTheAuctionAddOnIsPublished();

        foreach ([[], null] as $permissions) {
            $answer = $this->search(employee: $this->employeeHolding(permissions: $permissions), route: self::V2_SEARCH, keyword: 'Auction');

            $this->assertSame([], $answer['groups']);
        }
    }

    public function test_the_v2_search_drops_the_recent_searches_of_the_auction_add_on_while_it_is_not_published(): void
    {
        $this->skipWhenTheAuctionAddOnIsPublished();
        $master = $this->employeeHolding(permissions: null);
        foreach ([
            ['type' => 'menu', 'uri' => 'admin/auction/products/list', 'page_title_value' => 'Auction products'],
            ['type' => 'auctions', 'uri' => 'admin/auction/products/view?id=5', 'page_title_value' => 'An auction'],
            ['type' => 'page', 'uri' => 'admin/business-settings/auction-config', 'page_title_value' => 'Auction settings'],
            ['type' => 'page', 'uri' => 'vendor/auction/products/list', 'page_title_value' => 'Vendor auctions'],
            ['type' => 'menu', 'uri' => 'admin/dashboard', 'page_title_value' => 'Dashboard'],
        ] as $hit) {
            $this->storeAHit(employee: $master, hit: $hit + ['priority' => 1]);
        }

        $answer = $this->search(employee: $master, route: self::V2_SEARCH, keyword: '');

        $this->assertSame(['menu' => ['admin/dashboard']], $answer['urisByGroup']);
    }

    #[DataProvider('routes')]
    public function test_a_stored_hit_cannot_put_markup_into_the_list(string $route): void
    {
        $employee = $this->employeeHolding(permissions: []);
        $this->storeAHit(employee: $employee, hit: ['priority' => 3, 'type' => 'zz_stored', 'uri' => self::MARKUP, 'page_title_value' => self::MARKUP, 'image' => '"><script>alert(1)</script>']);

        $answer = $this->search(employee: $employee, route: $route, keyword: '');

        $this->assertSame(['zz_stored'], $answer['groups']);
        $this->assertStringNotContainsString(self::MARKUP, $answer['html']);
        $this->assertStringNotContainsString('"><script>alert(1)</script>', $answer['html']);
    }

    #[DataProvider('routes')]
    public function test_the_columns_of_a_recent_search_that_the_list_never_reads_cannot_reach_it(string $route): void
    {
        $employee = $this->employeeHolding(permissions: []);
        $this->actingAs(Admin::query()->findOrFail($employee->id), 'admin')->post('/admin/advanced-search-recent', [
            'routeName' => self::MARKUP,
            'routeUri' => 'admin/dashboard',
            'routeFullUrl' => self::MARKUP,
            'searchKeyword' => self::MARKUP,
            'response' => json_encode(['priority' => 1, 'type' => 'menu', 'uri' => 'admin/dashboard', 'page_title_value' => 'Dashboard']),
        ])->assertRedirect();

        $answer = $this->search(employee: $employee, route: $route, keyword: '');

        $this->assertSame(['menu' => ['admin/dashboard']], $answer['urisByGroup']);
        $this->assertStringNotContainsString('onerror', $answer['html']);
    }

    private function seedTheSearchableRows(): void
    {
        foreach ($this->searchableRows() as $row) {
            DB::table($row['table'])->insert($row['row']);
        }
        DB::table('orders')->insert(['id' => 6103, 'customer_id' => 4101, 'order_amount' => 7391.42, 'order_group_id' => 'earlier-order']);
    }

    /**
     * @return array<string, array{table: string, row: array<string, mixed>, shown: list<string>, probe: string}> by the group the row comes back in: the row, what of it
     *         could show in an answer (a name, the address of its page, a private value) and a word that only a private column of it holds
     */
    private function searchableRows(): array
    {
        return [
            'users' => [
                'table' => 'users',
                'row' => ['id' => 4101, 'name' => 'Quasarlynx Hollenbeck', 'f_name' => 'Quasarlynx', 'l_name' => 'Hollenbeck', 'email' => 'private.customer@example.test', 'phone' => '9990004101'],
                'shown' => ['Quasarlynx Hollenbeck', 'admin/customer/view/4101', 'private.customer@example.test', '9990004101', '7391.42'],
                'probe' => '9990004101',
            ],
            'sellers' => [
                'table' => 'sellers',
                'row' => ['id' => 4201, 'f_name' => 'Quasarlynx', 'l_name' => 'Vendorson', 'email' => 'private.vendor@example.test', 'phone' => '9990004201'],
                'shown' => ['admin/vendors/view/4201', 'private.vendor@example.test', '9990004201'],
                'probe' => '9990004201',
            ],
            'delivery_men' => [
                'table' => 'delivery_men',
                'row' => ['id' => 4301, 'f_name' => 'Quasarlynx', 'l_name' => 'Courier', 'phone' => '9990004301', 'email' => 'private.courier@example.test', 'password' => 'not-a-real-hash'],
                'shown' => ['private.courier@example.test', '9990004301'],
                'probe' => '9990004301',
            ],
            'subscriptions' => [
                'table' => 'subscriptions',
                'row' => ['id' => 4401, 'email' => 'quasarlynx.subscriberprobe@example.test'],
                'shown' => ['quasarlynx.subscriberprobe@example.test'],
                'probe' => 'subscriberprobe',
            ],
            'orders' => [
                'table' => 'orders',
                'row' => ['id' => 6102, 'customer_id' => 4102, 'order_amount' => 5127.63, 'order_group_id' => 'quasarlynx-orderprobe'],
                'shown' => ['5127.63', 'quasarlynx-orderprobe'],
                'probe' => 'orderprobe',
            ],
            'refund_requests' => [
                'table' => 'refund_requests',
                'row' => ['id' => 4501, 'customer_id' => 4101, 'order_id' => 6101, 'status' => 'pending', 'refund_reason' => 'Quasarlynx reason refundprobe'],
                'shown' => ['Quasarlynx reason refundprobe'],
                'probe' => 'refundprobe',
            ],
            'products' => [
                'table' => 'products',
                'row' => ['id' => 5101, 'name' => 'Quasarlynx product', 'slug' => 'quasarlynx-productprobe', 'code' => 'QLX-5101'],
                'shown' => ['Quasarlynx product', 'admin/products/update/5101', 'QLX-5101'],
                'probe' => 'productprobe',
            ],
            'categories' => [
                'table' => 'categories',
                'row' => ['id' => 5201, 'name' => 'Quasarlynx category', 'slug' => 'quasarlynx-categoryprobe'],
                'shown' => ['Quasarlynx category', 'admin/category/update/5201'],
                'probe' => 'categoryprobe',
            ],
            'brands' => [
                'table' => 'brands',
                'row' => ['id' => 5301, 'name' => 'Quasarlynx brandprobe'],
                'shown' => ['Quasarlynx brandprobe', 'admin/brand/update/5301'],
                'probe' => 'brandprobe',
            ],
            'coupons' => [
                'table' => 'coupons',
                'row' => ['id' => 4601, 'title' => 'Quasarlynx sale', 'code' => 'QUASARLYNX-SECRETCODE'],
                'shown' => ['admin/coupon/4601', 'QUASARLYNX-SECRETCODE', 'Quasarlynx sale'],
                'probe' => 'SECRETCODE',
            ],
            'blogs' => [
                'table' => 'blogs',
                'row' => ['id' => 4701, 'title' => 'Quasarlynx post', 'slug' => 'quasarlynx-blogprobe'],
                'shown' => ['Quasarlynx post', 'quasarlynx-blogprobe'],
                'probe' => 'blogprobe',
            ],
            'business_pages' => [
                'table' => 'business_pages',
                'row' => ['id' => 4901, 'title' => 'Quasarlynx page', 'slug' => 'quasarlynx-pageprobe'],
                'shown' => ['Quasarlynx page', 'quasarlynx-pageprobe'],
                'probe' => 'pageprobe',
            ],
            'contacts' => [
                'table' => 'contacts',
                'row' => ['id' => 4801, 'name' => 'Quasarlynx Writer', 'email' => 'private.writer@example.test', 'subject' => 'Hello', 'message' => 'Quasarlynx confidential messageprobe'],
                'shown' => ['Quasarlynx Writer', 'private.writer@example.test', 'Quasarlynx confidential messageprobe'],
                'probe' => 'messageprobe',
            ],
        ];
    }

    private function skipWhenTheAuctionAddOnIsPublished(): void
    {
        if (getCheckAddonPublishedStatus(moduleName: 'Auction')) {
            $this->markTestSkipped('The Auction add-on is published here: the search keeps its entries.');
        }
    }

    /** @param list<string>|null $permissions null is the master admin */
    private function employeeHolding(?array $permissions): Admin
    {
        if ($permissions === null) {
            return Admin::query()->findOrFail(1);
        }

        $roleId = DB::table('admin_roles')->insertGetId(['name' => 'Role of ' . count($permissions) . ' modules', 'module_access' => json_encode($permissions), 'status' => 1]);
        $adminId = DB::table('admins')->insertGetId(['name' => 'Employee', 'email' => 'employee' . $roleId . '@example.test', 'admin_role_id' => $roleId, 'status' => 1]);

        return Admin::query()->findOrFail($adminId);
    }

    /** @param list<string> $permissions */
    private function reduceTheRoleOf(Admin $employee, array $permissions): void
    {
        DB::table('admin_roles')->where('id', $employee->admin_role_id)->update(['module_access' => json_encode($permissions)]);
    }

    /** @return array{groups: list<string>, uris: list<string>, urisByGroup: array<string, list<string>>, text: string, html: string} */
    private function search(Admin $employee, string $route, string $keyword = self::KEYWORD): array
    {
        $response = $this->actingAs(Admin::query()->findOrFail($employee->id), 'admin')->getJson($route . '?' . http_build_query(['keyword' => $keyword]));

        $response->assertOk();

        return $this->answerOf(response: $response);
    }

    /**
     * @return array{groups: list<string>, uris: list<string>, urisByGroup: array<string, list<string>>, text: string, html: string} what the answer says; its text holds the
     *         JSON and the markup of the list the way a browser shows them, where the JSON of the body escapes slashes and the markup of the list escapes quotes
     */
    private function answerOf(TestResponse $response): array
    {
        $result = $response->json('result');
        $urisByGroup = [];
        foreach ($result as $group => $hits) {
            $urisByGroup[$group] = array_column($hits, 'uri');
        }

        return [
            'groups' => array_keys($result),
            'uris' => array_merge([], ...array_values($urisByGroup)),
            'urisByGroup' => $urisByGroup,
            'html' => (string) $response->json('htmlView'),
            'text' => $response->getContent() . "\n" . json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n" . html_entity_decode((string) $response->json('htmlView'), ENT_QUOTES | ENT_HTML5),
        ];
    }

    /**
     * @param array{text: string} $answer
     * @param iterable<string> $groups
     * @param string $typed the word the employee typed: the answer repeats it, and they know it
     * @return list<string> what the rows of the groups show of themselves in the answer
     */
    private function valuesShownBy(array $answer, iterable $groups, string $typed = ''): array
    {
        $shown = [];
        foreach ($groups as $group) {
            foreach ($this->searchableRows()[$group]['shown'] as $value) {
                if ($value !== $typed && str_contains($answer['text'], $value)) {
                    $shown[] = $value . ' (' . $group . ')';
                }
            }
        }

        return $shown;
    }

    /** @return list<string> the addresses of the menu hits of the package for the signed-in employee: what the box answered before it looked at anything but the menu */
    private function menuUrisThePackageGives(Admin $employee, string $keyword): array
    {
        $this->actingAs(Admin::query()->findOrFail($employee->id), 'admin');
        $package = new AdvanceSearch('admin', $keyword);

        return array_values(array_unique(array_diff(array_column($package->searchMenuList(), 'uri'), $package->getThemeWiseSkipRoutesList())));
    }

    /** @return array<string, string> the permission each page of the sidebar asks for, by its address; an empty one is for every employee */
    private function permissionOfEachSidebarPage(): array
    {
        $permissions = [];
        foreach ((new AdvanceSearch('admin', ''))->adminMenuWithRoutes() as $entry) {
            $permissions[ltrim((string) $entry['uri'], '/')] ??= (string) $entry['module'];
        }

        return $permissions;
    }

    /** @param list<string> $groups the employee clicks on the last hit of each of these groups in the box, as the box records it */
    private function clickOnTheHits(Admin $employee, array $groups): void
    {
        $result = $this->actingAs(Admin::query()->findOrFail(1), 'admin')->getJson(self::V2_SEARCH . '?keyword=' . self::KEYWORD)->json('result');

        foreach ($groups as $group) {
            $this->storeAHit(employee: $employee, hit: end($result[$group]));
        }
    }

    /** @param array<string, mixed> $hit the same form the box posts when an employee clicks on a hit */
    private function storeAHit(Admin $employee, array $hit): void
    {
        $this->actingAs(Admin::query()->findOrFail($employee->id), 'admin')->post('/admin/advanced-search-recent', [
            'routeName' => $hit['page_title_value'],
            'routeUri' => $hit['uri'],
            'routeFullUrl' => url($hit['uri']),
            'searchKeyword' => self::KEYWORD,
            'response' => json_encode($hit),
        ])->assertRedirect();
    }
}
