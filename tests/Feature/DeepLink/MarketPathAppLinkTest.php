<?php

namespace Tests\Feature\DeepLink;

use App\Services\DeepLink\AppLinkService;
use App\Services\DeepLink\DeepLinkResolver;
use Tests\TestCase;

/**
 * B-18: a link under a market's own path opens the app, in that market.
 *
 * The website answers each market under `/sa`, `/ae`, `/kw`, `/qa`, `/bh` and `/om` (A1-05), and those are the addresses a market's own
 * pages generate, put in the sitemap and print in the canonical tag. Two things were wrong with them, in opposite directions:
 *
 *  - **iOS treats the association file as AUTHORITATIVE**: a path that is not in it opens the BROWSER. `/kw/product/aspirin-100mg` was
 *    not in it, so the Kuwaiti storefront's own product link bounced the customer out of the app into Safari while the bare
 *    `/product/aspirin-100mg` opened the app. One shop, two behaviours, decided by a prefix;
 *  - and had it opened the app, `DeepLinkResolver` would have matched the path against the published patterns WITH the market still on
 *    the front, matched nothing, and answered `target: web` -- so the app would have put the shop in a web view inside itself.
 *
 * The market is taken off before the match and reported beside the answer, through the same `StorefrontPathPrefix` the website and the
 * theme's link actions use, so the three cannot disagree about what a market segment is. `path` and `web_url` keep the market, because a
 * web view has to open the market's own address and not Saudi Arabia's.
 *
 * The first test is the switch, and here it matters more than usual: a published association file is LIVE APP BEHAVIOUR that a phone has
 * already cached, not a page anybody can re-read, so with the paths switch off the files must be byte for byte the ones they are today.
 */
class MarketPathAppLinkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://shop.example.test',
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.paths.enabled' => true,
            'gcc.storefronts.countries.KW.active' => true,
        ]);
    }

    public function test_while_the_paths_switch_is_off_the_published_paths_are_the_ones_of_today(): void
    {
        config(['gcc.storefronts.paths.enabled' => false]);

        $published = app(AppLinkService::class)->paths(AppLinkService::PLATFORM_IOS);

        $this->assertSame([], array_values(array_filter($published, static fn (string $path): bool => str_starts_with($path, '/kw'))));
        $this->assertContains('/product/*', $published, 'the bare paths are what a phone has cached');
    }

    public function test_while_the_paths_switch_is_off_a_market_path_resolves_as_it_does_today(): void
    {
        config(['gcc.storefronts.paths.enabled' => false]);

        $resolved = $this->resolve('/kw/product/aspirin-100mg');

        $this->assertSame('web', $resolved['target'], 'a market path resolved to a screen although the website answers 404 for it');
        $this->assertNull($resolved['storefront']);
    }

    public function test_each_served_markets_paths_are_published_as_app_links(): void
    {
        $published = app(AppLinkService::class)->paths(AppLinkService::PLATFORM_IOS);

        foreach (['/kw/product/*', '/kw/brand/*', '/kw/products', '/kw'] as $path) {
            $this->assertContains($path, $published, 'the Kuwaiti storefront\'s own addresses do not open the app');
        }

        // The bare ones stay: the default storefront keeps the addresses it has today, and nothing generates /sa for it.
        $this->assertContains('/product/*', $published);
    }

    public function test_the_phone_would_open_the_app_for_a_market_path(): void
    {
        $links = app(AppLinkService::class);

        $this->assertTrue($links->opensTheApp('/kw/product/aspirin-100mg'), 'the association rule would send this to the browser');
        $this->assertTrue($links->opensTheApp('/product/aspirin-100mg'));
    }

    /** A short link is a machine path and is never given a market, so it must not gain one here either. */
    public function test_the_campaign_short_link_is_not_published_under_a_market(): void
    {
        $published = app(AppLinkService::class)->paths(AppLinkService::PLATFORM_IOS);

        $this->assertContains('/go/*', $published);
        $this->assertNotContains('/kw/go/*', $published);
    }

    public function test_a_market_path_to_a_product_opens_the_product_screen_in_that_market(): void
    {
        $resolved = $this->resolve('/kw/product/aspirin-100mg');

        $this->assertTrue($resolved['resolved']);
        $this->assertSame('product', $resolved['target']);
        $this->assertSame('aspirin-100mg', $resolved['parameter']);
        $this->assertSame('KW', $resolved['storefront']);
        // The web view has to open the market's own address, so the path and the url keep the prefix.
        $this->assertSame('/kw/product/aspirin-100mg', $resolved['path']);
        $this->assertSame('https://shop.example.test/kw/product/aspirin-100mg', $resolved['web_url']);
    }

    public function test_a_market_path_to_the_product_list_opens_the_list(): void
    {
        $resolved = $this->resolve('/kw/products');

        $this->assertSame('product_list', $resolved['target']);
        $this->assertSame('KW', $resolved['storefront']);
    }

    public function test_the_root_of_a_market_opens_the_home_screen(): void
    {
        $resolved = $this->resolve('/kw');

        $this->assertSame('home', $resolved['target']);
        $this->assertSame('KW', $resolved['storefront']);
    }

    /** The default storefront's bare addresses name no market and are what they have always been. */
    public function test_a_bare_path_names_no_market(): void
    {
        $resolved = $this->resolve('/product/aspirin-100mg');

        $this->assertSame('product', $resolved['target']);
        $this->assertNull($resolved['storefront'], 'a link with no market was given one');
    }

    /** A market the configuration does not serve is not a market: the segment stays part of the path. */
    public function test_a_segment_that_is_not_a_served_market_is_not_taken_for_one(): void
    {
        $resolved = $this->resolve('/zz/product/aspirin-100mg');

        $this->assertSame('web', $resolved['target']);
        $this->assertNull($resolved['storefront']);
    }

    public function test_an_inactive_markets_path_is_not_resolved(): void
    {
        config(['gcc.storefronts.countries.AE.active' => false]);

        $resolved = $this->resolve('/ae/product/aspirin-100mg');

        $this->assertSame('web', $resolved['target']);
    }

    /** @return array<string, mixed> */
    private function resolve(string $path): array
    {
        return app(DeepLinkResolver::class)->resolve('https://shop.example.test' . $path);
    }
}
