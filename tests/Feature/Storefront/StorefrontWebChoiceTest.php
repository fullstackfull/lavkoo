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
use App\Services\Storefront\StorefrontChoice;
use App\Services\Storefront\StorefrontContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * A1-06: a visitor of the website can choose their market, and the site remembers it.
 *
 * The backend has always let a request name its market -- a query, the header an app sends, and since A1-05 the path a page is served
 * under -- but the website offered the visitor no way to say it and kept nothing: a `?storefront=` choice was gone on the next click.
 *
 * Choosing is a POST, because it changes what the site serves this visitor and a GET could be done to them by a link or a prefetch. The
 * answer sends them to the page they were on inside the market they chose, so the address carries the market as well, and the choice is
 * kept in the session for the pages that name none. A market the platform does not serve is refused with nothing kept.
 */
class StorefrontWebChoiceTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
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
            'gcc.storefronts.countries.AE.active' => true,
        ]);

        $marker = new class () {
            public function index(): mixed
            {
                return $this->report();
            }

            public function getContactView(): mixed
            {
                return $this->report();
            }

            private function report(): mixed
            {
                return response()->json(['market' => StorefrontContext::current()->country, 'path' => request()->path()]);
            }
        };
        $this->instance(HomeController::class, $marker);
        $this->instance(PageController::class, $marker);
    }

    /** @param array<string, string> $headers */
    private function choose(string $country, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->post('/storefront', ['storefront' => $country], $headers);
    }

    private function refererOf(string $path): array
    {
        return ['referer' => url($path)];
    }

    public function test_the_choice_is_kept_and_decides_the_page_after_it(): void
    {
        $this->choose('KW');

        $this->assertSame('KW', session(StorefrontChoice::SESSION_KEY));
        $this->assertSame('KW', $this->get('/contacts')->assertOk()->json('market'), 'a page that names no market serves the one they chose');
    }

    public function test_the_choice_sends_the_visitor_to_the_same_page_in_that_market(): void
    {
        $this->choose('KW', $this->refererOf('/contacts'))->assertRedirect(url('/kw/contacts'));
    }

    /** Switching markets must replace the segment, not stack one on the other. */
    public function test_switching_from_one_market_to_another_does_not_stack_prefixes(): void
    {
        $this->choose('AE', $this->refererOf('/kw/contacts'))->assertRedirect(url('/ae/contacts'));
    }

    public function test_the_query_of_the_page_they_were_on_is_kept(): void
    {
        $this->choose('KW', $this->refererOf('/contacts?page=2'))->assertRedirect(url('/kw/contacts?page=2'));
    }

    /** The default storefront keeps its bare addresses, so choosing it sends the visitor to the page without a segment. */
    public function test_choosing_the_default_market_sends_them_to_the_bare_address(): void
    {
        $this->choose('SA', $this->refererOf('/kw/contacts'))->assertRedirect(url('/contacts'));
        $this->assertSame('SA', $this->get('/contacts')->assertOk()->json('market'));
    }

    public function test_a_visitor_with_nowhere_to_go_back_to_lands_on_that_markets_home_page(): void
    {
        $this->choose('KW')->assertRedirect(url('/kw'));
    }

    public function test_a_referer_from_another_site_is_not_followed(): void
    {
        $this->choose('KW', ['referer' => 'https://example.test/whatever'])->assertRedirect(url('/kw'));
        $this->assertSame('KW', session(StorefrontChoice::SESSION_KEY), 'and the choice is still kept');
    }

    public function test_a_market_the_platform_does_not_serve_is_refused_and_nothing_is_kept(): void
    {
        config(['gcc.storefronts.countries.QA.active' => false]);

        foreach (['QA', 'ZZ', 'not-a-country', ''] as $asked) {
            $this->choose($asked, $this->refererOf('/contacts'))->assertRedirect(url('/contacts'));
            $this->assertNull(session(StorefrontChoice::SESSION_KEY), "'{$asked}' is not a market this platform serves");
        }

        $this->assertSame('SA', $this->get('/contacts')->assertOk()->json('market'));
    }

    public function test_the_address_the_visitor_is_on_outranks_what_was_remembered(): void
    {
        $this->choose('KW');

        $this->assertSame('AE', $this->get('/ae/contacts')->assertOk()->json('market'));
        $this->assertSame('SA', $this->get('/sa/contacts')->assertOk()->json('market'), 'including the default market, when they asked for it by address');
    }

    public function test_an_explicit_query_outranks_what_was_remembered(): void
    {
        $this->choose('KW');

        $this->assertSame('AE', $this->get('/contacts?storefront=AE')->assertOk()->json('market'));
    }

    public function test_a_remembered_market_that_was_switched_off_is_ignored(): void
    {
        $this->choose('KW');

        config(['gcc.storefronts.countries.KW.active' => false]);

        $this->assertSame('SA', $this->get('/contacts')->assertOk()->json('market'));
        $this->assertNull(app(StorefrontChoice::class)->remembered(), 'what is no longer served is not a choice');
    }

    public function test_nothing_is_offered_or_reachable_while_storefronts_are_off(): void
    {
        config(['gcc.storefronts.enabled' => false]);

        $this->assertFalse(app(StorefrontChoice::class)->isOffered());
        $this->assertSame([], app(StorefrontChoice::class)->offered());
        $this->choose('KW')->assertNotFound();
    }

    public function test_nothing_is_offered_while_the_platform_serves_one_market(): void
    {
        config(['gcc.storefronts.countries.KW.active' => false, 'gcc.storefronts.countries.AE.active' => false]);

        $this->assertFalse(app(StorefrontChoice::class)->isOffered());
        $this->choose('KW')->assertNotFound();
    }

    /** What the control in the top bar is built from: every market the platform serves, named as the platform already names it. */
    public function test_the_markets_offered_are_the_ones_served_with_the_current_one_marked(): void
    {
        $this->assertSame(
            [
                ['country' => 'SA', 'name' => 'Saudi Arabia', 'chosen' => true],
                ['country' => 'AE', 'name' => 'United Arab Emirates', 'chosen' => false],
                ['country' => 'KW', 'name' => 'Kuwait', 'chosen' => false],
            ],
            app(StorefrontChoice::class)->offered(),
        );
    }
}
