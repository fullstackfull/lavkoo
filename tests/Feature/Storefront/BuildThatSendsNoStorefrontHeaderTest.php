<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use App\Services\Theme\StorefrontBrand;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What a build that sends NO `X-Storefront` gets (GCC-19; K-14's third criterion, and J-03's installed-build case).
 *
 * This is the one half of that criterion nobody can read off a store listing. Every seller and customer app that is installed TODAY was built
 * before the header existed, and the two apps' current builds send none either while their storefront flag is off -- which is the default, and
 * which the seller app proves on its own side (`test/market_selector/seller_market_choice_request_headers_test.dart`: a choice built with
 * `enabled: false` answers no header at all). So the day storefronts are switched ON for six markets, every phone in the field asks with no
 * header, and what those phones are served is settled HERE, by the resolver, and by nothing in either app.
 *
 * The answer must be Saudi Arabia -- the market this platform has always served -- and it must not depend on anything the visitor's device happens
 * to carry. That is what follows: with storefronts on and all six markets active, a request with no header is SA/SAR; it is still SA when the device
 * sends nothing at all; and it is still SA when the request carries a header the resolver cannot use.
 *
 * The seller app's own version gate is NOT touched by any of this and is not read here: K-14's other two criteria need the live published minimum
 * and the store versions, which are the owner's to supply (see `docs/gcc/audits/2026-11-12-seller-version-verification.md`).
 *
 * ## The resolver is not the place production reads this
 *
 * Everything above is asserted on a route this file registers itself, which proves the resolver and the middleware and nothing about the
 * addresses an installed build actually calls. So the second half of this file asks the same question of ROUTES THE PLATFORM SERVES, and asks
 * it in a form that can tell one market from another: each of the five markets that are not the default is given its own marketing name
 * (F-13), and a header-less request has to be answered the PLATFORM's name. A resolver that fell to the Emirates instead of the default would
 * answer `Lavkoo Emirates` and these tests would say so -- where an assertion of `SA` against the resolver's own return value could not.
 *
 * Two production facts come out of it that the probe cannot show. `GET /api/v1/config` resolves no storefront at all, deliberately, because an
 * app asks it before it knows which market it is in -- so an installed build is answered the default market's configuration whatever header it
 * sends. And the seller API binds no storefront on any of its routes, so a seller build's header moves nothing there either: what asks for the
 * current storefront on a seller request is answered the default market -- which is this criterion, for the build K-14 is about.
 */
class BuildThatSendsNoStorefrontHeaderTest extends TestCase
{
    /** The six markets of the approved design, so the test is about a platform serving all of them and not about a platform serving one. */
    private const MARKETS = ['SA', 'AE', 'KW', 'QA', 'BH', 'OM'];

    private const PLATFORM_NAME = 'Lavkoo';

