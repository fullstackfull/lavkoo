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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Maatwebsite\Excel\Facades\Excel;
use Mockery;
use Tests\Support\BuildsStaffReportWorld;
use Tests\Support\RendersNoViews;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The six countries of the platform, one employee at a time, on the screens of slice 3 (GCC-14c): the order report, the counters and the earnings of the dashboard, the poller of the
 * header, the refund transaction report, and the orders and money records on the screens of a customer, a delivery man and a vendor.
 *
 * The world is built from config('gcc.storefronts.countries'): every country has a product, a delivered order and a pending order; every pair of countries has an order that holds
 * both and a refund request and a refund money record for each of its two lines; one order holds three countries and one holds all six; and five orders are unattributed (no line,
 * a deleted product, a product without a country, and two that mix a country with one of those). The amounts are chosen so that every sum names the orders it is made of. What each
 * scope should see is worked out here from that description as plain set logic, never by asking the code under test: an order is visible to a scope when it has a line and every
 * line's country is in the scope; a refund request and its money record when the country of the line they are about is.
 *
 * The scopes are the six single countries, every pair, one triple and all six, then the master admin, an employee with no country and the switch off, who see everything.
 */
class StaffSixCountryReportMatrixTest extends TestCase
{
    use BuildsStaffReportWorld;
    use RestoresLanguageFiles;
    use RendersNoViews;

    private const NO_COUNTRY_PRODUCT = 90;

    private const DELETED_PRODUCT = 99;

    private const STATUSES = ['pending', 'confirmed', 'processing', 'out_for_delivery', 'delivered', 'canceled', 'returned', 'failed'];

    /** @var list<string> */
    private array $countries;

    /** @var array<int, string> product id => country ('' for a product without one); a product that is not here was deleted */
    private array $productCountry = [];

    /** @var array<int, array{lines: list<int>, status: string, amount: int}> */
    private array $orders = [];

    /** @var array<int, array{about: int|null, amount: int}> */
    private array $refundTransactions = [];

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
        DB::table('business_settings')->where('type', 'pagination_limit')->update(['value' => '1000']);
        $this->primeTaxSystemCache();
        $this->answerNoCustomers();
        $this->teachSqliteTheDateFunctionsOfMysql();
        $products = Mockery::mock(app(ProductRepositoryInterface::class))->makePartial();
        $products->shouldReceive('getTopRatedList')->andReturn(new EloquentCollection());
        $this->app->instance(ProductRepositoryInterface::class, $products);

