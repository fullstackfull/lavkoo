<?php

namespace Tests\Feature\StaffScope;

use App\Http\Controllers\Admin\Marketplace\ProductModerationController;
use App\Http\Controllers\Admin\Shipping\ProductDestinationPermissionController;
use App\Http\Controllers\Admin\Product\ProductController;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Http\Middleware\ScopeProductsToStaffCountries;
use App\Services\Staff\StaffProductRoutes;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\BuildsStaffScopeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\Support\StaffProbe\UnlistedProductController;
use Tests\TestCase;

/**
 * Every route of the product screens that names a product answers a restricted employee like an unknown product (a 404, the same body) when the product is in
 * another country, never a 403, so that belonging to a country is not something to find out by asking (GCC-14c, slice 1).
 *
 * The routes come from the real route table: for each one the id is put where its rule says to look, in the path, in the input or in a list. The middleware is called
 * by itself on the real route (the controllers and their form requests are not the subject); a second group of tests goes through the whole stack to compare the
 * bytes of the two answers. Product 10 is Kuwaiti, 20 is Saudi, 99 does not exist; restock request 1 asks for product 10, 2 for product 20, 3 for a product that is gone.
 */
class StaffProductIdRoutesTest extends TestCase
{
    use BuildsStaffScopeWorld;
    use RestoresLanguageFiles;

    private const IN_SCOPE = 10;

    private const FOREIGN = 20;

    private const UNKNOWN = 99;

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

        $this->buildStaffAdminTables();
        $this->buildStaffProductTables();
        $this->runStaffScopeMigration();

        $this->insertStaffProduct(id: self::IN_SCOPE, country: 'KW');
        $this->insertStaffProduct(id: self::FOREIGN, country: 'SA');
        DB::table('restock_products')->insert([
            ['id' => 1, 'product_id' => self::IN_SCOPE, 'variant' => 'a'],
            ['id' => 2, 'product_id' => self::FOREIGN, 'variant' => 'b'],
            ['id' => 3, 'product_id' => self::UNKNOWN, 'variant' => 'c'],
        ]);
        DB::table('digital_product_variations')->insert([
            ['id' => 1, 'product_id' => self::IN_SCOPE],
            ['id' => 2, 'product_id' => self::FOREIGN],
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function asKuwaitEmployee(int $adminId = 5): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: $adminId, countries: ['KW']);
        $this->signInAsStaff(adminId: $adminId);
    }

    // ---- the routes of the real table -------------------------------------------------------------------------------------------

    /** @return list<array{route: RoutingRoute, method: string, rule: string}> every route of a covered controller, with the rule of its action */
    private function coveredRoutes(): array
    {
        $routes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $controller = ltrim((string) $route->getControllerClass(), '\\');
            $rule = in_array($controller, StaffProductRoutes::controllers(), true) ? StaffProductRoutes::ruleOf(controller: $controller, method: $route->getActionMethod()) : null;
            if ($rule !== null) {
                $routes[] = ['route' => $route, 'method' => array_values(array_diff($route->methods(), ['HEAD']))[0], 'rule' => $rule];
            }
        }

