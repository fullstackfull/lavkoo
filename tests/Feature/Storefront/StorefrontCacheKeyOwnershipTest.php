<?php

namespace Tests\Feature\Storefront;

use App\Http\Middleware\ResolveStorefront;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use App\Services\Theme\StorefrontThemeRenderer;
use App\Services\Theme\ThemeManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use ReflectionProperty;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsThemeWorld;
use Tests\TestCase;

/**
 * Who owns a cache key, and whether the market a key NAMES is the market the content in it came from.
 *
 * This platform has two key helpers, and they resolve the market from different inputs. `StorefrontContext::current()` answers the market a
 * middleware BOUND, and the default market when nothing was bound; `StorefrontCatalog::cacheKey()` answers the market the REQUEST asks for
 * when nothing was bound, and a key of its own (`storefront:*:`) for a console run or a panel address. Two resolutions writing one key would
 * serve one market another's content, so what each of them owns is pinned here rather than inferred from the two implementations agreeing
 * today.
 *
 * Three questions, each of which was asked of this layer and answered by measurement rather than by reading:
 *
 * 1. **Does the theme renderer write panel content under a market's key?** No. Its key and the theme it reads both come from
 *    `StorefrontContext::current()` -- `StorefrontThemeRenderer::sectionsFor` for the key, `StorefrontThemeResolver::requestStorefront` for
 *    the theme -- so they cannot name different markets. The link that makes that true for the website is one word in
 *    `RouteServiceProvider::mapBetaWebRoutes`: the whole web route file carries the `storefront` alias, so every web request IS bound.
 *    Nothing asserted that before this class, and taking the word out is all it would take for a Kuwaiti visitor to be served the default
 *    market's theme under the default market's key while the catalogue on the same page narrowed itself to Kuwait.
 * 2. **Is the flag-off asymmetry between the two forget lists deliberate?** Yes. `StorefrontContext::keysForEveryStorefront` reaches every
 *    market's copy whether or not storefronts are on; `StorefrontCatalog::cacheKeysForEveryStorefront` reaches only the plain key while they
 *    are off, so that a missing or mistyped storefront configuration cannot reach the caches of a platform that is not using storefronts.
 *    The copies written while the flag was on therefore outlive a flip, which is why step 6 of `docs/gcc/STOREFRONT_FLAG_RUNBOOK.md` says to
 *    run `cache:clear` on every flip in either direction. Pinned here so that the asymmetry reads as a decision.
 * 3. **Do the two helpers agree about which market owns the UNPREFIXED key?** Today, yes, and only because the default market is Saudi
 *    Arabia. `StorefrontContext::UNSCOPED_COUNTRY` is the literal `SA` -- the market whose entries this platform already had cached -- while
 *    `StorefrontCatalog` reads the configured default market. Owner decision A1-03 makes the default market a recorded, changeable decision,
 *    and the moment it is changed those are two different markets: the new default market would take a prefixed key while Saudi Arabia kept
 *    the bare one, and before the `storefront_country` column exists Saudi Arabia narrows to nothing while the bare key still holds a list
 *    built from the whole catalogue. The guard below fails on that configuration rather than letting it ship quietly.
 */
class StorefrontCacheKeyOwnershipTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use BuildsThemeWorld;

    private const KEY = 'some_key';

    /** The markets this platform is configured to serve, which is what both forget lists are built from. */
    private const SIX_MARKETS = ['SA', 'AE', 'KW', 'QA', 'BH', 'OM'];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::SIX_MARKETS as $market) {
            config(["gcc.storefronts.countries.{$market}.active" => true]);
        }
        Cache::flush();
    }

    public function test_the_whole_web_route_file_resolves_a_storefront_so_a_pages_theme_and_its_catalogue_name_one_market(): void
    {
        config(['gcc.storefronts.enabled' => true]);

        foreach (['/', 'contacts', 'shop-cart'] as $name) {
            $route = Route::getRoutes()->getByName($name === '/' ? 'home' : $name);
            $this->assertNotNull($route, "the website serves [{$name}]");
            $this->assertContains('storefront', $route->middleware(), "the web route [{$name}] resolves a storefront");
        }
    }

    /**
     * The other half of the same question, asked of the middleware rather than of the route table: while the middleware the group above
     * carries is running, the key the theme is cached under and the key the catalogue is cached under name that one market. The test above
     * pins that the group carries it; this pins what carrying it buys.
     */
    public function test_the_middleware_the_web_group_carries_gives_the_theme_and_the_catalogue_the_same_key(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $this->serveAsHttpRequest('/products?storefront=KW');
        $keys = [];

        app(ResolveStorefront::class)->handle(request(), function () use (&$keys) {
            $keys = ['theme' => StorefrontContext::current()->scopedCacheKey(self::KEY), 'catalogue' => StorefrontCatalog::cacheKey(self::KEY)];

            return response('ok');
        });

        $this->assertSame(['theme' => 'storefront:KW:' . self::KEY, 'catalogue' => 'storefront:KW:' . self::KEY], $keys);
    }

    /**
     * The panel case the audit asked about. A panel request binds no storefront, so the renderer's key is the DEFAULT market's and the theme
     * it reads is the DEFAULT market's: one input, two answers that cannot disagree. The catalogue deliberately differs -- a panel reads every
     * country, so its lists go under a key no market reads -- and that difference is the point, not a drift.
     */
    public function test_a_panel_request_caches_the_default_markets_theme_under_the_default_markets_key(): void
    {
        $this->buildThemeWorld();
        $this->switchStorefrontThemesOn();
        $this->openEveryStorefront();
        $this->giveStorefrontTheme(name: 'Saudi', country: null, revision: 3, spacerHeight: 41, page: 'saudi-offers', active: true);
        $this->giveStorefrontTheme(name: 'Kuwaiti', country: 'KW', revision: 7, spacerHeight: 42, page: 'kuwaiti-offers', active: true);
        $this->serveAsHttpRequest('/admin/promotion/banners');
        app()->forgetInstance(StorefrontContext::class);

        $sections = app(StorefrontThemeRenderer::class)->sectionsFor('home');

        $this->assertSame(41, $sections[0]['settings']['height'], 'the panel is served the default market theme');
        $this->assertSame('Saudi', app(ThemeManager::class)->activeThemePublishedVersion()->theme->name);
        $this->assertTrue(Cache::has(StorefrontThemeRenderer::CACHE_KEY_PREFIX . 'home'), 'under the default market key');
        $this->assertFalse(Cache::has('storefront:KW:' . StorefrontThemeRenderer::CACHE_KEY_PREFIX . 'home'), 'and under no other market key');
        $this->assertSame('storefront:*:' . self::KEY, StorefrontCatalog::cacheKey(self::KEY), 'while its catalogue lists go under a key no market reads');
    }

    public function test_every_market_writes_a_key_of_its_own_and_a_publish_forgets_all_of_them(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $forgotten = StorefrontContext::keysForEveryStorefront(self::KEY);

        $written = [];
        foreach (self::SIX_MARKETS as $market) {
            $key = StorefrontResolver::fromConfig()->context(explicitChoice: $market)->scopedCacheKey(self::KEY);
            $this->assertNotContains($key, $written, "[{$market}] writes a key of its own");
            $this->assertContains($key, $forgotten, "a publish forgets the key [{$market}] writes");
            $written[$market] = $key;
        }
    }

    /**
     * The guard for the latent half of A1-03. The unprefixed key belongs to exactly one market, and it must be the market the platform
     * serves by default: that market is the one whose catalogue rows are the unmarked ones before the `storefront_country` column exists, and
     * the one whose entries this platform already had cached. Change `GCC_DEFAULT_COUNTRY` away from the market that owns the bare key and
     * this fails, which is the intended answer -- the cache keys and the pre-migration catalogue narrowing have to be moved with it.
     */
    public function test_the_unprefixed_key_belongs_to_the_market_the_platform_serves_by_default(): void
    {
        config(['gcc.storefronts.enabled' => true]);

        $owners = array_keys(array_filter(
            array_combine(self::SIX_MARKETS, array_map(
                static fn (string $market): string => StorefrontResolver::fromConfig()->context(explicitChoice: $market)->scopedCacheKey(self::KEY),
                self::SIX_MARKETS,
            )),
            static fn (string $key): bool => $key === self::KEY,
        ));

        $this->assertSame([StorefrontResolver::fromConfig()->defaultCountry()], $owners, 'the market that owns the bare cache key is the default market');
    }

    /** The same question for the markets a theme publish names explicitly: one market, one key, and the default market's is the bare one. */
    public function test_a_publish_of_one_markets_theme_forgets_that_markets_key_alone(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $default = StorefrontResolver::fromConfig()->defaultCountry();

        $this->assertSame([self::KEY], StorefrontContext::keysForStorefronts(countries: [$default], key: self::KEY));
        $this->assertSame(['storefront:KW:' . self::KEY], StorefrontContext::keysForStorefronts(countries: ['KW'], key: self::KEY));
    }

    /**
     * The asymmetry of question 2, pinned as a decision. A theme publish reaches every market's copy whether storefronts are on or off; a
     * catalogue change reaches only the plain key while they are off, so a storefront configuration that is missing or wrong cannot reach the
     * caches of a platform that is not using storefronts. What that leaves is named in step 6 of the flag runbook.
     */
    public function test_while_storefronts_are_off_a_theme_publish_still_reaches_every_markets_copy_and_a_catalogue_change_does_not(): void
    {
        $this->assertContains('storefront:KW:' . self::KEY, StorefrontContext::keysForEveryStorefront(self::KEY));
        $this->assertSame([self::KEY], StorefrontCatalog::cacheKeysForEveryStorefront(self::KEY));
    }

    /** And with them on, both reach every market, the catalogue's own cross-country key included. */
    public function test_while_storefronts_are_on_both_forget_lists_reach_every_market(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $catalogue = StorefrontCatalog::cacheKeysForEveryStorefront(self::KEY);

        foreach (['storefront:KW:' . self::KEY, 'storefront:OM:' . self::KEY, 'storefront:*:' . self::KEY, self::KEY] as $key) {
            $this->assertContains($key, $catalogue, $key);
        }
    }

    /** A console run belongs to no market, so what it caches never fills one market's entry -- the reason the two helpers differ at all. */
    public function test_a_console_run_fills_no_markets_entry(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        (new ReflectionProperty(app(), 'isRunningInConsole'))->setValue(app(), true);
        app()->forgetInstance(StorefrontContext::class);

        $this->assertSame('storefront:*:' . self::KEY, StorefrontCatalog::cacheKey(self::KEY));
    }

    /** No migration of this repository creates the theme tables, so absent is their migrated state and dropping them is the restore. */
    protected function tearDown(): void
    {
        $this->dropThemeWorld();

        parent::tearDown();
    }
}
