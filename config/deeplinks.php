<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The paths that open the app
    |--------------------------------------------------------------------------
    |
    | `DeepLinkResolver::match()` reads this list, and it was EMPTY. With nothing to match against,
    | every path fell through to `target: 'web'` with the reason
    | `this_path_is_not_published_as_an_app_link` — so /api/v1/deep-link/resolve answered "open this
    | in a browser" for a product link, a brand link and a campaign short link alike, and the app
    | bounced the customer straight back out to Safari or Chrome. Every deep link in the shop was
    | dead, and nothing said so: the endpoint answered 200 and the app did exactly as it was told.
    |
    | The list below is not invented. It mirrors, path for path, what the app itself already reads
    | offline in `DeepLinkResolver.readLocally` (lib/data/services/deep_link_resolver.dart), so the
    | shop's answer and the app's fallback agree instead of contradicting each other, and each path
    | is backed by a real web route:
    |
    |   /               → routes/web/routes.php, the storefront home
    |   /product/{slug} → Route::get('/product/{slug}', 'index')->name('product')
    |   /brand/{slug}   → Route::get('brand/{slug}', ...)->name('brand-products')
    |   /products       → Route::get('products', 'products')->name('products')
    |   /go/{code}      → the campaign short link, resolved by CampaignService
    |
    | `target` is the app's vocabulary for a screen: home, product, brand, product_list, campaign.
    |
    | /track-order is DELIBERATELY ABSENT, and that is a fix rather than an omission. The web routes
    | under that prefix are `result`, `result-view`, `last` and `order-wise-result-view` — the order
    | identifier travels in the QUERY STRING, never as a path segment. Publishing `/track-order/*`
    | would have the app read the word `result` out of
    | https://pharmacyksa.com/track-order/result?... and open order tracking for an order called
    | "result", which is what the order-placed email's Track Order button actually did. Left
    | unpublished, such a link resolves to `web` and the customer sees the real tracking page.
    |
    */
    'enabled_routes' => [
        ['path' => '/', 'target' => 'home'],
        ['path' => '/product/*', 'target' => 'product'],
        ['path' => '/brand/*', 'target' => 'brand'],
        ['path' => '/products', 'target' => 'product_list'],
        ['path' => '/go/*', 'target' => 'campaign'],
    ],

    /*
    |--------------------------------------------------------------------------
    | What each platform claims
    |--------------------------------------------------------------------------
    |
    | Android verifies the whole host, so this list is what the app's own intent filters have to
    | mirror; it is published on the setup screen and through /api/v1/deep-link/config so the app
    | team reads it from the shop rather than from somebody's notes. iOS reads its list out of the
    | association file, where it IS authoritative: a path missing from it opens the browser.
    |
    | `AppLinkService::paths()` appends the campaign pattern itself, so /go/* is not repeated here.
    |
    */
    'android_paths' => [
        '/',
        '/product/*',
        '/brand/*',
        '/products',
    ],

    'ios_paths' => [
        '/',
        '/product/*',
        '/brand/*',
        '/products',
    ],

];