        DB::table('shops')->insert(['id' => 1, 'seller_id' => 0, 'author_type' => 'admin', 'name' => 'In-house']);
        $this->countries = array_map('strval', array_keys((array) config('gcc.storefronts.countries')));
        $this->buildTheWorld();
    }

    // ---- the world ---------------------------------------------------------------------------------------------------------------------

    private function productOf(int $countryIndex): int
    {
        return 10 + $countryIndex;
    }

    private function buildTheWorld(): void
    {
        foreach ($this->countries as $index => $country) {
            $this->productCountry[$this->productOf($index)] = $country;
        }
        $this->productCountry[self::NO_COUNTRY_PRODUCT] = '';
        foreach ($this->productCountry as $productId => $country) {
            $this->insertStaffProduct(id: $productId, country: $country);
        }
        DB::table('products')->update(['created_at' => now(), 'updated_at' => now()]);

        $count = count($this->countries);
        $order = function (int $id, array $lines, string $status, int $amount): void {
            $this->orders[$id] = ['lines' => $lines, 'status' => $status, 'amount' => $amount];
        };
        $next = 0;
        $status = static function () use (&$next): string {
            return self::STATUSES[$next++ % count(self::STATUSES)];
        };
        for ($i = 0; $i < $count; $i++) {
            $order(101 + $i, [$this->productOf($i)], 'delivered', 1 << $i);
            $order(201 + $i, [$this->productOf($i)], 'pending', (1 << $i) * 1000);
        }
        $pairOrders = [];
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $id = 301 + count($pairOrders);
                $pairOrders[$id] = [$i, $j];
                $order($id, [$this->productOf($i), $this->productOf($j)], $status(), 100000 * ($id - 300));
            }
        }
        $order(320, [$this->productOf(0), $this->productOf(1), $this->productOf(2)], $status(), 10000000);
        $order(330, array_map(fn (int $index): int => $this->productOf($index), range(0, $count - 1)), $status(), 20000000);
        $order(401, [], $status(), 30000000);
        $order(402, [self::DELETED_PRODUCT], $status(), 40000000);
        $order(403, [self::NO_COUNTRY_PRODUCT], $status(), 50000000);
        $order(404, [$this->productOf(2), self::DELETED_PRODUCT], $status(), 60000000);
        $order(405, [$this->productOf(0), self::NO_COUNTRY_PRODUCT], $status(), 70000000);

        foreach ($this->orders as $id => $order) {
            $paid = $order['status'] === 'delivered' ? ['payment_status' => 'paid'] : [];
            $this->insertStaffOrder(id: $id, products: $order['lines'], extra: $paid + ['order_status' => $order['status'], 'order_amount' => $order['amount'], 'customer_id' => 1, 'seller_is' => 'seller', 'seller_id' => 5, 'delivery_man_id' => 1]);
            $this->insertStaffOrderTransaction(id: 1000 + $id, orderId: $id, extra: ['order_amount' => $order['amount'], 'seller_amount' => $order['amount'], 'seller_id' => 5, 'seller_is' => 'seller', 'status' => 'disburse']);
        }

        $refundTransaction = function (int $id, int $orderId, ?int $about, int $amount): void {
            $this->refundTransactions[$id] = ['about' => $about, 'amount' => $amount];
            $this->insertStaffRefundTransaction(id: $id, orderId: $orderId, aboutProduct: $about, extra: ['amount' => $amount]);
        };
        for ($i = 0; $i < $count; $i++) {
            $refundTransaction(1 + $i, 101 + $i, $this->productOf($i), 1 + $i);
        }
        foreach ($pairOrders as $orderId => [$i, $j]) {
            $refundTransaction(100 + 2 * ($orderId - 301), $orderId, $this->productOf($i), 10);
            $refundTransaction(101 + 2 * ($orderId - 301), $orderId, $this->productOf($j), 10);
        }
        $refundTransaction(900, 402, self::DELETED_PRODUCT, 10);
        $refundTransaction(901, 401, null, 10);
        $refundTransaction(902, 403, self::NO_COUNTRY_PRODUCT, 10);
        DB::table('sellers')->insert(['id' => 5, 'status' => 'approved']);
        DB::table('shops')->insert(['id' => 5, 'seller_id' => 5, 'name' => 'Shop five']);
    }

    /** @return array<string, list<string>> the six single countries, every pair, one triple and all six, by name */
    private function scopes(): array
    {
        $scopes = [];
        foreach ($this->countries as $country) {
            $scopes[$country] = [$country];
        }
        foreach ($this->countries as $i => $first) {
            foreach (array_slice($this->countries, $i + 1) as $second) {
                $scopes[$first . '+' . $second] = [$first, $second];
            }
        }
        $scopes[implode('+', array_slice($this->countries, 0, 3))] = array_slice($this->countries, 0, 3);
        $scopes['all six'] = $this->countries;

        return $scopes;
    }

    // ---- what each scope should see, worked out from the description of the world -------------------------------------------------------

    /** @param list<string>|null $scope null: not restricted, so every order */
    private function orderIsVisible(int $orderId, ?array $scope): bool
    {
        if ($scope === null) {
            return true;
        }
        $lines = $this->orders[$orderId]['lines'];
        if ($lines === []) {
            return false;
        }
        foreach ($lines as $productId) {
            $country = $this->productCountry[$productId] ?? null;
            if ($country === null || !in_array($country, $scope, true)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string>|null $scope */
    private function refundTransactionIsVisible(int $id, ?array $scope): bool
    {
        $about = $this->refundTransactions[$id]['about'];

        return $scope === null || ($about !== null && in_array($this->productCountry[$about] ?? null, $scope, true));
    }

    /**
     * @param list<string>|null $scope
     * @return list<int> the orders a scope sees, in ascending order
     */
    private function visibleOrders(?array $scope, ?callable $where = null): array
    {
        $ids = array_values(array_filter(array_keys($this->orders), fn (int $id): bool => $this->orderIsVisible($id, $scope) && ($where === null || $where($this->orders[$id]))));
        sort($ids);

        return $ids;
    }

    /** @param list<string>|null $scope */
    private function sumOfOrders(?array $scope, ?callable $where = null): int
    {
        return array_sum(array_map(fn (int $id): int => $this->orders[$id]['amount'], $this->visibleOrders($scope, $where)));
    }

    // ---- driving the screens -------------------------------------------------------------------------------------------------------------

    /** @param list<string> $scope */
    private function signInWith(array $scope): void
    {
        $this->switchStaffScopesOn();
        DB::table('admin_country_scopes')->where('admin_id', 9)->delete();
        $this->giveCountries(adminId: 9, countries: $scope);
        $this->signInAsStaff(adminId: 9);
    }

    private function ok(TestResponse $response): TestResponse
    {
        $this->assertTrue($response->isSuccessful(), $response->getStatusCode() . ' ' . (($response->baseResponse->exception ?? null)?->getMessage() ?? ''));

        return $response;
    }

    /** @return list<int> */
    private function idsOf(mixed $rows): array
    {
        $ids = collect($rows instanceof \Illuminate\Pagination\AbstractPaginator ? $rows->items() : $rows)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        sort($ids);

        return $ids;
    }

    /** @return array<string, mixed> the data of the pages a request rendered, by page */
    private function pagesOf(callable $request): array
    {
        $pages = [];
        View::composer('*', static function ($view) use (&$pages): void {
            if (!str_starts_with($view->name(), 'layouts.')) {
                $pages[$view->name()] = $view->getData();
            }
        });
        $request();

        return $pages;
    }

    /**
     * Every screen of slice 3 that the matrix asks, with what it handed its page, for the employee that is signed in.
     *
     * @return array<string, mixed>
     */
    private function figures(): array
    {
        $orderReport = $this->ok($this->get('/admin/report/order'));
        $status = $this->pagesOf(fn () => $this->ok($this->post('/admin/dashboard/order-status', ['statistics_type' => 'overall'])))['admin-views.partials._dashboard-order-status']['data'];
        $earnings = $this->pagesOf(fn () => $this->ok($this->get('/admin/dashboard/earning-statistics?type=yearEarn')))['admin-views.system.partials.earning-statistics'];

        return [
            'report orders' => $orderReport->viewData('order_count'),
            'report due' => (float) $orderReport->viewData('due_amount'),
            'report settled' => (float) $orderReport->viewData('settled_amount'),
            'dashboard orders' => ['all' => $status['order'], ...array_intersect_key($status, array_flip(self::STATUSES))],
            'dashboard earnings' => (float) array_sum($earnings['inHouseEarning']) + (float) array_sum($earnings['vendorEarning']),
            'poller' => $this->ok($this->get('/admin/dashboard/real-time-activities'))->json('new_order_count'),
            'refund transactions' => $this->idsOf($this->ok($this->get('/admin/report/transaction/refund-transaction-list'))->viewData('refundTransactions')),
            'customer' => $this->idsOf($this->ok($this->get('/admin/customer/view/1'))->viewData('orders')),
            'delivery man' => $this->idsOf($this->ok($this->get('/admin/delivery-man/order-wise-earning/1'))->viewData('orders')),
            'vendor orders' => $this->idsOf($this->ok($this->get('/admin/vendors/view/5/order'))->viewData('orders')),
            'vendor transactions' => $this->idsOf($this->ok($this->get('/admin/vendors/view/5/transaction'))->viewData('transactions')),
        ];
    }

    /**
     * What every screen should hand its page to an employee whose orders are the ones of a scope, from the description of the world.
     *
     * @param list<string>|null $scope
     * @return array<string, mixed>
     */
    private function expected(?array $scope): array
    {
        $ongoing = ['out_for_delivery', 'processing', 'confirmed', 'pending'];
        $countOf = fn (array $statuses): int => count($this->visibleOrders($scope, static fn (array $order): bool => in_array($order['status'], $statuses, true)));
        $counters = ['all' => count($this->visibleOrders($scope))];
        foreach (self::STATUSES as $status) {
            $counters[$status] = $countOf([$status]);
        }

        return [
            'report orders' => ['ongoing_order' => $countOf($ongoing), 'canceled_order' => $countOf(['canceled', 'failed', 'returned']), 'delivered_order' => $countOf(['delivered']), 'total_order' => $countOf([...$ongoing, 'canceled', 'failed', 'returned', 'delivered'])],
            'report due' => (float) $this->sumOfOrders($scope, static fn (array $order): bool => !in_array($order['status'], ['delivered', 'canceled', 'returned', 'failed'], true)),
            'report settled' => (float) $this->sumOfOrders($scope, static fn (array $order): bool => $order['status'] === 'delivered'),
            'dashboard orders' => $counters,
            'dashboard earnings' => (float) $this->sumOfOrders($scope),
            'poller' => count($this->visibleOrders($scope)),
            'refund transactions' => array_values(array_filter(array_keys($this->refundTransactions), fn (int $id): bool => $this->refundTransactionIsVisible($id, $scope))),
            'customer' => $this->visibleOrders($scope),
            'delivery man' => $this->visibleOrders($scope),
            'vendor orders' => $this->visibleOrders($scope),
            'vendor transactions' => array_map(static fn (int $id): int => 1000 + $id, $this->visibleOrders($scope)),
        ];
    }

    /** @param array<string, mixed> $figures */
    private function normalised(array $figures): array
    {
        foreach (['refund transactions', 'customer', 'delivery man', 'vendor orders', 'vendor transactions'] as $key) {
            sort($figures[$key]);
        }
        ksort($figures['dashboard orders']);

        return $figures;
    }

    public function test_the_world_holds_every_kind_of_order_and_every_scope_sees_something_different(): void
    {
        $this->assertCount(6, $this->countries);
        $this->assertCount(23, $this->scopes());
        $this->assertCount(34, $this->orders, '6 delivered, 6 pending, 15 pairs, a triple, one of all six, and 5 that no country owns');
        $seen = [];
        foreach ($this->scopes() as $name => $scope) {
            $seen[json_encode($this->visibleOrders($scope))] = $name;
        }
        $this->assertCount(23, $seen, 'no two scopes see the same orders');
    }

    public function test_every_scope_sees_the_figures_of_its_orders_and_nothing_else(): void
    {
        foreach ($this->scopes() as $name => $scope) {
            $this->signInWith($scope);

            $this->assertEquals($this->normalised($this->expected($scope)), $this->normalised($this->figures()), 'scope ' . $name);
        }
    }

    public function test_the_master_admin_an_employee_with_no_country_and_the_switch_off_see_every_order(): void
    {
        $everything = $this->normalised($this->expected(null));

        $this->signInAsStaff(adminId: 1);
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 1, countries: ['KW']);
        $this->assertEquals($everything, $this->normalised($this->figures()), 'the master admin, with a country row');

        DB::table('admin_country_scopes')->delete();
        $this->signInAsStaff(adminId: 9);
        $this->assertEquals($everything, $this->normalised($this->figures()), 'an employee with no country');

        $this->giveCountries(adminId: 9, countries: ['KW']);
        config(['gcc.staff.country_scopes' => false]);
        $this->assertEquals($everything, $this->normalised($this->figures()), 'the switch off, an employee who has a country');
    }

    public function test_the_unattributed_orders_are_nobodys_but_the_master_admins(): void
    {
        foreach ($this->scopes() as $name => $scope) {
            $this->assertSame([], array_values(array_intersect([401, 402, 403, 404, 405], $this->visibleOrders($scope))), 'scope ' . $name);
        }
        $this->assertSame([], array_diff([401, 402, 403, 404, 405], $this->visibleOrders(null)), 'the master admin\'s world holds them all');
    }
}
