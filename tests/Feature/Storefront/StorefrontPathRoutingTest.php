<?php

namespace Tests\Feature\Storefront;

use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\PageController;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Services\Storefront\StorefrontContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Route;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * A1-05: the website answers each market under its own path, with one route table.
 *
 * `/kw/contacts` is served by the SAME route as `/contacts` — the market's segment is taken off before routing — so a market's pages are the
 * platform's pages rather than a second copy of them, every route name stays what it is, and the market is taken from the segment that was
 * removed. What the pages then generate carries the market, so a visitor who entered Kuwait stays in Kuwait.
 *
 * The routes here are the real ones, and so is every middleware that decides which storefront a request is served as. The two controllers
 * behind the pages are replaced by markers that report the market they were served in and the addresses they generate: the real home page
 * reads the installation database, and what is under test is the address, not the page.
 */
class StorefrontPathRoutingTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        // The storefront routes give a visitor who is not signed in a guest row (GuestMiddleware). It is not what is under test, but the
        // request really does pass through it, so the table it writes to is built rather than the middleware switched off.
        Schema::create('guest_users', function (Blueprint $table): void {
            $table->id();
            $table->string('ip_address')->nullable();
            $table->string('fcm_token')->nullable();
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

        $this->instance(HomeController::class, $this->markerController());
        $this->instance(PageController::class, $this->markerController());
    }

    /** A stand-in for a page: it answers which market it was served in, and the addresses the application generates while serving it. */
    private function markerController(): object
    {
        return new class () {
            public function index(): mixed
            {
                return request()->boolean('redirect') ? redirect()->route('shop-cart') : $this->report();
            }

            public function getContactView(): mixed
            {
                return $this->report();
            }

            private function report(): mixed
            {
                return response()->json([
                    'market' => StorefrontContext::current()->country,
                    'path' => request()->path(),
                    'route' => Route::currentRouteName(),
                    'home_link' => route('home', absolute: false),
                    'page_link' => route('contacts', absolute: false),
                    'url_helper' => url('/shop-cart', secure: false),
                    'panel_link' => url('/admin/settings', secure: false),
                    'api_link' => url('/api/v1/config', secure: false),
                    'asset_link' => asset('public/assets/front-end/img/logo.png'),
                ]);
            }
        };
    }

    public function test_a_market_path_is_served_by_the_route_the_root_is_served_by(): void
    {
        $kuwait = $this->get('/kw/contacts')->assertOk()->json();

        $this->assertSame('KW', $kuwait['market'], 'the market comes from the segment that was taken off');
        $this->assertSame('contacts', $kuwait['path'], 'the router matched the path behind the prefix');
        $this->assertSame('contacts', $kuwait['route'], 'and it is the same named route, not a copy of it');
    }

    public function test_the_root_of_a_market_is_its_home_page(): void
    {
        $this->assertSame('KW', $this->get('/kw')->assertOk()->json('market'));
        $this->assertSame('KW', $this->get('/kw/')->assertOk()->json('market'));
        $this->assertSame('home', $this->get('/kw')->assertOk()->json('route'));
    }

    public function test_the_default_storefronts_pages_are_untouched_at_the_root(): void
    {
        $saudi = $this->get('/contacts')->assertOk()->json();

        $this->assertSame('SA', $saudi['market']);
        $this->assertSame('contacts', $saudi['path']);
        $this->assertSame('/contacts', $saudi['page_link'], 'a Saudi page keeps the bare addresses it has today');
        $this->assertSame('/', $saudi['home_link']);
    }

    /**
     * The plan lists /sa, so it is served, and a visitor who entered there stays there: the rule is the visitor's address, not the market.
     *
     * Nothing takes a Saudi visitor to /sa -- the root generates bare addresses, as it always has, and the canonical link of a page is the
     * bare one (StorefrontUrl::of), so what search engines keep is the address Saudi Arabia has today.
     */
    public function test_the_default_storefront_is_also_served_under_its_own_path(): void
    {
        $viaPath = $this->get('/sa/contacts')->assertOk()->json();

        $this->assertSame('SA', $viaPath['market']);
        $this->assertSame('/sa/contacts', $viaPath['page_link'], 'a visitor who entered under /sa keeps /sa');
        $this->assertSame('/contacts', $this->get('/contacts')->assertOk()->json('page_link'), 'and the root is untouched');
    }

    public function test_the_links_a_market_page_generates_keep_that_market(): void
    {
        $links = $this->get('/kw/contacts')->assertOk()->json();

        $this->assertSame('/kw/contacts', $links['page_link'], 'a named route keeps the market');
        $this->assertSame('/kw', $links['home_link'], 'and so does the home page of that market');
        $this->assertStringEndsWith('/kw/shop-cart', $links['url_helper'], 'and so does url()');
    }

    /** A market in a panel, an API or an asset address would mean nothing, and would carry one market into a screen that serves all of them. */
    public function test_the_panel_the_api_and_the_assets_are_never_given_a_market(): void
    {
        $links = $this->get('/kw/contacts')->assertOk()->json();

        $this->assertStringEndsWith('/admin/settings', $links['panel_link']);
        $this->assertStringNotContainsString('/kw/admin', $links['panel_link']);
        $this->assertStringEndsWith('/api/v1/config', $links['api_link']);
        $this->assertStringNotContainsString('/kw/api', $links['api_link']);
        $this->assertStringNotContainsString('/kw/', $links['asset_link']);
    }

    public function test_a_market_that_is_not_active_is_not_served_under_its_path(): void
    {
        config(['gcc.storefronts.countries.AE.active' => false]);

        $this->get('/ae/contacts')->assertNotFound();
    }

    public function test_a_path_that_names_no_market_is_not_served(): void
    {
        $this->get('/zz/contacts')->assertNotFound();
    }

    public function test_nothing_is_served_under_a_market_path_while_the_switch_is_off(): void
    {
        config(['gcc.storefronts.paths.enabled' => false]);

        $this->get('/kw/contacts')->assertNotFound();
        $this->assertSame('SA', $this->get('/contacts')->assertOk()->json('market'), 'and the root is exactly what it was');
        $this->assertSame('/contacts', $this->get('/contacts')->assertOk()->json('page_link'));
    }

    public function test_nothing_is_served_under_a_market_path_while_storefronts_are_off(): void
    {
        config(['gcc.storefronts.enabled' => false]);

        $this->get('/kw/contacts')->assertNotFound();
        $this->assertSame('SA', $this->get('/contacts')->assertOk()->json('market'));
    }

    /** A query that contradicts the page it is on does not move the visitor: the address they are at decides. */
    public function test_the_path_the_visitor_is_on_outranks_a_storefront_query(): void
    {
        $this->assertSame('KW', $this->get('/kw/contacts?storefront=SA')->assertOk()->json('market'));
        $this->assertSame('KW', $this->get('/contacts?storefront=KW')->assertOk()->json('market'), 'and a query still chooses on an unprefixed page');
    }

    /** A redirect is a link the server follows for the visitor, so it has to keep them in their market too. */
    public function test_a_redirect_written_in_a_page_keeps_the_market(): void
    {
        $this->get('/kw?redirect=1')->assertRedirectContains('/kw/shop-cart');
        $this->get('/?redirect=1')->assertRedirectContains('/shop-cart');
        $this->assertStringNotContainsString('/kw/', (string) $this->get('/?redirect=1')->headers->get('Location'));
    }

    /**
     * A market must not outlive the request it was served for.
     *
     * The path formatter lives on the URL generator, which is a singleton: one web request per process never notices, but a worker, a test
     * and a long-running server serve one request after another in one process, and a formatter left behind would put Kuwait in front of
     * the next request's links.
     */
    public function test_a_markets_links_do_not_reach_the_request_after_it(): void
    {
        $this->get('/kw/contacts')->assertOk();

        $this->assertSame('/contacts', $this->get('/contacts')->assertOk()->json('page_link'));
        $this->assertSame('/contacts', route('contacts', absolute: false), 'and nothing outside a request is addressed in a market either');
    }

    /** One route table: the switch adds no route, renames none and removes none. */
    public function test_the_route_table_is_the_same_whether_the_switch_is_on_or_off(): void
    {
        $withPaths = $this->routeNames();

        config(['gcc.storefronts.paths.enabled' => false]);

        $this->assertSame($withPaths, $this->routeNames());
    }

    public function test_a_market_path_to_a_page_that_does_not_exist_is_a_not_found(): void
    {
        $this->get('/kw/no-such-page')->assertNotFound();
    }

    /** @return list<string> */
    private function routeNames(): array
    {
        $names = [];

        foreach (Route::getRoutes() as $route) {
            $names[] = $route->methods()[0] . ' ' . $route->uri() . ' ' . (string) $route->getName();
        }

        sort($names);

        return $names;
    }

    public function test_the_query_string_of_a_market_page_survives_the_strip(): void
    {
        $served = $this->get('/kw/contacts?page=2&storefront=KW')->assertOk();

        $this->assertSame('KW', $served->json('market'));
        $this->assertSame('contacts', $served->json('path'));
    }
}