    protected function setUp(): void
    {
        parent::setUp();

        // The brand of each market is read from `business_settings`, which no migration of this repository creates.
        Schema::dropIfExists('business_settings');
        Schema::create('business_settings', static function (Blueprint $table): void {
            $table->id();
            $table->string('type')->nullable();
            $table->longText('value')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('business_settings');
        app(StorefrontBrand::class)->flush();

        parent::tearDown();
    }

    public function test_with_six_markets_open_a_build_that_sends_no_header_is_served_the_saudi_storefront(): void
    {
        $this->openEveryMarket();

        $this->probe('/__storefront-probe')->assertOk()->assertExactJson(['country' => 'SA', 'currency' => 'SAR']);
    }

    public function test_it_is_the_configured_default_that_decides_and_not_a_name_in_the_code(): void
    {
        $this->openEveryMarket();
        config(['gcc.storefronts.default_country' => 'KW']);

        // A1-03: which storefront a header-less request gets is a recorded decision read at boot, not a constant. The Saudi answer above is what
        // today's configuration says, and this is the proof that it is the configuration saying it.
        $this->probe('/__storefront-probe')->assertExactJson(['country' => 'KW', 'currency' => 'KWD']);
    }

    #[DataProvider('headersAnOldBuildMightSend')]
    public function test_a_header_the_resolver_cannot_use_is_served_the_default_too(mixed $value): void
    {
        $this->openEveryMarket();

        $this->probe('/__storefront-probe', ['X-Storefront' => $value])->assertExactJson(['country' => 'SA', 'currency' => 'SAR']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function headersAnOldBuildMightSend(): array
    {
        return [
            'an empty header' => [''],
            'spaces' => ['   '],
            'a country nobody configured' => ['ZZ'],
            'a country name rather than a code' => ['Saudi Arabia'],
            'the word null' => ['null'],
            'a header sent twice' => [['AE', 'KW']],
        ];
    }

    public function test_the_header_less_request_carries_nothing_of_a_market_away_with_it(): void
    {
        $this->openEveryMarket();

        $response = $this->probe('/__storefront-probe');

        // An installed build is not a browser: it keeps no cookie jar anybody manages, so a storefront remembered in a cookie would be a market
        // chosen for a phone that never chose one, and the next request would be served a market for a reason nobody could see.
        $this->assertSame([], $response->headers->getCookies());
        $this->assertFalse(session()->has('currency_code'));
    }

    public function test_while_storefronts_are_switched_off_a_header_less_request_is_served_exactly_what_it_always_was(): void
    {
        // The state every installation is in today: the switch off, so the resolver serves the default to everybody, header or no header.
        $this->assertFalse((bool) config('gcc.storefronts.enabled'));

        $this->probe('/__storefront-probe')->assertExactJson(['country' => 'SA', 'currency' => 'SAR']);
        $this->probe('/__storefront-probe', ['X-Storefront' => 'KW'])->assertExactJson(['country' => 'SA', 'currency' => 'SAR']);
    }

    // ---- the same question, asked of routes the platform serves ----

    /**
     * `GET /api/v1/theme/brand` is a route both apps call, it resolves a storefront, and its answer differs per market: the one shape in which
     * "which market was this request served" can be read off a production answer rather than out of the resolver.
     */
    public function test_on_a_route_the_platform_serves_a_header_less_build_is_answered_the_default_markets_brand(): void
    {
        $this->openEveryMarket();
        $this->giveEveryOtherMarketItsOwnName();

        $answer = $this->getJson('/api/v1/theme/brand')->assertOk();

        $this->assertSame(self::PLATFORM_NAME, $answer->json('company_name'));
        $this->assertSame([], array_values(array_filter(
            self::MARKETS,
            fn (string $market): bool => $market !== 'SA' && str_contains((string) $answer->json('company_name'), $market),
        )), 'no other market\'s identity reached a build that named none');
    }

    /** And the test can tell the markets apart, which is what makes the assertion above worth anything. */
    public function test_the_same_route_answers_each_market_its_own_brand_when_the_build_does_name_one(): void
    {
        $this->openEveryMarket();
        $this->giveEveryOtherMarketItsOwnName();

        foreach (self::MARKETS as $market) {
            $expected = $market === 'SA' ? self::PLATFORM_NAME : self::PLATFORM_NAME . ' ' . $market;

            $this->assertSame($expected, $this->getJson('/api/v1/theme/brand', [StorefrontResolver::HEADER => $market])->assertOk()->json('company_name'));
        }
    }

    #[DataProvider('headersAnOldBuildMightSend')]
    public function test_on_that_route_a_header_the_resolver_cannot_use_is_answered_the_default_markets_brand_too(mixed $value): void
    {
        $this->openEveryMarket();
        $this->giveEveryOtherMarketItsOwnName();

        $this->assertSame(self::PLATFORM_NAME, $this->getJson('/api/v1/theme/brand', [StorefrontResolver::HEADER => $value])->assertOk()->json('company_name'));
    }

    /** The dormant case on a production route: the state every installation is in today, where the switch is off and no market has anything of its own. */
    public function test_while_storefronts_are_switched_off_that_route_answers_the_platform_whatever_header_arrives(): void
    {
        $this->assertFalse((bool) config('gcc.storefronts.enabled'));
        $this->giveEveryOtherMarketItsOwnName();

        $withNoHeader = $this->getJson('/api/v1/theme/brand')->assertOk()->json();

        $this->assertSame(self::PLATFORM_NAME, $withNoHeader['company_name']);
        foreach (self::MARKETS as $market) {
            $this->assertSame($withNoHeader, $this->getJson('/api/v1/theme/brand', [StorefrontResolver::HEADER => $market])->assertOk()->json(), "the answer moved for {$market} while storefronts are off");
        }
    }

    /**
     * The route an installed build asks FIRST, before it could know a market, resolves no storefront on purpose -- so what it answers is the
     * default market's configuration, however the request is headed. Asserted at the brand reader `GET /api/v1/config` itself calls, because
     * the route reads a dozen tables this test does not build; which routes resolve a storefront is pinned in ResolveStorefrontMiddlewareTest.
     */
    public function test_the_configuration_an_installed_build_asks_for_first_is_read_in_the_default_market(): void
    {
        $this->openEveryMarket();
        $this->giveEveryOtherMarketItsOwnName();

        $this->assertNotContains('storefront', $this->middlewareOf('api/v1/config'));
        $this->assertFalse(app()->bound(StorefrontContext::class));
        $this->assertSame(self::PLATFORM_NAME, getWebConfig(name: 'company_name'), 'a market\'s own identity reached a reader no storefront was bound for');
    }

    /**
     * The seller API, which is where K-14's build lives. No route of it binds a storefront, so nothing a seller build sends -- or does not
     * send -- moves it off the default market, and whatever asks is answered the default.
     */
    public function test_no_seller_route_binds_a_storefront_so_a_seller_build_is_served_the_default_market(): void
    {
        $this->openEveryMarket();

        $binding = array_values(array_filter(
            array_map(fn ($route) => $route->uri(), iterator_to_array(Route::getRoutes())),
            fn (string $uri): bool => str_starts_with($uri, 'api/v3/seller') || str_starts_with($uri, 'api/v2/seller'),
        ));
        $resolving = array_values(array_filter($binding, fn (string $uri): bool => in_array('storefront', $this->middlewareOf($uri), true)));

        $this->assertNotEmpty($binding, 'the seller API is registered at all');
        $this->assertSame([], $resolving, 'a seller route resolving a storefront would make a seller build\'s header decide its market');
        $this->assertFalse(app()->bound(StorefrontContext::class), 'nothing bound a context outside a request');
        $this->assertSame(['SA', 'SAR'], [StorefrontContext::current()->country, StorefrontContext::current()->currency], 'what asks on a seller request is answered the default market');
    }

    /** @return list<string> */
    private function middlewareOf(string $uri): array
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === $uri) {
                return $route->gatherMiddleware();
            }
        }

        return [];
    }

    /**
     * Each market that is not the default given a marketing name of its own (F-13), so that an answer naming a market can be told from an
     * answer naming the platform. The default market has no record of its own by design: the global settings ARE its brand.
     */
    private function giveEveryOtherMarketItsOwnName(): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => 'company_name'], ['value' => self::PLATFORM_NAME]);

        foreach (self::MARKETS as $market) {
            if ($market !== 'SA') {
                DB::table('business_settings')->updateOrInsert(
                    ['type' => StorefrontBrand::SETTING_PREFIX . $market],
                    ['value' => json_encode(['company_name' => self::PLATFORM_NAME . ' ' . $market])],
                );
            }
        }

        Cache::flush();
        clearWebConfigCacheKeys();
        app(StorefrontBrand::class)->flush();
    }

    private function openEveryMarket(): void
    {
        $settings = ['gcc.storefronts.enabled' => true];
        foreach (self::MARKETS as $market) {
            $settings["gcc.storefronts.countries.{$market}.active"] = true;
        }
        config($settings);

        $this->assertSame(self::MARKETS, array_keys((array) config('gcc.storefronts.countries')), 'the six markets of the approved design, in configuration order');
    }

    private function probe(string $uri, array $headers = []): TestResponse
    {
        Route::middleware('storefront')->get('/__storefront-probe', fn () => response()->json([
            'country' => app(StorefrontContext::class)->country,
            'currency' => app(StorefrontContext::class)->currency,
        ]));

        return $this->withoutExceptionHandling()->get($uri, $headers);
    }
}
