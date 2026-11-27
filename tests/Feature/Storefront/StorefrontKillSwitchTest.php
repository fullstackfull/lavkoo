<?php

namespace Tests\Feature\Storefront;

use App\Models\Product;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * How to switch a country off once it has products. Turning a country inactive keeps every storefront narrowed: the Saudi storefront never
 * shows its products and a visitor who asks for it is served the default storefront. Turning the flag off removes the narrowing for all
 * of them, so it is not a kill switch once another country has products. These tests pin both, so the runbook cannot drift from the code.
 *
 * M1-18's criterion is that a GCC switch can be turned off AGAIN after use without exposing data across countries, and the third test
 * below is the measurement that said it could not: `gcc.storefronts.enabled` off narrows nothing, so the market that was added is shown
 * to the one that remains. `gcc.storefronts.off_is_one_market` is the answer, and it ships OFF -- the first test is that with it off the
 * old behaviour is exactly what it was. With it on, off means ONE MARKET: a storefront request falls back to the default market's
 * catalogue and the added market's rows stay out of it, while the panels, the seller APIs, the webhooks and the platform admin API stay
 * cross-country, which is what they are for.
 *
 * The other three switches M1-18 names are NOT closed here and are not pretended to be. `gcc.staff.country_scopes` off makes a restricted
 * employee unrestricted, `gcc.catalog.country_scoped_codes` off makes a code lookup reach another country's product, and
 * `gcc.platform_admin.enabled` off removes a capability and widens nothing. Each needs the same shape of mechanism as this one, in its own
 * reader, and each is its own measurement.
 */
class StorefrontKillSwitchTest extends TestCase
{
    use BuildsStorefrontCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontCatalogTables();
        $this->runStorefrontCountryMigration();
        $this->insertCatalogProduct(id: 1, name: 'Saudi serum');
        $this->insertCatalogProduct(id: 2, name: 'Emirati serum', country: 'AE');
    }

    private function serveRequestAskingFor(string $country): void
    {
        app()->instance(StorefrontContext::class, StorefrontResolver::fromConfig()->context(explicitChoice: $country));
    }

    private function visibleProductIds(): array
    {
        return Product::query()->active()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_deactivating_a_country_keeps_its_products_out_of_every_other_storefront(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.AE.active' => false]);

        $this->serveRequestAskingFor('SA');
        $this->assertSame([1], $this->visibleProductIds());
    }

    public function test_a_visitor_who_asks_for_a_deactivated_country_is_served_the_default_storefront_and_none_of_its_products(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.AE.active' => false]);

        $this->serveRequestAskingFor('AE');

        $this->assertSame('SA', StorefrontContext::current()->country);
        $this->assertSame([1], $this->visibleProductIds());
    }

    public function test_turning_the_flag_off_removes_the_narrowing_for_every_country_so_it_is_not_a_kill_switch(): void
    {
        $this->assertFalse((bool) config('gcc.storefronts.off_is_one_market'), 'the switch that changes this ships off');

        config(['gcc.storefronts.enabled' => false, 'gcc.storefronts.countries.AE.active' => true]);

        $this->serveRequestAskingFor('SA');

        $this->assertSame([1, 2], $this->visibleProductIds());
    }

    /** The same request, with the switch off, through the real request path rather than a bound context: still both markets. */
    public function test_as_it_ships_a_storefront_request_with_the_flag_off_is_shown_every_market_s_products(): void
    {
        config(['gcc.storefronts.enabled' => false]);
        $this->serveAsHttpRequest('/');

        $this->assertSame([1, 2], $this->visibleProductIds());
    }

    private function offMeansOneMarket(): void
    {
        config(['gcc.storefronts.enabled' => false, 'gcc.storefronts.off_is_one_market' => true]);
    }

    public function test_off_means_one_market_so_the_added_market_s_products_stay_out_of_the_catalogue(): void
    {
        $this->offMeansOneMarket();
        $this->serveAsHttpRequest('/');

        $this->assertSame([1], $this->visibleProductIds(), 'the default market keeps its own catalogue and is shown no other');
    }

    public function test_a_visitor_who_asks_for_the_added_market_is_still_shown_only_the_default_one(): void
    {
        $this->offMeansOneMarket();
        $this->serveAsHttpRequest('/', ['X-Storefront' => 'AE']);

        $this->assertSame('SA', StorefrontCatalog::country(), 'with the switch off no request may choose a market');
        $this->assertSame([1], $this->visibleProductIds());
        $this->assertSame(
            ['AE'],
            [$this->app['request']->header('X-Storefront')],
            'the request really did ask for the other market, which the resolver refuses twice over: it does not resolve a choice while'
            . ' the switch is off, and this branch asks it for the default market by name and never for what the request wanted',
        );
    }

    public function test_a_context_bound_to_the_added_market_cannot_widen_the_catalogue_either(): void
    {
        $this->offMeansOneMarket();
        $this->serveAsHttpRequest('/');
        $this->serveRequestAskingFor('AE');

        $this->assertSame([1], $this->visibleProductIds(), 'this branch can only narrow, never widen');
    }

    public function test_the_panels_the_seller_apis_and_the_platform_admin_stay_cross_country(): void
    {
        $this->offMeansOneMarket();

        foreach (['/admin/products', '/vendor/products', '/api/v3/seller/products', '/api/platform-admin/markets'] as $uri) {
            $this->serveAsHttpRequest($uri);
            StorefrontCatalog::flush();

            $this->assertSame([1, 2], $this->visibleProductIds(), "{$uri} works across every country on purpose");
        }
    }

    public function test_a_queue_or_a_console_command_stays_cross_country(): void
    {
        $this->offMeansOneMarket();

        $this->assertSame([1, 2], $this->visibleProductIds(), 'nothing of a worker is narrowed to one market');
    }

    public function test_it_is_inert_before_the_products_migration_has_run(): void
    {
        $this->removeStorefrontCountryColumn();
        StorefrontCatalog::flush();
        $this->offMeansOneMarket();
        $this->serveAsHttpRequest('/');

        $this->assertNull(
            StorefrontCatalog::country(),
            'before the migration the request belongs to no market at all, which is what the rest of the application reads while'
            . ' storefronts are off: the switch is inert at the context and not only at the query',
        );
        $this->assertSame([1, 2], $this->visibleProductIds(), 'no column, so no market was ever added and there is nothing to keep out');
    }

    public function test_with_the_flag_on_again_the_added_market_is_served_its_own_products(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.off_is_one_market' => true, 'gcc.storefronts.countries.AE.active' => true]);

        $this->serveRequestAskingFor('AE');

        $this->assertSame([2], $this->visibleProductIds(), 'turning it off is reversible: the rows were never touched');
    }
}
