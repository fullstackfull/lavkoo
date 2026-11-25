<?php

namespace Tests\Feature\Catalogue;

use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Http\Controllers\Web\ProductDetailsController;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontContext;
use App\Utils\ApplySeoRedirects;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * B-18: `/kw/product/{slug}` serves the KUWAITI storefront's product page.
 *
 * The two halves of this were built apart and nothing joined them. A1-05 made the website answer each market under its own path by taking
 * the market's segment off before the request is routed, and B-13 narrowed the catalogue to the storefront a request is served from
 * (`Product::scopeActive()` ends in `inCurrentStorefront()`). So the claim "a country-prefixed URL serves that country's product page"
 * rests on the two meeting on one request -- the middleware binding the market the prefix named, and the page's own read being narrowed by
 * it -- and that had never been measured.
 *
 * It is measured here through the REAL route, the REAL middleware stack that decides a storefront, and the REAL repository method the
 * product page calls. **What is NOT asserted is the rendered view**: the product page reads the installation's own database for its theme,
 * reviews, related rails and currency, so the controller is replaced by a marker that reports WHICH PRODUCT the page's read answered --
 * which is the whole question, and the same substitution `StorefrontPathRoutingTest` makes for the same reason.
 *
 * Three cases, because one of them alone proves nothing: the market's own product is served under its prefix, another market's product is
 * NOT (so the prefix narrows rather than merely resolving), and the bare address still serves the default storefront's product exactly as
 * it does today.
 */
class MarketPathProductPageTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        // A visitor who is not signed in is given a guest row by the storefront routes. Not what is under test, but the request really
        // passes through that middleware, so the table it writes to is built rather than the middleware switched off.
        Schema::create('guest_users', function (Blueprint $table): void {
            $table->id();
            $table->string('ip_address')->nullable();
            $table->string('fcm_token')->nullable();
            $table->timestamps();
        });
        // `Product::scopeActive()` asks whether a seller is approved, so the table it joins has to be there even though every product
        // here is the shop's own.
        Schema::create('sellers', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('approved');
            $table->softDeletes();
            $table->timestamps();
        });
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class, ActivationCheckMiddleware::class,
        ]);
        Http::preventStrayRequests();

        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.paths.enabled' => true,
            'gcc.storefronts.countries.KW.active' => true,
        ]);

        (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();
        StorefrontCatalog::flush();
        $this->giveProduct(id: 1, slug: 'saudi-lamp', country: 'SA');
        $this->giveProduct(id: 2, slug: 'kuwaiti-lamp', country: 'KW');

        $this->instance(ProductDetailsController::class, $this->markerController());
    }

    public function test_a_market_path_serves_that_markets_product(): void
    {
        $answer = $this->get('/kw/product/kuwaiti-lamp')->assertOk()->json();

        $this->assertSame('KW', $answer['market'], 'the request was not served as the market its path named');
        $this->assertSame('kuwaiti-lamp', $answer['slug']);
        $this->assertSame(2, $answer['product_id']);
    }

    /** And it narrows: another market's product is not on the Kuwaiti storefront, prefix or no prefix. */
    public function test_a_market_path_does_not_serve_another_markets_product(): void
    {
        $answer = $this->get('/kw/product/saudi-lamp')->assertOk()->json();

        $this->assertSame('KW', $answer['market']);
        $this->assertNull($answer['product_id'], 'the Kuwaiti storefront served a Saudi product');
    }

    /** The default storefront keeps the bare addresses it has today, and they answer what they answer today. */
    public function test_the_bare_address_still_serves_the_default_storefronts_product(): void
    {
        $answer = $this->get('/product/saudi-lamp')->assertOk()->json();

        $this->assertSame('SA', $answer['market']);
        $this->assertSame(1, $answer['product_id']);
    }

    /** With the paths switch off a market's product address is a 404, as it is today. */
    public function test_nothing_is_served_under_a_market_path_while_the_switch_is_off(): void
    {
        config(['gcc.storefronts.paths.enabled' => false]);

        $this->get('/kw/product/kuwaiti-lamp')->assertNotFound();
    }

    /**
     * A stand-in for the product page: it answers which market the request was served in and which product the page's OWN read
     * resolved, so the test is about the storefront and the catalogue rather than about a theme.
     */
    private function markerController(): object
    {
        return new class () {
            public function index(string $slug): mixed
            {
                $product = app(ProductRepositoryInterface::class)->getWebFirstWhereActive(params: ['slug' => $slug, 'customer_id' => 0]);

                return response()->json([
                    'market' => StorefrontContext::current()->country,
                    'slug' => $slug,
                    'product_id' => $product === null ? null : (int) $product->id,
                ]);
            }
        };
    }

    private function giveProduct(int $id, string $slug, string $country): void
    {
        DB::table('products')->insert([
            'id' => $id,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'added_by' => 'admin',
            'product_type' => 'physical',
            'status' => 1,
            'request_status' => 1,
            'unit_price' => 10,
            'current_stock' => 5,
            StorefrontCatalog::COLUMN => $country,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
