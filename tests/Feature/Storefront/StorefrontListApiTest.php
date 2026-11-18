<?php

namespace Tests\Feature\Storefront;

use App\Http\Controllers\RestAPI\v1\ConfigController;
use App\Http\Controllers\RestAPI\v1\StorefrontController;
use App\Http\Middleware\ResolveStorefront;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ApprovesStorefrontTax;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\TestCase;

class StorefrontListApiTest extends TestCase
{
    use ApprovesStorefrontTax;
    use BuildsStorefrontLegalDocuments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontLegalDocumentsTable();
    }

    public function test_flag_off_lists_only_saudi(): void
    {
        config(['gcc.storefronts.countries.AE.active' => true]);

        $this->getJson('/api/v1/storefronts')
            ->assertOk()
            ->assertExactJson([
                'enabled' => false,
                'default_country' => 'SA',
                'storefronts' => [
                    ['country' => 'SA', 'path' => 'sa', 'currency' => 'SAR', 'is_default' => true],
                ],
            ]);
    }

    public function test_flag_on_lists_active_countries(): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.countries.AE.active' => true,
            'gcc.storefronts.countries.OM.active' => true,
        ]);

        $this->getJson('/api/v1/storefronts', ['X-Storefront' => 'AE'])
            ->assertOk()
            ->assertExactJson([
                'enabled' => true,
                'default_country' => 'SA',
                'storefronts' => [
                    ['country' => 'SA', 'path' => 'sa', 'currency' => 'SAR', 'is_default' => true, 'sellable' => true],
                    ['country' => 'AE', 'path' => 'ae', 'currency' => 'AED', 'is_default' => false, 'sellable' => false],
                    ['country' => 'OM', 'path' => 'om', 'currency' => 'OMR', 'is_default' => false, 'sellable' => false],
                ],
            ]);
    }

    public function test_flag_on_lists_an_active_storefront_as_not_sellable_until_its_legal_documents_are_approved(): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.countries.AE.active' => true,
        ]);
        $this->publishRequiredLegalDocuments(country: 'AE', withheldType: 'refund');

        $this->getJson('/api/v1/storefronts')
            ->assertOk()
            ->assertJsonPath('storefronts.0.sellable', true)
            ->assertJsonPath('storefronts.1.country', 'AE')
            ->assertJsonPath('storefronts.1.sellable', false);
    }

    public function test_flag_on_lists_an_active_storefront_as_sellable_once_its_legal_documents_and_its_tax_are_approved(): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.countries.AE.active' => true,
        ]);
        $this->publishRequiredLegalDocuments(country: 'AE');
        $this->approveNoTaxFor('AE');

        $this->getJson('/api/v1/storefronts')
            ->assertOk()
            ->assertJsonPath('storefronts.1.country', 'AE')
            ->assertJsonPath('storefronts.1.sellable', true);
    }

    public function test_flag_on_without_the_legal_table_lists_every_non_default_storefront_as_not_sellable(): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.countries.AE.active' => true,
        ]);
        Schema::drop('storefront_legal_documents');

        $this->getJson('/api/v1/storefronts')
            ->assertOk()
            ->assertJsonPath('storefronts.0.sellable', true)
            ->assertJsonPath('storefronts.1.country', 'AE')
            ->assertJsonPath('storefronts.1.sellable', false);
    }

    public function test_endpoint_has_no_storefront_middleware_and_needs_no_auth(): void
    {
        $route = $this->apiRoute(uri: 'api/v1/storefronts');

        $this->assertSame(StorefrontController::class . '@index', $route->getActionName());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame(['api', 'api_lang', 'storefront.vary', 'throttle:60,1'], $route->middleware(), 'the only storefront middleware is the one that adds a header');
        $this->assertNotContains('storefront', $route->gatherMiddleware());
        $this->assertNotContains(ResolveStorefront::class, $route->gatherMiddleware());

        $this->assertGuest();
        $this->getJson('/api/v1/storefronts')->assertOk()->assertJsonStructure(['enabled', 'default_country', 'storefronts']);
        $this->assertGuest();
    }

    public function test_config_route_and_controller_are_untouched_by_storefronts(): void
    {
        $route = $this->apiRoute(uri: 'api/v1/config');

        $this->assertSame(ConfigController::class . '@configuration', $route->getActionName());
        $this->assertSame(['api', 'api_lang', 'storefront.vary'], $route->middleware(), 'the only storefront middleware is the one that adds a header');

        $configControllerSource = (string) file_get_contents(base_path('app/Http/Controllers/RestAPI/v1/ConfigController.php'));
        $this->assertStringNotContainsStringIgnoringCase('storefront', $configControllerSource);
        $this->assertStringNotContainsString('gcc.', $configControllerSource);
    }

    private function apiRoute(string $uri): RoutingRoute
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(fn (RoutingRoute $candidate) => $candidate->uri() === $uri);
        $this->assertNotNull($route, "Route [{$uri}] is not registered.");

        return $route;
    }
}
