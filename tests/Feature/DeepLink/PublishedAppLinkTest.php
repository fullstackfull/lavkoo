<?php

namespace Tests\Feature\DeepLink;

use App\Services\DeepLink\DeepLinkResolver;
use Tests\TestCase;

/**
 * Does a shop link open the app, and does the right link open the right screen?
 *
 * `config('deeplinks.enabled_routes')` shipped EMPTY. `DeepLinkResolver::match()` iterates it, so it
 * matched nothing, so every path returned `target: 'web'` with the reason
 * `this_path_is_not_published_as_an_app_link` — and the app, doing exactly as it was told, sent the
 * customer out to the browser for every product link, brand link and campaign short link in the
 * shop. The endpoint answered 200 throughout. Nothing anywhere said the feature was off.
 *
 * An empty list cannot be tested against: any assertion about where a path leads passes trivially
 * when the answer is always "the web". So these tests are written against the paths the shop really
 * serves, and the last of them is the one that would have caught the original state.
 */
class PublishedAppLinkTest extends TestCase
{
    /** The shop's own host, since `pathOf()` refuses a URL on any other. */
    private function shopUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/') . $path;
    }

    private function resolve(string $path): array
    {
        return app(DeepLinkResolver::class)->resolve($this->shopUrl($path));
    }

    public function test_the_home_page_opens_the_app(): void
    {
        $answer = $this->resolve('/');

        $this->assertTrue($answer['resolved']);
        $this->assertSame('home', $answer['target']);
    }

    public function test_a_product_link_opens_the_product_and_carries_its_slug(): void
    {
        $answer = $this->resolve('/product/panadol-extra-24-tablets');

        $this->assertTrue($answer['resolved']);
        $this->assertSame('product', $answer['target']);
        $this->assertSame('panadol-extra-24-tablets', $answer['parameter']);
    }

    public function test_a_brand_link_opens_the_brand(): void
    {
        $answer = $this->resolve('/brand/cerave');

        $this->assertSame('brand', $answer['target']);
        $this->assertSame('cerave', $answer['parameter']);
    }

    public function test_the_product_list_opens_the_list(): void
    {
        $this->assertSame('product_list', $this->resolve('/products')['target']);
    }

    public function test_the_utm_tags_on_a_campaign_link_survive_resolution(): void
    {
        $answer = app(DeepLinkResolver::class)->resolve(
            $this->shopUrl('/product/panadol?utm_source=instagram&utm_campaign=ramadan'),
        );

        $this->assertSame('instagram', $answer['attribution']['utm_source'] ?? null);
        $this->assertSame('ramadan', $answer['attribution']['utm_campaign'] ?? null);
    }

    // ---- what must NOT be claimed ------------------------------------------------------------------

    public function test_a_tracking_link_goes_to_the_web_because_the_order_id_is_not_in_the_path(): void
    {
        // The real link, from the order-placed email. Every route under this prefix -- result,
        // result-view, last, order-wise-result-view -- carries the order in the QUERY STRING. If
        // /track-order/* were published, the app would read the word "result" as an order id and
        // open tracking for an order by that name, which is precisely what it used to do.
        $answer = $this->resolve('/track-order/result?order_id=4821&phone=%2B966500000000');

        $this->assertFalse($answer['resolved']);
        $this->assertSame('web', $answer['target']);
        $this->assertNull($answer['parameter'], 'nothing may be invented from the path');
    }

    public function test_a_page_the_app_has_no_screen_for_opens_in_a_web_view(): void
    {
        $answer = $this->resolve('/support-ticket');

        $this->assertSame('web', $answer['target']);
        $this->assertSame('this_path_is_not_published_as_an_app_link', $answer['reason']);
    }

    public function test_a_link_on_another_shop_s_domain_is_not_ours(): void
    {
        $answer = app(DeepLinkResolver::class)->resolve('https://not-this-shop.example/product/panadol');

        $this->assertFalse($answer['resolved']);
        $this->assertSame('that_link_does_not_belong_to_this_shop', $answer['reason']);
    }

    // ---- and the list itself -----------------------------------------------------------------------

    public function test_something_is_published_at_all(): void
    {
        // The test the empty config would have failed, and the reason this file exists. Every
        // assertion above would also have failed, but each would have read as a routing disagreement
        // rather than as "the whole feature is switched off".
        $routes = (array) config('deeplinks.enabled_routes');

        $this->assertNotEmpty($routes, 'with no published routes every shop link opens the browser');

        foreach ($routes as $route) {
            $this->assertArrayHasKey('path', $route);
            $this->assertArrayHasKey('target', $route);
            $this->assertNotSame('', trim((string) $route['path']), 'match() skips an empty pattern');
        }
    }

    public function test_every_published_path_names_a_screen_the_app_knows(): void
    {
        // The app's own vocabulary, from deep_link_resolver.dart. A target the app does not know
        // falls through its switch to `web`, so a typo here would silently un-publish a path.
        $known = ['home', 'product', 'brand', 'product_list', 'order_tracking', 'campaign', 'web'];

        foreach ((array) config('deeplinks.enabled_routes') as $route) {
            $this->assertContains((string) $route['target'], $known, "{$route['path']} names no app screen");
        }
    }

    public function test_the_published_ios_paths_cover_every_route_the_app_can_open(): void
    {
        // iOS reads its path list out of the association file and opens the browser for anything
        // absent from it, so a route published here but missing there resolves in the API and still
        // never reaches the app.
        $ios = (array) config('deeplinks.ios_paths');

        foreach ((array) config('deeplinks.enabled_routes') as $route) {
            $path = (string) $route['path'];

            if ($route['target'] === 'campaign') {
                continue; // AppLinkService::paths() appends the campaign pattern itself.
            }

            $this->assertContains($path, $ios, "{$path} resolves but iOS will not open it");
        }
    }
}
