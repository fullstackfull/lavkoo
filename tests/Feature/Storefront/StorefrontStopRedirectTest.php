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
use App\Services\Storefront\StorefrontLifecycle;
use App\Services\Storefront\StorefrontStopRedirect;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ActivatesStorefront;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * I2-15: a market an operator paused in stop mode "redirect" sends the visitors of its pages to the same page on the default storefront.
 *
 * The mode has been stored, audited and told to both apps since the lifecycle was built. What it did to a web request was NOTHING, and the
 * card said why: the website resolved no market from an address. It does now (A1-05), so a stop mode that says it redirects can finally
 * redirect -- and the tracker's reason for the gap is the thing that has changed, not the mode.
 *
 * The routes here are the real web routes and the middleware is the real stack, because what is under test is an ADDRESS and the thing that
 * nearly broke it is the URL formatter A1-05 hangs on the generator: it gives every path the application builds the market back, which is
 * right for a link on a Kuwaiti page and catastrophic for the one address whose purpose is to leave Kuwait. The two controllers behind the
 * pages are markers, as in {@see StorefrontPathRoutingTest}: the real home page reads the installation database and the page is not the
 * subject.
 */
class StorefrontStopRedirectTest extends TestCase
{
    use ActivatesStorefront;
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    private const PERSON = 'The head of the Kuwait market';

    private const REASON = 'Decision of the operations meeting';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        // The storefront routes give a visitor who is not signed in a guest row (GuestMiddleware): not the subject, but the request really
        // passes through it, so the table is built rather than the middleware switched off.
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
        $this->activateStorefrontFor('KW', 'AE');

        $this->instance(HomeController::class, $this->markerController());
        $this->instance(PageController::class, $this->markerController());

