<?php

namespace Tests\Feature\Storefront;

use App\Http\Middleware\ResolveStorefront;
use App\Services\Storefront\StorefrontContext;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Tests\TestCase;

class ResolveStorefrontMiddlewareTest extends TestCase
{
    public function test_the_storefront_alias_points_at_the_resolver_middleware(): void
    {
        $this->assertSame(ResolveStorefront::class, app(Router::class)->getMiddleware()['storefront'] ?? null);
    }

    public function test_the_alias_is_in_no_middleware_group_and_on_no_route(): void
    {
        $router = app(Router::class);
        $kernel = app(HttpKernel::class);

        $this->assertNotContains(ResolveStorefront::class, $kernel->getGlobalMiddleware());
        foreach (['web', 'api'] as $groupName) {
            $group = $router->getMiddlewareGroups()[$groupName] ?? [];
            $this->assertNotContains('storefront', $group, "The {$groupName} group must not resolve a storefront.");
            $this->assertNotContains(ResolveStorefront::class, $group, "The {$groupName} group must not resolve a storefront.");
        }

        foreach (Route::getRoutes() as $route) {
            $routeMiddleware = $route->middleware();
            $this->assertNotContains('storefront', $routeMiddleware, "Route [{$route->uri()}] must not resolve a storefront yet.");
            $this->assertNotContains(ResolveStorefront::class, $routeMiddleware, "Route [{$route->uri()}] must not resolve a storefront yet.");
        }
    }

    public function test_while_switched_off_a_query_choice_and_a_path_prefix_resolve_to_saudi(): void
    {
        $this->registerProbeRoutes();

        $this->probe('/__storefront-probe?storefront=AE')->assertExactJson(['country' => 'SA', 'currency' => 'SAR']);
        $this->probe('/ae/__storefront-probe')->assertExactJson(['country' => 'SA', 'currency' => 'SAR']);
    }

    public function test_switched_on_with_the_country_inactive_still_resolves_to_saudi(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $this->registerProbeRoutes();

        $this->probe('/__storefront-probe?storefront=AE')->assertExactJson(['country' => 'SA', 'currency' => 'SAR']);
        $this->probe('/ae/__storefront-probe')->assertExactJson(['country' => 'SA', 'currency' => 'SAR']);
    }

    public function test_switched_on_with_the_country_active_resolves_to_that_country(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.AE.active' => true]);
        $this->registerProbeRoutes();

        $this->probe('/__storefront-probe?storefront=AE')->assertExactJson(['country' => 'AE', 'currency' => 'AED']);
        $this->probe('/ae/__storefront-probe')->assertExactJson(['country' => 'AE', 'currency' => 'AED']);
    }

    public function test_an_unknown_code_falls_back_to_the_default_country(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.AE.active' => true]);
        $this->registerProbeRoutes();

        $this->probe('/__storefront-probe?storefront=ZZ')->assertExactJson(['country' => 'SA', 'currency' => 'SAR']);
    }

    public function test_an_array_choice_is_ignored_and_the_path_prefix_applies(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.AE.active' => true]);
        $this->registerProbeRoutes();

        $this->probe('/ae/__storefront-probe?storefront[]=sa')
            ->assertOk()
            ->assertExactJson(['country' => 'AE', 'currency' => 'AED']);
    }

    public function test_a_keyed_array_choice_is_ignored_and_the_default_applies(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.AE.active' => true]);
        $this->registerProbeRoutes();

        $this->probe('/__storefront-probe?storefront[x]=ae')
            ->assertOk()
            ->assertExactJson(['country' => 'SA', 'currency' => 'SAR']);
    }

    public function test_an_array_choice_resolves_to_saudi_while_switched_off(): void
    {
        $this->registerProbeRoutes();

        $this->probe('/ae/__storefront-probe?storefront[]=ae')
            ->assertOk()
            ->assertExactJson(['country' => 'SA', 'currency' => 'SAR']);
    }

    public function test_an_invalid_string_choice_falls_back_to_the_path_and_then_the_default(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.AE.active' => true]);
        $this->registerProbeRoutes();

        $this->probe('/ae/__storefront-probe?storefront=XX')->assertOk()->assertExactJson(['country' => 'AE', 'currency' => 'AED']);
        $this->probe('/__storefront-probe?storefront=%00')->assertOk()->assertExactJson(['country' => 'SA', 'currency' => 'SAR']);
        $this->probe('/__storefront-probe?storefront=')->assertOk()->assertExactJson(['country' => 'SA', 'currency' => 'SAR']);
    }

    public function test_an_inactive_default_country_fails_the_request_instead_of_serving_another_storefront(): void
    {
        config(['gcc.storefronts.countries.SA.active' => false]);
        $this->registerProbeRoutes();

        $this->expectException(InvalidArgumentException::class);

        $this->probe('/__storefront-probe');
    }

    public function test_no_session_cookie_or_currency_is_written_while_switched_off(): void
    {
        $this->assertWritesNothingToTheVisitor();
    }

    public function test_no_session_cookie_or_currency_is_written_while_switched_on(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.AE.active' => true]);

        $this->assertWritesNothingToTheVisitor();
    }

    private function assertWritesNothingToTheVisitor(): void
    {
        $this->registerProbeRoutes();
        $sessionBefore = session()->all();

        $response = $this->probe('/ae/__storefront-probe?storefront=AE');

        $response->assertOk();
        $this->assertSame([], $response->headers->getCookies());
        $this->assertSame($sessionBefore, session()->all());
        $this->assertFalse(session()->has('currency_code'));
    }

    private function probe(string $uri): TestResponse
    {
        return $this->withoutExceptionHandling()->get($uri);
    }

    private function registerProbeRoutes(): void
    {
        $describeStorefront = fn () => response()->json([
            'country' => app(StorefrontContext::class)->country,
            'currency' => app(StorefrontContext::class)->currency,
        ]);

        Route::middleware('storefront')->get('/__storefront-probe', $describeStorefront);
        Route::middleware('storefront')->get('/ae/__storefront-probe', $describeStorefront);
    }
}
