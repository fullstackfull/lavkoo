<?php

namespace Tests\Feature\StaffScope;

use App\Exports\OrderExport;
use App\Exports\RefundRequestExport;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Http\Middleware\ScopeOrdersToStaffCountries;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\BuildsStaffOrderWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The six countries of the platform, one employee at a time (GCC-14c, slice 2): which orders, POS orders and refund requests each scope sees in the lists, the counters, the
 * details by id, the status actions and the exports, and that everything it does not see answers exactly like an id that does not exist.
 *
 * The world is built from config('gcc.storefronts.countries'): every country has a product, an order, a POS order and a refund request; every pair of countries has an order that
 * holds both and a refund request for each of its two lines; one order holds three countries and one holds all six; and five orders are unattributed (no line, a deleted product,
 * a product without a country, and two orders that mix a country with one of those). What each scope should see is worked out here from that description, as plain set logic and
 * not by asking the code under test: an order is visible to a scope when it has a line and every line's country is in the scope; a refund request when the country of its line is.
 *
 * The scopes are the six single countries, every pair, one triple and all six. Employee 7 holds orders and the POS and is given the scope of each in turn.
 */
class StaffSixCountryMatrixTest extends TestCase
{
    use BuildsStaffOrderWorld;
    use RestoresLanguageFiles;

    private const STATUSES = ['pending', 'confirmed', 'processing', 'out_for_delivery', 'delivered', 'canceled', 'returned', 'failed'];

    private const REFUND_STATUSES = ['pending', 'approved', 'refunded', 'rejected'];

    private const NO_COUNTRY_PRODUCT = 90;

    private const DELETED_PRODUCT = 99;

    private const MISSING = 999999;

    /** @var list<string> */
    private array $countries;

    /** @var array<int, string> product id => country ('' for a product without one); a product that is not here was deleted */
    private array $productCountry = [];

    /** @var array<int, array{lines: list<int>, status: string, type: string}> */
    private array $orders = [];

    /** @var array<int, array{order: int, about: int|null, lineGone: bool, status: string}> */
    private array $refunds = [];

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
        DB::table('business_settings')->where('type', 'pagination_limit')->update(['value' => '1000']);
        $this->answerNoCustomers();
        $this->teachSqliteWhatMysqlKnows();
        $this->primeTaxSystemCache();
        Cache::put('tax_system_type_vendor_with_vat', ['SystemTaxVat' => ['tax_ids' => [], 'additionalData' => []], 'SystemTaxVatType' => 'order_wise', 'is_included' => 1, 'productWiseTax' => false, 'categoryWiseTax' => false, 'taxVats' => []]);

        $this->countries = array_map('strval', array_keys((array) config('gcc.storefronts.countries')));
        $this->buildTheWorld();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
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

