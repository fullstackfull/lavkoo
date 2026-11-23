<?php

namespace Tests\Feature\StaffScope;

use App\Exports\CustomerOrderListExport;
use App\Exports\DeliveryManOrderHistory;
use App\Exports\VendorOrderListExport;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Http\Middleware\ScopeOrdersToStaffCountries;
use App\Services\Staff\StaffCountryScope;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\AssertsNarrowedOrderReads;
use Tests\Support\BuildsStaffReportWorld;
use Tests\Support\RendersNoViews;
use Tests\Support\RestoresLanguageFiles;
use Tests\Support\StaffProbe\UnlistedPeopleController;
use Tests\TestCase;

/**
 * What a restricted employee is shown by the screens of the customers, the delivery men and the vendors, which list orders without managing them (GCC-14c, slice 3): the orders
 * and refund requests of one customer, the orders, earnings and status history of a delivery man, the orders and money records of a vendor, and an order read by its number
 * outside the order screens. Customers, delivery men and vendors belong to no country, so their own lists are not narrowed; only what they list about orders is.
 *
 * The requests go through the real route table, the real sign-in check and module permission, the real controllers and repositories; the pages render to nothing and the test reads
 * what the controller handed them. Employee 9 holds the people, report and order screens and works with Kuwait only; employee 1 is the master admin. Products: 1 SA, 2 KW, 3 KW,
 * 4 AE. Orders of the customer 1, the delivery man 1 and the vendor 5: 101 SA, 102 KW, 103 KW (two lines, delivered), 104 SA and KW, 105 without a line, 109 AE.
 */
class StaffPeopleScreensTest extends TestCase
{
    use AssertsNarrowedOrderReads;
    use BuildsStaffReportWorld;
    use RestoresLanguageFiles;
    use RendersNoViews;

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();

        Http::preventStrayRequests();
        Event::fake();
        Excel::fake();
        $this->renderNoViews();
        $this->withoutMiddleware([
            ActivationCheckMiddleware::class, Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
        $this->buildStaffReportTables();
        $this->primeTaxSystemCache();
        $this->answerNoCustomers();

        $this->insertStaffProduct(id: 1, country: 'SA');
        $this->insertStaffProduct(id: 2, country: 'KW');
        $this->insertStaffProduct(id: 3, country: 'KW');
        $this->insertStaffProduct(id: 4, country: 'AE');
        $ofTheVendor = ['seller_is' => 'seller', 'seller_id' => 5, 'delivery_man_id' => 1, 'order_group_id' => 'G1'];
        $this->insertStaffOrder(id: 101, products: [1], extra: $ofTheVendor);
        $this->insertStaffOrder(id: 102, products: [2], extra: $ofTheVendor);
        $this->insertStaffOrder(id: 103, products: [2, 3], extra: $ofTheVendor + ['order_status' => 'delivered']);
        $this->insertStaffOrder(id: 104, products: [1, 2], extra: $ofTheVendor);
        $this->insertStaffOrder(id: 105, products: [], extra: $ofTheVendor);
        $this->insertStaffOrder(id: 109, products: [4], extra: $ofTheVendor);
        foreach ([101, 102, 103, 104, 105, 109] as $order) {
            $this->insertStaffOrderTransaction(id: 1000 + $order, orderId: $order, extra: ['seller_id' => 5, 'seller_is' => 'seller']);
        }
        $this->insertStaffRefund(id: 1, orderId: 101, aboutProduct: 1, extra: ['customer_id' => 1, 'status' => 'refunded']);
        $this->insertStaffRefund(id: 2, orderId: 102, aboutProduct: 2, extra: ['customer_id' => 1, 'status' => 'refunded']);
        $this->insertStaffRefund(id: 3, orderId: 104, aboutProduct: 1, extra: ['customer_id' => 1, 'status' => 'refunded']);
        DB::table('sellers')->insert(['id' => 5, 'status' => 'approved']);
        DB::table('shops')->insert(['id' => 5, 'seller_id' => 5, 'name' => 'Shop five']);
        DB::table('deliveryman_wallets')->insert(['delivery_man_id' => 1, 'current_balance' => 50]);
    }

    private function asKuwaitEmployee(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 9, countries: ['KW']);
        $this->signInAsStaff(adminId: 9);
    }

