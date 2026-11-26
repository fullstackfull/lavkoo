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
use App\Models\Admin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsAdvancedSearchSchema;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\BuildsStaffScopeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The header search of the admin panel narrowed by BOTH filters at once (GCC-14c; G-06): the modules the employee's ROLE holds, and, for a
 * restricted employee, the COUNTRIES they work with.
 *
 * The two filters were built in different pull requests and, until this tree, had never run together: `AdvancedSearchPermissionFilter`
 * drops the hit groups of a module the role does not hold, and `StaffSearchHits` drops the orders, refunds and products of another market
 * from the groups that survive. A filter that is an AND of two independent passes is exactly the shape where each is proved alone and the
 * composition is proved by nobody, and the composition is what an employee actually gets.
 *
 * The world. Products: 7101 is Kuwaiti, 7102 Saudi, both holding the keyword. Orders: 6201 holds the Kuwaiti product, 6202 the Saudi one,
 * both holding the keyword in `order_group_id`, which is the column the search reads an order by. An order is the employee's when every
 * line of it is a product of their countries (`StaffOrderVisibility`), so 6201 is a Kuwait employee's and 6202 is not.
 *
 * **The search cannot narrow its own query**: it reads whole tables once into a cache every employee shares, so the first employee to
 * search would otherwise decide what the others find. The cache is flushed between the requests of one test for the opposite reason -- to
 * prove that each answer is computed for the employee who asked, and not read back from the employee before them.
 */
class StaffSearchRoleAndCountryTest extends TestCase
{
    use BuildsAdvancedSearchSchema;
    use BuildsFulfillmentSchema;
    use BuildsStaffScopeWorld;
    use RestoresLanguageFiles;

    /** A word only the rows seeded here hold. */
    private const KEYWORD = 'quasarlynx';

    private const ROUTES = [
        'the classic search' => '/admin/advanced-search',
        'the v2 search' => '/admin/v2/advanced-search',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildAdvancedSearchSchema();
        (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();
        $this->runStaffScopeMigration();
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class, ActivationCheckMiddleware::class,
        ]);

        // The keyword is the WHOLE value of a searched column on each row, because the search offers the page of one row only on an
        // exact match of a column (`$strictMatchRoutes` in the search package): a row that merely contains the word is answered with the
        // LIST page of its kind, which carries no id and so no market, and would make every assertion here read the same for everybody.
        $this->insertStaffProduct(id: 7101, country: 'KW', extra: ['name' => 'Kuwait capsule', 'slug' => self::KEYWORD, 'code' => 'QLX-7101']);
        $this->insertStaffProduct(id: 7102, country: 'SA', extra: ['name' => 'Saudi capsule', 'slug' => self::KEYWORD, 'code' => 'QLX-7102']);

        // The search reads an order with its customer, so an order whose customer is missing is not a hit at all.
        DB::table('users')->insert(['id' => 4101, 'name' => 'Buyer', 'f_name' => 'Buyer', 'l_name' => 'One', 'email' => 'buyer@example.test', 'phone' => '9990004101']);

