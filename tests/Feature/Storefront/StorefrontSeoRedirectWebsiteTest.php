<?php

namespace Tests\Feature\Storefront;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Redirect;
use App\Services\Storefront\StorefrontChoice;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontLifecycle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Support\ActivatesStorefront;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\BuildsSeoRedirectRules;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The operator's SEO redirects, as a VISITOR of the website meets them: one market's rules must not reach another market's shoppers, and no
 * address may send a browser in a circle (A1-V2, owner decision 4 of 2026-11-26).
 *
 * **Why this file exists, measured and not supposed.** `StorefrontSeoRedirectTest` asked {@see \App\Http\Middleware\ApplySeoRedirects} for
 * its rule set with a storefront already BOUND, and every one of its assertions passed -- while the narrowing was inert on every page of the
 * live shop. `ApplySeoRedirects` is `web` GROUP middleware (`bootstrap/app.php`); the only thing that binds a storefront,
 * {@see \App\Http\Middleware\ResolveStorefront}, is ROUTE middleware applied to the web route file
 * (`RouteServiceProvider::mapBetaWebRoutes`), so it runs after the whole group. `StorefrontContext::current()` answers the DEFAULT
 * storefront when nothing is bound, so a Kuwaiti visitor was filtered against Saudi Arabia: the Saudi shop's rules applied to them, their
 * own market's rules never fired, and the answer was a 301, which a browser and a crawler both remember.
 * {@see \App\Http\Middleware\NoIndexUnlaunchedStorefront} avoids the same trap by reading after `$next`; a redirect cannot, so the market is
 * read off the request.
 *
 * So everything here is driven over HTTP through the REAL route table and the REAL middleware stack, both redirecting middlewares of the
 * `web` group live. The pages are three probe routes carrying the same middleware the web route file carries, because what is under test is
 * an ADDRESS and not a page -- the same reason {@see StorefrontStopRedirectTest} and {@see StorefrontPathRoutingTest} use markers.
 *
 * Three markets, deliberately: Saudi Arabia (the default storefront, whose behaviour must not move), Kuwait (a market with rules of its own)
 * and the Emirates (a market with none), so that a rule proven to apply in its own market is also proven NOT to apply in two others.
 *
 * TEETH, each mutation applied and restored, on SQLite 8.3 and MariaDB 10.11.14:
 *  - `ApplySeoRedirects::marketBeingServed()` returning null, which is byte for byte what the code did before this round -> **7 red**: the
 *    Kuwaiti visitor gets `/kw/__page-c` (the Saudi rule's target) instead of `/kw/__page-b`, a market's own rule does not fire at all, an
 *    unmarked rule bleeds into Kuwait and the Emirates, the hit is counted against the wrong market's rule, a market's chained rules never
 *    fire, and a remembered market is ignored. That is the proof of the inertness, in both directions.
 *  - `leadsBackHere()` returning false -> **4 red**, all of them loops; and the chain follower on its own prints the circle,
 *    `/kw/__page-a -> /kw/__page-b -> /kw/__page-a`, which is how it is known to have teeth of its own rather than only riding on the
 *    `assertOk()` before it.
 *  - `RedirectResolverService::isSelfReferential()` removed -> **1 red**, and only because of the test written for exactly that; see
 *    {@see test_a_rule_whose_target_is_its_own_source_serves_the_page}, whose own teeth check came back INERT and says so.
 */
class StorefrontSeoRedirectWebsiteTest extends TestCase
{
    use ActivatesStorefront;
    use BuildsFulfillmentSchema;
    use BuildsSeoRedirectRules;
    use RestoresLanguageFiles;

    private const PERSON = 'The head of the Kuwait market';

    private const REASON = 'Decision of the operations meeting';

    /** How many hops a chain is followed before it is called endless. A browser gives up at about twenty. */
    private const HOPS_FOLLOWED = 12;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        $this->buildTheRedirectTable();
        Cache::forget(Redirect::ACTIVE_CACHE_KEY);

        // Everything that is not the subject and would otherwise write a row or a language file on every probe request. `ApplySeoRedirects`
        // and `RedirectStoppedStorefront` are deliberately NOT here: they are what is under test, and they are under test TOGETHER, because
        // two redirecting middlewares in one group in a fixed order is where a loop comes from.
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class, ActivationCheckMiddleware::class,
        ]);
        Http::preventStrayRequests();

        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.paths.enabled' => true,
            'gcc.storefronts.default_country' => 'SA',
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.storefronts.countries.AE.active' => true,
        ]);
        $this->activateStorefrontFor('KW', 'AE');

        $this->probePages();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('redirects');
        Cache::forget(Redirect::ACTIVE_CACHE_KEY);
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /**
     * Three pages, inside the real `web` group and carrying the same storefront middleware the web route file carries, each answering which
     * market it was served in and at which path. A redirect rule can only fire on a path that MATCHES A ROUTE -- group middleware runs
     * inside the matched route's pipeline -- so a test of redirects needs real pages at both ends of a rule.
     */
    private function probePages(): void
    {
        Route::middleware(['web', 'storefront', 'storefront.session'])->group(function (): void {
            foreach (['a', 'b', 'c'] as $page) {
                Route::get("/__page-{$page}", fn () => response()->json([
                    'market' => StorefrontContext::current()->country,
                    'path' => request()->path(),
                ]))->name("probe.page.{$page}");
            }
        });
    }

    /** What the admin screens do when a rule is saved: the one cache entry that holds every active rule is forgotten. */
    private function rulesWereSaved(): void
    {
        Cache::forget(Redirect::ACTIVE_CACHE_KEY);
    }

    private function pause(string $mode, string $country = 'KW'): void
    {
        app(StorefrontLifecycle::class)->pause(country: $country, mode: $mode, by: self::PERSON, reason: self::REASON);
    }

    /** The path (and query) of the address a response sends the browser to. */
    private function locationOf(TestResponse $response): string
    {
        $location = (string) $response->headers->get('Location');
        $path = '/' . ltrim((string) parse_url($location, PHP_URL_PATH), '/');
        $query = (string) parse_url($location, PHP_URL_QUERY);

        return $path . ($query === '' ? '' : '?' . $query);
    }

    /**
     * Every address a browser starting here would be sent to, in order, stopping at the first that answers with a page, at the first that
     * repeats, or at the hop cap.
     *
     * @return list<string>
     */
    private function followTheChain(string $path): array
    {
        $visited = [];
        $here = $path;

        for ($hop = 0; $hop < self::HOPS_FOLLOWED; $hop++) {
            $visited[] = $here;
            $response = $this->get($here);

            if (!$response->isRedirect()) {
                return $visited;
            }

            $here = $this->locationOf($response);

            if (in_array($here, $visited, true)) {
                $visited[] = $here;

                return $visited;
            }
        }

        return $visited;
    }

    /** A browser that starts here reaches a page: it is not sent in a circle and the chain is not endless. */
    private function assertNoLoopFrom(string $path): void
    {
        $chain = $this->followTheChain($path);
        $trail = implode(' -> ', $chain);

        $this->assertSame(count($chain), count(array_unique($chain)), "the browser is sent in a circle: {$trail}");
        $this->assertLessThan(self::HOPS_FOLLOWED, count($chain), "the chain of redirects never reaches a page: {$trail}");
    }

    /**
     * Dormancy first, because this is middleware on every page of the live shop: with storefronts off the website redirects exactly as it
     * does today -- every rule applies to every request, whatever market it names, and a market path is a 404.
     */
    public function test_with_storefronts_off_the_website_redirects_exactly_as_it_does_today(): void
    {
        config(['gcc.storefronts.enabled' => false]);
        $this->aRuleOf(null, '/__page-a', '/__page-b');
        $this->aRuleOf('KW', '/__page-b', '/__page-c');
        $this->rulesWereSaved();

        $this->assertSame('/__page-b', $this->locationOf($this->get('/__page-a')->assertStatus(301)));
        $this->assertSame(
            '/__page-c',
            $this->locationOf($this->get('/__page-b')->assertStatus(301)),
            'a rule naming a market still applies to the whole shop while storefronts are off, as it does on the platform today',
        );
        $this->get('/kw/__page-a')->assertNotFound();
    }

    /**
     * The other state every installation starts in: the SEO subsystem's table does not exist at all. Nothing is read, nothing is resolved
     * and the page is served -- which is also what keeps the market resolution added this round off the request path of a platform that
     * writes no redirects.
     */
    public function test_a_platform_whose_redirect_table_does_not_exist_yet_serves_its_pages(): void
    {
        Schema::dropIfExists('redirects');

        $this->assertSame('KW', $this->get('/kw/__page-a')->assertOk()->json('market'));
        $this->assertSame('SA', $this->get('/__page-a')->assertOk()->json('market'));
    }

    /**
     * **The defect, and the proof.** Each market is served its OWN rules. Both markets hold a rule for the same path, which is the case the
     * card is about: `/offers` in Kuwait is not `/offers` in Riyadh.
     *
     * Before the fix this answered `/kw/__page-c` -- the SAUDI rule's target, dressed in the Kuwaiti market's path by the URL formatter --
     * because the rule set had been filtered against the default storefront.
     */
    public function test_a_market_is_served_its_own_redirect_rules_on_the_website(): void
    {
        $this->aRuleOf('KW', '/__page-a', '/__page-b');
        $this->aRuleOf('SA', '/__page-a', '/__page-c');
        $this->rulesWereSaved();

        $this->assertSame(
            '/kw/__page-b',
            $this->locationOf($this->get('/kw/__page-a')->assertStatus(301)),
            'the Kuwaiti visitor was sent to the Saudi rule\'s target: the per-market narrowing never ran on the website',
        );
        $this->assertSame(
            '/__page-c',
            $this->locationOf($this->get('/__page-a')->assertStatus(301)),
            'and the default storefront still answers its own rule, at the bare address it answers at today',
        );
    }

    /** A rule of one market applies in its own market and in NO other -- both directions, three markets. */
    public function test_a_rule_of_one_market_does_not_reach_another_markets_shoppers(): void
    {
        $this->aRuleOf('KW', '/__page-a', '/__page-b');
        $this->aRuleOf('SA', '/__page-c', '/__page-b');
        $this->rulesWereSaved();

        $this->assertSame('/kw/__page-b', $this->locationOf($this->get('/kw/__page-a')->assertStatus(301)));
        $this->assertSame('AE', $this->get('/ae/__page-a')->assertOk()->json('market'), 'the Emirates has no such rule and must not inherit Kuwait\'s');
        $this->assertSame('SA', $this->get('/__page-a')->assertOk()->json('market'), 'nor does the default storefront inherit another market\'s rule');

        $this->assertSame('/__page-b', $this->locationOf($this->get('/__page-c')->assertStatus(301)));
        $this->assertSame('KW', $this->get('/kw/__page-c')->assertOk()->json('market'), 'and the Saudi shop\'s rule does not reach Kuwait');
        $this->assertSame('AE', $this->get('/ae/__page-c')->assertOk()->json('market'));
    }

    /** A rule written before the column existed is the default storefront's, which is every rule on the platform today. */
    public function test_a_rule_written_before_markets_existed_belongs_to_the_default_storefront_alone(): void
    {
        $this->aRuleOf(null, '/__page-a', '/__page-b');
        $this->rulesWereSaved();

        $this->assertSame('/__page-b', $this->locationOf($this->get('/__page-a')->assertStatus(301)));
        $this->assertSame('KW', $this->get('/kw/__page-a')->assertOk()->json('market'));
        $this->assertSame('AE', $this->get('/ae/__page-a')->assertOk()->json('market'));
    }

    /**
     * The hit count an operator reads to decide whether a rule is still used, driven over HTTP: two markets hold a rule for the same path
     * and the Kuwaiti visitor's hit is counted against the Kuwaiti rule.
     *
     * The SAUDI rule is written first on purpose: `->limit(1)->update()` with no order takes whichever row the engine reaches first, so a
     * test that wrote the served market's rule first would pass with no narrowing at all.
     */
    public function test_a_hit_is_counted_against_the_rule_of_the_market_the_visitor_was_served(): void
    {
        $saudi = $this->aRuleOf('SA', '/__page-a', '/__page-c');
        $kuwaiti = $this->aRuleOf('KW', '/__page-a', '/__page-b');
        $this->rulesWereSaved();

        $this->get('/kw/__page-a')->assertStatus(301);

        $this->assertSame(1, (int) DB::table('redirects')->where('id', $kuwaiti)->value('hits'));
        $this->assertSame(0, (int) DB::table('redirects')->where('id', $saudi)->value('hits'), 'the Saudi rule was not touched');
    }

    /**
     * A rule whose target IS its source: the page is served rather than the browser asked for it again.
     *
     * **An inert teeth check, recorded rather than left as false coverage.** Taking
     * {@see \App\Services\Seo\RedirectResolverService}'s own `isSelfReferential()` guard out fails NOTHING in this file, because the
     * middleware's chain walk reaches the same answer: the target reduces to the path being answered, which is already in the chain. So this
     * test does not distinguish the two guards, and the resolver's guard has no other test anywhere in the suite -- which is what the next
     * test is for.
     */
    public function test_a_rule_whose_target_is_its_own_source_serves_the_page(): void
    {
        $this->aRuleOf('KW', '/__page-a', '/__page-a');
        $this->rulesWereSaved();

        $this->get('/kw/__page-a')->assertOk();
        $this->assertNoLoopFrom('/kw/__page-a');
    }

    /**
     * The one case where the two loop guards answer differently, so that the resolver's is proved rather than assumed.
     *
     * The resolver's guard means *a self-referential rule is not a rule*, so a LESS SPECIFIC rule for the same address still applies. The
     * middleware's guard means *this redirect would come back here, serve the page*. Two rules of one market for one path -- an exact rule
     * pointing at itself and a prefix rule pointing somewhere real -- separate them: with the resolver's guard the prefix rule wins and the
     * visitor is moved; without it the exact rule wins, the middleware refuses it, and the prefix rule never applies at all.
     */
    public function test_a_self_referential_rule_does_not_shadow_a_less_specific_rule_for_the_same_address(): void
    {
        $this->aRuleOf('KW', '/__page-a', '/__page-a');
        $this->aRuleOf('KW', '/__page-a', '/__page-b', 301, 'prefix');
        $this->rulesWereSaved();

        $this->assertSame(
            '/kw/__page-b',
            $this->locationOf($this->get('/kw/__page-a')->assertStatus(301)),
            'a rule pointing at itself is not a rule, so the other rule for that address is the one that applies',
        );
        $this->assertNoLoopFrom('/kw/__page-a');
    }

    /**
     * The loop a market PATH makes, which no comparison of the two columns can see: the router matched `/__page-a`, because the market's
     * segment was taken off before routing, so `/__page-a` and `/kw/__page-a` are not equal as strings -- and `/kw/__page-a` is the address
     * the visitor is already on.
     */
    public function test_a_rule_that_puts_the_market_back_into_its_target_is_not_a_redirect(): void
    {
        $this->aRuleOf('KW', '/__page-a', '/kw/__page-a');
        $this->rulesWereSaved();

        $this->get('/kw/__page-a')->assertOk();
        $this->assertNoLoopFrom('/kw/__page-a');
    }

    /** And the same shape in the default storefront, whose own path (`/sa`) is served although nothing generates it. */
    public function test_a_rule_that_puts_the_default_markets_own_path_into_its_target_is_not_a_redirect(): void
    {
        $this->aRuleOf('SA', '/__page-a', '/sa/__page-a');
        $this->rulesWereSaved();

        $this->get('/__page-a')->assertOk();
        $this->assertNoLoopFrom('/__page-a');
        $this->assertNoLoopFrom('/sa/__page-a');
    }

    /** Two rules pointing at each other: a cycle no single-rule check can see, and a chain is followed to find it. */
    public function test_a_cycle_of_two_rules_is_not_followed(): void
    {
        $this->aRuleOf('KW', '/__page-a', '/__page-b');
        $this->aRuleOf('KW', '/__page-b', '/__page-a');
        $this->rulesWereSaved();

        $this->get('/kw/__page-a')->assertOk();
        $this->get('/kw/__page-b')->assertOk();
        $this->assertNoLoopFrom('/kw/__page-a');
    }

    /** A chain that is not a cycle is still followed one hop at a time, which is what a redirect is: the behaviour must not change. */
    public function test_a_chain_of_two_rules_still_redirects_hop_by_hop(): void
    {
        $this->aRuleOf('KW', '/__page-a', '/__page-b');
        $this->aRuleOf('KW', '/__page-b', '/__page-c');
        $this->rulesWereSaved();

        $this->assertSame('/kw/__page-b', $this->locationOf($this->get('/kw/__page-a')->assertStatus(301)));
        $this->assertSame('/kw/__page-c', $this->locationOf($this->get('/kw/__page-b')->assertStatus(301)));
        $this->assertSame(['/kw/__page-a', '/kw/__page-b', '/kw/__page-c'], $this->followTheChain('/kw/__page-a'));
    }

    /**
     * The loop the two redirecting middlewares make between them: a rule of the Saudi shop pointing into a market that is paused in mode
     * redirect. {@see \App\Http\Middleware\RedirectStoppedStorefront} answers `/kw/__page-a` by sending the visitor to `/__page-a`, and this
     * rule would send them straight back.
     */
    public function test_a_rule_pointing_into_a_market_that_is_sending_its_visitors_away_is_not_a_redirect(): void
    {
        $this->aRuleOf('SA', '/__page-a', '/kw/__page-a');
        $this->rulesWereSaved();
        $this->pause(mode: StorefrontLifecycle::REDIRECT);

        $this->get('/__page-a')->assertOk();
        $this->assertSame('/__page-a', $this->locationOf($this->get('/kw/__page-a')->assertStatus(302)), 'the stop still takes the visitor out of the market');
        $this->assertNoLoopFrom('/kw/__page-a');
        $this->assertNoLoopFrom('/__page-a');
    }

    /**
     * And the other way round: a stop-mode redirect whose target carries a rule. The target is a different page, so it IS followed -- the
     * visitor leaves the stopped market and then the default storefront's own rule applies, which is what the stop middleware's docblock
     * says should happen. What must not happen is a circle, so the chain is followed to a page.
     */
    public function test_the_default_storefronts_rule_applies_after_a_stopped_market_sends_a_visitor_to_it(): void
    {
        $this->aRuleOf('SA', '/__page-a', '/__page-c');
        $this->rulesWereSaved();
        $this->pause(mode: StorefrontLifecycle::REDIRECT);

        $this->assertSame(
            ['/kw/__page-a', '/__page-a', '/__page-c'],
            $this->followTheChain('/kw/__page-a'),
            'out of the stopped market, then the default storefront\'s own rule, then a page',
        );
    }

    /** A market paused in another mode is not redirected at all, so its own rules still apply in it. */
    public function test_a_market_paused_in_browse_only_still_applies_its_own_rules(): void
    {
        $this->aRuleOf('KW', '/__page-a', '/__page-b');
        $this->rulesWereSaved();
        $this->pause(mode: StorefrontLifecycle::BROWSE_ONLY);

        $this->assertSame('/kw/__page-b', $this->locationOf($this->get('/kw/__page-a')->assertStatus(301)));
    }

    /** A rule of a market that is not the one being served must not be reachable by a remembered choice either: the market is the address. */
    public function test_the_market_the_visitor_chose_on_an_earlier_page_is_the_market_whose_rules_apply(): void
    {
        $this->aRuleOf('KW', '/__page-a', '/__page-b');
        $this->aRuleOf('SA', '/__page-a', '/__page-c');
        $this->rulesWereSaved();

        $response = $this->withSession([StorefrontChoice::SESSION_KEY => 'KW'])->get('/__page-a');

        $this->assertSame('/__page-b', $this->locationOf($response->assertStatus(301)), 'the remembered market\'s rule, not the default storefront\'s');
    }

    /** An address the redirect never touches is untouched whatever rules exist, so the exclusion list is not quietly bypassed by a rule. */
    public function test_an_excluded_address_is_never_redirected_by_a_rule(): void
    {
        $this->aRuleOf(null, '/api/v1/config', '/__page-b');
        $this->aRuleOf(null, '/admin/settings', '/__page-b');
        $this->rulesWereSaved();

        foreach (['/api/v1/config', '/admin/settings'] as $address) {
            $response = $this->get($address);

            $this->assertNotSame(301, $response->getStatusCode(), "{$address} was redirected by a rule");
            $this->assertNotSame('/__page-b', $this->locationOf($response), "{$address} was sent to a rule's target");
        }
    }
}
