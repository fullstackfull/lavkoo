<?php

namespace Tests\Feature\StaffScope;

use App\Http\Controllers\Admin\Order\DeliverySyriaDispatchController;
use App\Http\Controllers\Admin\Order\OrderController;
use App\Http\Controllers\Admin\Order\OrderEditController;
use App\Http\Controllers\Admin\Order\RefundController;
use App\Http\Controllers\Admin\POS\CartController;
use App\Http\Controllers\Admin\POS\POSController;
use App\Http\Controllers\Admin\POS\POSOrderController;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Http\Middleware\ScopeOrdersToStaffCountries;
use App\Services\Staff\StaffCountryScope;
use App\Services\Staff\StaffOrderRoutes;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\BuildsStaffOrderWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\Support\StaffProbe\UnlistedOrderController;
use Tests\TestCase;

/**
 * Every route of the order, POS and refund screens that names an order, an order line, a refund request or a product answers a restricted employee like an unknown one (a
 * 404, the same body) when it is outside their countries, never a 403, so that belonging to a country is not something to find out by asking (GCC-14c, slice 2).
 *
 * The routes come from the real route table: for each one the ids are put where its rule says to look, in the path, in the input or in a list. The middleware is called by
 * itself on the real route (the controllers and their form requests are not the subject); a second group of tests goes through the whole stack to compare the bytes of the
 * two answers. Order 102 holds a Kuwaiti product and is in scope, 101 holds a Saudi one and is not, 999999 does not exist; the same for the lines, the refund requests and the
 * products.
 */
class StaffOrderRoutesTest extends TestCase
{
    use BuildsStaffOrderWorld;
    use RestoresLanguageFiles;

    private const ID_PART = '/^(order|line|refund|product)\.(route|input|inputs):/';

    private const ORDER = ['in' => 102, 'foreign' => 101, 'unknown' => 999999];

    private const REFUND = ['in' => 2, 'foreign' => 1, 'unknown' => 424242];

    private const PRODUCT = ['in' => 2, 'foreign' => 1, 'unknown' => 99];

