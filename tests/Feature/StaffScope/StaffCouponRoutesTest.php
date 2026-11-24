<?php

namespace Tests\Feature\StaffScope;

use App\Http\Controllers\Admin\Promotion\CouponController;
use App\Http\Middleware\ScopeCouponsToStaffCountries;
use App\Services\Staff\StaffCouponRoutes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\BuildsStaffScopeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * A coupon belongs to a market, so acting on one is an act in that market (GCC-14c; the bulk-route finding of G-11).
 *
 * Nothing narrowed these actions: a restricted employee could switch off, edit or delete the coupon of a country they do not work with, and
 * `bulk-status` took a whole list of ids with no check at all. `money.market:coupon`, which the route group already carries, answers which
 * MONEY a coupon is read in -- a different question from whose coupon it is.
 *
 * Every route comes from the real route table, and the id is put where its own rule says to look. A coupon of another country is answered
 * like a coupon that does not exist, as a product of another country is, so belonging to a country is not something to find out by asking.
 *
 * Coupon 10 is Kuwaiti, 20 is Saudi, 30 belongs to no market (which makes it the DEFAULT market's), 99 does not exist.
 */
class StaffCouponRoutesTest extends TestCase
{
    use BuildsStaffScopeWorld;
    use RestoresLanguageFiles;

    private const KUWAITI = 10;

    private const SAUDI = 20;

    private const NO_MARKET = 30;

    private const UNKNOWN = 99;

    /** The employee the world seeds with the role that may use the marketing module, which is the module the coupon routes ask for. */
    private const STAFF = 70;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        Http::preventStrayRequests();
        $this->buildStaffAdminTables();
        // The scope is only enforced where the products carry their country, which is what StaffCountryScope::enabled() asks: the employee's
        // countries mean nothing on an installation whose catalogue has no markets in it.
        $this->buildStaffProductTables();
        $this->buildCouponTable();
        $this->runStaffScopeMigration();
        $this->switchStaffScopesOn();
        // A coupon is read by its own market only while storefront currencies are on, which is CouponCountry's own condition.
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.currency.enabled' => true]);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function buildCouponTable(): void
    {
        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('added_by')->default('admin');
            $table->boolean('status')->default(1);
            $table->timestamps();
        });
        (require database_path('migrations/2026_12_01_100000_add_storefront_country_to_coupons_table.php'))->up();

        DB::table('coupons')->insert([
            ['id' => self::KUWAITI, 'code' => 'KW10', 'storefront_country' => 'KW'],
            ['id' => self::SAUDI, 'code' => 'SA20', 'storefront_country' => 'SA'],
            ['id' => self::NO_MARKET, 'code' => 'ANY30', 'storefront_country' => null],
        ]);
    }

    /** Every coupon route the middleware has a rule for, with the rule, as the real route table holds them. */
    private function enforcedRoutes(): array
    {
        $covered = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (ltrim((string) $route->getControllerClass(), '\\') !== CouponController::class) {
                continue;
            }

            $rule = StaffCouponRoutes::ruleOf(controller: CouponController::class, method: $route->getActionMethod());
            if ($rule !== null && StaffCouponRoutes::isEnforced($rule)) {
                $covered[$route->getActionMethod() . ' ' . $route->uri()] = ['route' => $route, 'rule' => $rule, 'method' => array_values(array_diff($route->methods(), ['HEAD']))[0]];
            }
        }

        return $covered;
    }

    /** What the middleware does with the request of a route: true when it let it through, false when it answered 404. */
    private function letsThrough(array $covered, int $coupon): bool
    {
        [$kind, $name] = array_pad(explode(':', $covered['rule'], 2), 2, '');
        $inPath = $kind === 'route';
        $uri = preg_replace_callback('/\{(\w+)\??\}/', static fn (array $match): string => $inPath && $match[1] === $name ? (string) $coupon : '1', '/' . $covered['route']->uri());
        $input = match ($kind) {
            'input' => [$name => $coupon],
            'inputs' => [$name => [$coupon]],
            default => [],
        };

        $request = Request::create($uri, $covered['method'], $input);

        try {
            $route = Route::getRoutes()->match($request);
        } catch (NotFoundHttpException) {
            return false;
        }

        $request->setRouteResolver(static fn () => $route);
        $route->bind($request);
        $this->app->instance('request', $request);

        try {
            app(ScopeCouponsToStaffCountries::class)->handle($request, static fn () => response('reached'));
        } catch (NotFoundHttpException) {
            return false;
        }

        return true;
    }

    private function staffHolding(string ...$countries): void
    {
        $this->giveCountries(self::STAFF, $countries);
        $this->signInAsStaff(self::STAFF);
    }

    /** @return array<string, string> every action of the coupon controller the real route table answers, with its uri */
    private function routedActions(): array
    {
        $actions = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (ltrim((string) $route->getControllerClass(), '\\') === CouponController::class) {
                $actions[$route->getActionMethod()] = $route->uri();
            }
        }

        ksort($actions);

        return $actions;
    }

    public function test_every_coupon_route_has_a_rule(): void
    {
        $without = array_values(array_diff(array_keys($this->routedActions()), array_keys(StaffCouponRoutes::rulesOf(CouponController::class))));

        $this->assertSame([], $without, "a coupon route with no rule is refused to a restricted employee rather than let through. Give it one:\n - " . implode("\n - ", $without));
    }

    public function test_no_rule_names_an_action_the_controller_no_longer_answers(): void
    {
        $gone = array_values(array_diff(array_keys(StaffCouponRoutes::rulesOf(CouponController::class)), array_keys($this->routedActions())));

        $this->assertSame([], $gone, "a rule for an action that is gone hides the next real one. Take it off:\n - " . implode("\n - ", $gone));
    }

    /**
     * The two kinds of rule the middleware does NOT enforce, pinned so they cannot grow quietly and cannot be mistaken for enforcement: a
     * restricted employee still sees every market's coupons on the list, and can still create one for a market they do not work with.
     * Coupons have no query scope of their own, which is why these are named gaps rather than called `list` and `new`.
     */
    public function test_the_rules_that_are_not_enforced_are_the_two_named_gaps(): void
    {
        $unenforced = [];
        foreach (StaffCouponRoutes::rulesOf(CouponController::class) as $action => $rule) {
            if (!StaffCouponRoutes::isEnforced($rule) && $rule !== 'none') {
                $unenforced[$action] = $rule;
            }
        }

        $this->assertSame([
            'getAddListView' => 'list_not_narrowed',
            'add' => 'new_not_checked',
            'exportList' => 'list_not_narrowed',
        ], $unenforced, 'what the coupon scope does not yet cover changed: narrow it, or say why a new action may narrow nothing');
    }

    /** The middleware is registered by the controller, so it runs after the sign-in check and the module permission of the route. */
    public function test_the_controller_registers_the_middleware(): void
    {
        $registered = array_map(
            static fn (array $entry): string => is_string($entry['middleware']) ? $entry['middleware'] : '',
            app(CouponController::class)->getMiddleware(),
        );

        $this->assertContains(ScopeCouponsToStaffCountries::class, $registered, 'without this the rules above are never read');
    }

    public function test_the_coupon_routes_the_middleware_enforces_are_the_ones_that_name_a_coupon(): void
    {
        $this->assertSame([
            'quickView admin/coupon/quick-view-details',
            'getUpdateView admin/coupon/update/{id}',
            'update admin/coupon/update/{id}',
            'updateStatus admin/coupon/status/{id}/{status}',
            'bulkStatus admin/coupon/bulk-status',
            'delete admin/coupon/delete/{id}',
        ], array_keys($this->enforcedRoutes()), 'the coupon routes that name a coupon changed: give the new one a rule');
    }

    public function test_every_route_that_names_a_coupon_refuses_another_countrys_coupon(): void
    {
        $this->staffHolding('KW');

        foreach ($this->enforcedRoutes() as $name => $covered) {
            $this->assertTrue($this->letsThrough($covered, self::KUWAITI), "{$name} must allow the employee's own coupon");
            $this->assertFalse($this->letsThrough($covered, self::SAUDI), "{$name} must refuse another country's coupon");
            $this->assertFalse($this->letsThrough($covered, self::UNKNOWN), "{$name} must answer an unknown id the same way");
        }
    }

    /** A coupon that records no market is the DEFAULT market's, which is CouponCountry's rule and not a second one invented here. */
    public function test_a_coupon_with_no_market_belongs_to_the_default_market(): void
    {
        $this->staffHolding('KW');
        $routes = $this->enforcedRoutes();

        $this->assertFalse($this->letsThrough($routes['delete admin/coupon/delete/{id}'], self::NO_MARKET), 'a Kuwaiti employee does not hold the default market');

        $this->staffHolding('SA');
        $this->assertTrue($this->letsThrough($routes['delete admin/coupon/delete/{id}'], self::NO_MARKET), 'and an employee of the default market does');
    }

    /** An employee restricted to nothing is not restricted: the screens answer exactly as they always did. */
    public function test_an_unrestricted_employee_reaches_every_coupon(): void
    {
        $this->signInAsStaff(self::STAFF);
        $routes = $this->enforcedRoutes();

        foreach ([self::KUWAITI, self::SAUDI, self::NO_MARKET] as $coupon) {
            $this->assertTrue($this->letsThrough($routes['delete admin/coupon/delete/{id}'], $coupon));
        }
    }

    /** And with the switch off, which is how the platform ships, nothing is narrowed at all. */
    public function test_nothing_is_narrowed_while_the_switch_is_off(): void
    {
        config(['gcc.staff.country_scopes' => false]);
        $this->staffHolding('KW');

        $this->assertTrue($this->letsThrough($this->enforcedRoutes()['delete admin/coupon/delete/{id}'], self::SAUDI));
    }

    public static function bulkLists(): array
    {
        return [
            'one of the employee\'s own' => [[self::KUWAITI], true],
            'one of another country\'s' => [[self::SAUDI], false],
            'their own and another country\'s together' => [[self::KUWAITI, self::SAUDI], false],
            'their own and an id that is not a coupon' => [[self::KUWAITI, self::UNKNOWN], false],
        ];
    }

    /**
     * The bulk list is all or nothing, which is the rule the product and order bulk actions already follow: the refusal happens before the
     * action runs, so a list holding one coupon the employee may not touch changes none of them. A partial bulk write is not something an
     * operator can undo.
     *
     * @param list<int> $ids
     */
    #[DataProvider('bulkLists')]
    public function test_a_bulk_list_is_refused_whole(array $ids, bool $allowed): void
    {
        $this->staffHolding('KW');
        $covered = $this->enforcedRoutes()['bulkStatus admin/coupon/bulk-status'];

        $request = Request::create('/admin/coupon/bulk-status', 'POST', ['ids' => $ids, 'status' => 0]);
        $route = Route::getRoutes()->match($request);
        $request->setRouteResolver(static fn (): RoutingRoute => $route);
        $route->bind($request);
        $this->app->instance('request', $request);

        $reached = false;
        try {
            app(ScopeCouponsToStaffCountries::class)->handle($request, static function () use (&$reached) {
                $reached = true;

                return response('reached');
            });
        } catch (NotFoundHttpException) {
            $reached = false;
        }

        $this->assertSame($allowed, $reached);
        $this->assertSame([1, 1, 1], DB::table('coupons')->orderBy('id')->pluck('status')->map(fn ($status): int => (int) $status)->all(), 'no coupon was switched: the middleware answers before the action runs');
        unset($covered);
    }
}