    /** @return list<int> */
    private function idsOf(mixed $orders): array
    {
        $ids = collect($orders instanceof \Illuminate\Pagination\AbstractPaginator ? $orders->items() : $orders)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        sort($ids);

        return $ids;
    }

    private function viewOf(TestResponse $response, string $key): mixed
    {
        $response->assertOk();

        return $response->viewData($key);
    }

    private const EVERY_ORDER = [101, 102, 103, 104, 105, 109];

    private const KUWAIT = [102, 103];

    /** @return array<string, array{0: string}> the routes of the people screens that read orders */
    public static function routesThatReadOrders(): array
    {
        $routes = [
            '/admin/customer/list', '/admin/customer/view/1', '/admin/customer/export', '/admin/customer/order-list-export/1',
            '/admin/delivery-man/list', '/admin/delivery-man/export', '/admin/delivery-man/order-wise-earning/1', '/admin/delivery-man/order-list-by-filer/1',
            '/admin/delivery-man/order-history-log/1', '/admin/delivery-man/order-history-log-export/1', '/admin/delivery-man/ajax-order-status-history/102',
            '/admin/vendors/list', '/admin/vendors/export', '/admin/vendors/order-list-export/5',
            '/admin/vendors/view/5', '/admin/vendors/view/5/order', '/admin/vendors/view/5/transaction',
        ];

        return array_combine($routes, array_map(static fn (string $route): array => [$route], $routes));
    }

    /** @dataProvider routesThatReadOrders */
    #[\PHPUnit\Framework\Attributes\DataProvider('routesThatReadOrders')]
    public function test_no_order_is_read_without_the_countries_of_a_restricted_employee_and_nothing_is_narrowed_for_the_master_admin(string $route): void
    {
        $this->asKuwaitEmployee();
        $restricted = $this->statementsOf(fn () => $this->get($route)->assertOk());
        $this->signInAsStaff(adminId: 1);
        $master = $this->statementsOf(fn () => $this->get($route)->assertOk());

        $this->assertNoOrderReadEscapes($restricted, $route);
        $this->assertNothingIsNarrowed($master, $route . ' as the master admin');
    }

    // ---- the customer ----------------------------------------------------------------------------------------------------------------

    public function test_the_screen_of_a_customer_lists_the_orders_and_counts_the_refund_requests_of_the_employees_countries(): void
    {
        $this->asKuwaitEmployee();

        $page = $this->get('/admin/customer/view/1');

        $this->assertSame(self::KUWAIT, $this->idsOf($this->viewOf($page, 'orders')));
        $this->assertSame(2, $page->viewData('orderStatusArray')['total_order']);
        $this->assertSame(1, $page->viewData('orderStatusArray')['refunded'], 'only the refund request about a line of their country');
        $this->signInAsStaff(adminId: 1);
        $master = $this->get('/admin/customer/view/1');
        $this->assertSame(self::EVERY_ORDER, $this->idsOf($master->viewData('orders')));
        $this->assertSame([6, 3], [$master->viewData('orderStatusArray')['total_order'], $master->viewData('orderStatusArray')['refunded']]);
    }

    public function test_the_export_of_the_orders_of_a_customer_holds_the_employees_orders_only(): void
    {
        $this->asKuwaitEmployee();
        $this->get('/admin/customer/order-list-export/1')->assertOk();

        Excel::assertDownloaded('Customer-Order-List.xlsx', function (CustomerOrderListExport $export): bool {
            $this->assertSame(self::KUWAIT, $this->idsOf($export->view()->getData()['data']['orders']));

            return true;
        });
    }

    public function test_the_list_of_customers_counts_the_orders_a_customer_has_in_the_employees_countries(): void
    {
        $this->asKuwaitEmployee();

        $customers = $this->viewOf($this->get('/admin/customer/list'), 'customers');

        $this->assertSame(self::KUWAIT, $this->idsOf($customers->first()->orders));
    }

    // ---- the delivery man ------------------------------------------------------------------------------------------------------------

