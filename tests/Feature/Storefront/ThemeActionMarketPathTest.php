<?php

namespace Tests\Feature\Storefront;

use App\Services\Theme\ActionResolver;
use Tests\TestCase;

/**
 * GCC F-10: an internal link that carries a market's path opens a native screen, not a browser.
 *
 * `ActionResolver` turns a merchant's stored link into a typed action so the app opens the product screen rather than a web view on top of
 * itself. It read the path from its first segment, and once the website began answering each market under its own path (A1-05,
 * `/kw/product/aspirin-100mg`) that first segment became the MARKET -- which matched nothing, fell through to the default branch and came
 * back as an ordinary external `url`. So the one kind of link a six-market storefront generates for itself was the one kind the app could
 * not open.
 *
 * The market's segment is now taken off before the path is read, through the same `StorefrontPathPrefix` the request middleware uses, so the
 * website and the app cannot disagree about what counts as a market segment. The market travels with the action, additively, so a client
 * holding several storefronts opens the destination in the one the link belongs to rather than in whichever it is showing.
 *
 * The first test is the switch: with the paths switch off, `/kw/...` is a 404 on the website, and an action that resolved it to a product
 * would be answering for a page the website does not serve -- so it stays a `url`, exactly as today.
 */
class ThemeActionMarketPathTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.paths.enabled' => true,
            'gcc.storefronts.countries.KW.active' => true,
            'app.url' => 'https://shop.example.test',
        ]);
    }

    public function test_while_the_paths_switch_is_off_a_market_path_is_the_external_url_it_has_always_been(): void
    {
        config(['gcc.storefronts.paths.enabled' => false]);

        $action = $this->resolve('/kw/product/aspirin-100mg');

        $this->assertSame(ActionResolver::URL, $action['type']);
        $this->assertArrayNotHasKey('storefront', $action);
    }

    public function test_a_market_path_to_a_product_opens_the_product_screen(): void
    {
        $action = $this->resolve('/kw/product/aspirin-100mg');

        $this->assertSame(ActionResolver::PRODUCT, $action['type']);
        $this->assertSame('aspirin-100mg', $action['slug']);
        $this->assertSame('KW', $action['storefront']);
        // The merchant's own link travels unchanged, because the web renderer keeps reading it.
        $this->assertSame('/kw/product/aspirin-100mg', $action['url']);
    }

    public function test_a_market_path_to_a_category_opens_the_category_screen(): void
    {
        $action = $this->resolve('/kw/category/vitamins');

        $this->assertSame(ActionResolver::CATEGORY, $action['type']);
        $this->assertSame('vitamins', $action['slug']);
        $this->assertSame('KW', $action['storefront']);
    }

    public function test_a_market_path_to_a_collection_opens_that_collection(): void
    {
        $action = $this->resolve('/kw/featured-products');

        $this->assertSame(ActionResolver::COLLECTION, $action['type']);
        $this->assertSame('featured', $action['collection']);
        $this->assertSame('KW', $action['storefront']);
    }

    public function test_a_market_path_to_a_composed_page_opens_that_page(): void
    {
        $action = $this->resolve('/kw/p/about-us');

        $this->assertSame(ActionResolver::PAGE, $action['type']);
        $this->assertSame('about-us', $action['slug']);
        $this->assertSame('KW', $action['storefront']);
    }

    public function test_a_market_path_keeps_its_query_string(): void
    {
        $action = $this->resolve('/kw/searched-products?name=paracetamol');

        $this->assertSame(ActionResolver::SEARCH, $action['type']);
        $this->assertSame('paracetamol', $action['query']);
        $this->assertSame('KW', $action['storefront']);
    }

    /** An absolute link of this installation under a market path is still ours. */
    public function test_an_absolute_link_of_this_installation_under_a_market_path_is_resolved_too(): void
    {
        $action = $this->resolve('https://shop.example.test/kw/product/aspirin-100mg');

        $this->assertSame(ActionResolver::PRODUCT, $action['type']);
        $this->assertSame('KW', $action['storefront']);
    }

    /** The default storefront's bare addresses are what they have always been, and name no market. */
    public function test_a_bare_path_names_no_market_and_is_resolved_exactly_as_before(): void
    {
        $action = $this->resolve('/product/aspirin-100mg');

        $this->assertSame(ActionResolver::PRODUCT, $action['type']);
        $this->assertSame('aspirin-100mg', $action['slug']);
        $this->assertArrayNotHasKey('storefront', $action, 'a link with no market was given one');
    }

    /** The default storefront is served under its own path as well, and a link that carries it says so. */
    public function test_the_default_storefronts_own_path_is_resolved_and_names_it(): void
    {
        $action = $this->resolve('/sa/product/aspirin-100mg');

        $this->assertSame(ActionResolver::PRODUCT, $action['type']);
        $this->assertSame('SA', $action['storefront']);
    }

    /** A market the configuration does not serve is not a market: the segment stays part of the path. */
    public function test_a_segment_that_is_not_a_served_market_is_not_taken_for_one(): void
    {
        $action = $this->resolve('/zz/product/aspirin-100mg');

        $this->assertSame(ActionResolver::URL, $action['type']);
        $this->assertArrayNotHasKey('storefront', $action);
    }

    /** An inactive market's path is a 404 on the website, so it is not a destination here either. */
    public function test_an_inactive_markets_path_is_not_resolved(): void
    {
        config(['gcc.storefronts.countries.AE.active' => false]);

        $action = $this->resolve('/ae/product/aspirin-100mg');

        $this->assertSame(ActionResolver::URL, $action['type']);
    }

    /** Another site's address is another site's address, market path or not. */
    public function test_another_sites_link_is_left_as_an_external_url(): void
    {
        $action = $this->resolve('https://other-shop.test/kw/product/aspirin-100mg');

        $this->assertSame(ActionResolver::URL, $action['type']);
        $this->assertArrayNotHasKey('storefront', $action);
    }

    /** `annotate()` is how a payload gains an action, so the market has to reach it there too. */
    public function test_a_payload_annotated_with_an_action_carries_the_market(): void
    {
        $payload = app(ActionResolver::class)->annotate(['link' => '/kw/product/aspirin-100mg']);

        $this->assertSame(ActionResolver::PRODUCT, $payload['action']['type']);
        $this->assertSame('KW', $payload['action']['storefront']);
    }

    /** @return array<string, mixed> */
    private function resolve(string $link): array
    {
        return app(ActionResolver::class)->resolve($link);
    }
}
