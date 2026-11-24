<?php

namespace Tests\Feature\PlatformAdmin;

use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
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
        $owner = $this->platformAdmin();
        $token = $this->mintToken($owner);
        $trail = $this->seedTrail([$owner]);

        foreach ($this->readRoutes() as $route) {
            [, $uri] = $this->requestTo($route);

            foreach (['', '?market=all', '?market=SA', '?market=KW', '?market=AE'] as $market) {
                $this->forgetRequestsOfTheTest($trail);
                $baseline = $this->getJson($uri . $market, $this->bearer($token));

                foreach (self::WHAT_CHOOSES_A_STOREFRONT as $what => $change) {
                    $query = isset($change['query']) ? ($market === '' ? '?' : $market . '&') . $change['query'] : $market;

                    $this->forgetRequestsOfTheTest($trail);
                    $changed = $this->withSession($change['session'] ?? [])
                        ->withUnencryptedCookies($change['cookies'] ?? [])
                        ->getJson($uri . $query, $this->bearer($token) + ($change['headers'] ?? []));

                    $this->assertSame($baseline->getStatusCode(), $changed->getStatusCode(), "{$uri}{$market} with {$what}");
                    $this->assertSame($baseline->getContent(), $changed->getContent(), "{$uri}{$market} with {$what} must answer what it answers without it");
                }
            }
        }
    }

    /**
     * The premise of this suite, now true twice over.
     *
     * It used to read the other way round: a storefront choice DID narrow a product query on this path, and the suite's point was that the
     * API's answers never use one. That hazard is gone -- `api/platform-admin` is one of the cross-country paths, so nothing narrows it
     * whatever a header, a cookie or a session says (A1-11). The control is kept and inverted, with the storefront path beside it, so the
     * assertion is about the PATH and not about the switches being off: the same header that narrows nothing here narrows a storefront's own
     * API.
     */
    public function test_a_storefront_choice_narrows_nothing_on_this_path_and_still_narrows_a_storefronts_own(): void
    {
        $this->serveAsHttpRequest('/api/platform-admin/v1/overview', ['X-Storefront' => 'AE']);

        $this->assertNull(StorefrontCatalog::country(), 'the platform admin sees every market: a query through the product model is narrowed to none');

        $this->serveAsHttpRequest('/api/v1/products/latest', ['X-Storefront' => 'AE']);

        $this->assertSame('AE', StorefrontCatalog::country(), 'the control: the same header still narrows a storefront\'s own API, so the path is what decides');
    }

    public function test_two_principals_with_different_scopes_never_see_each_others_data(): void
    {
        $ownerAdmin = $this->platformAdmin();
        $viewerAdmin = $this->platformAdmin(countries: ['KW']);
        $owner = $this->mintToken($ownerAdmin);
        $viewer = $this->mintToken($viewerAdmin);
        $trail = $this->seedTrail([$ownerAdmin, $viewerAdmin]);
        $queries = [];
        foreach ($this->readRoutes() as $route) {
            [, $uri] = $this->requestTo($route);
            array_push($queries, $uri, $uri . '?market=all', $uri . '?market=KW');
        }
        $ownersOwn = $viewersOwn = [];
        foreach ($queries as $query) {
            $this->forgetRequestsOfTheTest($trail);
            $ownersOwn[$query] = $this->getJson($query, $this->bearer($owner))->getContent();
            $this->forgetRequestsOfTheTest($trail);
            $viewersOwn[$query] = $this->getJson($query, $this->bearer($viewer))->getContent();
        }

        for ($round = 0; $round < 3; $round++) {
            foreach (array_reverse($queries) as $query) {
                $this->forgetRequestsOfTheTest($trail);
                $this->assertSame($viewersOwn[$query], $this->getJson($query, $this->bearer($viewer))->getContent(), "the restricted viewer, round {$round}: {$query}");
                $this->forgetRequestsOfTheTest($trail);
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

    /**
     * Puts a few lines in the audit trail for each admin, so that the answer of the trail has something in it, and returns the last id they have.
     * Every request of a test adds a line of its own, which the trail would show the next time it is read: those are taken out before each request.
     *
     * @param list<array{id: int}> $admins
     */
    private function seedTrail(array $admins): int
    {
        foreach ($admins as $admin) {
            DB::table('platform_admin_audit_logs')->insert(['admin_id' => $admin['id'], 'action' => 'overview.view', 'market' => 'KW', 'ip' => '203.0.113.7', 'created_at' => now()]);
        }

        return (int) DB::table('platform_admin_audit_logs')->max('id');
    }

    private function forgetRequestsOfTheTest(int $lastSeededLine): void
    {
        DB::table('platform_admin_audit_logs')->where('id', '>', $lastSeededLine)->delete();
    }

    /** @return list<RoutingRoute> */
    private function readRoutes(): array
    {
        return array_values(array_filter($this->platformRoutes(), static fn ($route): bool => in_array('GET', $route->methods(), true)));
    }
}