    public function test_the_orders_of_a_delivery_man_are_the_employees_orders_on_every_screen_and_export_that_lists_them(): void
    {
        $this->asKuwaitEmployee();

        $this->assertSame(self::KUWAIT, $this->idsOf($this->viewOf($this->get('/admin/delivery-man/order-wise-earning/1'), 'orders')));
        $this->assertSame(self::KUWAIT, $this->idsOf($this->viewOf($this->get('/admin/delivery-man/order-history-log/1'), 'orders')));
        $this->assertSame(2, $this->get('/admin/delivery-man/order-list-by-filer/1')->assertOk()->json('count'));
        $this->get('/admin/delivery-man/order-history-log-export/1')->assertOk();
        Excel::assertDownloaded('Delivery-Man-Order-List.xlsx', function (DeliveryManOrderHistory $export): bool {
            $this->assertSame(self::KUWAIT, $this->idsOf($export->view()->getData()['data']['orders']));

            return true;
        });
    }

    public function test_the_screens_of_a_delivery_man_that_show_what_cannot_be_told_apart_by_country_are_unavailable_to_a_restricted_employee(): void
    {
        $this->asKuwaitEmployee();

        $this->get('/admin/delivery-man/earning-statement-overview/1')->assertNotFound();
        $this->get('/admin/delivery-man/rating/1')->assertNotFound();
        $this->signInAsStaff(adminId: 1);
        $this->get('/admin/delivery-man/earning-statement-overview/1')->assertOk();
        $this->get('/admin/delivery-man/rating/1')->assertOk();
    }

    public function test_the_status_history_of_an_order_is_read_by_its_number_only_for_an_order_of_the_employees_countries(): void
    {
        DB::table('order_status_histories')->insert([['order_id' => 101, 'status' => 'pending'], ['order_id' => 102, 'status' => 'pending']]);
        $this->asKuwaitEmployee();

        $own = $this->get('/admin/delivery-man/ajax-order-status-history/102');
        $foreign = $this->getJson('/admin/delivery-man/ajax-order-status-history/101');
        $unknown = $this->getJson('/admin/delivery-man/ajax-order-status-history/424242');

        $own->assertOk();
        $this->assertCount(1, $own->viewData('histories'));
        $this->get('/admin/delivery-man/ajax-order-status-history/104')->assertNotFound();
        $this->get('/admin/delivery-man/ajax-order-status-history/105')->assertNotFound();
        $this->assertSame(404, $foreign->getStatusCode());
        $this->assertSame($this->answer($unknown), $this->answer($foreign), 'the same answer for an order of another country and for one that does not exist');
        $this->signInAsStaff(adminId: 1);
        $this->get('/admin/delivery-man/ajax-order-status-history/101')->assertOk();
    }

    /**
     * What a client is told: the status and the body, without what APP_DEBUG adds to an error answer.
     *
     * @return array{0: int, 1: mixed}
     */
    private function answer(TestResponse $response): array
    {
        $body = json_decode((string) $response->getContent(), true);

        return [$response->getStatusCode(), is_array($body) ? Arr::except($body, ['exception', 'file', 'line', 'trace']) : $response->getContent()];
    }

    // ---- the vendor ------------------------------------------------------------------------------------------------------------------

    /** Runs what an action of a route does once the middleware of the order scope has let it in, as that action: with the orders narrowed as the rule of the route says. */
    private function insideTheAction(string $uri, callable $action): mixed
    {
        $request = Request::create($uri, 'GET');
        $route = Route::getRoutes()->match($request);
        $request->setRouteResolver(static fn () => $route);
        $route->bind($request);
        $this->app->instance('request', $request);
        $result = null;

        app(ScopeOrdersToStaffCountries::class)->handle($request, static function () use ($action, &$result) {
            $result = $action();

            return response('reached');
        });

        return $result;
    }

    public function test_an_order_is_opened_through_the_vendor_screen_only_when_it_is_the_employees_and_its_neighbours_are_theirs_too(): void
    {
        $this->asKuwaitEmployee();
        $orders = app(OrderRepositoryInterface::class);

        [$previous, $next, $linked, $customerOrders, $delivered] = $this->insideTheAction('/admin/vendors/order-details/102/5', fn (): array => [
            $orders->getPreviousFirstOrderWhere(id: 102)?->id,
            $orders->getNextFirstOrderWhere(id: 102)?->id,
            $orders->getListWhereNotIn(filters: ['order_group_id' => 'G1'], whereNotIn: ['order_group_id' => ['def-order-group'], 'id' => [102]], dataLimit: 'all')->pluck('id')->all(),
            $orders->getListWhereCount(filters: ['customer_id' => 1]),
            $orders->getListWhereCount(filters: ['seller_id' => 5, 'order_status' => 'delivered', 'order_type' => 'default_type']),
        ]);

        $this->assertNull($previous, '101 is Saudi');
        $this->assertSame(103, $next, 'the next order the employee can open');
        $this->assertSame([103], $linked, '104 is of the same checkout and holds a Saudi line');
        $this->assertSame(2, $customerOrders);
        $this->assertSame(2, $delivered, 'the count the screen shows beside the vendor is the one the repository gives, which counts the orders of the scope: 2 against 6');
    }