        $count = count($this->countries);
        $next = 0;
        $order = function (int $id, array $lines, string $type = 'default_type') use (&$next): void {
            $this->orders[$id] = ['lines' => $lines, 'status' => self::STATUSES[$next++ % count(self::STATUSES)], 'type' => $type];
        };
        for ($i = 0; $i < $count; $i++) {
            $order(101 + $i, [$this->productOf($i)]);
        }
        for ($i = 0; $i < $count; $i++) {
            $order(201 + $i, [$this->productOf($i)], 'POS');
        }
        $pairOrders = [];
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $id = 301 + count($pairOrders);
                $pairOrders[$id] = [$i, $j];
                $order($id, [$this->productOf($i), $this->productOf($j)]);
            }
        }
        $order(320, [$this->productOf(0), $this->productOf(1), $this->productOf(2)]);
        $order(330, array_map(fn (int $index): int => $this->productOf($index), range(0, $count - 1)));
        $order(401, []);
        $order(402, [self::DELETED_PRODUCT]);
        $order(403, [self::NO_COUNTRY_PRODUCT]);
        $order(404, [$this->productOf(2), self::DELETED_PRODUCT]);
        $order(405, [$this->productOf(0), self::NO_COUNTRY_PRODUCT]);

        foreach ($this->orders as $id => $order) {
            $this->insertStaffOrder(id: $id, products: $order['lines'], extra: ['order_status' => $order['status'], 'order_type' => $order['type'], 'customer_id' => 1]);
        }

        $refund = function (int $id, int $orderId, ?int $about, bool $lineGone = false) use (&$refundStatus): void {
            $this->refunds[$id] = ['order' => $orderId, 'about' => $about, 'lineGone' => $lineGone, 'status' => self::REFUND_STATUSES[$refundStatus++ % count(self::REFUND_STATUSES)]];
        };
        $refundStatus = 0;
        for ($i = 0; $i < $count; $i++) {
            $refund(1 + $i, 101 + $i, $this->productOf($i));
        }
        foreach ($pairOrders as $orderId => [$i, $j]) {
            $refund(100 + 2 * ($orderId - 301), $orderId, $this->productOf($i));
            $refund(101 + 2 * ($orderId - 301), $orderId, $this->productOf($j));
        }
        $refund(900, 402, self::DELETED_PRODUCT);
        $refund(901, 401, null);
        $refund(902, 403, self::NO_COUNTRY_PRODUCT);
        $refund(903, 101, $this->productOf(0), lineGone: true);

        foreach ($this->refunds as $id => $refund) {
            $this->insertStaffRefund(id: $id, orderId: $refund['order'], aboutProduct: $refund['about'], extra: array_filter(['status' => $refund['status'], 'order_details_id' => $refund['lineGone'] ? 424242 : false], static fn ($value): bool => $value !== false));
        }
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

    /**
     * Every scope is asked everything that only reads, and what a change would be let through or refused is asked of the middleware for every scope; the changes themselves are
     * made for real by the six single countries and by the employee who holds all six, which is where each country is first alone and then together with the others.
     */
    private function writesForReal(string $scopeName): bool
    {
        return in_array($scopeName, [...$this->countries, 'all six'], true);
    }

    // ---- what each scope should see, worked out from the description of the world -------------------------------------------------------

    /** @param list<string> $scope */
    private function orderIsVisible(int $orderId, array $scope): bool
    {
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

    /** @param list<string> $scope */
    private function refundIsVisible(int $refundId, array $scope): bool
    {
        $refund = $this->refunds[$refundId];
        if ($refund['about'] === null || $refund['lineGone']) {
            return false;
        }

        return in_array($this->productCountry[$refund['about']] ?? null, $scope, true);
    }

    /**
     * @param list<string> $scope
     * @return list<int> the orders a scope sees, newest first, that satisfy a condition on the order
     */
    private function visibleOrders(array $scope, ?callable $where = null): array
    {
        $ids = array_values(array_filter(array_keys($this->orders), fn (int $id): bool => $this->orderIsVisible($id, $scope) && ($where === null || $where($this->orders[$id]))));
        rsort($ids);

        return $ids;
    }

    /**
     * @param list<string> $scope
     * @return list<int>
     */
    private function visibleRefunds(array $scope, ?string $status = null): array
    {
        $ids = array_values(array_filter(array_keys($this->refunds), fn (int $id): bool => $this->refundIsVisible($id, $scope) && ($status === null || $this->refunds[$id]['status'] === $status)));
        rsort($ids);

        return $ids;
    }

    /**
     * @param list<string> $scope
     * @return array<string, int> the counters of the order list
     */
    private function expectedCounters(array $scope): array
    {
        $counters = array_fill_keys(self::STATUSES, 0);
        foreach ($this->visibleOrders($scope) as $id) {
            $counters[$this->orders[$id]['status']]++;
        }

        return $counters;
    }

    // ---- driving the screens -------------------------------------------------------------------------------------------------------------

    /** @param list<string> $scope */
    private function signInWith(array $scope): void
    {
        $this->switchStaffScopesOn();
        DB::table('admin_country_scopes')->where('admin_id', 7)->delete();
        $this->giveCountries(adminId: 7, countries: $scope);
        $this->signInAsStaff(adminId: 7);
    }

    /** @return list<int> */
    private function printed(TestResponse $page, string $pattern): array
    {
        $page->assertOk();
        preg_match_all($pattern, (string) $page->getContent(), $matches);

        return array_map('intval', $matches[1]);
    }

    /** @return list<int> */
    private function ordersListed(string $uri): array
    {
        return $this->printed($this->get($uri), '/\[order:(\d+)\]/');
    }

    /** @return array<string, int> */
    private function countersListed(string $uri): array
    {
        $page = $this->get($uri)->assertOk();
        preg_match('/\[counts ([^\]]+)\]/', (string) $page->getContent(), $counts);
        parse_str(str_replace(' ', '&', $counts[1]), $parsed);

        return array_map('intval', $parsed);
    }

    /** @return list<int> */
    private function refundsListed(string $status): array
    {
        return $this->printed($this->get('/admin/refund-section/refund/list/' . $status), '/\[refund:(\d+) /');
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

    /** What the middleware of the order screens does with a request for a route: true when it lets it through, false when it answers 404. */
    private function lets(string $method, string $uri, array $input = []): bool
    {
        $request = Request::create($uri, $method, $input);
        try {
            $route = Route::getRoutes()->match($request);
        } catch (NotFoundHttpException) {
            return false;
        }
        $request->setRouteResolver(static fn () => $route);
        $route->bind($request);
        $this->app->instance('request', $request);

        try {
            app(ScopeOrdersToStaffCountries::class)->handle($request, static fn () => response('reached'));
        } catch (NotFoundHttpException) {
            return false;
        }

        return true;
    }

    // ---- the orders --------------------------------------------------------------------------------------------------------------------

    public function test_the_world_is_made_of_what_it_says_it_is(): void
    {
        $this->assertSame(['SA', 'AE', 'KW', 'QA', 'BH', 'OM'], $this->countries);
        $this->assertCount(34, $this->orders);
        $this->assertCount(6 + 6 + 15 + 2 + 5, $this->orders);
        $this->assertCount(6 + 15 + 1 + 1, $this->scopes(), 'six singles, fifteen pairs, one triple, all six');
        $this->assertSame(34, DB::table('orders')->count());
        $this->assertSame(count($this->refunds), DB::table('refund_requests')->count());
        $this->assertSame(array_sum(array_map(static fn (array $order): int => count($order['lines']), $this->orders)), DB::table('order_details')->count());
    }

    public function test_every_scope_lists_exactly_the_orders_whose_every_line_is_in_it_and_counts_them(): void
    {
        foreach ($this->scopes() as $name => $scope) {
            $this->signInWith($scope);

            $this->assertSame($this->visibleOrders($scope), $this->ordersListed('/admin/orders/list/all'), $name . ': the list');
            $this->assertSame($this->expectedCounters($scope), $this->countersListed('/admin/orders/list/all'), $name . ': the counters');
            foreach (self::STATUSES as $status) {
                $this->assertSame($this->visibleOrders($scope, static fn (array $order): bool => $order['status'] === $status), $this->ordersListed('/admin/orders/list/' . $status), $name . ': the list of ' . $status);
            }
        }
    }

    public function test_every_scope_lists_exactly_the_pos_orders_it_can_see(): void
    {
        foreach ($this->scopes() as $name => $scope) {
            $this->signInWith($scope);
            $expected = $this->visibleOrders($scope, static fn (array $order): bool => $order['type'] === 'POS');

            $this->assertSame($expected, $this->ordersListed('/admin/orders/list/all?filter=POS'), $name . ': the POS orders');
            $this->assertSame($expected, $this->ordersListed('/admin/orders/list/all?order_types[]=POS'), $name . ': the POS orders by type');
            $this->assertSame(array_values(array_filter($this->visibleOrders($scope), static fn (int $id): bool => !in_array($id, $expected, true))), $this->ordersListed('/admin/orders/list/all?filter=default_type'), $name . ': the others');
        }
    }

    public function test_every_scope_gets_an_export_of_exactly_the_orders_it_can_see(): void
    {
        Excel::fake();

        foreach ($this->scopes() as $name => $scope) {
            $this->signInWith($scope);
            foreach (['all', 'delivered'] as $status) {
                $this->get('/admin/orders/export-excel/' . $status)->assertOk();
                $expected = $this->visibleOrders($scope, static fn (array $order): bool => $status === 'all' || $order['status'] === $status);

                Excel::assertDownloaded('Orders.xlsx', function (OrderExport $export) use ($expected, $name, $status): bool {
                    $orders = $export->view()->getData()['data']['orders']->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                    rsort($orders);
                    $this->assertSame($expected, $orders, $name . ': the export of ' . $status);

                    return true;
                });
                Excel::fake();
            }
        }
    }

    public function test_every_order_a_scope_cannot_see_answers_by_its_number_like_an_order_that_does_not_exist_and_every_other_one_opens(): void
    {
        foreach ($this->scopes() as $name => $scope) {
            $this->signInWith($scope);
            $unknown = $this->answer($this->getJson('/admin/orders/details/' . self::MISSING));

            foreach (array_keys($this->orders) as $id) {
                if ($this->orderIsVisible($id, $scope)) {
                    $this->assertTrue($this->lets('GET', '/admin/orders/details/' . $id), $name . ': order ' . $id . ' opens');
                    $this->assertTrue($this->lets('GET', '/admin/orders/generate-invoice/' . $id), $name . ': the invoice of order ' . $id);

                    continue;
                }
                $answer = $this->getJson('/admin/orders/details/' . $id);
                $this->assertSame(404, $answer->getStatusCode(), $name . ': order ' . $id . ' is not theirs');
                $this->assertSame($unknown, $this->answer($answer), $name . ': order ' . $id . ' answers like an order that does not exist');
                $this->assertSame($unknown, $this->answer($this->getJson('/admin/orders/generate-invoice/' . $id)), $name . ': the invoice of order ' . $id);
            }
        }
    }

    public function test_the_status_actions_work_on_exactly_the_orders_a_scope_can_see_and_on_none_of_the_others(): void
    {
        foreach ($this->scopes() as $name => $scope) {
            $this->signInWith($scope);
            DB::table('orders')->update(['payment_status' => 'unpaid']);
            $unknown = $this->answer($this->postJson('/admin/orders/payment-status', ['id' => self::MISSING, 'payment_status' => 'paid']));
            $everyone = array_keys($this->orders);

            foreach ($everyone as $id) {
                $visible = $this->orderIsVisible($id, $scope);
                if ($this->writesForReal($name)) {
                    $answer = $this->postJson('/admin/orders/payment-status', ['id' => $id, 'payment_status' => 'paid']);
                    if ($visible) {
                        $answer->assertOk();
                        $this->assertSame('paid', DB::table('orders')->where('id', $id)->value('payment_status'), $name . ': order ' . $id . ' is marked paid');
                    } else {
                        $this->assertSame($unknown, $this->answer($answer), $name . ': order ' . $id . ' answers like an order that does not exist');
                        $this->assertSame('unpaid', DB::table('orders')->where('id', $id)->value('payment_status'), $name . ': order ' . $id . ' was not touched');
                    }
                }

                $this->assertSame($visible, $this->lets('POST', '/admin/orders/payment-status', ['id' => $id, 'payment_status' => 'paid']), $name . ': the payment of order ' . $id);
                $this->assertSame($visible, $this->lets('POST', '/admin/orders/status', ['id' => $id, 'order_status' => 'confirmed']), $name . ': the status of order ' . $id);
                $this->assertSame($visible, $this->lets('POST', '/admin/orders/address-update', ['order_id' => $id]), $name . ': the address of order ' . $id);
                $this->assertSame($visible, $this->lets('GET', '/admin/orders/add-delivery-man/' . $id . '/1'), $name . ': a courier for order ' . $id);
                $this->assertSame($visible, $this->lets('POST', '/admin/orders/customer-return-amount', ['order_id' => $id]), $name . ': the return amount of order ' . $id);
            }

            $mine = array_values(array_filter($everyone, fn (int $id): bool => $this->orderIsVisible($id, $scope)));
            $this->assertTrue($this->lets('POST', '/admin/orders/bulk-status', ['ids' => $mine, 'order_status' => 'confirmed']), $name . ': a bulk change of exactly their own orders');
            $this->assertSame(count($mine) === count($everyone), $this->lets('POST', '/admin/orders/bulk-status', ['ids' => $everyone, 'order_status' => 'confirmed']), $name . ': a bulk change of every order is refused whole unless every order is theirs');
            $this->assertFalse($this->lets('POST', '/admin/orders/bulk-status', ['ids' => [...$mine, self::MISSING], 'order_status' => 'confirmed']), $name . ': nor one that names an order that does not exist');
        }
    }

    // ---- the refund requests -----------------------------------------------------------------------------------------------------------

    public function test_every_scope_lists_exactly_the_refund_requests_about_a_line_in_it(): void
    {
        foreach ($this->scopes() as $name => $scope) {
            $this->signInWith($scope);

            $everyStatus = [];
            foreach (self::REFUND_STATUSES as $status) {
                $expected = $this->visibleRefunds($scope, $status);
                $this->assertSame($expected, $this->refundsListed($status), $name . ': the refund requests that are ' . $status);
                array_push($everyStatus, ...$expected);
            }
            rsort($everyStatus);
            $this->assertSame($this->visibleRefunds($scope), $everyStatus, $name . ': all of them');
        }
    }

    public function test_every_scope_gets_an_export_of_exactly_the_refund_requests_it_can_see(): void
    {
        Excel::fake();

        foreach ($this->scopes() as $name => $scope) {
            $this->signInWith($scope);
            $this->get('/admin/refund-section/refund/export/pending')->assertOk();

            Excel::assertDownloaded('refund-request.xlsx', function (RefundRequestExport $export) use ($scope, $name): bool {
                $this->assertSame($this->visibleRefunds($scope, 'pending'), $export->view()->getData()['data']['refundList']->pluck('id')->map(static fn ($id): int => (int) $id)->all(), $name);

                return true;
            });
            Excel::fake();
        }
    }

    public function test_every_refund_request_a_scope_cannot_see_answers_like_one_that_does_not_exist_and_the_others_open_and_can_be_decided(): void
    {
        foreach ($this->scopes() as $name => $scope) {
            $this->signInWith($scope);
            $this->resetRefunds();
            $unknownDetails = $this->answer($this->getJson('/admin/refund-section/refund/details/' . self::MISSING));
            $unknownUpdate = $this->answer($this->postJson('/admin/refund-section/refund/refund-status-update', ['id' => self::MISSING, 'refund_status' => 'rejected', 'rejected_note' => 'No']));

            foreach (array_keys($this->refunds) as $id) {
                $visible = $this->refundIsVisible($id, $scope);
                $this->assertSame($visible, $this->lets('GET', '/admin/refund-section/refund/details/' . $id), $name . ': the details of refund ' . $id);
                $this->assertSame($visible, $this->lets('POST', '/admin/refund-section/refund/refund-status-update', ['id' => $id, 'refund_status' => 'rejected']), $name . ': the decision on refund ' . $id);

                if (!$visible) {
                    $this->assertSame($unknownDetails, $this->answer($this->getJson('/admin/refund-section/refund/details/' . $id)), $name . ': the details of refund ' . $id);
                    $this->assertSame($unknownUpdate, $this->answer($this->postJson('/admin/refund-section/refund/refund-status-update', ['id' => $id, 'refund_status' => 'rejected', 'rejected_note' => 'No'])), $name . ': the decision on refund ' . $id);
                    $this->assertSame($this->refunds[$id]['status'], DB::table('refund_requests')->where('id', $id)->value('status'), $name . ': refund ' . $id . ' was not touched');
                } elseif ($this->writesForReal($name)) {
                    $this->assertSame([$id], $this->printed($this->get('/admin/refund-section/refund/details/' . $id), '/\[refund:(\d+)\]/'), $name . ': refund ' . $id . ' opens');
                    if ($this->refunds[$id]['status'] !== 'refunded') {
                        $this->postJson('/admin/refund-section/refund/refund-status-update', ['id' => $id, 'refund_status' => 'rejected', 'rejected_note' => 'No'])->assertOk();
                        $this->assertSame('rejected', DB::table('refund_requests')->where('id', $id)->value('status'), $name . ': refund ' . $id . ' is decided');
                    }
                }
            }
        }
    }

    private function resetRefunds(): void
    {
        foreach ($this->refunds as $id => $refund) {
            DB::table('refund_requests')->where('id', $id)->update(['status' => $refund['status']]);
        }
    }

    // ---- the POS -----------------------------------------------------------------------------------------------------------------------

    public function test_every_scope_is_offered_the_products_of_its_countries_in_the_pos_and_refused_the_others(): void
    {
        foreach ($this->scopes() as $name => $scope) {
            $this->signInWith($scope);
            $expected = array_keys(array_filter($this->productCountry, static fn (string $country): bool => in_array($country, $scope, true)));
            $result = $this->getJson('/admin/pos/search-product?name=Product')->assertOk()->json('result');
            preg_match_all('/\[product:(\d+)\]/', (string) $result, $matches);

            $this->assertEqualsCanonicalizing($expected, array_map('intval', $matches[1]), $name . ': the grid');
            foreach ([...array_keys($this->productCountry), self::DELETED_PRODUCT] as $productId) {
                $isTheirs = in_array($productId, $expected, true);
                $this->assertSame($isTheirs, $this->lets('POST', '/admin/pos/add-to-cart', ['id' => $productId]), $name . ': product ' . $productId . ' into the cart');
                $this->assertSame($isTheirs, $this->lets('POST', '/admin/pos/quantity-update', ['key' => $productId]), $name . ': the quantity of product ' . $productId);
                $this->assertSame($isTheirs, $this->lets('GET', '/admin/pos/quick-view', ['product_id' => $productId]), $name . ': the quick view of product ' . $productId);
            }
        }
    }

    public function test_an_order_is_placed_in_the_pos_only_when_every_product_of_the_cart_is_in_the_scope_and_is_theirs_afterwards(): void
    {
        $products = array_map(fn (int $index): int => $this->productOf($index), array_keys($this->countries));

        foreach ($this->scopes() as $name => $scope) {
            if (!$this->writesForReal($name)) {
                continue;
            }
            $this->signInWith($scope);

            foreach ([...array_map(static fn (int $product): array => [$product], $products), [$products[0], $products[1]], [$products[2], $products[0]]] as $cart) {
                $this->withSession(['current_user' => 'walk-in-customer-1', 'walk-in-customer-1' => [...array_map(static fn (int $id): array => ['id' => $id, 'customerId' => 0, 'customerOnHold' => false, 'quantity' => 1, 'price' => 10, 'name' => "P{$id}", 'productType' => 'physical', 'image' => [], 'discount' => 0, 'variant' => null, 'variations' => []], $cart), 'add_to_cart_time' => now()]]);
                $inScope = array_reduce($cart, fn (bool $all, int $id): bool => $all && in_array($this->productCountry[$id], $scope, true), true);
                $before = (int) DB::table('orders')->max('id');
                $number = 100000 + DB::table('orders')->count() + 1;

                $response = $this->postJson('/admin/pos/order-place', ['amount' => 10 * count($cart), 'type' => 'cash', 'paid_amount' => 10 * count($cart)]);

                if ($inScope) {
                    $response->assertOk();
                    $this->assertGreaterThan($before, (int) DB::table('orders')->max('id'), $name . ': the order of ' . implode(',', $cart) . ' is placed');
                    $this->assertSame($number, (int) DB::table('orders')->max('id'), $name . ': its number counts every order');
                    $this->assertContains($number, $this->ordersListed('/admin/orders/list/all'), $name . ': it is theirs');
                } else {
                    $response->assertNotFound();
                    $this->assertSame($before, (int) DB::table('orders')->max('id'), $name . ': nothing is placed with ' . implode(',', $cart));
                }
            }
        }
    }

    // ---- who is not narrowed -------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: int, 1: bool}> */
    public static function unnarrowedCases(): array
    {
        return [
            'the master admin, who has a row' => [1, true],
            'an employee with no country, switch on' => [7, true],
            'an employee with countries, switch off' => [7, false],
        ];
    }

    #[DataProvider('unnarrowedCases')]
    public function test_nobody_who_is_not_restricted_sees_anything_less_than_everything(int $adminId, bool $switchOn): void
    {
        config(['gcc.staff.country_scopes' => $switchOn]);
        if ($adminId === 1 || !$switchOn) {
            $this->giveCountries(adminId: $adminId, countries: ['KW']);
        }
        $this->signInAsStaff(adminId: $adminId);
        $everyOrder = array_keys($this->orders);
        rsort($everyOrder);
        $counters = array_fill_keys(self::STATUSES, 0);
        foreach ($this->orders as $order) {
            $counters[$order['status']]++;
        }

        $this->assertSame($everyOrder, $this->ordersListed('/admin/orders/list/all'));
        $this->assertSame($counters, $this->countersListed('/admin/orders/list/all'));
        foreach (self::STATUSES as $status) {
            $expected = array_values(array_filter($everyOrder, fn (int $id): bool => $this->orders[$id]['status'] === $status));
            $this->assertSame($expected, $this->ordersListed('/admin/orders/list/' . $status), $status);
        }
        $everyRefund = array_keys($this->refunds);
        rsort($everyRefund);
        foreach (self::REFUND_STATUSES as $status) {
            $this->assertSame(array_values(array_filter($everyRefund, fn (int $id): bool => $this->refunds[$id]['status'] === $status)), $this->refundsListed($status), 'the refund requests that are ' . $status);
        }
        foreach ($everyOrder as $id) {
            $this->assertTrue($this->lets('GET', '/admin/orders/details/' . $id), 'order ' . $id);
            $this->postJson('/admin/orders/payment-status', ['id' => $id, 'payment_status' => 'paid'])->assertOk();
        }
        $this->assertSame([], $this->ordersWithStatus('unpaid'), 'every order, the unattributed ones too, was marked paid');
        $this->assertTrue($this->lets('GET', '/admin/orders/details/' . self::MISSING), 'an unknown order is the controller\'s to answer, as it always was');
    }

    /** @return list<int> */
    private function ordersWithStatus(string $paymentStatus): array
    {
        return DB::table('orders')->where('payment_status', $paymentStatus)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    public function test_an_employee_restricted_to_nothing_sees_no_order_and_no_refund_request_whatever_the_world_holds(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 7, countries: ['zz', 'sa']);
        $this->signInAsStaff(adminId: 7);

        $this->assertSame([], $this->ordersListed('/admin/orders/list/all'));
        $this->assertSame(array_fill_keys(self::STATUSES, 0), $this->countersListed('/admin/orders/list/all'));
        foreach (self::REFUND_STATUSES as $status) {
            $this->assertSame([], $this->refundsListed($status));
        }
        foreach (array_keys($this->orders) as $id) {
            $this->assertFalse($this->lets('GET', '/admin/orders/details/' . $id), 'order ' . $id);
        }
    }

    public function test_the_scopes_of_the_matrix_add_up_every_order_belongs_to_exactly_the_employees_who_hold_all_its_countries(): void
    {
        $everyoneWhoSeesIt = [];
        foreach ($this->scopes() as $name => $scope) {
            foreach ($this->visibleOrders($scope) as $id) {
                $everyoneWhoSeesIt[$id][] = $name;
            }
        }

        $this->assertSame(['SA', 'SA+AE', 'SA+KW', 'SA+QA', 'SA+BH', 'SA+OM', 'SA+AE+KW', 'all six'], $everyoneWhoSeesIt[101], 'a Saudi order: every scope that holds Saudi Arabia');
        $this->assertSame(['SA+AE', 'SA+AE+KW', 'all six'], $everyoneWhoSeesIt[301], 'the order of Saudi Arabia and the Emirates: the scopes that hold both');
        $this->assertSame(['all six'], $everyoneWhoSeesIt[330], 'an order of six countries: only the employee who holds all six');
        $this->assertSame(['SA+AE+KW', 'all six'], $everyoneWhoSeesIt[320]);
        foreach ([401, 402, 403, 404, 405] as $unattributed) {
            $this->assertArrayNotHasKey($unattributed, $everyoneWhoSeesIt, 'order ' . $unattributed . ' is unattributed: nobody who is restricted sees it, not even the employee with all six countries');
        }
    }
}