    /** @var array<string, int> */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        Http::preventStrayRequests();
        View::getFinder()->prependLocation(base_path('tests/Support/staff-views'));
        $this->withoutMiddleware([
            ActivationCheckMiddleware::class, Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);

        $this->buildStaffOrderTables();
        $this->insertStaffProduct(id: 1, country: 'SA');
        $this->insertStaffProduct(id: 2, country: 'KW');
        $this->insertStaffOrder(id: 101, products: [1]);
        $this->insertStaffOrder(id: 102, products: [2]);
        $this->insertStaffRefund(id: 1, orderId: 101, aboutProduct: 1);
        $this->insertStaffRefund(id: 2, orderId: 102, aboutProduct: 2);
        $this->lines = ['in' => $this->staffOrderLineIds(102)[0], 'foreign' => $this->staffOrderLineIds(101)[0], 'unknown' => 424242];
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function asKuwaitEmployee(int $adminId = 7): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: $adminId, countries: ['KW']);
        $this->signInAsStaff(adminId: $adminId);
    }

    // ---- the routes of the real table -------------------------------------------------------------------------------------------

    /** @return list<array{route: RoutingRoute, method: string, rule: list<string>}> every route of a covered controller, with the rule of its action */
    private function coveredRoutes(): array
    {
        $routes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $controller = ltrim((string) $route->getControllerClass(), '\\');
            $rule = in_array($controller, StaffOrderRoutes::controllers(), true) ? StaffOrderRoutes::ruleOf(controller: $controller, method: $route->getActionMethod()) : null;
            if ($rule !== null) {
                $routes[] = ['route' => $route, 'method' => array_values(array_diff($route->methods(), ['HEAD']))[0], 'rule' => $rule];
            }
        }

        return $routes;
    }

    /** @return list<string> the parts of a rule that name an order, a line, a refund request or a product by an id */
    private function idParts(array $rule): array
    {
        return array_values(array_filter($rule, static fn (string $part): bool => preg_match(self::ID_PART, $part) === 1));
    }

    /** @return list<array{route: RoutingRoute, method: string, rule: list<string>}> */
    private function idRoutes(): array
    {
        return array_values(array_filter($this->coveredRoutes(), fn (array $covered): bool => $this->idParts($covered['rule']) !== []));
    }

    /** What a request puts where a part looks, to name something that is in scope, of another country or nothing. */
    private function valueOf(string $part, string $case): mixed
    {
        [$what] = explode(':', $part, 2);
        [$subject, $source] = explode('.', $what, 2);
        $id = match ($subject) {
            'order' => self::ORDER[$case],
            'line' => $this->lines[$case],
            'refund' => self::REFUND[$case],
            'product' => self::PRODUCT[$case],
        };

        return $source === 'inputs' ? [$id] : $id;
    }

    /**
     * @param array<string, mixed> $overrides what a part names instead of what is in scope, by part
     * @return array<string, mixed>
     */
    private function valuesFor(array $rule, array $overrides = []): array
    {
        $values = [];
        foreach ($this->idParts($rule) as $part) {
            $values[$part] = array_key_exists($part, $overrides) ? $overrides[$part] : $this->valueOf(part: $part, case: 'in');
        }

        return $values;
    }

    /**
     * A request for a route, with the ids placed where the rule looks for them.
     *
     * @param array<string, mixed> $values by part
     * @return array{uri: string, input: array<string, mixed>}
     */
    private function placing(RoutingRoute $route, array $values): array
    {
        $path = [];
        $input = [];
        foreach ($values as $part => $value) {
            [$what, $name] = explode(':', $part, 2);
            if (str_ends_with($what, '.route')) {
                $path[$name] = $value;
            } else {
                $input[$name] = $value;
            }
        }

        $uri = preg_replace_callback('/\{(\w+)\??\}/', static fn (array $match): string => array_key_exists($match[1], $path) ? (is_scalar($path[$match[1]]) ? rawurlencode((string) $path[$match[1]]) : 'x') : ($match[1] === 'status' ? 'all' : '1'), '/' . $route->uri());

        return ['uri' => $uri, 'input' => $input];
    }

    /** What the middleware does with the request of a route: true when it let the request through, false when it answered 404. */
    private function letsThrough(array $covered, array $values, ?callable $reached = null): bool
    {
        $place = $this->placing(route: $covered['route'], values: $values);
        $request = Request::create($place['uri'], $covered['method'], $place['input']);

        try {
            $route = Route::getRoutes()->match($request);
        } catch (NotFoundHttpException) {
            return false;
        }

        $request->setRouteResolver(static fn () => $route);
        $route->bind($request);
        $this->app->instance('request', $request);

        try {
            app(ScopeOrdersToStaffCountries::class)->handle($request, static function () use ($reached) {
                if ($reached !== null) {
                    $reached();
                }

                return response('reached');
            });
        } catch (NotFoundHttpException) {
            return false;
        }

        return true;
    }

    private function label(array $covered): string
    {
        return $covered['method'] . ' ' . $covered['route']->uri() . ' [' . implode(' + ', $covered['rule']) . ']';
    }

    /** A POS cart in the session, as the POS keeps it: items, and the discounts that live beside them. */
    private function putCart(array $items, string $cartId = 'walk-in-customer-1'): void
    {
        session()->put('current_user', $cartId);
        session()->put($cartId, array_merge($items, ['ext_discount' => 5, 'ext_discount_type' => 'percent', 'coupon_discount' => 0]));
    }

    private function cartItem(mixed $productId): array
    {
        return ['id' => $productId, 'quantity' => 1, 'variant' => null, 'productType' => 'physical', 'price' => 10, 'discount' => 0];
    }

    /** @param list<mixed> $productIds */
    private function putOrderEditSession(int $orderId, array $productIds): void
    {
        session()->put('ADMIN_SESSION_FOR_EDIT_ORDER_' . $orderId, ['product_list' => array_map(static fn ($id): array => ['product_id' => $id, 'qty' => 1], $productIds)]);
    }

    /** Puts what the parts that read the session look at in scope, so that a request that names things in scope is let through as a whole. */
    private function prepareSessionInScope(): void
    {
        $this->putCart([$this->cartItem(self::PRODUCT['in'])]);
        $this->putOrderEditSession(orderId: self::ORDER['in'], productIds: [self::PRODUCT['in']]);
    }

    public function test_the_walk_finds_the_routes_of_the_seven_controllers_and_every_kind_of_part(): void
    {
        $kinds = [];
        foreach ($this->coveredRoutes() as $covered) {
            foreach ($covered['rule'] as $part) {
                $kinds[explode(':', $part)[0]] = true;
            }
        }
        $kinds = array_keys($kinds);
        sort($kinds);
        $controllers = array_values(array_unique(array_map(static fn (array $covered): string => ltrim((string) $covered['route']->getControllerClass(), '\\'), $this->coveredRoutes())));
        sort($controllers);
        $expected = [DeliverySyriaDispatchController::class, OrderController::class, OrderEditController::class, RefundController::class, CartController::class, POSController::class, POSOrderController::class];
        sort($expected);

        $this->assertSame(['cart', 'edit', 'line.input', 'list', 'none', 'order.input', 'order.inputs', 'order.route', 'product.input', 'refund.input', 'refund.route'], $kinds);
        $this->assertSame($expected, $controllers);
        $this->assertCount(48, $this->coveredRoutes());
    }

    // ---- a restricted employee ---------------------------------------------------------------------------------------------------

    public function test_what_is_in_the_scope_passes_on_every_route_that_names_something(): void
    {
        $this->asKuwaitEmployee();
        $this->prepareSessionInScope();

        foreach ($this->idRoutes() as $covered) {
            $this->assertTrue($this->letsThrough($covered, $this->valuesFor($covered['rule'])), $this->label($covered));
        }
    }

    public function test_something_of_another_country_is_refused_on_every_route_that_names_it_whatever_else_the_request_names(): void
    {
        $this->asKuwaitEmployee();
        $this->prepareSessionInScope();

        foreach ($this->idRoutes() as $covered) {
            foreach ($this->idParts($covered['rule']) as $part) {
                $values = $this->valuesFor($covered['rule'], [$part => $this->valueOf(part: $part, case: 'foreign')]);

                $this->assertFalse($this->letsThrough($covered, $values), $this->label($covered) . ' with a foreign ' . $part);
            }
        }
    }

    public function test_something_that_does_not_exist_is_refused_exactly_like_something_of_another_country(): void
    {
        $this->asKuwaitEmployee();
        $this->prepareSessionInScope();

        foreach ($this->idRoutes() as $covered) {
            foreach ($this->idParts($covered['rule']) as $part) {
                $values = $this->valuesFor($covered['rule'], [$part => $this->valueOf(part: $part, case: 'unknown')]);

                $this->assertFalse($this->letsThrough($covered, $values), $this->label($covered) . ' with an unknown ' . $part);
            }
        }
    }

    public function test_a_list_of_orders_is_refused_whole_when_one_of_them_is_out_of_scope_or_unknown(): void
    {
        $this->asKuwaitEmployee();
        $lists = array_values(array_filter($this->idRoutes(), fn (array $covered): bool => array_filter($covered['rule'], static fn (string $part): bool => str_starts_with($part, 'order.inputs:')) !== []));

        $this->assertNotEmpty($lists);
        foreach ($lists as $covered) {
            $part = array_values(array_filter($covered['rule'], static fn (string $candidate): bool => str_starts_with($candidate, 'order.inputs:')))[0];
            $with = fn (array $ids): bool => $this->letsThrough($covered, [$part => $ids]);

            $this->assertTrue($with([self::ORDER['in']]), $this->label($covered));
            $this->assertTrue($with([(string) self::ORDER['in'], self::ORDER['in']]), $this->label($covered) . ' with a repeat');
            $this->assertTrue($with([]), $this->label($covered) . ' with none: the action refuses an empty selection itself');
            $this->assertFalse($with([self::ORDER['in'], self::ORDER['foreign']]), $this->label($covered) . ' with a foreign one');
            $this->assertFalse($with([self::ORDER['in'], self::ORDER['unknown']]), $this->label($covered) . ' with an unknown one');
            $this->assertFalse($with([self::ORDER['in'], 'x']), $this->label($covered) . ' with one that is no id');
        }
    }

    public function test_a_value_that_is_not_an_id_is_refused_whatever_the_database_would_make_of_it(): void
    {
        $this->asKuwaitEmployee();
        $this->prepareSessionInScope();

        foreach ($this->idRoutes() as $covered) {
            foreach ($this->idParts($covered['rule']) as $part) {
                if (str_contains($part, '.inputs:')) {
                    continue;
                }
                foreach (['102abc', '0102', '102.0', '1e2', ' 102', '102 ', '0', '-102', 'abc', '', [102], ['102']] as $junk) {
                    $values = $this->valuesFor($covered['rule'], [$part => $junk]);

                    $this->assertFalse($this->letsThrough($covered, $values), $this->label($covered) . ' with ' . json_encode($junk) . ' for ' . $part);
                }
            }
        }
    }

    public function test_a_missing_id_is_refused_on_every_route_that_needs_one(): void
    {
        $this->asKuwaitEmployee();
        $this->prepareSessionInScope();

        foreach ($this->idRoutes() as $covered) {
            foreach ($this->idParts($covered['rule']) as $part) {
                if (str_contains($part, '.route:') || str_contains($part, '.inputs:')) {
                    continue;
                }
                $values = $this->valuesFor($covered['rule']);
                unset($values[$part]);

                $this->assertFalse($this->letsThrough($covered, $values), $this->label($covered) . ' without ' . $part);
            }
        }
    }

    public function test_the_routes_that_name_nothing_pass_a_restricted_employee_and_say_what_they_list(): void
    {
        $this->asKuwaitEmployee();
        $others = array_values(array_filter($this->coveredRoutes(), fn (array $covered): bool => $this->idParts($covered['rule']) === [] && !in_array('cart', $covered['rule'], true)));

        $this->assertGreaterThan(15, count($others));
        foreach ($others as $covered) {
            $narrowedTo = [];
            $reached = function () use (&$narrowedTo): void {
                $scope = app(StaffCountryScope::class);
                $narrowedTo = array_values(array_filter([StaffCountryScope::SUBJECT_ORDERS, StaffCountryScope::SUBJECT_REFUNDS], static fn (string $subject): bool => $scope->narrows(subject: $subject)));
            };

            $this->assertTrue($this->letsThrough($covered, [], $reached), $this->label($covered));
            $listed = array_values(array_map(static fn (string $part): string => substr($part, 5), array_filter($covered['rule'], static fn (string $part): bool => str_starts_with($part, 'list:'))));
            sort($listed);
            $this->assertSame($listed, $narrowedTo, $this->label($covered) . ': narrowed for what it lists and for nothing else');
        }
    }

    public function test_an_action_that_changes_an_order_runs_on_the_real_data_and_one_that_shows_a_list_is_narrowed(): void
    {
        $this->asKuwaitEmployee();
        $this->prepareSessionInScope();

        foreach ($this->coveredRoutes() as $covered) {
            $narrowed = null;
            $reached = function () use (&$narrowed): void {
                $narrowed = app(StaffCountryScope::class)->narrows(subject: StaffCountryScope::SUBJECT_ORDERS) || app(StaffCountryScope::class)->narrows(subject: StaffCountryScope::SUBJECT_REFUNDS);
            };

            $this->assertTrue($this->letsThrough($covered, $this->valuesFor($covered['rule']), $reached), $this->label($covered));
            $lists = array_filter($covered['rule'], static fn (string $part): bool => str_starts_with($part, 'list:')) !== [];
            $this->assertSame($lists, $narrowed, $this->label($covered));
        }
    }

    public function test_an_employee_restricted_to_nothing_is_refused_every_id_even_of_a_country_that_exists(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 7, countries: ['zz']);
        $this->signInAsStaff(adminId: 7);

        foreach ($this->idRoutes() as $covered) {
            $this->assertFalse($this->letsThrough($covered, $this->valuesFor($covered['rule'])), $this->label($covered));
        }
    }

    public function test_something_in_any_of_several_countries_of_the_scope_passes(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 7, countries: ['KW', 'SA']);
        $this->signInAsStaff(adminId: 7);

        foreach ($this->idRoutes() as $covered) {
            $overrides = [];
            foreach ($this->idParts($covered['rule']) as $part) {
                $overrides[$part] = $this->valueOf(part: $part, case: 'foreign');
            }

            $this->assertTrue($this->letsThrough($covered, $this->valuesFor($covered['rule'], $overrides)), $this->label($covered));
        }
    }

    // ---- the cart of the POS and the edit of an order ------------------------------------------------------------------------------

    private function placeOrderRoute(): array
    {
        return array_values(array_filter($this->coveredRoutes(), static fn (array $covered): bool => $covered['rule'] === ['cart']))[0];
    }

    public function test_an_order_is_placed_only_when_every_product_of_the_cart_is_in_scope(): void
    {
        $this->asKuwaitEmployee();
        $covered = $this->placeOrderRoute();

        $this->putCart([$this->cartItem(self::PRODUCT['in'])]);
        $this->assertTrue($this->letsThrough($covered, []), 'the products of their country');

        $this->putCart([$this->cartItem(self::PRODUCT['in']), $this->cartItem(self::PRODUCT['foreign'])]);
        $this->assertFalse($this->letsThrough($covered, []), 'one product of another country in the cart: no order whose lines are outside their countries');

        $this->putCart([$this->cartItem(self::PRODUCT['in']), $this->cartItem(self::PRODUCT['unknown'])]);
        $this->assertFalse($this->letsThrough($covered, []), 'a product that is gone');

        $this->putCart([$this->cartItem(self::PRODUCT['in']), $this->cartItem('2abc')]);
        $this->assertFalse($this->letsThrough($covered, []), 'an item that names no product');

        $this->putCart([]);
        $this->assertTrue($this->letsThrough($covered, []), 'an empty cart names no product: the action answers it as it always did');

        session()->flush();
        $this->assertTrue($this->letsThrough($covered, []), 'no cart in the session at all');
    }

    public function test_a_cart_that_holds_only_what_the_pos_keeps_beside_its_items_names_no_product(): void
    {
        $this->asKuwaitEmployee();
        $this->putCart([]);

        $this->assertTrue($this->letsThrough($this->placeOrderRoute(), []));
    }

    public function test_only_the_cart_in_use_is_looked_at_so_a_held_cart_of_another_country_does_not_stop_the_order(): void
    {
        $this->asKuwaitEmployee();
        $this->putCart([$this->cartItem(self::PRODUCT['foreign'])], cartId: 'saved-customer-9');
        $this->putCart([$this->cartItem(self::PRODUCT['in'])], cartId: 'walk-in-customer-1');
        session()->put('saved-customer-9', [$this->cartItem(self::PRODUCT['foreign'])]);

        $this->assertTrue($this->letsThrough($this->placeOrderRoute(), []));
    }

    public function test_an_edited_order_is_saved_only_when_every_product_of_its_edit_is_in_scope(): void
    {
        $this->asKuwaitEmployee();
        $covered = array_values(array_filter($this->coveredRoutes(), static fn (array $candidate): bool => in_array('edit:order_id', $candidate['rule'], true)))[0];
        $values = $this->valuesFor($covered['rule']);

        $this->putOrderEditSession(orderId: self::ORDER['in'], productIds: [self::PRODUCT['in']]);
        $this->assertTrue($this->letsThrough($covered, $values));

        $this->putOrderEditSession(orderId: self::ORDER['in'], productIds: [self::PRODUCT['in'], self::PRODUCT['foreign']]);
        $this->assertFalse($this->letsThrough($covered, $values), 'a product of another country in the edit: no line outside their countries is written');

        $this->putOrderEditSession(orderId: self::ORDER['in'], productIds: [self::PRODUCT['unknown']]);
        $this->assertFalse($this->letsThrough($covered, $values));

        $this->putOrderEditSession(orderId: self::ORDER['in'], productIds: ['x']);
        $this->assertFalse($this->letsThrough($covered, $values));

        $this->putOrderEditSession(orderId: self::ORDER['in'], productIds: []);
        $this->assertTrue($this->letsThrough($covered, $values), 'an empty edit names no product');

        session()->flush();
        $this->assertTrue($this->letsThrough($covered, $values), 'no edit in the session: the action starts one from the order, whose lines are in scope');
    }

    public function test_the_edit_of_another_order_is_not_looked_at_for_this_one(): void
    {
        $this->asKuwaitEmployee();
        $covered = array_values(array_filter($this->coveredRoutes(), static fn (array $candidate): bool => in_array('edit:order_id', $candidate['rule'], true)))[0];
        $this->putOrderEditSession(orderId: self::ORDER['foreign'], productIds: [self::PRODUCT['foreign']]);

        $this->assertTrue($this->letsThrough($covered, $this->valuesFor($covered['rule'])));
    }

    // ---- who is not restricted -----------------------------------------------------------------------------------------------------

    public function test_nothing_changes_for_the_master_admin_an_employee_without_countries_or_a_switch_that_is_off(): void
    {
        $this->giveCountries(adminId: 7, countries: ['KW']);
        $this->giveCountries(adminId: 1, countries: ['KW']);
        $this->putCart([$this->cartItem(self::PRODUCT['foreign'])]);

        $cases = [
            'switch off, employee with a country' => [false, 7],
            'switch on, master admin with a country row' => [true, 1],
            'switch on, employee with no country' => [true, 8],
        ];
        foreach ($cases as $label => [$switchOn, $adminId]) {
            config(['gcc.staff.country_scopes' => $switchOn]);
            $this->signInAsStaff(adminId: $adminId);

            foreach ($this->coveredRoutes() as $covered) {
                foreach (['foreign', 'unknown'] as $case) {
                    $overrides = [];
                    foreach ($this->idParts($covered['rule']) as $part) {
                        $overrides[$part] = $this->valueOf(part: $part, case: $case);
                    }
                    $narrowed = null;
                    $reached = function () use (&$narrowed): void {
                        $narrowed = app(StaffCountryScope::class)->restricts();
                    };

                    $this->assertTrue($this->letsThrough($covered, $this->valuesFor($covered['rule'], $overrides), $reached), $label . ': ' . $this->label($covered) . ' with ' . $case . ' ids: an unknown id is the controller\'s to answer, as it always was');
                    $this->assertFalse($narrowed, $label . ': ' . $this->label($covered));
                }
            }
        }
    }

    public function test_with_the_switch_off_the_middleware_asks_the_database_nothing(): void
    {
        $this->giveCountries(adminId: 7, countries: ['KW']);
        $this->signInAsStaff(adminId: 7);
        $covered = array_values(array_filter($this->idRoutes(), static fn (array $candidate): bool => $candidate['rule'] === ['order.route:id']))[0];
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertTrue($this->letsThrough($covered, ['order.route:id' => self::ORDER['foreign']]));

        $this->assertSame([], DB::getQueryLog());
    }

    // ---- the whole stack: the same bytes for something of another country and for something that does not exist -------------------------

    /**
     * What a client is told: the status and the body, without what APP_DEBUG adds to an error answer (CI runs with it on): the trace names the line of the test that
     * made the request, so two requests for the same thing never answer the same bytes.
     *
     * @return array{0: int, 1: mixed}
     */
    private function answer(TestResponse $response): array
    {
        $body = json_decode((string) $response->getContent(), true);

        return [$response->getStatusCode(), is_array($body) ? Arr::except($body, ['exception', 'file', 'line', 'trace']) : $response->getContent()];
    }

    private function ask(array $covered, array $values, bool $json): TestResponse
    {
        $place = $this->placing(route: $covered['route'], values: $values);
        $headers = $json ? ['Accept' => 'application/json'] : [];

        return $covered['method'] === 'GET' ? $this->get($place['uri'] . '?' . http_build_query($place['input']), $headers) : $this->call($covered['method'], $place['uri'], $place['input'], [], [], $this->transformHeadersToServerVars($headers));
    }

    public function test_every_route_answers_something_of_another_country_with_the_bytes_it_answers_something_unknown_and_never_with_a_403(): void
    {
        $this->asKuwaitEmployee();
        $this->prepareSessionInScope();

        foreach ($this->idRoutes() as $covered) {
            foreach ($this->idParts($covered['rule']) as $part) {
                foreach ([false, true] as $json) {
                    $label = $this->label($covered) . ' with a foreign ' . $part . ($json ? ' as json' : ' as html');
                    $foreign = $this->ask($covered, $this->valuesFor($covered['rule'], [$part => $this->valueOf(part: $part, case: 'foreign')]), $json);
                    $unknown = $this->ask($covered, $this->valuesFor($covered['rule'], [$part => $this->valueOf(part: $part, case: 'unknown')]), $json);

                    $this->assertSame(404, $foreign->getStatusCode(), $label);
                    $this->assertSame($this->answer($unknown), $this->answer($foreign), $label);
                }
            }
        }
    }

    public function test_the_refusal_is_a_404_that_names_neither_the_order_nor_a_reason(): void
    {
        $this->asKuwaitEmployee();
        $covered = array_values(array_filter($this->idRoutes(), static fn (array $candidate): bool => $candidate['rule'] === ['order.route:id'] && $candidate['method'] === 'GET'))[0];
        $values = [array_values($this->idParts($covered['rule']))[0] => self::ORDER['foreign']];

        $html = $this->ask($covered, $values, false);
        $json = $this->ask($covered, $values, true);

        $this->assertSame(404, $html->getStatusCode());
        $this->assertSame(404, $json->getStatusCode());
        $this->assertSame('', $json->json('message'), 'no message: nothing that tells why');
        $this->assertStringNotContainsStringIgnoringCase('order ' . self::ORDER['foreign'], $json->getContent() . strip_tags((string) $html->getContent()));
        $this->assertStringNotContainsStringIgnoringCase('forbidden', $html->getContent() . $json->getContent());
    }

    // ---- who is told what, and in what order ----------------------------------------------------------------------------------------

    public function test_an_employee_without_the_permission_of_the_screen_is_told_so_before_the_scope_looks_at_any_order(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 8, countries: ['KW']);
        $this->signInAsStaff(adminId: 8);

        foreach ([self::ORDER['in'], self::ORDER['foreign'], self::ORDER['unknown']] as $order) {
            $response = $this->get('/admin/orders/details/' . $order);

            $this->assertSame(302, $response->getStatusCode(), 'order ' . $order);
            $this->assertStringEndsWith('/admin/dashboard', (string) $response->headers->get('Location'), 'the permission check answered, whatever the order: the scope reveals nothing to an employee who may not be here');
        }
    }

    public function test_the_middleware_of_the_scope_runs_after_the_sign_in_and_the_permission_of_the_route_on_every_covered_route(): void
    {
        foreach ($this->coveredRoutes() as $covered) {
            $middleware = $covered['route']->gatherMiddleware();
            $position = array_search(ScopeOrdersToStaffCountries::class, $middleware, true);
            $permission = array_values(array_filter(array_keys($middleware), static fn ($index): bool => str_starts_with((string) $middleware[$index], 'module:')));

            $this->assertNotFalse($position, $this->label($covered) . ' does not run the middleware of the scope');
            $this->assertSame([], array_filter($permission, static fn ($index): bool => $index > $position), $this->label($covered) . ' asks for a permission after the scope has looked');
            $this->assertGreaterThan(array_search('admin', $middleware, true), $position, 'the sign-in check comes first');
        }
    }

    public function test_only_the_covered_controllers_register_the_middleware_of_the_scope(): void
    {
        $registering = [];
        foreach ([app_path('Http/Controllers'), base_path('Modules'), base_path('routes')] as $root) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'ScopeOrdersToStaffCountries')) {
                    $registering[] = $file->getPathname();
                }
            }
        }
        $covered = array_map(static fn (string $controller): string => (string) (new \ReflectionClass($controller))->getFileName(), StaffOrderRoutes::controllers());
        sort($registering);
        sort($covered);

        $this->assertSame($covered, $registering, 'slice 2 covers the order, POS and refund screens of the admin panel only: no vendor, API or storefront controller, and no route file, names the middleware');
    }

    // ---- a route that has no rule ---------------------------------------------------------------------------------------------------

    public function test_a_route_of_a_covered_controller_that_has_no_rule_is_refused_to_a_restricted_employee_and_open_to_the_rest(): void
    {
        Route::get('admin/orders/zz-unlisted', [UnlistedOrderController::class, 'unlisted'])->middleware(['web', 'admin', 'module:orders']);

        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 7, countries: ['KW']);

        $this->signInAsStaff(adminId: 7)->getJson('/admin/orders/zz-unlisted')->assertNotFound();
        $this->signInAsStaff(adminId: 1)->getJson('/admin/orders/zz-unlisted')->assertOk()->assertJson(['reached' => 'unlisted']);
        DB::table('admin_country_scopes')->where('admin_id', 7)->delete();
        $this->signInAsStaff(adminId: 7)->getJson('/admin/orders/zz-unlisted')->assertOk();
    }

    // ---- the registry itself ----------------------------------------------------------------------------------------------------------

    public function test_every_part_of_every_rule_is_one_the_registry_knows(): void
    {
        foreach (StaffOrderRoutes::controllers() as $controller) {
            foreach (StaffOrderRoutes::rulesOf(controller: $controller) as $action => $rule) {
                $this->assertNotSame([], $rule, $controller . '@' . $action);
                foreach ($rule as $part) {
                    $this->assertMatchesRegularExpression('/^(none|cart|edit:[a-z_]+|list:(orders|refunds)|(order|line|refund|product)\.(route|input|inputs):[A-Za-z_]+)$/', $part, $controller . '@' . $action);
                }
                $this->assertSame($rule === ['none'], in_array('none', $rule, true), $controller . '@' . $action . ': none stands alone');
            }
        }
    }

    public function test_a_part_nobody_knows_names_nothing_that_can_be_checked(): void
    {
        $this->asKuwaitEmployee();
        $request = Request::create('/admin/orders/details/102');

        $this->assertFalse(StaffOrderRoutes::isCovered(request: $request, rule: ['bogus:id'], scope: app(StaffCountryScope::class)));
        $this->assertFalse(StaffOrderRoutes::isCovered(request: $request, rule: ['order.bogus:id'], scope: app(StaffCountryScope::class)));
        $this->assertFalse(StaffOrderRoutes::isCovered(request: $request, rule: ['bogus.route:id'], scope: app(StaffCountryScope::class)));
        $this->assertSame([], StaffOrderRoutes::subjectsOf(rule: ['order.route:id']));
        $this->assertSame(['orders', 'refunds'], StaffOrderRoutes::subjectsOf(rule: ['list:orders', 'list:refunds']));
    }

    public function test_a_controller_is_found_with_or_without_the_backslash_a_route_may_carry_in_front_of_its_class(): void
    {
        $this->assertSame(['list:orders'], StaffOrderRoutes::ruleOf(controller: '\\App\\Http\\Controllers\\Admin\\Order\\OrderController', method: 'index'));
        $this->assertSame(['list:orders'], StaffOrderRoutes::ruleOf(controller: 'App\\Http\\Controllers\\Admin\\Order\\OrderController', method: 'index'));
        $this->assertNull(StaffOrderRoutes::ruleOf(controller: 'App\\Http\\Controllers\\Admin\\Product\\ProductController', method: 'index'));
    }
}