    public function test_an_order_of_another_country_is_not_opened_through_the_vendor_screen_whatever_it_is_named_and_is_not_touched(): void
    {
        $this->asKuwaitEmployee();

        $unknown = $this->answer($this->getJson('/admin/vendors/order-details/424242/5'));
        foreach ([101, 104, 105] as $order) {
            $foreign = $this->getJson('/admin/vendors/order-details/' . $order . '/5');

            $this->assertSame(404, $foreign->getStatusCode(), 'order ' . $order);
            $this->assertSame($unknown, $this->answer($foreign), 'order ' . $order . ': the same answer as for one that does not exist');
        }
        $this->assertSame(['0.00', '0.00'], [number_format((float) DB::table('orders')->where('id', 101)->value('init_order_amount'), 2), number_format((float) DB::table('orders')->where('id', 104)->value('init_order_amount'), 2)], 'the screen repairs the first amount of an order it opens, and it did not open these');
    }

    public function test_an_order_of_the_employees_countries_passes_the_scope_on_the_vendor_screen_and_every_order_does_for_who_is_not_restricted(): void
    {
        $this->asKuwaitEmployee();
        $reached = [];
        foreach ([102, 103, 101, 104, 105, 424242] as $order) {
            $request = Request::create('/admin/vendors/order-details/' . $order . '/5', 'GET');
            $route = Route::getRoutes()->match($request);
            $request->setRouteResolver(static fn () => $route);
            $route->bind($request);
            $this->app->instance('request', $request);
            try {
                app(ScopeOrdersToStaffCountries::class)->handle($request, static fn () => response('reached'));
                $reached[] = $order;
            } catch (NotFoundHttpException) {
            }
        }
        $this->assertSame([102, 103], $reached);

        $this->signInAsStaff(adminId: 1);
        $this->app->instance('request', Request::create('/admin/vendors/order-details/424242/5', 'GET'));
        $this->assertTrue(app(StaffCountryScope::class)->coversEveryOrder(orderIds: [101, 424242]), 'the master admin\'s unknown order is the screen\'s to answer, as it always was');
    }

    public function test_the_orders_and_the_money_records_of_a_vendor_are_the_employees_only_on_every_tab_and_export(): void
    {
        $this->asKuwaitEmployee();

        $orders = $this->get('/admin/vendors/view/5/order');
        $this->assertSame(self::KUWAIT, $this->idsOf($this->viewOf($orders, 'orders')));
        $this->assertSame(['pending_order' => 1, 'delivered_order' => 1], array_filter($orders->viewData('allOrdersInfo'), static fn ($count): bool => $count > 0));

        $transactions = $this->get('/admin/vendors/view/5/transaction');
        $this->assertSame([1102, 1103], $this->idsOf($this->viewOf($transactions, 'transactions')));

        $this->assertSame(2, $this->viewOf($this->get('/admin/vendors/view/5'), 'seller')->orders_count);
        $this->get('/admin/vendors/order-list-export/5')->assertOk();
        Excel::assertDownloaded('Order-List.xlsx', function (VendorOrderListExport $export): bool {
            $this->assertSame(self::KUWAIT, $this->idsOf($export->view()->getData()['data']['orders']));

            return true;
        });
    }

    public function test_the_list_of_vendors_counts_the_orders_a_vendor_has_in_the_employees_countries(): void
    {
        $this->asKuwaitEmployee();

        $this->assertSame(2, $this->viewOf($this->get('/admin/vendors/list'), 'vendors')->first()->orders_count);
    }

