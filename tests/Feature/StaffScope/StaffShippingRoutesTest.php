<?php

namespace Tests\Feature\StaffScope;

use App\Http\Controllers\Admin\CategoryShippingCostController;
use App\Http\Controllers\Admin\Marketplace\ShippingZoneController;
use App\Http\Controllers\Admin\Shipping\ShippingMethodController;
use App\Http\Controllers\Admin\Shipping\ShippingTypeController;
use App\Http\Middleware\ScopeShippingToStaffCountries;
use App\Services\Staff\StaffShippingRoutes;
use App\Services\Storefront\StorefrontCategoryShippingCosts;
use App\Services\Storefront\StorefrontShippingMethods;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\BuildsStaffScopeWorld;
use Tests\Support\RestoresCategoryShippingCostsTable;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * A shipping method and a per-category shipping cost belong to a market, so changing one is an act in that market (GCC-14c slice 6; H1-14).
 *
 * Nothing narrowed these actions. An employee restricted to Kuwait could edit the Saudi store's delivery price, switch its method off or
 * delete it, and the category-cost form posted a whole list of row ids with no check at all. The money is the point: a cost is a bare number
 * typed in one market's currency, and the same figure read in another currency is a different price.
 *
 * Every route comes from the real route table and the id is put where that route's own rule says to look, so a route that moves breaks this
 * test rather than quietly stopping being covered. A row of another market is answered like a row that does not exist.
 *
 * Method 10 is Kuwaiti, 20 is Saudi, 30 names no market (which makes it the DEFAULT market's), 99 does not exist. The category costs carry
 * the same three markets under the same three ids.
 */
class StaffShippingRoutesTest extends TestCase
{
    use BuildsStaffScopeWorld;
    use RestoresCategoryShippingCostsTable;
    use RestoresLanguageFiles;

    private const KUWAITI = 10;

    private const SAUDI = 20;

    private const NO_MARKET = 30;

    private const UNKNOWN = 99;

    /** The employee the world seeds with a role that may reach the business-settings module, which the shipping routes ask for. */
    private const STAFF = 70;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        Http::preventStrayRequests();
        $this->buildStaffAdminTables();
        // The scope is enforced only where the products carry their country, which is what StaffCountryScope::enabled() asks.
        $this->buildStaffProductTables();
        $this->buildShippingTables();
        $this->runStaffScopeMigration();
        $this->switchStaffScopesOn();
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.default_country' => 'SA']);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();
        Schema::dropIfExists('shipping_methods');
        $this->restoreCategoryShippingCostsTable();

        parent::tearDown();
    }

    private function buildShippingTables(): void
    {
        Schema::dropIfExists('shipping_methods');
        Schema::create('shipping_methods', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('creator_id')->nullable();
            $table->string('creator_type')->default('admin');
            $table->string('title')->nullable();
            $table->decimal('cost', 21, 12)->nullable();
            $table->integer('duration')->nullable();
            $table->boolean('status')->default(1);
            $table->char(StorefrontShippingMethods::COLUMN, 2)->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('category_shipping_costs');
        Schema::create('category_shipping_costs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedInteger('category_id')->nullable();
            $table->decimal('cost', 21, 12)->nullable();
            $table->boolean('multiply_qty')->nullable();
            $table->char(StorefrontCategoryShippingCosts::COLUMN, 2)->nullable();
            $table->timestamps();
        });

        foreach ([self::KUWAITI => 'KW', self::SAUDI => 'SA', self::NO_MARKET => null] as $id => $market) {
            DB::table('shipping_methods')->insert([
                'id' => $id,
                'creator_id' => 0,
                'creator_type' => 'admin',
                'title' => 'Method ' . $id,
                'cost' => 15,
                'duration' => 3,
                'status' => 1,
                StorefrontShippingMethods::COLUMN => $market,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('category_shipping_costs')->insert([
                'id' => $id,
                'seller_id' => 0,
                'category_id' => $id,
                'cost' => 15,
                'multiply_qty' => 0,
                StorefrontCategoryShippingCosts::COLUMN => $market,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        app(StorefrontShippingMethods::class)->forget();
    }

    /**
     * Every shipping route the middleware has a rule for, with the rule, as the real route table holds them.
     *
     * @return array<string, array{route: \Illuminate\Routing\Route, rule: string, method: string}>
     */
    private function enforcedRoutes(): array
    {
        $covered = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $controller = ltrim((string) $route->getControllerClass(), '\\');
            if (!in_array($controller, StaffShippingRoutes::controllers(), true)) {
                continue;
            }

            $rule = StaffShippingRoutes::ruleOf(controller: $controller, method: $route->getActionMethod());
            if ($rule !== null && StaffShippingRoutes::isEnforced($rule)) {
                $covered[$route->getActionMethod() . ' ' . $route->uri()] = [
                    'route' => $route,
                    'rule' => $rule,
                    'method' => array_values(array_diff($route->methods(), ['HEAD']))[0],
                ];
            }
        }

        return $covered;
    }

    /** What the middleware does with the request of a route: true when it let it through, false when it answered 404. */
    private function letsThrough(array $covered, int $row): bool
    {
        [$kind, $name] = array_pad(explode(':', $covered['rule'], 2), 2, '');
        $inPath = $kind === 'route';
        $uri = preg_replace_callback('/\{(\w+)\??\}/', static fn (array $match): string => $inPath && $match[1] === $name ? (string) $row : '1', '/' . $covered['route']->uri());
        $input = match ($kind) {
            'input' => [$name => $row],
            'inputs', 'costs' => [$name => [$row]],
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
            app(ScopeShippingToStaffCountries::class)->handle($request, static fn () => response('reached'));
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

    /** @return array<string, array<string, string>> every routed action of each shipping controller, with its uri */
    private function routedActions(): array
    {
        $actions = [];

        foreach (StaffShippingRoutes::controllers() as $controller) {
            $actions[$controller] = [];
        }

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $controller = ltrim((string) $route->getControllerClass(), '\\');
            if (array_key_exists($controller, $actions)) {
                $actions[$controller][$route->getActionMethod()] = $route->uri();
            }
        }

        foreach ($actions as $controller => $byAction) {
            ksort($byAction);
            $actions[$controller] = $byAction;
        }

        return $actions;
    }

    public function test_every_shipping_route_has_a_rule(): void
    {
        foreach ($this->routedActions() as $controller => $byAction) {
            $without = array_values(array_diff(array_keys($byAction), array_keys(StaffShippingRoutes::rulesOf($controller))));

            $this->assertSame([], $without, "a shipping route with no rule is refused to a restricted employee rather than let through. Give it one in {$controller}:\n - " . implode("\n - ", $without));
        }
    }

    public function test_no_rule_names_an_action_the_controller_no_longer_answers(): void
    {
        foreach ($this->routedActions() as $controller => $byAction) {
            $gone = array_values(array_diff(array_keys(StaffShippingRoutes::rulesOf($controller)), array_keys($byAction)));

            $this->assertSame([], $gone, "a rule for an action that is gone hides the next real one. Take it off {$controller}:\n - " . implode("\n - ", $gone));
        }
    }

    /**
     * What the shipping scope does NOT enforce, pinned one reason at a time so none of it can grow quietly and none of it can be mistaken
     * for enforcement. Three different reasons, which is why they are three different words and not one.
     */
    public function test_the_rules_that_are_not_enforced_are_the_named_ones(): void
    {
        $unenforced = [];

        foreach (StaffShippingRoutes::controllers() as $controller) {
            foreach (StaffShippingRoutes::rulesOf($controller) as $action => $rule) {
                if (!StaffShippingRoutes::isEnforced($rule)) {
                    $unenforced[class_basename($controller) . '::' . $action] = $rule;
                }
            }
        }

        $this->assertSame([
            'ShippingMethodController::index' => 'list_not_narrowed',
            'ShippingMethodController::add' => 'new_not_checked',
            'ShippingMethodController::updateShippingResponsibility' => 'platform_setting',
            'ShippingTypeController::addOrUpdate' => 'platform_setting',
            'ShippingZoneController::index' => 'market_not_recorded',
            'ShippingZoneController::store' => 'market_not_recorded',
            'ShippingZoneController::toggle' => 'market_not_recorded',
            'ShippingZoneController::update' => 'market_not_recorded',
            'ShippingZoneController::destroy' => 'market_not_recorded',
        ], $unenforced, 'what the shipping scope does not yet cover changed: narrow it, or say why a new action may narrow nothing');
    }

    /** The middleware is registered by each controller, so it runs after the sign-in check and the module permission of the route. */
    public function test_every_shipping_controller_registers_the_middleware(): void
    {
        foreach ([ShippingMethodController::class, CategoryShippingCostController::class, ShippingTypeController::class, ShippingZoneController::class] as $controller) {
            $registered = array_map(
                static fn (array $entry): string => is_string($entry['middleware']) ? $entry['middleware'] : '',
                app($controller)->getMiddleware(),
            );

            $this->assertContains(ScopeShippingToStaffCountries::class, $registered, "{$controller} must register it, or its rules are never read");
        }
    }

    public function test_the_shipping_routes_the_middleware_enforces_are_the_ones_that_name_a_row(): void
    {
        $this->assertSame([
            'getUpdateView admin/business-settings/shipping-method/update/{id}',
            'update admin/business-settings/shipping-method/update/{id}',
            'updateStatus admin/business-settings/shipping-method/update-status',
            'delete admin/business-settings/shipping-method/delete',
            'add admin/business-settings/category-shipping-cost/store',
        ], array_keys($this->enforcedRoutes()), 'the shipping routes that name a row changed: give the new one a rule');
    }

    public function test_every_route_that_names_a_row_refuses_another_countrys_row(): void
    {
        $this->staffHolding('KW');

        foreach ($this->enforcedRoutes() as $name => $covered) {
            $this->assertTrue($this->letsThrough($covered, self::KUWAITI), "{$name} must allow the employee's own row");
            $this->assertFalse($this->letsThrough($covered, self::SAUDI), "{$name} must refuse another country's row");
            $this->assertFalse($this->letsThrough($covered, self::UNKNOWN), "{$name} must answer an unknown id the same way");
        }
    }

    /** A row that records no market is the DEFAULT market's, which is StorefrontMarketRows' rule and not a second one invented here. */
    public function test_a_row_with_no_market_belongs_to_the_default_market(): void
    {
        $routes = $this->enforcedRoutes();
        $method = $routes['delete admin/business-settings/shipping-method/delete'];
        $cost = $routes['add admin/business-settings/category-shipping-cost/store'];

        $this->staffHolding('KW');
        $this->assertFalse($this->letsThrough($method, self::NO_MARKET), 'a Kuwaiti employee does not hold the default market');
        $this->assertFalse($this->letsThrough($cost, self::NO_MARKET), 'and the costs follow the same rule');

        $this->staffHolding('SA');
        $this->assertTrue($this->letsThrough($method, self::NO_MARKET), 'an employee of the default market does');
        $this->assertTrue($this->letsThrough($cost, self::NO_MARKET));
    }

    /** The two tables are judged apart: a cost id is never looked up among the methods, which would let one table's ids pass for the other's. */
    public function test_a_cost_id_is_read_in_the_costs_table_and_not_among_the_methods(): void
    {
        $this->staffHolding('KW');
        DB::table('category_shipping_costs')->where('id', self::SAUDI)->update([StorefrontCategoryShippingCosts::COLUMN => 'KW']);

        $routes = $this->enforcedRoutes();

        $this->assertTrue($this->letsThrough($routes['add admin/business-settings/category-shipping-cost/store'], self::SAUDI), 'the COST row 20 is now Kuwaiti');
        $this->assertFalse($this->letsThrough($routes['delete admin/business-settings/shipping-method/delete'], self::SAUDI), 'while the METHOD of the same id is still Saudi');
    }

    /** One id out of scope in a list refuses the whole request, so a bulk form is never half-applied. */
    public function test_one_cost_of_another_market_in_the_list_refuses_the_whole_form(): void
    {
        $this->staffHolding('KW');
        $covered = $this->enforcedRoutes()['add admin/business-settings/category-shipping-cost/store'];

        $this->assertTrue($this->bulkLetsThrough($covered, [self::KUWAITI]));
        $this->assertFalse($this->bulkLetsThrough($covered, [self::KUWAITI, self::SAUDI]), 'the Saudi row must stop the form');
    }

    /** An employee restricted to nothing is not restricted: the screens answer exactly as they always did. */
    public function test_an_unrestricted_employee_reaches_every_row(): void
    {
        $this->signInAsStaff(self::STAFF);
        $routes = $this->enforcedRoutes();

        foreach ([self::KUWAITI, self::SAUDI, self::NO_MARKET, self::UNKNOWN] as $row) {
            $this->assertTrue($this->letsThrough($routes['delete admin/business-settings/shipping-method/delete'], $row));
        }
    }

    /** And with the switch off, which is how the platform ships, nothing is narrowed at all. */
    public function test_nothing_is_narrowed_while_the_switch_is_off(): void
    {
        config(['gcc.staff.country_scopes' => false]);
        $this->staffHolding('KW');
        $routes = $this->enforcedRoutes();

        foreach ([self::SAUDI, self::NO_MARKET, self::UNKNOWN] as $row) {
            $this->assertTrue($this->letsThrough($routes['delete admin/business-settings/shipping-method/delete'], $row), 'the switch is off');
        }
    }

    /**
     * Before the tables carry a market every row is the default storefront's, because that is the only market this platform has ever had
     * rows for -- so an employee of the default market reaches them and an employee of another reaches none.
     */
    public function test_before_the_column_exists_every_row_is_the_default_markets(): void
    {
        Schema::dropIfExists('shipping_methods');
        Schema::create('shipping_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->boolean('status')->default(1);
            $table->timestamps();
        });
        DB::table('shipping_methods')->insert([['id' => self::SAUDI, 'title' => 'Legacy']]);
        app(StorefrontShippingMethods::class)->forget();

        $covered = $this->enforcedRoutes()['delete admin/business-settings/shipping-method/delete'];

        $this->staffHolding('KW');
        $this->assertFalse($this->letsThrough($covered, self::SAUDI), 'a Kuwaiti employee holds none of the rows of a platform with one market');

        $this->staffHolding('SA');
        $this->assertTrue($this->letsThrough($covered, self::SAUDI));
        $this->assertFalse($this->letsThrough($covered, self::UNKNOWN), 'and an id that names no row is still no row');
    }

    /**
     * The admin's category-cost writer keys the row it changes by its market as well as its category.
     *
     * Without that it keyed a row by seller and category alone, which names one row per category and not one per market: the database would
     * answer with whichever of two markets' rows it reached first, so a cost typed for Kuwait could be overwritten from the Saudi screen.
     */
    public function test_the_market_of_a_cost_row_is_part_of_the_key_its_writer_changes(): void
    {
        $costs = app(StorefrontCategoryShippingCosts::class);

        $this->assertSame(['storefront_country' => 'KW'], $costs->marketKeyOf(costId: self::KUWAITI));
        $this->assertSame(['storefront_country' => 'SA'], $costs->marketKeyOf(costId: self::SAUDI));
        $this->assertSame(['storefront_country' => null], $costs->marketKeyOf(costId: self::NO_MARKET), 'a row of no market stays a row of no market');
        $this->assertSame(['storefront_country' => null], $costs->marketKeyOf(costId: self::UNKNOWN), 'an id that names no row writes the default market\'s row, as it did before there were markets');

        Schema::dropIfExists('category_shipping_costs');
        Schema::create('category_shipping_costs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedInteger('category_id')->nullable();
            $table->timestamps();
        });
        $costs->forget();

        $this->assertSame([], $costs->marketKeyOf(costId: self::KUWAITI), 'before the migration the key is the one it always was');
    }

    /**
     * And the writer really uses it: two costs of the same category in two markets, and the one the form did not name is left alone.
     *
     * This is the test the direct one above does not replace. `marketKeyOf()` answering correctly proves nothing if the controller does not
     * ask it -- taking the call out of the controller leaves every other test here green, which is how a false proof looks.
     */
    public function test_a_cost_of_another_market_is_not_overwritten_from_the_default_markets_screen(): void
    {
        DB::table('category_shipping_costs')->insert([
            ['id' => 40, 'seller_id' => 0, 'category_id' => 7, 'cost' => 15, 'multiply_qty' => 0, StorefrontCategoryShippingCosts::COLUMN => 'SA', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 41, 'seller_id' => 0, 'category_id' => 7, 'cost' => 25, 'multiply_qty' => 0, StorefrontCategoryShippingCosts::COLUMN => 'KW', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $request = Request::create('/admin/business-settings/category-shipping-cost/store', 'POST', [
            'ids' => [41],
            'category_ids' => [7],
            'cost' => [99],
        ]);
        $this->app->instance('request', $request);

        app(CategoryShippingCostController::class)->add($request);

        $this->assertSame(99.0, (float) DB::table('category_shipping_costs')->where('id', 41)->value('cost'), 'the Kuwaiti cost the form named');
        $this->assertSame(15.0, (float) DB::table('category_shipping_costs')->where('id', 40)->value('cost'), 'the Saudi cost of the same category was typed in riyals and nobody asked for it to change');
    }

    /** @param list<int> $rows */
    private function bulkLetsThrough(array $covered, array $rows): bool
    {
        [, $name] = array_pad(explode(':', $covered['rule'], 2), 2, '');
        $request = Request::create('/' . $covered['route']->uri(), $covered['method'], [$name => $rows]);

        try {
            $route = Route::getRoutes()->match($request);
        } catch (NotFoundHttpException) {
            return false;
        }

        $request->setRouteResolver(static fn () => $route);
        $route->bind($request);
        $this->app->instance('request', $request);

        try {
            app(ScopeShippingToStaffCountries::class)->handle($request, static fn () => response('reached'));
        } catch (NotFoundHttpException) {
            return false;
        }

        return true;
    }
}
