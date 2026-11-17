<?php

namespace Tests\Feature\StaffScope;

use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Exports\OrderExport;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Http\Middleware\ScopeOrdersToStaffCountries;
use App\Models\Order;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Support\BuildsStaffOrderWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * What a restricted employee is shown by the order screens, and what they can change there (GCC-14c, slice 2): the lists, the counters, the exports and the neighbours of an
 * order show the orders whose every line is a product of their countries and nothing else, and the actions work on those orders and on no other.
 *
 * The requests go through the real route table, the real sign-in check and module permission, the real controllers and the real repositories; only the markup of the pages is a
 * test stand-in that prints the ids the controller handed it. Employee 7 holds orders and the POS and works with Kuwait only, employee 8 holds the POS only, employee 1 is the
 * master admin. Products: 1 SA, 2 KW, 3 KW, 4 AE. Orders: 101 SA, 102 KW, 103 KW (POS, delivered), 104 SA and KW, 105 without a line, 109 AE (confirmed).
 */
class StaffOrderScreensTest extends TestCase
{
    use BuildsStaffOrderWorld;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        Http::preventStrayRequests();
        Event::fake();
        View::getFinder()->prependLocation(base_path('tests/Support/staff-views'));
        $this->withoutMiddleware([
            ActivationCheckMiddleware::class, Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);

        $this->buildStaffOrderTables();
        $this->primeTaxSystemCache();
        $this->insertStaffProduct(id: 1, country: 'SA');
        $this->insertStaffProduct(id: 2, country: 'KW');
        $this->insertStaffProduct(id: 3, country: 'KW');
        $this->insertStaffProduct(id: 4, country: 'AE');
        $this->insertStaffOrder(id: 101, products: [1], extra: ['transaction_ref' => 'NEEDLE']);
        $this->insertStaffOrder(id: 102, products: [2], extra: ['order_group_id' => 'G1']);
        $this->insertStaffOrder(id: 103, products: [2, 3], extra: ['order_group_id' => 'G1', 'order_type' => 'POS', 'order_status' => 'delivered']);
        $this->insertStaffOrder(id: 104, products: [1, 2], extra: ['order_group_id' => 'G1']);
        $this->insertStaffOrder(id: 105, products: []);
        $this->insertStaffOrder(id: 109, products: [4], extra: ['order_status' => 'confirmed']);

        $this->answerNoCustomers();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** Employee 7 works with Kuwait only. */
    private function asKuwaitEmployee(): static
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 7, countries: ['KW']);