        // A POST the real web stack answers. The website's own POST routes all reach controllers that read the installation database, and
        // what is under test is whether the method is redirected, not what a form does -- so one route is added, inside the real `web`
        // group, which is the shape StorefrontStopModesTest uses for the same reason.
        Route::middleware('web')->post('/__form-probe', fn () => response()->json(['submitted' => true]));
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** A stand-in for a page: it answers which market it was served in and at which path, so a request that is NOT redirected can be read. */
    private function markerController(): object
    {
        return new class () {
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
    }

    private function pause(string $mode, string $country = 'KW'): void
    {
        app(StorefrontLifecycle::class)->pause(country: $country, mode: $mode, by: self::PERSON, reason: self::REASON);
    }

    /**
     * Dormancy first, in the two states every installation is in today: a market nobody has paused serves its own pages, and with
     * storefronts off the website is what it has always been.
     */
    public function test_a_market_nobody_has_paused_serves_its_own_page(): void
    {
        $this->assertSame('KW', $this->get('/kw/contacts')->assertOk()->json('market'));
        $this->assertSame('SA', $this->get('/contacts')->assertOk()->json('market'));
    }

    public function test_nothing_is_redirected_while_storefronts_are_off(): void
    {
        $this->pause(mode: StorefrontLifecycle::REDIRECT);
        config(['gcc.storefronts.enabled' => false]);

        $this->get('/contacts')->assertOk();
        $this->get('/kw/contacts')->assertNotFound();
    }

    public function test_a_page_of_a_market_paused_in_redirect_mode_is_answered_with_the_same_page_on_the_default_storefront(): void
    {
        $this->pause(mode: StorefrontLifecycle::REDIRECT);

        $response = $this->get('/kw/contacts');

        $response->assertStatus(302);
        $this->assertSame(url('/contacts'), $response->headers->get('Location'), 'the same page, at the address the default storefront answers at');
    }

    /**
     * The trap this whole file exists for: the market's own path formatter must not be allowed to put the market back into the one address
     * that is leaving it. `/kw/contacts` must not redirect to `/kw/contacts`.
     */
    public function test_the_redirect_leaves_the_market_rather_than_pointing_back_into_it(): void
    {
        $this->pause(mode: StorefrontLifecycle::REDIRECT);

        $location = (string) $this->get('/kw/contacts')->assertStatus(302)->headers->get('Location');

        $this->assertStringNotContainsString('/kw/', $location, 'a redirect into the market being left is a loop the browser follows for ever');
        $this->assertStringEndsWith('/contacts', $location);
    }

    public function test_the_home_page_of_a_redirecting_market_goes_to_the_home_page_of_the_default_storefront(): void
    {
        $this->pause(mode: StorefrontLifecycle::REDIRECT);

        $this->assertSame(url('/'), (string) $this->get('/kw')->assertStatus(302)->headers->get('Location'));
    }

    /** A market named in a query is an address too, and the query that named it is dropped so the new address does not choose it again. */
    public function test_a_market_chosen_by_query_is_redirected_and_the_query_that_chose_it_is_dropped(): void
    {
        $this->pause(mode: StorefrontLifecycle::REDIRECT);

        $this->assertSame(url('/contacts'), (string) $this->get('/contacts?storefront=KW')->assertStatus(302)->headers->get('Location'));
    }

    /** The rest of the query is the visitor's, and is kept: a redirect that loses their search is a redirect to the wrong page. */
    public function test_the_rest_of_the_query_survives_the_redirect(): void
    {
        $this->pause(mode: StorefrontLifecycle::REDIRECT);

        $location = (string) $this->get('/contacts?storefront=KW&page=2&q=mask')->assertStatus(302)->headers->get('Location');

        $this->assertStringContainsString('page=2', $location);
        $this->assertStringContainsString('q=mask', $location);
        $this->assertStringNotContainsString('storefront=', $location);
    }

    /**
     * A market the visitor only REMEMBERS names no address, so it is forgotten rather than redirected: the page they asked for is served by
     * the default storefront, and there is no redirect to the address they are already on.
     */
    public function test_a_remembered_market_that_is_redirecting_is_forgotten_and_the_default_storefront_serves_the_page(): void
    {
        $this->pause(mode: StorefrontLifecycle::REDIRECT);

        $response = $this->withSession([StorefrontChoice::SESSION_KEY => 'KW'])->get('/contacts');

        $response->assertOk();
        $this->assertSame('SA', $response->json('market'), 'the market that is sending its visitors away does not serve them');
        $response->assertSessionMissing(StorefrontChoice::SESSION_KEY);
    }

    /** A POST is a submission, and a 302 turns one into a GET: the visitor would lose what they typed and the default storefront would be handed a request it never saw the form of. */
    public function test_a_post_is_never_redirected(): void
    {
        $this->pause(mode: StorefrontLifecycle::REDIRECT);

        $this->post('/kw/__form-probe')->assertOk()->assertExactJson(['submitted' => true]);
        $this->post('/__form-probe?storefront=KW')->assertOk();
        $this->get('/kw/contacts')->assertStatus(302, 'and the same address is redirected when it is read rather than written to');
    }

    #[DataProvider('theOtherStopModes')]
    public function test_the_other_stop_modes_do_not_redirect_a_page(string $mode): void
    {
        $this->pause(mode: $mode);

        $this->assertSame('KW', $this->get('/kw/contacts')->assertOk()->json('market'));
    }

    /** @return array<string, array{string}> */
    public static function theOtherStopModes(): array
    {
        return [
            'browse only' => [StorefrontLifecycle::BROWSE_ONLY],
            'maintenance' => [StorefrontLifecycle::MAINTENANCE],
        ];
    }

    public function test_one_market_stopping_does_not_move_another_markets_visitors(): void
    {
        $this->pause(mode: StorefrontLifecycle::REDIRECT);

        $this->assertSame('AE', $this->get('/ae/contacts')->assertOk()->json('market'));
        $this->assertSame('SA', $this->get('/contacts')->assertOk()->json('market'));
    }

    /** The storefront everybody falls back to cannot be put in this mode at all, so there is no address for its own visitors to be sent to. */
    public function test_the_default_storefront_cannot_be_paused_in_redirect_mode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('would strand its own visitors');

        $this->pause(mode: StorefrontLifecycle::REDIRECT, country: 'SA');
    }

