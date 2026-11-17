<?php

namespace Tests\Feature\StaffScope;

use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Maatwebsite\Excel\Facades\Excel;
use Mockery;
use Tests\Support\AssertsNarrowedOrderReads;
use Tests\Support\BuildsStaffReportWorld;
use Tests\Support\RendersNoViews;
use Tests\TestCase;

/**
 * What a restricted employee is shown by the dashboard and the reports (GCC-14c, slice 3): every number counts the orders of their countries only, so a restricted employee's
 * figures are the figures an unrestricted request gives over a shop that holds nothing else; what cannot be told apart by country (the wallets) is not shown to them.
 *
 * The requests go through the real route table, the real sign-in check and module permission, the real controllers and repositories; the pages render to nothing and the test reads
 * what the controllers handed them and the statements they ran. Employee 9 holds the people, report and order screens and the dashboard and works with Kuwait only; employee 1
 * is the master admin. Products: 1 SA, 2 KW, 3 KW, 4 AE. Orders: 101 SA, 102 KW, 103 KW (two lines, delivered, paid, a vendor's, with an in-house coupon), 104 SA and KW, 105 without a line,
 * 109 AE (delivered, paid, a vendor's, with an in-house coupon).
 */
class StaffReportScreensTest extends TestCase
{
    use AssertsNarrowedOrderReads;
    use BuildsStaffReportWorld;
    use RendersNoViews;

    protected function setUp(): void
    {
        parent::setUp();

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
        $this->teachSqliteTheDateFunctionsOfMysql();
        $this->answerNoTopRatedProducts();

        DB::table('shops')->insert(['id' => 1, 'seller_id' => 0, 'author_type' => 'admin', 'name' => 'In-house']);
        $this->insertStaffProduct(id: 1, country: 'SA');
        $this->insertStaffProduct(id: 2, country: 'KW');
        $this->insertStaffProduct(id: 3, country: 'KW');
        $this->insertStaffProduct(id: 4, country: 'AE');
        DB::table('products')->update(['created_at' => now(), 'updated_at' => now()]);
        $delivered = ['order_status' => 'delivered', 'payment_status' => 'paid', 'seller_is' => 'seller', 'seller_id' => 5, 'coupon_code' => 'SAVE', 'coupon_discount_bearer' => 'inhouse', 'discount_amount' => 3, 'refer_and_earn_discount' => 1];
        $this->insertStaffOrder(id: 101, products: [1], extra: ['order_amount' => 100]);
        $this->insertStaffOrder(id: 102, products: [2], extra: ['order_amount' => 20]);
        $this->insertStaffOrder(id: 103, products: [2, 3], extra: $delivered + ['order_amount' => 30]);
        $this->insertStaffOrder(id: 104, products: [1, 2], extra: ['order_amount' => 400]);
        $this->insertStaffOrder(id: 105, products: [], extra: ['order_amount' => 5000]);
        $this->insertStaffOrder(id: 109, products: [4], extra: $delivered + ['order_amount' => 90000]);
        foreach ([101 => 100, 102 => 20, 103 => 30, 104 => 400, 105 => 5000, 109 => 90000] as $order => $amount) {
            $this->insertStaffOrderTransaction(id: 1000 + $order, orderId: $order, extra: ['order_amount' => $amount, 'seller_amount' => $amount, 'admin_commission' => 0, 'seller_is' => 'admin', 'status' => 'disburse']);
        }
        $this->insertStaffRefund(id: 1, orderId: 101, aboutProduct: 1);
        $this->insertStaffRefund(id: 2, orderId: 102, aboutProduct: 2);
        $this->insertStaffRefund(id: 3, orderId: 104, aboutProduct: 1);
        $this->insertStaffRefundTransaction(id: 1, orderId: 101, aboutProduct: 1, extra: ['amount' => 7]);
        $this->insertStaffRefundTransaction(id: 2, orderId: 102, aboutProduct: 2, extra: ['amount' => 11]);
        DB::table('admin_wallets')->insert(['admin_id' => 1, 'inhouse_earning' => 123456, 'commission_earned' => 777, 'delivery_charge_earned' => 55, 'pending_amount' => 9, 'total_tax_collected' => 3]);
        DB::table('sellers')->insert(['id' => 5, 'status' => 'approved']);
        DB::table('seller_wallets')->insert(['seller_id' => 5, 'total_earning' => 8888]);
    }

    /** The "top rated" lists of the dashboard group by a column that a query without an aggregate may not name on SQLite; they are product lists, not what is under test. */
    private function answerNoTopRatedProducts(): void
    {
        $products = Mockery::mock(app(ProductRepositoryInterface::class))->makePartial();
        $products->shouldReceive('getTopRatedList')->andReturn(new EloquentCollection());
        $this->app->instance(ProductRepositoryInterface::class, $products);
    }

