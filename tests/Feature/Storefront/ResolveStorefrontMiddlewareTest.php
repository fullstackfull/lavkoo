<?php

namespace Tests\Feature\Storefront;

use App\Http\Middleware\ResolveStorefront;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
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

    /**
     * The alias is on the storefront-facing routes (the product and theme routes) and on the routes that start a new sale, which
     * also carry the sale gate (GCC-11). It is in no named group, and it is not on the storefront list or the config route, which
     * must not depend on a storefront.
     */
    public function test_the_alias_resolves_the_storefront_only_on_storefront_facing_routes(): void
    {
        $router = app(Router::class);
        $kernel = app(HttpKernel::class);

        $this->assertNotContains(ResolveStorefront::class, $kernel->getGlobalMiddleware());
        foreach (['web', 'api'] as $groupName) {
            $this->assertNotContains('storefront', $router->getMiddlewareGroups()[$groupName] ?? [], "The {$groupName} group stays unchanged.");
        }

        $resolving = [];
        $startingASale = [];
        foreach (Route::getRoutes() as $route) {
            if (in_array('storefront', $route->middleware(), true)) {
                $resolving[] = $route->uri();
                if (in_array('storefront.sellable', $route->middleware(), true)) {
                    $startingASale[] = $route->uri();
                }
            }
        }

        $this->assertNotEmpty(array_filter($resolving, fn (string $uri) => str_starts_with($uri, 'api/v1/products')));
        $this->assertNotEmpty(array_filter($resolving, fn (string $uri) => str_starts_with($uri, 'api/v1/theme')));
        $this->assertNotEmpty($startingASale);
        $this->assertNotContains('api/v1/storefronts', $resolving);
        $this->assertNotContains('api/v1/config', $resolving);
        foreach ($resolving as $uri) {
            $isApi = str_starts_with($uri, 'api/');
            $isStorefrontFacing = str_starts_with($uri, 'api/v1/products') || str_starts_with($uri, 'api/v1/theme');
            $this->assertTrue(
                !$isApi || $isStorefrontFacing || in_array($uri, $startingASale, true),
                "API route [{$uri}] must not resolve a storefront unless it starts a new sale.",
            );
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

    public function test_while_switched_off_the_header_resolves_to_saudi(): void
    {
        config(['gcc.storefronts.countries.AE.active' => true]);
        $this->registerProbeRoutes();

        $this->probe('/__storefront-probe', ['X-Storefront' => 'AE'])->assertExactJson(['country' => 'SA', 'currency' => 'SAR']);
        $this->probe('/ae/__storefront-probe?storefront=AE', ['X-Storefront' => 'AE'])
            ->assertExactJson(['country' => 'SA', 'currency' => 'SAR']);
    }

    public function test_switched_on_an_active_header_country_is_resolved(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.AE.active' => true]);
        $this->registerProbeRoutes();

        $this->probe('/__storefront-probe', ['X-Storefront' => 'ae'])->assertExactJson(['country' => 'AE', 'currency' => 'AED']);
    }

    public function test_the_header_beats_the_path_prefix(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.AE.active' => true, 'gcc.storefronts.countries.KW.active' => true]);
        $this->registerProbeRoutes();

        $this->probe('/ae/__storefront-probe', ['X-Storefront' => 'KW'])->assertExactJson(['country' => 'KW', 'currency' => 'KWD']);
    }

    public function test_query_choice_beats_header(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.AE.active' => true, 'gcc.storefronts.countries.KW.active' => true]);
        $this->registerProbeRoutes();

        $this->probe('/__storefront-probe?storefront=KW', ['X-Storefront' => 'AE'])->assertExactJson(['country' => 'KW', 'currency' => 'KWD']);
        $this->probe('/__storefront-probe?storefront=XX', ['X-Storefront' => 'AE'])->assertExactJson(['country' => 'AE', 'currency' => 'AED']);
    }

    public function test_unknown_or_inactive_header_falls_back_to_path_then_default(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.AE.active' => true]);
        $this->registerProbeRoutes();

        foreach (['ZZ', 'KW', '', '   '] as $headerValue) {
            $this->probe('/ae/__storefront-probe', ['X-Storefront' => $headerValue])
                ->assertExactJson(['country' => 'AE', 'currency' => 'AED']);
            $this->probe('/__storefront-probe', ['X-Storefront' => $headerValue])
                ->assertExactJson(['country' => 'SA', 'currency' => 'SAR']);
        }
    }

    public function test_a_non_string_header_is_ignored(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.AE.active' => true, 'gcc.storefronts.countries.KW.active' => true]);
        $this->registerProbeRoutes();

        $this->probe('/ae/__storefront-probe', ['X-Storefront' => ['KW', 'KW']])
            ->assertOk()
            ->assertExactJson(['country' => 'AE', 'currency' => 'AED']);
        $this->probe('/__storefront-probe', ['X-Storefront' => ['KW', 'AE']])
            ->assertOk()
            ->assertExactJson(['country' => 'SA', 'currency' => 'SAR']);
    }

    public function test_while_switched_on_every_api_v1_answer_says_it_varies_by_storefront_even_where_the_storefront_middleware_is_not_bound(): void
    {
        config(['gcc.storefronts.enabled' => true]);

        $response = $this->getJson('/api/v1/storefronts')->assertOk();

        $this->assertContains('X-Storefront', array_map('trim', explode(',', implode(',', $response->headers->all('vary')))));
    }

    public function test_while_switched_off_no_api_v1_answer_varies_by_storefront(): void
    {
        $response = $this->getJson('/api/v1/storefronts')->assertOk();

        $this->assertNotContains('X-Storefront', array_map('trim', explode(',', implode(',', $response->headers->all('vary')))));
    }

    public function test_while_switched_off_a_missing_or_broken_configuration_still_serves_saudi(): void
    {
        $this->registerProbeRoutes();

        $broken = [
            'no gcc configuration at all (a cached config that predates it)' => null,
            'an unknown default country' => ['storefronts' => ['enabled' => false, 'default_country' => 'XX', 'countries' => ['SA' => ['path' => 'sa', 'currency' => 'SAR', 'active' => true]]]],
            'no countries' => ['storefronts' => ['enabled' => false, 'default_country' => 'SA', 'countries' => []]],
            'an inactive default country' => ['storefronts' => ['enabled' => false, 'default_country' => 'SA', 'countries' => ['SA' => ['path' => 'sa', 'currency' => 'SAR', 'active' => false]]]],
            'only the flag' => ['storefronts' => ['enabled' => false]],
        ];

        foreach ($broken as $case => $gcc) {
            config(['gcc' => $gcc]);

            $this->probe('/__storefront-probe')->assertExactJson(['country' => 'SA', 'currency' => 'SAR']);
            $this->assertSame('SA', StorefrontResolver::fromConfig()->defaultCountry(), $case);
        }
    }

    public function test_while_switched_off_a_valid_configuration_keeps_its_own_default_country(): void
    {
        config(['gcc.storefronts.default_country' => 'AE', 'gcc.storefronts.countries.AE.active' => true]);

        $this->assertSame('AE', StorefrontResolver::fromConfig()->defaultCountry());
    }

    public function test_switched_on_a_missing_configuration_still_fails_loudly(): void
    {
        config(['gcc' => ['storefronts' => ['enabled' => true]]]);

        $this->expectException(InvalidArgumentException::class);

        StorefrontResolver::fromConfig();
    }

    public function test_switched_on_an_inactive_default_country_fails_the_request_instead_of_serving_another_storefront(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.SA.active' => false]);
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

        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.AE.active' => true]);

        $headerResponse = $this->probe('/__storefront-probe', ['X-Storefront' => 'AE']);

        $headerResponse->assertExactJson(['country' => 'AE', 'currency' => 'AED']);
        $this->assertSame([], $headerResponse->headers->getCookies());
        $this->assertSame($sessionBefore, session()->all());
        $this->assertFalse(session()->has('currency_code'));
    }

    private function probe(string $uri, array $headers = []): TestResponse
    {
        return $this->withoutExceptionHandling()->get($uri, $headers);
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
