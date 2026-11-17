<?php

namespace Tests\Feature\PlatformAdmin;

use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Routing\Route as RoutingRoute;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\Support\SeedsPlatformMarketData;

/**
 * The market is always the `market` parameter, validated, and never what the storefront would have picked for a visitor. A header, a query
 * value, a cookie or a session value that makes a storefront serve another country changes no answer of this API, whichever market is asked
 * for. And nothing is kept from one request to the next that a second admin, with a different scope, could be handed.
 */
class StorefrontIsolationTest extends PlatformAdminTestCase
{
    use BuildsStorefrontCatalog;
    use BuildsStorefrontLegalDocuments;
    use SeedsPlatformMarketData;

    /** What a storefront reads to choose a country, and a session and a currency a visitor may have chosen, each pointing at another market. */
    private const WHAT_CHOOSES_A_STOREFRONT = [
        'the storefront header' => ['headers' => ['X-Storefront' => 'AE']],
        'the storefront header of another country' => ['headers' => ['X-Storefront' => 'KW']],
        'the storefront query value' => ['query' => 'storefront=AE'],
        'a country query value' => ['query' => 'country=AE&country_code=AE'],
        'a currency query value' => ['query' => 'currency_code=AED&currency=AED'],
        'a session value' => ['session' => ['country' => 'AE', 'storefront' => 'AE', 'currency_code' => 'AED', 'local' => 'ar']],
        'a cookie' => ['cookies' => ['storefront' => 'AE', 'country' => 'AE', 'currency_code' => 'AED']],
        'a language header' => ['headers' => ['Accept-Language' => 'ar-AE', 'X-Country' => 'AE', 'CF-IPCountry' => 'AE']],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMarketScenario();
        $this->buildStorefrontLegalDocumentsTable();
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.AE.active' => true, 'gcc.storefronts.countries.KW.active' => true]);
    }

    public function test_nothing_a_storefront_uses_to_pick_a_country_changes_what_any_market_returns(): void
    {
        $token = $this->mintToken($this->platformAdmin());

        foreach ($this->readRoutes() as $route) {
            [, $uri] = $this->requestTo($route);

            foreach (['', '?market=all', '?market=SA', '?market=KW', '?market=AE'] as $market) {
                $baseline = $this->getJson($uri . $market, $this->bearer($token));

                foreach (self::WHAT_CHOOSES_A_STOREFRONT as $what => $change) {
                    $query = isset($change['query']) ? ($market === '' ? '?' : $market . '&') . $change['query'] : $market;

                    $changed = $this->withSession($change['session'] ?? [])
                        ->withUnencryptedCookies($change['cookies'] ?? [])
                        ->getJson($uri . $query, $this->bearer($token) + ($change['headers'] ?? []));

                    $this->assertSame($baseline->getStatusCode(), $changed->getStatusCode(), "{$uri}{$market} with {$what}");
                    $this->assertSame($baseline->getContent(), $changed->getContent(), "{$uri}{$market} with {$what} must answer what it answers without it");
                }
            }
        }
    }

    public function test_the_choice_of_a_storefront_would_narrow_a_product_query_on_this_path_so_the_answers_do_not_use_one(): void
    {
        $this->serveAsHttpRequest('/api/platform-admin/v1/overview', ['X-Storefront' => 'AE']);

        $this->assertSame('AE', StorefrontCatalog::country(), 'the control: a query through the product model would be narrowed to Emirati products on this very path');
    }

    public function test_two_principals_with_different_scopes_never_see_each_others_data(): void
    {
        $owner = $this->mintToken($this->platformAdmin());
        $viewer = $this->mintToken($this->platformAdmin(countries: ['KW']));
        $queries = [];
        foreach ($this->readRoutes() as $route) {
            [, $uri] = $this->requestTo($route);
            array_push($queries, $uri, $uri . '?market=all', $uri . '?market=KW');
        }
        $ownersOwn = $viewersOwn = [];
        foreach ($queries as $query) {
            $ownersOwn[$query] = $this->getJson($query, $this->bearer($owner))->getContent();
            $viewersOwn[$query] = $this->getJson($query, $this->bearer($viewer))->getContent();
        }

        for ($round = 0; $round < 3; $round++) {
            foreach (array_reverse($queries) as $query) {
                $this->assertSame($viewersOwn[$query], $this->getJson($query, $this->bearer($viewer))->getContent(), "the restricted viewer, round {$round}: {$query}");
                $this->assertSame($ownersOwn[$query], $this->getJson($query, $this->bearer($owner))->getContent(), "the platform owner, round {$round}: {$query}");
            }
        }

        $this->assertNotSame($ownersOwn[self::PREFIX . '/overview'], $viewersOwn[self::PREFIX . '/overview'], 'the control: the two scopes really are different');
    }

    public function test_level_one_keeps_no_server_side_cache_of_what_it_reads(): void
    {
        $files = array_merge(
            glob(app_path('Services/PlatformAdmin/*.php')) ?: [],
            glob(app_path('Http/Middleware/*PlatformAdmin*.php')) ?: [],
            glob(app_path('Http/Controllers/RestAPI/PlatformAdmin/v1/*.php')) ?: [],
        );
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            foreach (['Cache::', 'cache(', '->remember(', 'rememberForever', 'Cache\\Repository', 'CacheManager'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, basename($file) . ' must not cache anything: a cache entry needs a key of its own for the market and for who asks, and Level 1 has none');
            }
        }
    }

    /** @return list<RoutingRoute> */
    private function readRoutes(): array
    {
        return array_values(array_filter($this->platformRoutes(), static fn ($route): bool => in_array('GET', $route->methods(), true)));
    }
}