    public function test_the_answer_is_a_302_by_default_and_a_301_only_when_an_operator_asks_for_one(): void
    {
        $this->pause(mode: StorefrontLifecycle::REDIRECT);

        $this->get('/kw/contacts')->assertStatus(302);

        config(['gcc.storefronts.stop.redirect_status' => 301]);
        $this->get('/kw/contacts')->assertStatus(301);

        config(['gcc.storefronts.stop.redirect_status' => 307]);
        $this->get('/kw/contacts')->assertStatus(302);
        $this->assertSame(302, app(StorefrontStopRedirect::class)->status(), 'an answer this redirect does not make is read as the default rather than sent');
    }

    /**
     * The half a list of prefixes cannot do on its own: the real route table is read, and every web address that belongs to a machine
     * rather than to a person has to be excluded.
     *
     * A gateway's return address is not a page somebody is browsing, and moving it loses the payment that was taken in the market being
     * stopped -- the one thing I2-16 says a stop must never do. So a gateway added later fails this test rather than quietly becoming
     * redirectable.
     */
    public function test_every_payment_callback_and_webhook_address_of_the_website_is_excluded(): void
    {
        $redirectable = $this->machineAddressesThisRedirectWouldMove();

        $this->assertSame([], $redirectable, "a machine's address this redirect would move:\n - " . implode("\n - ", $redirectable));
    }

    /**
     * The scan's own teeth, because today every machine address of this website is caught by its PATH and the name half of the scan is
     * therefore unexercised. A gateway whose return address says nothing in its path -- `gateways/acme/return`, named `acme.callback` --
     * is exactly the case a list of path words cannot catch, and it has to be reported rather than silently redirectable.
     */
    public function test_the_scan_reads_a_route_whose_name_names_a_callback_and_whose_path_does_not(): void
    {
        Route::get('/gateways/acme/return', fn () => 'ok')->name('acme.callback');

        $this->assertSame(
            ['gateways/acme/return'],
            $this->machineAddressesThisRedirectWouldMove(),
            'a return address that names itself only in the route name must still be reported',
        );
    }

    /**
     * Every web address whose path or route NAME says it belongs to a payment or to a machine, and which this redirect would nonetheless
     * move. The name is read as well as the path on purpose: a gateway's return address need not say so in its URL.
     *
     * @return list<string>
     */
    private function machineAddressesThisRedirectWouldMove(): array
    {
        $redirect = app(StorefrontStopRedirect::class);
        $machineAddresses = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $subject = strtolower($route->uri() . ' ' . (string) $route->getName());

            foreach (['payment', 'callback', 'webhook', 'ipn', 'notify'] as $word) {
                if (str_contains($subject, $word)) {
                    $machineAddresses[] = $route->uri();
                    break;
                }
            }
        }

        $this->assertNotEmpty($machineAddresses, 'the scan found no payment or callback route at all, so it proves nothing');