    public function test_a_value_that_is_not_an_id_is_refused_on_the_two_routes_that_read_an_order_by_its_number_whatever_the_database_would_make_of_it(): void
    {
        $this->asKuwaitEmployee();
        $unknown = $this->answer($this->getJson('/admin/vendors/order-details/424242/5'));

        foreach (['102abc', '0102', '1e2', '-102', '102.0', '%20102', '102%20', '1%2C2', '0'] as $junk) {
            $vendor = $this->getJson('/admin/vendors/order-details/' . $junk . '/5');
            $history = $this->getJson('/admin/delivery-man/ajax-order-status-history/' . $junk);

            $this->assertSame($unknown, $this->answer($vendor), 'vendors/order-details/' . $junk);
            $this->assertSame($unknown, $this->answer($history), 'ajax-order-status-history/' . $junk);
        }
    }

    public function test_the_answer_for_an_order_that_does_not_exist_is_what_it_always_was_for_who_is_not_restricted(): void
    {
        $this->signInAsStaff(adminId: 1);
        $this->switchStaffScopesOn();

        $this->get('/admin/vendors/order-details/424242/5')->assertRedirect();
        $this->get('/admin/delivery-man/ajax-order-status-history/424242')->assertOk();
    }

    public function test_a_route_of_a_covered_people_controller_that_has_no_rule_is_refused_to_a_restricted_employee_and_open_to_the_rest(): void
    {
        Route::get('admin/customer/zz-unlisted', [UnlistedPeopleController::class, 'unlisted'])->middleware(['web', 'admin', 'module:people']);
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 9, countries: ['KW']);

        $this->signInAsStaff(adminId: 9)->getJson('/admin/customer/zz-unlisted')->assertNotFound();
        $this->signInAsStaff(adminId: 1)->getJson('/admin/customer/zz-unlisted')->assertOk()->assertJson(['reached' => 'unlisted']);
        DB::table('admin_country_scopes')->where('admin_id', 9)->delete();
        $this->signInAsStaff(adminId: 9)->getJson('/admin/customer/zz-unlisted')->assertOk();
    }

    // ---- what cannot be told apart by country ----------------------------------------------------------------------------------------

    public function test_the_wallet_cards_of_a_delivery_man_and_of_a_vendor_are_left_out_of_their_pages_for_a_restricted_employee(): void
    {
        $earning = (string) file_get_contents(resource_path('views/admin-views/delivery-man/earning-statement/earning.blade.php'));
        $vendor = (string) file_get_contents(resource_path('views/admin-views/vendor/view.blade.php'));

        $guard = preg_quote('@unless (app(\\App\\Services\\Staff\\StaffCountryScope::class)->restricts())', '/');
        $this->assertMatchesRegularExpression('/' . $guard . '\s*<div class="card mb-3">(?:(?!@endunless).)*total_earning(?:(?!@endunless).)*cash_in_hand(?:(?!@endunless).)*@endunless/s', $earning);
        $this->assertStringContainsString("@if (\$seller['status'] != 'pending' && !app(\\App\\Services\\Staff\\StaffCountryScope::class)->restricts())", $vendor);
        $this->assertSame(1, substr_count($vendor, 'vendor_Wallet'), 'the one wallet card of the page is the one that is guarded');
    }

    // ---- who is not narrowed ---------------------------------------------------------------------------------------------------------

    public function test_the_master_admin_an_employee_with_no_country_and_the_switch_off_see_every_order_on_these_screens(): void
    {
        $this->giveCountries(adminId: 9, countries: ['KW']);
        $cases = ['switch off, employee with a country' => [false, 9], 'switch on, master admin' => [true, 1]];
        foreach ($cases as $label => [$switchOn, $adminId]) {
            config(['gcc.staff.country_scopes' => $switchOn]);
            $this->signInAsStaff(adminId: $adminId);

            $this->assertSame(self::EVERY_ORDER, $this->idsOf($this->viewOf($this->get('/admin/customer/view/1'), 'orders')), $label);
            $this->assertSame(self::EVERY_ORDER, $this->idsOf($this->viewOf($this->get('/admin/delivery-man/order-wise-earning/1'), 'orders')), $label);
            $this->assertSame(self::EVERY_ORDER, $this->idsOf($this->viewOf($this->get('/admin/vendors/view/5/order'), 'orders')), $label);
            $this->get('/admin/delivery-man/rating/1')->assertOk();
        }

        DB::table('admin_country_scopes')->where('admin_id', 9)->delete();
        $this->switchStaffScopesOn();
        $this->signInAsStaff(adminId: 9);
        $this->assertSame(self::EVERY_ORDER, $this->idsOf($this->viewOf($this->get('/admin/customer/view/1'), 'orders')), 'no country, so no restriction');
    }
}
