<?php

namespace Tests\Feature\Storefront;

use App\Http\Controllers\RestAPI\v1\ConfigController;
use App\Http\Controllers\RestAPI\v1\StorefrontController;
use App\Http\Middleware\ResolveStorefront;
use App\Services\Theme\LocalisedSettings;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    /**
     * I1-16: each listed storefront says which languages it presents, which it writes in and which way that one reads.
     *
     * A market with no list of its own is listed with the PLATFORM's languages and not with an empty list: an app that read an empty list
     * would offer no language at all, and "this market has not been given a list yet" means "it presents what the shop presents".
     */
    public function test_each_storefront_is_listed_with_the_languages_it_presents(): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.countries.AE.active' => true,
            'gcc.storefronts.language.lists_by_country' => ['AE' => ['ar']],
            'gcc.storefronts.language.by_country' => ['AE' => 'ar'],
        ]);
        // This class deliberately builds no `business_settings`, so the shop's language list has to be built here for this one test.
        Schema::dropIfExists('business_settings');
        Schema::create('business_settings', static function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->string('type')->nullable();
            $table->longText('value')->nullable();
            $table->timestamps();
        });
        DB::table('business_settings')->insert(['type' => 'language', 'value' => json_encode([
            ['id' => 1, 'name' => 'English', 'direction' => 'ltr', 'code' => 'en', 'status' => 1, 'default' => true],
            ['id' => 2, 'name' => 'Arabic', 'direction' => 'rtl', 'code' => 'ar', 'status' => 1, 'default' => false],
        ])]);
        Cache::flush();
        LocalisedSettings::forget();
        $this->beforeApplicationDestroyed(static fn () => Schema::dropIfExists('business_settings'));

        $listed = collect($this->getJson('/api/v1/storefronts')->assertOk()->json('storefronts'))->keyBy('country');

        $this->assertSame(['ar'], $listed['AE']['languages'], 'the market presents its own list');
        $this->assertSame('ar', $listed['AE']['default_language']);
        $this->assertSame('rtl', $listed['AE']['direction'], "the direction the shop's language list declares, not one read off the code");
        $this->assertSame(['en', 'ar'], $listed['SA']['languages'], "a market with no list of its own is listed with the platform's languages");
        $this->assertSame('ltr', $listed['SA']['direction']);
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
                // The three language keys are additive (I1-16) and describe a shop whose language list cannot be read here -- this test
                // builds no `business_settings`, so the platform names no installed locale and no market can present one. On an installed
                // shop a market with no list of its own is listed with the PLATFORM's languages, which the language test asserts.
                'storefronts' => [
                    ['country' => 'SA', 'path' => 'sa', 'currency' => 'SAR', 'is_default' => true, 'sellable' => true, 'state' => 'active',
                     'languages' => [], 'default_language' => null, 'direction' => 'ltr'],
                    ['country' => 'AE', 'path' => 'ae', 'currency' => 'AED', 'is_default' => false, 'sellable' => false, 'state' => 'draft',
                     'languages' => [], 'default_language' => null, 'direction' => 'ltr'],
                    ['country' => 'OM', 'path' => 'om', 'currency' => 'OMR', 'is_default' => false, 'sellable' => false, 'state' => 'draft',
                     'languages' => [], 'default_language' => null, 'direction' => 'ltr'],
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