        return array_values(array_unique(array_filter(
            $machineAddresses,
            static fn (string $uri): bool => !$redirect->isExcluded($uri),
        )));
    }

    /** And the other direction, or the list above could exclude everything and still pass: an ordinary page is redirectable. */
    public function test_an_ordinary_page_is_not_excluded(): void
    {
        $redirect = app(StorefrontStopRedirect::class);

        $this->assertFalse($redirect->isExcluded('/contacts'));
        $this->assertFalse($redirect->isExcluded('/shop-cart'));
        $this->assertFalse($redirect->isExcluded('/kw/contacts'), 'the market segment is taken off before the list is read');
        $this->assertTrue($redirect->isExcluded('/admin/settings'));
        $this->assertTrue($redirect->isExcluded('/api/v1/config'));
        $this->assertTrue($redirect->isExcluded('/payment/stripe/success'));
        $this->assertTrue($redirect->isExcluded('/login/google/callback'));
    }

    /**
     * **Owner decision 5, first proof: a market whose own setting did not choose this mode is never redirected, whatever the other markets
     * are set to.** The mode ships with no feature flag in front of it, and the owner's acceptance of that is conditional on exactly this.
     *
     * Three states of the Emirates against Kuwait redirecting: selling, and paused in each of the two other modes. A market is redirected by
     * its OWN setting and by nothing else.
     */
    #[DataProvider('theStatesAnotherMarketCanBeIn')]
    public function test_a_market_whose_operator_did_not_choose_redirect_is_never_redirected(?string $emiratiMode): void
    {
        $this->pause(mode: StorefrontLifecycle::REDIRECT);

        if ($emiratiMode !== null) {
            $this->pause(mode: $emiratiMode, country: 'AE');
        }

        $this->assertSame('AE', $this->get('/ae/contacts')->assertOk()->json('market'));
        $this->assertSame('SA', $this->get('/contacts')->assertOk()->json('market'));
        $this->assertSame(StorefrontLifecycle::REDIRECT, app(StorefrontLifecycle::class)->stopModeOf('KW'), 'Kuwait is still the market whose own setting chose it');
        $this->get('/kw/contacts')->assertStatus(302, 'and it is still the only market being left');
    }

    /** @return array<string, array{string|null}> */
    public static function theStatesAnotherMarketCanBeIn(): array
    {
        return [
            'selling' => [null],
            'paused, browse only' => [StorefrontLifecycle::BROWSE_ONLY],
            'paused, maintenance' => [StorefrontLifecycle::MAINTENANCE],
        ];
    }

    /**
     * **Owner decision 5, second proof: a market with no lifecycle record at all is never redirected.** Qatar is configured and reachable
     * here and nobody has ever recorded a decision about it, which is the state of five of the six markets on the platform today. Its
     * implicit state is draft, so it has no mode, so there is nothing to redirect -- and a reader can see that it is the ABSENCE of a record
     * that answers, not a record saying "do not redirect".
     */
    public function test_a_market_with_no_lifecycle_record_at_all_is_never_redirected(): void
    {
        config(['gcc.storefronts.countries.QA.active' => true]);
        $this->pause(mode: StorefrontLifecycle::REDIRECT);

        $this->assertNull(app(StorefrontLifecycle::class)->stopModeOf('QA'), 'a market nobody has recorded a decision about is in no stop mode');
        $this->assertSame(0, DB::table('business_settings')->where('type', StorefrontLifecycle::settingName('QA'))->count(), 'and it has no record at all');
        $this->assertSame('QA', $this->get('/qa/contacts')->assertOk()->json('market'));
    }

    /**
     * **Owner decision 5, third proof: the address the visitor is sent to cannot carry them back into the market being stopped.** The
     * redirect is followed one hop, which is the only thing that proves it: the target is requested, and it answers with a page in the
     * default storefront rather than with another redirect.
     *
     * `ApplySeoRedirects` is switched off in this file, so what is measured here is the stop redirect alone. The two redirecting middlewares
     * TOGETHER, where an operator's rule can point back into the stopped market, are measured in
     * {@see StorefrontSeoRedirectWebsiteTest}.
     */
    public function test_the_address_a_stopped_market_sends_a_visitor_to_is_not_itself_redirected(): void
    {
        $this->pause(mode: StorefrontLifecycle::REDIRECT);

        $location = (string) $this->get('/kw/contacts')->assertStatus(302)->headers->get('Location');
        $target = '/' . ltrim((string) parse_url($location, PHP_URL_PATH), '/');

        $this->assertSame('/contacts', $target);

        $arrival = $this->get($target);

        $arrival->assertOk();
        $this->assertSame('SA', $arrival->json('market'), 'the visitor arrives in the default storefront and is not sent on again');
    }

    /**
     * **Owner decision 5, fourth proof: the target is never an address outside the platform.** The target is a PATH of this installation,
     * built through the URL generator, so it is always this host -- including for a request whose own path is shaped like an address
     * somewhere else, which is the case a string comparison would miss.
     *
     * The catch-all page is registered here and not in `setUp` on purpose: this is the one test that needs a route for an address the
     * website does not have, and a catch-all in `setUp` would turn the 404s the other tests assert into pages.
     */
    public function test_every_address_this_stop_sends_a_visitor_to_is_on_this_platform(): void
    {
        Route::middleware(['web', 'storefront'])->get('/{anything}', fn () => 'a page')->where('anything', '.*');
        $this->pause(mode: StorefrontLifecycle::REDIRECT);

        $host = parse_url(url('/'), PHP_URL_HOST);

        foreach (['/kw/contacts', '/kw', '/contacts?storefront=KW', '/kw//example.com/pwn', '/kw/https:/example.com'] as $address) {
            $response = $this->get($address);

            // Asserted rather than skipped: a shape that stopped being drivable would otherwise blind this test silently, and the two
            // hostile shapes are the whole point of it. Measured when it was written: all five answer 302, and the last two arrive at
            // `http://localhost/example.com/pwn` and `http://localhost/https:/example.com` -- paths of this installation, not addresses
            // elsewhere.
            $this->assertTrue($response->isRedirect(), "{$address} is no longer redirected, so this test no longer measures it");

            $location = (string) $response->headers->get('Location');

            $this->assertSame($host, parse_url($location, PHP_URL_HOST), "{$address} was sent off this platform, to {$location}");
            $this->assertStringStartsWith(url('/'), $location, "{$address} was sent to {$location}");
        }
    }

    /**
     * **Owner decision 5, fifth proof, the other half of the real-route scan: a route is judged by the MIDDLEWARE it carries and not by how
     * its address is spelt.** The scan above reads path words and route names; a panel route that says nothing in either -- and the classic
     * vendor panel has several -- is caught only by what guards it.
     *
     * So every route of the real table that is guarded as a panel, a seller surface, a platform-admin surface or a courier's own endpoint
     * has to be an address this redirect never moves. It is the same question `notVisitorPages()` answers from a hand-written list, asked of
     * the route table instead, which is what makes a panel mounted at a NEW first segment fail this test rather than quietly become
     * redirectable.
     */
    public function test_every_guarded_surface_of_the_website_is_excluded_whatever_its_address_says(): void
    {
        $redirectable = $this->guardedAddressesThisRedirectWouldMove();

        $this->assertSame([], $redirectable, "a guarded surface this redirect would move:\n - " . implode("\n - ", $redirectable));
    }

    /**
     * Every address of the real route table that is guarded as a panel, a seller surface, a platform-admin surface or a machine's endpoint,
     * and which this redirect would nonetheless move.
     *
     * @return list<string>
     */
    private function guardedAddressesThisRedirectWouldMove(): array
    {
        $redirect = app(StorefrontStopRedirect::class);
        $guards = ['admin', 'seller', 'seller_staff_access', 'seller_center', 'platform_admin', 'platform_admin_auth', 'delivery_man_auth', 'deliverysyria_auth', 'seller_api_auth'];
        $guarded = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var RoutingRoute $route */
            foreach ($route->middleware() as $middleware) {
                if (in_array(explode(':', (string) $middleware, 2)[0], $guards, true)) {
                    $guarded[] = $route->uri();
                    break;
                }
            }
        }

        $this->assertNotEmpty($guarded, 'the scan found no guarded route at all, so it proves nothing');

        return array_values(array_unique(array_filter($guarded, static fn (string $uri): bool => !$redirect->isExcluded($uri))));
    }

    /** And its teeth, the same way the path scan has them: a guarded surface at an address that says nothing must be reported. */
    public function test_the_guard_scan_reads_a_panel_mounted_at_an_address_that_names_nothing(): void
    {
        Route::get('/operations/console', fn () => 'ok')->middleware('admin');

        $this->assertContains(
            'operations/console',
            $this->guardedAddressesThisRedirectWouldMove(),
            'a panel mounted at a new first segment must be reported rather than quietly become redirectable',
        );
    }

    /** The audit line of the stop is the operator's record, and it is written whichever mode was chosen -- including this one. */
    public function test_the_stop_is_audited_with_the_mode_the_operator_chose(): void
    {
        $this->pause(mode: StorefrontLifecycle::REDIRECT);

        $this->assertSame(StorefrontLifecycle::REDIRECT, app(StorefrontLifecycle::class)->stopModeOf('KW'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'storefront.lifecycle.paused')->count());
    }
}