        return $routes;
    }

    /** @return list<array{route: RoutingRoute, method: string, rule: string}> the covered routes whose rule names a product, or restock request, by an id */
    private function idRoutes(): array
    {
        return array_values(array_filter($this->coveredRoutes(), static fn (array $covered): bool => preg_match('/^(route|input|inputs|restock|variation):/', $covered['rule']) === 1));
    }

    /**
     * A request for a route, with the id (or ids) placed where the rule looks for it.
     *
     * @return array{uri: string, input: array<string, mixed>}
     */
    private function placing(RoutingRoute $route, string $rule, mixed $value): array
    {
        [$kind, $name] = array_pad(explode(':', $rule, 2), 2, '');
        $name = rtrim($name, '?');
        $inPath = in_array($kind, ['route', 'restock', 'variation'], true);

        $uri = preg_replace_callback('/\{(\w+)\??\}/', static fn (array $match): string => $inPath && $match[1] === $name ? (is_scalar($value) ? rawurlencode((string) $value) : 'x') : ($match[1] === 'addedBy' ? 'in-house' : 'in_house'), '/' . $route->uri());

        return ['uri' => $uri, 'input' => in_array($kind, ['input', 'inputs'], true) ? [$name => $value] : []];
    }

    /** What the middleware does with the request of a route: true when it let the request through, false when it answered 404. */
    private function letsThrough(array $covered, mixed $value): bool
    {
        $place = $this->placing(route: $covered['route'], rule: $covered['rule'], value: $value);
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
            app(ScopeProductsToStaffCountries::class)->handle($request, static fn () => response('reached'));
        } catch (NotFoundHttpException) {
            return false;
        }

        return true;
    }

    /** What a request puts where the rule looks, to name a product: the id, a list of one, or the restock request or digital variation that belongs to it. */
    private function valueFor(string $rule, int $product): mixed
    {
        return match (true) {
            str_starts_with($rule, 'inputs:') => [$product],
            str_starts_with($rule, 'restock:'), str_starts_with($rule, 'variation:') => match ($product) {
                self::IN_SCOPE => 1,
                self::FOREIGN => 2,
                default => 99,
            },
            default => $product,
        };
    }

    public function test_the_walk_finds_the_routes_of_both_controllers_and_every_kind_of_rule(): void
    {
        $kinds = array_values(array_unique(array_map(static fn (array $covered): string => explode(':', $covered['rule'])[0], $this->coveredRoutes())));
        sort($kinds);
        $controllers = array_values(array_unique(array_map(static fn (array $covered): string => ltrim((string) $covered['route']->getControllerClass(), '\\'), $this->coveredRoutes())));
        sort($controllers);

        $this->assertSame(['input', 'inputs', 'list', 'new', 'none', 'restock', 'route', 'variation'], $kinds);
        $this->assertSame(
            [ProductModerationController::class, ProductController::class, ProductDestinationPermissionController::class],
            $controllers,
        );
        // 45 since the product-identity lane added POST admin/products/copy-to-country/{id}, which is a route of a covered controller and so
        // must carry a rule (route:id, the product it copies named by its route parameter). 47 since the shipping destination permissions
        // (H1-03, H1-04) joined slice 1: its two actions take the product id in the request, so the walk below exercises both with a
        // foreign product and proves each answers like a product that does not exist.
        $this->assertCount(47, $this->coveredRoutes());
    }

    // ---- a restricted employee ---------------------------------------------------------------------------------------------------

    public function test_a_product_in_the_scope_passes_on_every_route_that_names_one(): void
    {
        $this->asKuwaitEmployee();

        foreach ($this->idRoutes() as $covered) {
            $this->assertTrue($this->letsThrough($covered, $this->valueFor(rule: $covered['rule'], product: self::IN_SCOPE)), $covered['method'] . ' ' . $covered['route']->uri() . ' [' . $covered['rule'] . ']');
        }
    }

    public function test_a_product_of_another_country_is_refused_on_every_route_that_names_one(): void
    {
        $this->asKuwaitEmployee();

        foreach ($this->idRoutes() as $covered) {
            $this->assertFalse($this->letsThrough($covered, $this->valueFor(rule: $covered['rule'], product: self::FOREIGN)), $covered['method'] . ' ' . $covered['route']->uri() . ' [' . $covered['rule'] . ']');
        }
    }

    public function test_a_product_that_does_not_exist_is_refused_exactly_like_one_of_another_country(): void
    {
        $this->asKuwaitEmployee();

        foreach ($this->idRoutes() as $covered) {
            $this->assertFalse($this->letsThrough($covered, $this->valueFor(rule: $covered['rule'], product: self::UNKNOWN)), $covered['method'] . ' ' . $covered['route']->uri() . ' [' . $covered['rule'] . ']');
        }
    }

    public function test_a_list_of_products_is_refused_whole_when_one_of_them_is_out_of_scope_or_unknown(): void
    {
        $this->asKuwaitEmployee();
        $lists = array_values(array_filter($this->idRoutes(), static fn (array $covered): bool => str_starts_with($covered['rule'], 'inputs:')));

        $this->assertNotEmpty($lists);
        foreach ($lists as $covered) {
            $label = $covered['method'] . ' ' . $covered['route']->uri();
            $this->assertTrue($this->letsThrough($covered, [self::IN_SCOPE]), $label);
            $this->assertTrue($this->letsThrough($covered, [(string) self::IN_SCOPE, self::IN_SCOPE]), $label . ' with a repeat');
            $this->assertTrue($this->letsThrough($covered, []), $label . ' with none: the action refuses an empty selection itself');
            $this->assertFalse($this->letsThrough($covered, [self::IN_SCOPE, self::FOREIGN]), $label . ' with a foreign one');
            $this->assertFalse($this->letsThrough($covered, [self::IN_SCOPE, self::UNKNOWN]), $label . ' with an unknown one');
            $this->assertFalse($this->letsThrough($covered, [self::IN_SCOPE, 'x']), $label . ' with one that is no id');
        }
    }

    public function test_a_value_that_is_not_a_product_id_is_refused_whatever_the_database_would_make_of_it(): void
    {
        $this->asKuwaitEmployee();
        $single = array_values(array_filter($this->idRoutes(), static fn (array $covered): bool => preg_match('/^(route|input):/', $covered['rule']) === 1 && !str_ends_with($covered['rule'], '?')));

        foreach ($single as $covered) {
            foreach (['10abc', '010', '10.0', '1e1', ' 10', '10 ', '0', '-10', 'abc', '', [10], ['10']] as $junk) {
                $this->assertFalse($this->letsThrough($covered, $junk), $covered['method'] . ' ' . $covered['route']->uri() . ' with ' . json_encode($junk));
            }
        }
    }

    public function test_a_missing_id_is_refused_when_the_route_needs_one_and_let_through_when_it_is_optional(): void
    {
        $this->asKuwaitEmployee();

        foreach ($this->idRoutes() as $covered) {
            if (preg_match('/^input:/', $covered['rule']) !== 1) {
                continue;
            }
            $this->assertSame(str_ends_with($covered['rule'], '?'), $this->letsThrough($covered, null), $covered['method'] . ' ' . $covered['route']->uri() . ' [' . $covered['rule'] . ']');
        }
    }

    public function test_a_restock_request_whose_product_is_gone_is_refused(): void
    {
        $this->asKuwaitEmployee();
        $restock = array_values(array_filter($this->idRoutes(), static fn (array $covered): bool => str_starts_with($covered['rule'], 'restock:')));

        $this->assertCount(1, $restock);
        $this->assertTrue($this->letsThrough($restock[0], 1));
        $this->assertFalse($this->letsThrough($restock[0], 2), 'a request for a product of another country');
        $this->assertFalse($this->letsThrough($restock[0], 3), 'a request for a product that is gone');
        $this->assertFalse($this->letsThrough($restock[0], 99), 'a request that does not exist');
        foreach (['abc', '1abc', '01', '1.0', '', '0', '-1'] as $junk) {
            $this->assertFalse($this->letsThrough($restock[0], $junk), 'the id of a restock request that is no id: ' . json_encode($junk));
        }
    }

    public function test_the_routes_that_name_no_product_pass_a_restricted_employee(): void
    {
        $this->asKuwaitEmployee();
        $others = array_values(array_filter($this->coveredRoutes(), static fn (array $covered): bool => !in_array(explode(':', $covered['rule'])[0], ['route', 'input', 'inputs', 'restock', 'variation'], true)));

        $this->assertGreaterThan(10, count($others));
        foreach ($others as $covered) {
            $this->assertTrue($this->letsThrough($covered, null), $covered['method'] . ' ' . $covered['route']->uri() . ' [' . $covered['rule'] . ']');
        }
    }

    public function test_an_employee_restricted_to_nothing_is_refused_every_id_even_of_a_country_that_exists(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 5, countries: ['zz']);
        $this->signInAsStaff(adminId: 5);

        foreach ($this->idRoutes() as $covered) {
            $this->assertFalse($this->letsThrough($covered, $this->valueFor(rule: $covered['rule'], product: self::IN_SCOPE)), $covered['method'] . ' ' . $covered['route']->uri());
        }
    }

    public function test_a_product_in_any_of_several_countries_of_the_scope_passes(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 5, countries: ['KW', 'SA']);
        $this->signInAsStaff(adminId: 5);

        foreach ($this->idRoutes() as $covered) {
            $this->assertTrue($this->letsThrough($covered, $this->valueFor(rule: $covered['rule'], product: self::FOREIGN)), $covered['method'] . ' ' . $covered['route']->uri());
        }
    }

    // ---- who is not restricted -----------------------------------------------------------------------------------------------------

    public function test_nothing_changes_for_the_master_admin_an_employee_without_countries_or_a_switch_that_is_off(): void
    {
        $this->giveCountries(adminId: 5, countries: ['KW']);
        $this->giveCountries(adminId: 1, countries: ['KW']);

        $cases = [
            'switch off, employee with a country' => [false, 5],
            'switch on, master admin with a country row' => [true, 1],
            'switch on, employee with no country' => [true, 2],
        ];
        foreach ($cases as $label => [$switchOn, $adminId]) {
            config(['gcc.staff.country_scopes' => $switchOn]);
            $this->signInAsStaff(adminId: $adminId);

            foreach ($this->coveredRoutes() as $covered) {
                $this->assertTrue($this->letsThrough($covered, $this->valueFor(rule: $covered['rule'], product: self::FOREIGN)), $label . ': ' . $covered['method'] . ' ' . $covered['route']->uri());
                $this->assertTrue($this->letsThrough($covered, $this->valueFor(rule: $covered['rule'], product: self::UNKNOWN)), $label . ': an unknown id is the controller\'s to answer, as it always was');
            }
        }
    }

    public function test_an_empty_list_of_products_asks_the_database_nothing_even_for_a_restricted_employee(): void
    {
        $this->asKuwaitEmployee();
        $this->app->instance('request', Request::create('/admin/products/bulk-product-status', 'POST'));
        app(\App\Services\Staff\StaffCountryScope::class)->bind(request: request(), admin: \App\Models\Admin::query()->findOrFail(5));
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertTrue(app(\App\Services\Staff\StaffCountryScope::class)->coversEveryProduct(productIds: []));

        $this->assertSame([], DB::getQueryLog());
    }

    public function test_with_the_switch_off_the_middleware_asks_the_database_nothing(): void
    {
        $this->giveCountries(adminId: 5, countries: ['KW']);
        $this->signInAsStaff(adminId: 5);
        $covered = array_values(array_filter($this->idRoutes(), static fn (array $candidate): bool => $candidate['rule'] === 'route:id'))[0];
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertTrue($this->letsThrough($covered, self::FOREIGN));

        $this->assertSame([], DB::getQueryLog());
    }

    // ---- the whole stack: the same bytes for a product of another country and for one that does not exist ----------------------------

    /**
     * What a client is told: the status and the body, without what APP_DEBUG adds to an error answer (CI runs with it on): the trace names the line of the test that
     * made the request, so two requests for the same thing never answer the same bytes.
     *
     * @return array{0: int, 1: mixed}
     */
    private function answer(\Illuminate\Testing\TestResponse $response): array
    {
        $body = json_decode((string) $response->getContent(), true);

        return [$response->getStatusCode(), is_array($body) ? Arr::except($body, ['exception', 'file', 'line', 'trace']) : $response->getContent()];
    }

    private function ask(array $covered, mixed $value, bool $json): \Illuminate\Testing\TestResponse
    {
        $place = $this->placing(route: $covered['route'], rule: $covered['rule'], value: $value);
        $headers = $json ? ['Accept' => 'application/json'] : [];

        return $covered['method'] === 'GET' ? $this->get($place['uri'] . '?' . http_build_query($place['input']), $headers) : $this->call($covered['method'], $place['uri'], $place['input'], [], [], $this->transformHeadersToServerVars($headers));
    }

    public function test_every_route_answers_a_foreign_product_with_the_bytes_it_answers_an_unknown_one_and_never_with_a_403(): void
    {
        $this->asKuwaitEmployee();

        foreach ($this->idRoutes() as $covered) {
            foreach ([false, true] as $json) {
                $label = $covered['method'] . ' ' . $covered['route']->uri() . ($json ? ' as json' : ' as html');
                $foreign = $this->ask($covered, $this->valueFor(rule: $covered['rule'], product: self::FOREIGN), $json);
                $unknown = $this->ask($covered, $this->valueFor(rule: $covered['rule'], product: self::UNKNOWN), $json);

                $this->assertSame(404, $foreign->getStatusCode(), $label);
                $this->assertSame($this->answer($unknown), $this->answer($foreign), $label);
            }
        }
    }

    public function test_the_refusal_is_a_404_that_names_neither_the_product_nor_a_reason(): void
    {
        $this->asKuwaitEmployee();
        $covered = array_values(array_filter($this->idRoutes(), static fn (array $candidate): bool => $candidate['rule'] === 'route:id' && $candidate['method'] === 'GET'))[0];

        $html = $this->ask($covered, self::FOREIGN, false);
        $json = $this->ask($covered, self::FOREIGN, true);

        $this->assertSame(404, $html->getStatusCode());
        $this->assertSame(404, $json->getStatusCode());
        $this->assertSame('', $json->json('message'), 'no message: nothing that tells why');
        $this->assertStringNotContainsStringIgnoringCase('Product 20', $html->getContent());
        $this->assertStringNotContainsStringIgnoringCase('forbidden', $html->getContent() . $json->getContent());
    }

    // ---- who is told what, and in what order ----------------------------------------------------------------------------------------

    public function test_an_employee_without_the_permission_of_the_screen_is_told_so_before_the_scope_looks_at_any_product(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 6, countries: ['KW']);
        $this->signInAsStaff(adminId: 6);

        foreach ([self::IN_SCOPE, self::FOREIGN, self::UNKNOWN] as $product) {
            $response = $this->delete('/admin/products/delete/' . $product);

            $this->assertSame(302, $response->getStatusCode(), 'product ' . $product);
            $this->assertStringEndsWith('/admin/dashboard', (string) $response->headers->get('Location'), 'the permission check answered, whatever the product: the scope reveals nothing to an employee who may not be here');
        }
    }

    public function test_the_middleware_of_the_scope_runs_after_the_permission_of_the_route_on_every_covered_route(): void
    {
        foreach ($this->coveredRoutes() as $covered) {
            $middleware = $covered['route']->gatherMiddleware();
            $position = array_search(ScopeProductsToStaffCountries::class, $middleware, true);
            $permission = array_values(array_filter(array_keys($middleware), static fn ($index): bool => str_starts_with((string) $middleware[$index], 'module:')));

            $this->assertNotFalse($position, $covered['method'] . ' ' . $covered['route']->uri() . ' does not run the middleware of the scope');
            $this->assertSame([], array_filter($permission, static fn ($index): bool => $index > $position), $covered['method'] . ' ' . $covered['route']->uri() . ' asks for a permission after the scope has looked');
            $this->assertGreaterThan(array_search('admin', $middleware, true), $position, 'the sign-in check comes first');
        }
    }

    public function test_only_the_covered_controllers_register_the_middleware_of_the_scope(): void
    {
        $registering = [];
        foreach ([app_path('Http/Controllers'), base_path('Modules')] as $root) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'ScopeProductsToStaffCountries')) {
                    $registering[] = $file->getPathname();
                }
            }
        }
        $covered = array_map(static fn (string $controller): string => (string) (new \ReflectionClass($controller))->getFileName(), StaffProductRoutes::controllers());
        sort($registering);
        sort($covered);

        $this->assertSame($covered, $registering, 'slice 1 covers the product screens only');
    }

    // ---- a route that has no rule ---------------------------------------------------------------------------------------------------

    public function test_a_route_of_a_covered_controller_that_has_no_rule_is_refused_to_a_restricted_employee_and_open_to_the_rest(): void
    {
        Route::get('admin/products/zz-unlisted', [UnlistedProductController::class, 'unlisted'])->middleware(['web', 'admin', 'module:catalog']);

        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 5, countries: ['KW']);

        $this->signInAsStaff(adminId: 5)->getJson('/admin/products/zz-unlisted')->assertNotFound();
        $this->signInAsStaff(adminId: 2)->getJson('/admin/products/zz-unlisted')->assertOk()->assertJson(['reached' => 'unlisted']);
        $this->signInAsStaff(adminId: 1)->getJson('/admin/products/zz-unlisted')->assertOk();
    }

    // ---- the moderation screen is covered too ----------------------------------------------------------------------------------------

    public function test_the_moderation_routes_are_covered_by_the_same_rules(): void
    {
        $rules = StaffProductRoutes::rulesOf(controller: ProductModerationController::class);

        $this->assertSame(['index' => 'none', 'history' => 'route:productId', 'moderate' => 'input:product_id', 'bulk' => 'inputs:product_ids'], $rules);
        $this->assertNotEmpty(StaffProductRoutes::rulesOf(controller: ProductController::class));
    }
}