    private function asKuwaitEmployee(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 9, countries: ['KW']);
        $this->signInAsStaff(adminId: 9);
    }

    private function visit(string $method, string $uri): TestResponse
    {
        $response = $this->call($method, $uri);
        $this->assertTrue($response->isSuccessful(), $method . ' ' . $uri . ' answered ' . $response->getStatusCode() . ' ' . (($response->baseResponse->exception ?? null)?->getMessage() ?? ""));

        return $response;
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function screens(): array
    {
        $screens = [
            ['GET', '/admin/dashboard'], ['POST', '/admin/dashboard/order-status'], ['GET', '/admin/dashboard/earning-statistics?type=yearEarn'], ['GET', '/admin/dashboard/order-statistics?type=yearEarn'],
            ['GET', '/admin/dashboard/real-time-activities'],
            ['GET', '/admin/report/order'], ['GET', '/admin/report/order-report-excel'],
            ['GET', '/admin/report/admin-earning'], ['GET', '/admin/report/admin-earning-excel-export'], ['GET', '/admin/report/vendor-earning-excel-export'],
            ['GET', '/admin/transaction/order-transaction-list'], ['GET', '/admin/transaction/order-transaction-export-excel'],
            ['GET', '/admin/transaction/expense-transaction-list'], ['GET', '/admin/transaction/expense-transaction-export-excel'],
            ['GET', '/admin/report/transaction/refund-transaction-list'], ['GET', '/admin/report/transaction/refund-transaction-export'],
            ['GET', '/admin/report/vendor-report'], ['GET', '/admin/report/vendor-report-export'],
            ['GET', '/admin/report/all-product'], ['GET', '/admin/report/all-product-excel'], ['GET', '/admin/report/inhouse-product-sale'],
        ];

        return array_combine(array_map(static fn (array $screen): string => $screen[0] . ' ' . $screen[1], $screens), $screens);
    }

    /** @dataProvider screens */
    #[\PHPUnit\Framework\Attributes\DataProvider('screens')]
    public function test_no_order_is_read_without_the_countries_of_a_restricted_employee_and_nothing_is_narrowed_for_the_master_admin(string $method, string $uri): void
    {
        $this->asKuwaitEmployee();
        $restricted = $this->statementsOf(fn () => $this->visit($method, $uri));
        $this->signInAsStaff(adminId: 1);
        $master = $this->statementsOf(fn () => $this->visit($method, $uri));

        $this->assertNoOrderReadEscapes($restricted, $uri);
        $this->assertNothingIsNarrowed($master, $uri . ' as the master admin');
    }

    // ---- the figures --------------------------------------------------------------------------------------------------------------

    /** The data every page of a request was handed, by page, with what is not about the orders left out. */
    private function figuresOf(callable $request): array
    {
        $captured = [];
        View::composer('*', static function ($view) use (&$captured): void {
            if (!str_starts_with($view->name(), 'layouts.')) {
                $captured[$view->name()] = $view->getData();
            }
        });
        $request();

        return array_map(static fn (array $data): array => Arr::except((array) json_decode((string) json_encode($data), true), ['errors', '__env', 'app', 'obLevel', 'web_config', 'language']), $captured);
    }

    /** Takes every order, line, money record, refund and product that is not the employee's out of the shop, as the set logic of the rule says: the figures that remain are the ones an unrestricted request over a shop of their own would give. */
    private function pruneToTheOrdersOf(array $countries): void
    {
        $countryOfProduct = DB::table('products')->pluck('storefront_country', 'id')->all();
        $linesOfOrder = DB::table('order_details')->get()->groupBy('order_id');
        $theirs = [];
        foreach (DB::table('orders')->pluck('id') as $order) {
            $lines = $linesOfOrder->get($order, collect());
            $theirs[$order] = $lines->isNotEmpty() && $lines->every(static fn ($line): bool => in_array($countryOfProduct[$line->product_id] ?? null, $countries, true));
        }
        $foreign = array_keys(array_filter($theirs, static fn (bool $visible): bool => !$visible));
        $theirLines = DB::table('order_details')->whereNotIn('order_id', $foreign)->pluck('id')->all();
        $refunds = DB::table('refund_requests')->pluck('order_details_id', 'id');
        $refundTransactions = DB::table('refund_transactions')->pluck('order_details_id', 'id');
        $lineIsTheirs = static fn ($line): bool => $line !== null && in_array($line, $theirLines, true);

        DB::table('order_details')->whereIn('order_id', $foreign)->delete();
        DB::table('order_transactions')->whereIn('order_id', $foreign)->orWhereNull('order_id')->delete();
        DB::table('orders')->whereIn('id', $foreign)->delete();
        DB::table('refund_requests')->whereIn('id', $refunds->filter(static fn ($line): bool => !$lineIsTheirs($line))->keys())->delete();
        DB::table('refund_transactions')->whereIn('id', $refundTransactions->filter(static fn ($line): bool => !$lineIsTheirs($line))->keys())->delete();
        DB::table('products')->whereNotIn('storefront_country', $countries)->delete();
    }

    /** @return array<string, array{0: string, 1: string}> the screens whose numbers are the orders of the employee's countries and nothing else */
    public static function screensWhoseFiguresAreOrderFigures(): array
    {
        $screens = [
            ['POST', '/admin/dashboard/order-status'], ['GET', '/admin/dashboard/earning-statistics?type=yearEarn'], ['GET', '/admin/dashboard/order-statistics?type=yearEarn'],
            ['GET', '/admin/report/order'], ['GET', '/admin/report/admin-earning'], ['GET', '/admin/transaction/order-transaction-list'],
            ['GET', '/admin/transaction/expense-transaction-list'], ['GET', '/admin/report/transaction/refund-transaction-list'],
            ['GET', '/admin/report/vendor-report'], ['GET', '/admin/report/all-product'], ['GET', '/admin/report/inhouse-product-sale'],
        ];

        return array_combine(array_map(static fn (array $screen): string => $screen[0] . ' ' . $screen[1], $screens), $screens);
    }

    /** @dataProvider screensWhoseFiguresAreOrderFigures */
    #[\PHPUnit\Framework\Attributes\DataProvider('screensWhoseFiguresAreOrderFigures')]
    public function test_the_figures_of_a_restricted_employee_are_the_figures_of_an_unrestricted_request_over_their_orders_only(string $method, string $uri): void
    {
        $this->asKuwaitEmployee();
        $restricted = $this->figuresOf(fn () => $this->visit($method, $uri));
        $this->assertNotSame([], $restricted, $uri . ' handed its pages no data');
        $this->signInAsStaff(adminId: 1);
        $everything = $this->figuresOf(fn () => $this->visit($method, $uri));
        $this->assertNotEquals($everything, $restricted, $uri . ': the figures of every order are not the employee\'s, or the comparison below proves nothing');

        $this->pruneToTheOrdersOf(countries: ['KW']);
        $unrestricted = $this->figuresOf(fn () => $this->visit($method, $uri));

        $this->assertEquals($unrestricted, $restricted, $uri);
    }

    public function test_the_figures_would_differ_without_the_scope_so_the_comparison_proves_something(): void
    {
        $this->signInAsStaff(adminId: 1);
        $everything = $this->figuresOf(fn () => $this->visit('GET', '/admin/report/order'));
        $this->pruneToTheOrdersOf(countries: ['KW']);
        $theirs = $this->figuresOf(fn () => $this->visit('GET', '/admin/report/order'));

        $this->assertNotEquals($everything, $theirs);
    }

    // ---- numbers worked out from the description of the world ------------------------------------------------------------------------

    public function test_the_order_report_counts_and_sums_the_orders_of_the_employees_countries(): void
    {
        $this->asKuwaitEmployee();
        $kuwait = $this->visit('GET', '/admin/report/order');
        $this->signInAsStaff(adminId: 1);
        $master = $this->visit('GET', '/admin/report/order');

        $this->assertSame(['ongoing_order' => 1, 'canceled_order' => 0, 'delivered_order' => 1, 'total_order' => 2], $kuwait->viewData('order_count'), '102 is pending, 103 delivered');
        $this->assertEquals([20.0, 30.0], [$kuwait->viewData('due_amount'), $kuwait->viewData('settled_amount')]);
        $this->assertSame(['ongoing_order' => 4, 'canceled_order' => 0, 'delivered_order' => 2, 'total_order' => 6], $master->viewData('order_count'), 'the control: 101, 102, 104 and 105 are pending, 103 and 109 delivered');
        $this->assertEquals([5520.0, 90030.0], [$master->viewData('due_amount'), $master->viewData('settled_amount')]);
    }

    public function test_the_counters_of_the_dashboard_count_the_orders_and_products_of_the_employees_countries(): void
    {
        $this->asKuwaitEmployee();
        $kuwait = $this->figuresOf(fn () => $this->visit('POST', '/admin/dashboard/order-status', ['statistics_type' => 'overall']))['admin-views.partials._dashboard-order-status']['data'];
        $this->signInAsStaff(adminId: 1);
        $master = $this->figuresOf(fn () => $this->visit('POST', '/admin/dashboard/order-status', ['statistics_type' => 'overall']))['admin-views.partials._dashboard-order-status']['data'];

        $this->assertSame([2, 1, 1, 2], [$kuwait['order'], $kuwait['pending'], $kuwait['delivered'], $kuwait['product']], 'orders 102 and 103; products 2 and 3');
        $this->assertSame([6, 4, 2, 4], [$master['order'], $master['pending'], $master['delivered'], $master['product']]);
    }

    public function test_the_poller_of_the_header_counts_the_new_orders_of_the_employees_countries(): void
    {
        $this->asKuwaitEmployee();
        $kuwait = $this->visit('GET', '/admin/dashboard/real-time-activities')->json('new_order_count');
        $this->signInAsStaff(adminId: 1);
        $master = $this->visit('GET', '/admin/dashboard/real-time-activities')->json('new_order_count');

        $this->assertSame([2, 6], [$kuwait, $master]);
    }

    // ---- what cannot be told apart by country ---------------------------------------------------------------------------------------

    public function test_the_wallets_of_the_dashboard_are_not_shown_to_a_restricted_employee(): void
    {
        $this->asKuwaitEmployee();
        $kuwait = $this->visit('GET', '/admin/dashboard')->viewData('data');
        $this->signInAsStaff(adminId: 1);
        $master = $this->visit('GET', '/admin/dashboard')->viewData('data');

        $this->assertTrue($kuwait['wallet_figures_hidden']);
        $this->assertSame([0, 0, 0, 0, 0], [$kuwait['inhouse_earning'], $kuwait['commission_earned'], $kuwait['delivery_charge_earned'], $kuwait['pending_amount'], $kuwait['total_tax_collected']]);
        $this->assertCount(0, $kuwait['topVendorByEarning']);
        $this->assertFalse($kuwait['auction_wallet_visible']);
        $this->assertFalse($master['wallet_figures_hidden']);
        $this->assertEquals([123456, 777, 55, 9, 3], [$master['inhouse_earning'], $master['commission_earned'], $master['delivery_charge_earned'], $master['pending_amount'], $master['total_tax_collected']]);
        $this->assertCount(1, $master['topVendorByEarning'], 'the control: the vendor wallet is there to be hidden');
    }

    public function test_the_dashboard_view_hides_the_admin_wallet_card_when_its_figures_are_hidden(): void
    {
        $dashboard = (string) file_get_contents(resource_path('views/admin-views/system/dashboard.blade.php'));

        $this->assertMatchesRegularExpression("/@if\\(empty\\(\\\$data\\['wallet_figures_hidden'\\]\\)\\)\\s*<div class=\"card mb-3 remove-card-shadow\">(?:(?!@endif).)*_dashboard-wallet-stats/s", $dashboard);
    }

    public function test_the_report_of_the_wallets_of_the_vendors_is_unavailable_to_a_restricted_employee_and_the_same_for_one_that_does_not_exist(): void
    {
        $this->asKuwaitEmployee();

        $this->get('/admin/report/vendor-earning')->assertNotFound();
        $this->signInAsStaff(adminId: 1);
        $this->get('/admin/report/vendor-earning')->assertOk();
    }

    // ---- who is not narrowed ---------------------------------------------------------------------------------------------------------

    public function test_the_master_admin_an_employee_with_no_country_and_the_switch_off_see_every_order_on_the_reports_and_ask_the_same_statements(): void
    {
        $this->signInAsStaff(adminId: 1);
        $everything = $this->statementsOf(fn () => $this->visit('GET', '/admin/report/admin-earning'));
        $figures = $this->figuresOf(fn () => $this->visit('GET', '/admin/report/order'));

        $this->giveCountries(adminId: 9, countries: ['KW']);
        $this->signInAsStaff(adminId: 9);
        $switchOff = $this->statementsOf(fn () => $this->visit('GET', '/admin/report/admin-earning'));
        $this->assertSame($everything, $switchOff, 'the switch is off: the same statements, in the same order');
        $this->assertEquals($figures, $this->figuresOf(fn () => $this->visit('GET', '/admin/report/order')));

        $this->switchStaffScopesOn();
        DB::table('admin_country_scopes')->where('admin_id', 9)->delete();
        $this->assertEquals($figures, $this->figuresOf(fn () => $this->visit('GET', '/admin/report/order')), 'no country, so no restriction');
        $this->giveCountries(adminId: 1, countries: ['KW']);
        $this->signInAsStaff(adminId: 1);
        $this->assertEquals($figures, $this->figuresOf(fn () => $this->visit('GET', '/admin/report/order')), 'the master admin is never restricted');
    }
}
