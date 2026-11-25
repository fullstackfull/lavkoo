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
                    ['country' => 'SA', 'path' => 'sa', 'currency' => 'SAR', 'is_default' => true, 'sellable' => true, 'state' => 'active'],
                    ['country' => 'AE', 'path' => 'ae', 'currency' => 'AED', 'is_default' => false, 'sellable' => false, 'state' => 'draft'],
                    ['country' => 'OM', 'path' => 'om', 'currency' => 'OMR', 'is_default' => false, 'sellable' => false, 'state' => 'draft'],
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

    public function test_flag_on_lists_an_active_storefront_as_not_sellable_while_the_independent_legal_review_is_missing(): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.countries.AE.active' => true,
        ]);
        $this->publishRequiredLegalDocuments(country: 'AE', reviewed: false);
        $this->approveNoTaxFor('AE');

        $this->getJson('/api/v1/storefronts')
            ->assertOk()
            ->assertJsonPath('storefronts.1.country', 'AE')
            ->assertJsonPath('storefronts.1.sellable', false);
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

    /**
     * The configuration endpoint is not narrowed by a storefront: it carries no storefront middleware, and it reads no flag of its own.
     *
     * It states storefront things, and it asks ONE service for all of them -- the money service -- which is what this counts. The answer
     * does vary by the `X-Storefront` header, and always did: `storefront.vary` is on the route for exactly that reason, so "not
     * narrowed" means no middleware BINDS a storefront here and the controller resolves none itself, not that the answer is identical
     * for every market.
     *
     * Two asks, and each is named so a third cannot arrive unannounced:
     *
     *   - `apiMoney()` (B-03), so an app formats prices in the currency its storefront trades in, with that currency's decimals,
     *     instead of working them out from a numeric setting;
     *   - `currencyCodeInForce()` (D2-V1 / J-09.4), so the payment-method list this market may offer can be judged against the money it
     *     trades in -- a gateway with no currency list for this market, and a three-decimal market whose gateway nobody has verified,
     *     are the two cases that need it.
     *
     * Both statements are absent while the markets are off, which
     * {@see test_the_config_answer_can_carry_storefront_money_only_from_the_money_service} pins here and StorefrontMoneyAndSeoTest
     * proves against the service that produces them.
     */
    public function test_the_config_route_is_not_narrowed_by_a_storefront(): void
    {
        $route = $this->apiRoute(uri: 'api/v1/config');

        $this->assertSame(ConfigController::class . '@configuration', $route->getActionName());
        $this->assertSame(['api', 'api_lang', 'storefront.vary'], $route->middleware(), 'the only storefront middleware is the one that adds a header');
        $this->assertNotContains('storefront', $route->gatherMiddleware());
        $this->assertNotContains(ResolveStorefront::class, $route->gatherMiddleware());

        $configControllerSource = (string) file_get_contents(base_path('app/Http/Controllers/RestAPI/v1/ConfigController.php'));
        $this->assertStringNotContainsString('gcc.', $configControllerSource, 'the controller reads no storefront flag of its own');
        $this->assertSame(
            2,
            preg_match_all('/StorefrontCurrency/', $configControllerSource) - 1,
            'the only storefront service the controller names is the money service, and it asks it twice: the money of the market, and '
            . 'the currency that market trades in so the payment-method list can be judged against it. A third ask, or any other '
            . 'storefront service, is a decision somebody should make deliberately rather than discover here.',
        );
    }

    /**
     * The dormancy pin for the configuration answer: the controller cannot name the key itself, it can only add what the money service
     * hands it, and that service hands it nothing while the markets are off — proved against the service in
     * {@see StorefrontMoneyAndSeoTest::test_with_the_markets_switched_off_every_number_tag_and_file_is_what_it_is_today}.
     */
    public function test_the_config_answer_can_carry_storefront_money_only_from_the_money_service(): void
    {
        $configControllerSource = (string) file_get_contents(base_path('app/Http/Controllers/RestAPI/v1/ConfigController.php'));

        $this->assertStringContainsString('app(StorefrontCurrency::class)->apiMoney()', $configControllerSource);
        $this->assertStringNotContainsString('storefront_money', $configControllerSource, 'the controller never writes the key itself');
    }

    private function apiRoute(string $uri): RoutingRoute
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(fn (RoutingRoute $candidate) => $candidate->uri() === $uri);
        $this->assertNotNull($route, "Route [{$uri}] is not registered.");

        return $route;
    }
}