        return $this->signInAsStaff(adminId: 7);
    }

    /** @return list<int> the ids of the orders a stand-in page printed, which must have been a success (the page of an error holds no id and would pass for an empty list) */
    private function ordersOf(TestResponse $page): array
    {
        $page->assertOk();
        preg_match_all('/\[order:(\d+)\]/', (string) $page->getContent(), $matches);

        return array_map('intval', $matches[1]);
    }

    /** @return array<string, int> the counters a stand-in page printed */
    private function countersOf(TestResponse $page): array
    {
        $page->assertOk();
        preg_match('/\[counts ([^\]]+)\]/', (string) $page->getContent(), $counts);
        parse_str(str_replace(' ', '&', $counts[1]), $parsed);

        return array_map('intval', $parsed);
    }

    private function checkedOrders(): array
    {
        return DB::table('orders')->where('checked', 1)->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    // ---- the lists ---------------------------------------------------------------------------------------------------------------

    public function test_the_order_list_shows_a_restricted_employee_the_orders_whose_every_line_is_a_product_of_their_country(): void
    {
        $response = $this->asKuwaitEmployee()->get('/admin/orders/list/all');

        $this->assertSame([103, 102], $this->ordersOf($response), 'newest first; 104 holds a Saudi line too, 105 has no line');
    }

    public function test_a_list_per_status_is_narrowed(): void
    {
        $this->asKuwaitEmployee();

        $this->assertSame([102], $this->ordersOf($this->get('/admin/orders/list/pending')));
        $this->assertSame([103], $this->ordersOf($this->get('/admin/orders/list/delivered')));
        $this->assertSame([], $this->ordersOf($this->get('/admin/orders/list/confirmed')), '109 is confirmed, and Emirati');
        $this->assertSame([103, 102], $this->ordersOf($this->get('/admin/orders/list/all?order_current_status[]=pending&order_current_status[]=delivered')));
    }

    public function test_the_filters_cannot_widen_the_list_past_the_scope(): void
    {
        $this->asKuwaitEmployee();

        $this->assertSame([103], $this->ordersOf($this->get('/admin/orders/list/all?order_types[]=POS')));
        $this->assertSame([103], $this->ordersOf($this->get('/admin/orders/list/all?filter=POS')));
        $this->assertSame([103, 102], $this->ordersOf($this->get('/admin/orders/list/all?seller_id=all&customer_id=1')));
        $this->assertSame([103, 102], $this->ordersOf($this->get('/admin/orders/list/all?payment_status[]=unpaid')));
        $this->assertSame([], $this->ordersOf($this->get('/admin/orders/list/all?payment_status[]=paid')));
        $this->assertSame([], $this->ordersOf($this->get('/admin/orders/list/all?delivery_man_id=77')));
    }

    public function test_the_search_cannot_bring_back_an_order_of_another_country(): void
    {
        $this->asKuwaitEmployee();

        $this->assertSame([], $this->ordersOf($this->get('/admin/orders/list/all?searchValue=NEEDLE')), 'the transaction reference of a Saudi order finds nothing');
        $this->assertSame([], $this->ordersOf($this->get('/admin/orders/list/all?searchValue=101')), 'nor does its number');
        $this->assertSame([102], $this->ordersOf($this->get('/admin/orders/list/all?searchValue=102')));
        $this->assertSame([101], $this->ordersOf($this->signInAsStaff(adminId: 1)->get('/admin/orders/list/all?searchValue=NEEDLE')), 'the control: the order is there to be found');
    }

    public function test_the_counters_of_the_list_count_the_orders_the_employee_sees_only(): void
    {
        $this->asKuwaitEmployee();

        $kuwait = $this->countersOf($this->get('/admin/orders/list/all'));
        $master = $this->countersOf($this->signInAsStaff(adminId: 1)->get('/admin/orders/list/all'));

        $this->assertSame(['pending' => 1, 'confirmed' => 0, 'processing' => 0, 'out_for_delivery' => 0, 'delivered' => 1, 'canceled' => 0, 'returned' => 0, 'failed' => 0], $kuwait);
        $this->assertSame(['pending' => 4, 'confirmed' => 1, 'processing' => 0, 'out_for_delivery' => 0, 'delivered' => 1, 'canceled' => 0, 'returned' => 0, 'failed' => 0], $master, 'the control: every order, whatever it holds');
    }

    public function test_opening_the_list_marks_as_seen_only_the_orders_the_employee_can_see(): void
    {
        $this->asKuwaitEmployee()->get('/admin/orders/list/all')->assertOk();

        $this->assertSame([102, 103], $this->checkedOrders(), 'the new-order flag of an order of another country stays for the employees who can see it');

        $this->signInAsStaff(adminId: 1)->get('/admin/orders/list/all')->assertOk();

        $this->assertSame([101, 102, 103, 104, 105, 109], $this->checkedOrders());
    }

    // ---- the exports ---------------------------------------------------------------------------------------------------------------

    public function test_the_export_holds_only_the_orders_of_the_country_and_counts_them(): void
    {
        Excel::fake();

        $this->asKuwaitEmployee()->get('/admin/orders/export-excel/all')->assertOk();

        Excel::assertDownloaded('Orders.xlsx', function (OrderExport $export): bool {
            $data = $export->view()->getData()['data'];
            $this->assertEqualsCanonicalizing([102, 103], $data['orders']->pluck('id')->all());
            $this->assertSame(1, $data['status_array']['pending']);
            $this->assertSame(1, $data['status_array']['delivered']);
            $this->assertSame(0, $data['status_array']['confirmed']);

            return true;
        });
    }

    public function test_the_export_of_one_status_is_narrowed_too(): void
    {
        Excel::fake();

        $this->asKuwaitEmployee()->get('/admin/orders/export-excel/pending?searchValue=NEEDLE')->assertOk();

        Excel::assertDownloaded('Orders.xlsx', function (OrderExport $export): bool {
            $this->assertSame([], $export->view()->getData()['data']['orders']->pluck('id')->all());

            return true;
        });
    }

    // ---- who is not narrowed ---------------------------------------------------------------------------------------------------------

    public function test_the_master_admin_sees_every_order_whatever_it_holds(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 1, countries: ['KW']);

        $this->assertSame([109, 105, 104, 103, 102, 101], $this->ordersOf($this->signInAsStaff(adminId: 1)->get('/admin/orders/list/all')));
    }

    public function test_an_employee_with_no_country_sees_every_order_so_turning_the_switch_on_changes_nothing(): void
    {
        $this->switchStaffScopesOn();

        $this->assertSame([109, 105, 104, 103, 102, 101], $this->ordersOf($this->signInAsStaff(adminId: 7)->get('/admin/orders/list/all')));
    }

    public function test_with_the_switch_off_an_employee_who_has_countries_sees_every_order(): void
    {
        $this->giveCountries(adminId: 7, countries: ['KW']);

        $this->assertSame([109, 105, 104, 103, 102, 101], $this->ordersOf($this->signInAsStaff(adminId: 7)->get('/admin/orders/list/all')));
    }

    public function test_an_employee_restricted_to_nothing_sees_no_order(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 7, countries: ['zz']);

        $this->signInAsStaff(adminId: 7);

        $this->assertSame([], $this->ordersOf($this->get('/admin/orders/list/all')));
        $this->assertSame(array_fill_keys(['pending', 'confirmed', 'processing', 'out_for_delivery', 'delivered', 'canceled', 'returned', 'failed'], 0), $this->countersOf($this->get('/admin/orders/list/all')));
    }

    public function test_a_scope_assigned_between_two_requests_applies_to_the_second(): void
    {
        $this->switchStaffScopesOn();
        $this->signInAsStaff(adminId: 7);

        $this->assertCount(6, $this->ordersOf($this->get('/admin/orders/list/all')));

        $this->giveCountries(adminId: 7, countries: ['AE']);

        $this->assertSame([109], $this->ordersOf($this->get('/admin/orders/list/all')));
    }

    public function test_the_list_asks_the_database_the_same_questions_with_the_switch_off_as_for_an_employee_who_is_not_restricted(): void
    {
        $this->giveCountries(adminId: 7, countries: ['KW']);
        $queriesOf = function (int $adminId): array {
            $this->signInAsStaff(adminId: $adminId)->get('/admin/orders/list/all')->assertOk();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/admin/orders/list/all')->assertOk();

            return array_column(DB::getQueryLog(), 'query');
        };

        $withCountriesButOff = $queriesOf(7);
        $master = $queriesOf(1);

        $this->assertSame($master, $withCountriesButOff);
        $this->assertSame([], array_filter($withCountriesButOff, static fn (string $query): bool => str_contains($query, 'admin_country_scopes') || str_contains($query, 'visible_lines')));
    }

    public function test_a_narrowed_list_costs_the_same_number_of_queries_whatever_the_number_of_orders(): void
    {
        $this->asKuwaitEmployee();
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/admin/orders/list/all')->assertOk();

            return count(DB::getQueryLog());
        };
        $queries();
        $few = $queries();

        for ($id = 300; $id < 340; $id++) {
            $this->insertStaffOrder(id: $id, products: [2, 3]);
        }

        $this->assertSame($few, $queries(), 'a subquery for the whole list, never a query per order');
    }

    // ---- what the details of an order show around it ----------------------------------------------------------------------------------

    /** Runs what an action of a route does once the middleware of the order screens has let it in, as that action: with the orders narrowed as the rule of the route says. */
    private function insideTheAction(string $method, string $uri, callable $action, array $input = []): mixed
    {
        $request = Request::create($uri, $method, $input);
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

    public function test_the_neighbours_the_group_and_the_customers_orders_of_the_details_page_are_the_ones_the_employee_can_see(): void
    {
        $this->asKuwaitEmployee();
        $orders = app(OrderRepositoryInterface::class);

        [$previous, $next, $linked, $customerOrders] = $this->insideTheAction('GET', '/admin/orders/details/102', fn (): array => [
            $orders->getPreviousFirstOrderWhere(id: 102)?->id,
            $orders->getNextFirstOrderWhere(id: 102)?->id,
            $orders->getListWhereNotIn(filters: ['order_group_id' => 'G1'], whereNotIn: ['order_group_id' => ['def-order-group'], 'id' => [102]], dataLimit: 'all')->pluck('id')->all(),
            $orders->getListWhereCount(filters: ['customer_id' => 1]),
        ]);

        $this->assertNull($previous, '101 is Saudi');
        $this->assertSame(103, $next, 'the next order the employee can open, not the next one');
        $this->assertSame([103], $linked, '104 is of the same checkout and holds a Saudi line');
        $this->assertSame(2, $customerOrders, 'the orders of the customer in this employee\'s countries: the number of the others is not for them');
    }

    public function test_the_same_page_for_an_employee_who_is_not_narrowed_sees_every_neighbour(): void
    {
        $this->switchStaffScopesOn();
        $this->signInAsStaff(adminId: 1);
        $orders = app(OrderRepositoryInterface::class);

        [$previous, $next, $linked, $customerOrders] = $this->insideTheAction('GET', '/admin/orders/details/102', fn (): array => [
            $orders->getPreviousFirstOrderWhere(id: 102)?->id,
            $orders->getNextFirstOrderWhere(id: 102)?->id,
            $orders->getListWhereNotIn(filters: ['order_group_id' => 'G1'], whereNotIn: ['order_group_id' => ['def-order-group'], 'id' => [102]], dataLimit: 'all')->pluck('id')->all(),
            $orders->getListWhereCount(filters: ['customer_id' => 1]),
        ]);

        $this->assertSame([101, 103, [103, 104], 6], [$previous, $next, $linked, $customerOrders]);
    }

    public function test_an_action_that_changes_an_order_sees_every_order_so_what_it_counts_and_numbers_stays_right(): void
    {
        $this->asKuwaitEmployee();
        $orders = app(OrderRepositoryInterface::class);

        $seen = $this->insideTheAction('POST', '/admin/orders/status', fn (): array => [
            $orders->getList()->count(),
            DB::table('orders')->count(),
            Order::query()->where('customer_id', 1)->where('order_status', 'delivered')->count(),
            $orders->getFirstWhere(params: ['id' => 109])?->id,
        ], ['id' => 102, 'order_status' => 'delivered']);

        $this->assertSame([6, 6, 1, 109], $seen, 'the number of orders gives the next POS order id, the delivered orders of a customer decide a referral bonus, a coupon counts its uses: none of that may depend on where the employee works');
    }

    // ---- what a restricted employee changes -------------------------------------------------------------------------------------------

    public function test_the_payment_status_of_an_order_of_the_country_is_changed(): void
    {
        $this->asKuwaitEmployee();

        $this->postJson('/admin/orders/payment-status', ['id' => 102, 'payment_status' => 'paid'])->assertOk()->assertJson(['status' => 1]);

        $this->assertSame('paid', DB::table('orders')->where('id', 102)->value('payment_status'));
    }

    public function test_an_order_of_another_country_is_neither_marked_paid_nor_shipped_nor_assigned_a_courier_nor_given_a_date(): void
    {
        $this->asKuwaitEmployee();

        $this->postJson('/admin/orders/payment-status', ['id' => 101, 'payment_status' => 'paid'])->assertNotFound();
        $this->post('/admin/orders/update-deliver-info', ['order_id' => 101, 'delivery_service_name' => 'Elsewhere', 'third_party_delivery_tracking_id' => 'T1'])->assertNotFound();
        $this->get('/admin/orders/add-delivery-man/101/1')->assertNotFound();
        $this->postJson('/admin/orders/amount-date-update', ['order_id' => 101, 'field_name' => 'deliveryman_charge', 'field_val' => 5])->assertNotFound();
        $this->postJson('/admin/orders/status', ['id' => 101, 'order_status' => 'delivered'])->assertNotFound();
        $this->postJson('/admin/orders/bulk-status', ['ids' => [102, 101], 'order_status' => 'confirmed'])->assertNotFound();

        $order = DB::table('orders')->where('id', 101)->first();
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertNull($order->delivery_service_name);
        $this->assertNull($order->delivery_man_id);
        $this->assertSame('0.00', number_format((float) $order->deliveryman_charge, 2));
        $this->assertSame('pending', $order->order_status);
        $this->assertSame('pending', DB::table('orders')->where('id', 102)->value('order_status'), 'the bulk change was refused whole: not even the order of their own country was touched');
    }

    public function test_a_courier_is_assigned_to_an_order_of_the_country_and_the_charge_and_the_third_party_are_recorded(): void
    {
        $this->asKuwaitEmployee();

        $this->get('/admin/orders/add-delivery-man/102/1')->assertOk()->assertJson(['status' => true]);
        $this->assertSame(1, (int) DB::table('orders')->where('id', 102)->value('delivery_man_id'));

        $this->post('/admin/orders/update-deliver-info', ['order_id' => 103, 'delivery_service_name' => 'Courier Co', 'third_party_delivery_tracking_id' => 'T9'])->assertRedirect();
        $this->assertSame('Courier Co', DB::table('orders')->where('id', 103)->value('delivery_service_name'));
        $this->assertSame('third_party_delivery', DB::table('orders')->where('id', 103)->value('delivery_type'));

        $this->postJson('/admin/orders/amount-date-update', ['order_id' => 102, 'field_name' => 'deliveryman_charge', 'field_val' => 5])->assertOk();
        $this->assertGreaterThan(0, (float) DB::table('orders')->where('id', 102)->value('deliveryman_charge'));
    }

    public function test_an_order_that_holds_a_line_of_another_country_is_not_the_employees_to_change_even_by_its_number(): void
    {
        $this->asKuwaitEmployee();

        foreach ([104, 105] as $order) {
            $this->postJson('/admin/orders/payment-status', ['id' => $order, 'payment_status' => 'paid'])->assertNotFound();
            $this->assertSame('unpaid', DB::table('orders')->where('id', $order)->value('payment_status'), 'order ' . $order);
        }
    }

    public function test_the_invoice_of_an_order_of_another_country_is_not_printed_and_needs_no_module_permission_to_be_refused(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 8, countries: ['KW']);
        $this->signInAsStaff(adminId: 8);

        $this->get('/admin/orders/generate-invoice/101')->assertNotFound();
        $this->get('/admin/orders/generate-invoice/424242')->assertNotFound();
    }

    // ---- the layout every admin page shares ---------------------------------------------------------------------------------------------

    /**
     * What the layout of the admin panel shows of the orders: the counters of the sidebar and the latest pending orders of the header, read the way the layout reads them,
     * inside a request that is bound as the middleware of a screen binds it.
     *
     * @return array{orders: array<string, int>, refunds: array<string, int>, latest: list<int>}
     */
    private function theLayoutShows(string $method, string $uri, array $input = []): array
    {
        Schema::dropIfExists('contacts');
        Schema::create('contacts', function ($table) {
            $table->id();
            $table->boolean('seen')->default(false);
        });
        $provider = $this->app->getProvider(AppServiceProvider::class);
        (new \ReflectionMethod($provider, 'adminSidebarLayoutCount'))->invoke($provider);

        return $this->insideTheAction($method, $uri, function (): array {
            $view = view('layouts.admin.app');
            app('view')->callComposer($view);

            return [
                'orders' => $view->getData()['sidebarOrderCounts']->all(),
                'refunds' => $view->getData()['sidebarRefundCounts']->all(),
                'latest' => Order::query()->where('order_status', 'pending')->orderByDesc('id')->take(5)->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            ];
        }, $input);
    }

    public function test_the_counters_and_the_latest_orders_of_the_shared_layout_follow_the_screens_that_bound_the_employee(): void
    {
        $this->insertStaffRefund(id: 1, orderId: 101, aboutProduct: 1);
        $this->insertStaffRefund(id: 2, orderId: 102, aboutProduct: 2);
        $this->insertStaffRefund(id: 3, orderId: 104, aboutProduct: 1);
        $this->asKuwaitEmployee();

        $kuwait = $this->theLayoutShows('GET', '/admin/orders/list/all');
        $this->signInAsStaff(adminId: 1);
        $master = $this->theLayoutShows('GET', '/admin/orders/list/all');

        $this->assertSame(['delivered' => 1, 'pending' => 1], $kuwait['orders']);
        $this->assertSame(['pending' => 1], $kuwait['refunds']);
        $this->assertSame([102], $kuwait['latest'], 'no thumbnail and no number of an order of another country in the header');
        $this->assertSame(['confirmed' => 1, 'delivered' => 1, 'pending' => 4], $master['orders']);
        $this->assertSame(['pending' => 3], $master['refunds']);
        $this->assertSame([105, 104, 102, 101], $master['latest']);
    }

    public function test_the_layout_of_a_screen_that_bound_nobody_counts_everything_as_it_always_did(): void
    {
        $this->giveCountries(adminId: 7, countries: ['KW']);
        $this->signInAsStaff(adminId: 7);

        $withTheSwitchOff = $this->theLayoutShows('GET', '/admin/orders/list/all');

        $this->assertSame(['confirmed' => 1, 'delivered' => 1, 'pending' => 4], $withTheSwitchOff['orders']);
    }
}