        DB::table('orders')->insert([
            ['id' => 6201, 'customer_id' => 4101, 'order_amount' => 11.11, 'order_group_id' => self::KEYWORD],
            ['id' => 6202, 'customer_id' => 4101, 'order_amount' => 22.22, 'order_group_id' => self::KEYWORD],
        ]);
        DB::table('order_details')->insert([
            ['order_id' => 6201, 'product_id' => 7101],
            ['order_id' => 6202, 'product_id' => 7102],
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** @return array<string, array{0: string}> */
    public static function routes(): array
    {
        $cases = [];
        foreach (self::ROUTES as $name => $route) {
            $cases[$name] = [$route];
        }

        return $cases;
    }

    #[DataProvider('routes')]
    public function test_an_employee_holding_only_orders_and_one_country_gets_the_intersection_of_both_filters(string $route): void
    {
        $employee = $this->employeeHolding(permissions: ['orders'], countries: ['KW']);

        $answer = $this->search(employee: $employee, route: $route);

        $this->assertSame(['orders'], array_keys($answer), 'the catalog is not this role\'s, so the products its country holds are still not listed');
        $this->assertSame(['admin/orders/details/6201'], $this->rowsIn($answer['orders']), 'and of the orders the role does hold, only the one of its country');
    }

    #[DataProvider('routes')]
    public function test_the_same_role_without_a_country_gets_both_orders_and_still_no_products(string $route): void
    {
        $employee = $this->employeeHolding(permissions: ['orders'], countries: []);

        $answer = $this->search(employee: $employee, route: $route);

        $this->assertSame(['orders'], array_keys($answer));
        $this->assertSame(['admin/orders/details/6201', 'admin/orders/details/6202'], $this->rowsIn($answer['orders']), 'the role filter alone leaves both orders');
    }

    #[DataProvider('routes')]
    public function test_the_same_country_with_a_role_that_holds_the_catalog_too_gets_one_product_and_one_order(string $route): void
    {
        $employee = $this->employeeHolding(permissions: ['orders', 'catalog'], countries: ['KW']);

        $answer = $this->search(employee: $employee, route: $route);

        $this->assertSame(['orders', 'products'], $this->sorted(array_keys($answer)));
        $this->assertSame(['admin/orders/details/6201'], $this->rowsIn($answer['orders']));
        $this->assertSame(['admin/products/update/7101'], $this->rowsIn($answer['products']), 'the country filter reaches the group the role let through');
    }

    #[DataProvider('routes')]
    public function test_an_employee_restricted_to_a_country_the_platform_does_not_serve_is_shown_nothing_of_either_kind(string $route): void
    {
        $employee = $this->employeeHolding(permissions: ['orders', 'catalog'], countries: ['ZZ']);

        $answer = $this->search(employee: $employee, route: $route);

        // The LIST pages of the two groups are menu hits and carry no market, so what this asserts is that no ROW of either is offered.
        $this->assertSame([], $this->rowsIn(array_merge(...array_values($answer) ?: [[]])), 'a stored value that names no configured market grants nothing, and is never widened');
    }

    #[DataProvider('routes')]
    public function test_one_employees_scope_never_decides_what_another_finds_from_the_shared_cache(string $route): void
    {
        $kuwait = $this->employeeHolding(permissions: ['orders', 'catalog'], countries: ['KW']);
        $saudi = $this->employeeHolding(permissions: ['orders', 'catalog'], countries: ['SA']);

        // Deliberately NOT flushed between these two: the cache is shared, and the point is that it holds every market's rows.
        $first = $this->search(employee: $kuwait, route: $route, flush: true);
        $second = $this->search(employee: $saudi, route: $route, flush: false);

        $this->assertSame(['admin/products/update/7101'], $this->rowsIn($first['products']));
        $this->assertSame(['admin/products/update/7102'], $this->rowsIn($second['products']), 'the second employee reads their own market out of the cache the first one filled');
    }

    // ---- the recent searches, which are stored rows and not a query -------------------------------------------------------------------

    /**
     * A recent search is a row of `recent_searches` holding what the employee's browser posted when they last clicked a hit, so one saved
     * BEFORE the employee was restricted keeps naming, titling and linking the row it named then, for as long as the row lives. The role
     * filter has always reached these; the country filter had not (G-06, decision 17 -- the default it named was "filter").
     */
    #[DataProvider('routes')]
    public function test_a_recent_search_saved_before_the_restriction_is_narrowed_by_the_employees_countries(string $route): void
    {
        $employee = $this->employeeHolding(permissions: ['orders', 'catalog'], countries: ['KW']);
        $this->rememberSearchesOf(employee: $employee);

        $answer = $this->recentSearchesOf(employee: $employee, route: $route);

        $this->assertSame(['admin/orders/details/6201'], $this->rowsIn($answer['orders'] ?? []), 'the Saudi order it had opened before is not offered again');
        $this->assertSame(['admin/products/update/7101'], $this->rowsIn($answer['products'] ?? []));
    }

    /** The twin: the same four rows, for the same role with no country, are all still offered -- so the narrowing above is the scope's. */
    #[DataProvider('routes')]
    public function test_the_same_recent_searches_are_all_offered_to_an_employee_the_scopes_do_not_restrict(string $route): void
    {
        $employee = $this->employeeHolding(permissions: ['orders', 'catalog'], countries: []);
        $this->rememberSearchesOf(employee: $employee);

        $answer = $this->recentSearchesOf(employee: $employee, route: $route);

        $this->assertSame(['admin/orders/details/6201', 'admin/orders/details/6202'], $this->rowsIn($answer['orders'] ?? []));
        $this->assertSame(['admin/products/update/7101', 'admin/products/update/7102'], $this->rowsIn($answer['products'] ?? []));
    }

    /** A kind that belongs to no market is left alone: a customer is one identity across every country (GCC-13), so the row stays. */
    #[DataProvider('routes')]
    public function test_a_recent_hit_of_a_kind_that_belongs_to_no_market_is_still_offered(string $route): void
    {
        $employee = $this->employeeHolding(permissions: ['orders', 'catalog', 'people'], countries: ['KW']);
        $this->rememberSearchesOf(employee: $employee);
        $this->rememberOneSearch(employee: $employee, type: 'users', uri: 'admin/customer/view/4101', priority: 9);

        $answer = $this->recentSearchesOf(employee: $employee, route: $route);

        $this->assertSame(['admin/customer/view/4101'], array_values((array) ($answer['users'] ?? [])));
    }

    /** And a LIST page of a scoped kind, which carries no id and so no market and is the same link for every employee. */
    #[DataProvider('routes')]
    public function test_a_recent_hit_that_names_a_list_page_rather_than_a_row_is_still_offered(string $route): void
    {
        $employee = $this->employeeHolding(permissions: ['orders'], countries: ['KW']);
        $this->rememberOneSearch(employee: $employee, type: 'orders', uri: 'admin/orders/list/all', priority: 1);

        $answer = $this->recentSearchesOf(employee: $employee, route: $route);

        $this->assertSame(['admin/orders/list/all'], array_values((array) ($answer['orders'] ?? [])));
    }

    /** A stored address of a scoped kind that names something which is not an id is dropped: nobody can resolve it, so nobody is shown it. */
    #[DataProvider('routes')]
    public function test_a_recent_hit_whose_address_names_no_readable_row_is_dropped(string $route): void
    {
        $employee = $this->employeeHolding(permissions: ['orders'], countries: ['KW']);
        $this->rememberOneSearch(employee: $employee, type: 'orders', uri: 'admin/orders/details/0', priority: 1);
        $this->rememberOneSearch(employee: $employee, type: 'orders', uri: 'admin/orders/details/99999', priority: 2);

        $answer = $this->recentSearchesOf(employee: $employee, route: $route);

        $this->assertSame([], array_values((array) ($answer['orders'] ?? [])));
    }

    /** The four rows an employee who had been unrestricted would have saved: both orders and both products. */
    private function rememberSearchesOf(Admin $employee): void
    {
        $this->rememberOneSearch(employee: $employee, type: 'orders', uri: 'admin/orders/details/6201', priority: 1);
        $this->rememberOneSearch(employee: $employee, type: 'orders', uri: 'admin/orders/details/6202', priority: 2);
        $this->rememberOneSearch(employee: $employee, type: 'products', uri: 'admin/products/update/7101', priority: 3);
        $this->rememberOneSearch(employee: $employee, type: 'products', uri: 'admin/products/update/7102', priority: 4);
    }

    private function rememberOneSearch(Admin $employee, string $type, string $uri, int $priority): void
    {
        DB::table('recent_searches')->insert([
            'user_id' => $employee->getKey(),
            'user_type' => 'admin',
            'title' => 'Saved hit',
            'route_uri' => $uri,
            'route_full_url' => url($uri),
            'keyword' => self::KEYWORD,
            'response' => json_encode([
                'type' => $type,
                'uri' => $uri,
                'page_title_value' => 'Saved hit',
                'priority' => $priority,
                'image' => '',
            ]),
            'created_at' => now()->subMinutes(100 - $priority),
            'updated_at' => now(),
        ]);
    }

    /**
     * The recent searches a route offers: the same two routes, asked with NO keyword, which is the branch that reads the stored rows.
     *
     * @return array<string, list<string>>
     */
    private function recentSearchesOf(Admin $employee, string $route): array
    {
        Cache::flush();

        $response = $this->actingAs($employee, 'admin')->getJson($route);
        $response->assertOk();

        $byGroup = [];
        foreach ((array) $response->json('result') as $group => $hits) {
            $byGroup[(string) $group] = array_values(array_map(static fn (array $hit): string => (string) ($hit['uri'] ?? ''), $hits));
        }
        ksort($byGroup);

        return $byGroup;
    }

    /**
     * @param list<string> $permissions
     * @param list<string> $countries an empty list leaves the employee unrestricted: no row is written, which is what an employee nobody
     *                                has given a country to looks like
     */
    private function employeeHolding(array $permissions, array $countries): Admin
    {
        $roleId = DB::table('admin_roles')->insertGetId(['name' => 'Role of ' . implode(' and ', $permissions ?: ['nothing']), 'module_access' => json_encode($permissions), 'status' => 1]);
        $adminId = DB::table('admins')->insertGetId(['name' => 'Employee', 'email' => 'employee' . $roleId . '@example.test', 'admin_role_id' => $roleId, 'status' => 1]);

        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: $adminId, countries: $countries);

        return Admin::query()->findOrFail($adminId);
    }

    /** @return array<string, list<string>> the addresses the answer offers, by the group they came back in */
    private function search(Admin $employee, string $route, bool $flush = true): array
    {
        if ($flush) {
            Cache::flush();
        }

        $response = $this->actingAs($employee, 'admin')->getJson($route . '?' . http_build_query(['keyword' => self::KEYWORD]));
        $response->assertOk();

        $byGroup = [];
        foreach ((array) $response->json('result') as $group => $hits) {
            $byGroup[(string) $group] = array_values(array_map(static fn (array $hit): string => (string) ($hit['uri'] ?? ''), $hits));
        }
        ksort($byGroup);

        return $byGroup;
    }

    /**
     * The addresses of a group that name ONE ROW, which are the ones a market can be read off. The list page of the kind is a menu hit and
     * is there for everybody.
     *
     * @param list<string> $uris
     * @return list<string>
     */
    private function rowsIn(array $uris): array
    {
        return $this->sorted(array_values(array_filter($uris, static fn (string $uri): bool => (bool) preg_match('#/(details|update)/\\d+$#', $uri))));
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
