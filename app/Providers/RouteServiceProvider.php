<?php

namespace App\Providers;

use Illuminate\Http\Request; 
use App\Services\Platform\Policy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * This namespace is applied to your controller routes.
     *
     * In addition, it is set as the URL generator's root namespace.
     *
     * @var string
     */
    protected $namespace = 'App\Http\Controllers';

    /**
     * The path to the "home" route for your application.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        //

        parent::boot();
        $this->configureRateLimiting();
    }

    /**
     * Define the routes for the application.
     *
     * @return void
     */
    public function map()
    {
        $this->mapApiRoutes();
        $this->mapApiv2Routes();
        $this->mapApiv3Routes();
        $this->mapDeliverySyriaRoutes();
        $this->mapFulfillmentWebhookRoutes();
        $this->mapMyFatoorahWebhookRoutes();
        $this->mapPlatformAdminRoutes();

        //$this->mapInstallRoutes();
        //$this->mapUpdateRoutes();

        $this->mapBetaAdminRoutes();
        $this->mapFulfillmentAdminRoutes();
        $this->mapAddressAdminRoutes();
        $this->mapStorefrontAdminRoutes();
        $this->mapBetaVendorRoutes();
        $this->mapSellerCenterRoutes();
        $this->mapBetaWebRoutes();
    }

    /**
     * Define the "web" routes for the application.
     *
     * These routes all receive session state, CSRF protection, etc.
     *
     * @return void
     */

    protected function mapInstallRoutes()
    {
        Route::middleware('web')
            ->namespace($this->namespace)
            ->group(base_path('routes/install.php'));
    }

    protected function mapUpdateRoutes()
    {
        Route::middleware('web')
            ->namespace($this->namespace)
            ->group(base_path('routes/update.php'));
    }

    /**
     * Define the "api" routes for the application.
     *
     * These routes are typically stateless.
     *
     * @return void
     */
    protected function mapApiRoutes()
    {
        Route::prefix('api')
            ->middleware('api')
            ->namespace($this->namespace)
            ->group(base_path('routes/rest_api/v1/api.php'));
    }

    protected function mapApiv2Routes()
    {
        Route::prefix('api')
            ->middleware('api')
            ->namespace($this->namespace)
            ->group(base_path('routes/rest_api/v2/api.php'));
    }

    protected function mapApiv3Routes()
    {
        Route::prefix('api')
            ->middleware('api')
            ->namespace($this->namespace)
            ->group(base_path('routes/rest_api/v3/seller.php'));
    }

    /**
     * Delivery Syria inbound status webhook (optional courier integration).
     *
     * Kept in its own file, outside the versioned v1/v2/v3 groups, so the courier's exact spec URL
     * resolves — POST /api/delivery-syria/orders/update-status — with no version segment.
     */
    protected function mapDeliverySyriaRoutes(): void
    {
        Route::prefix('api')
            ->middleware('api')
            ->namespace($this->namespace)
            ->group(base_path('routes/rest_api/delivery_syria.php'));
    }

    /**
     * Provider fulfillment webhooks. Under the `api` prefix but outside the `api` middleware group —
     * see routes/rest_api/fulfillment_webhooks.php for why.
     */
    protected function mapFulfillmentWebhookRoutes(): void
    {
        Route::prefix('api')
            ->namespace($this->namespace)
            ->group(base_path('routes/rest_api/fulfillment_webhooks.php'));
    }

    /**
     * The MyFatoorah payment webhook. Same reasoning as the fulfillment webhooks: under the `api`
     * prefix, outside the `api` middleware group, authenticated by a signature in the controller.
     */
    protected function mapMyFatoorahWebhookRoutes(): void
    {
        Route::prefix('api')
            ->namespace($this->namespace)
            ->group(base_path('routes/rest_api/myfatoorah_webhook.php'));
    }

    /**
     * The platform admin API (GCC-20). Under the `api` prefix but outside the `api` middleware group, which records a telemetry row and a
     * response shape for every request; its own middleware decide whether the API exists at all.
     */
    protected function mapPlatformAdminRoutes(): void
    {
        Route::prefix('api')
            ->namespace($this->namespace)
            ->group(base_path('routes/rest_api/platform_admin.php'));
    }

    /**
     * Define the "beta" routes for the application.
     *
     * These routes all receive session state, CSRF protection, etc.
     *
     * @return void
     */

    /**
     * The Seller Center, the redesigned screens the seller operates the shop from.
     *
     * Mounted on `/vendor` beside the classic panel rather than on a prefix of its own, because
     * there is one seller panel and not two.
     *
     * **Loaded after the classic panel, deliberately.** Both files mount on the same prefix, and
     * the first matching route wins — so this order is what makes the redesign additive: a new
     * screen here can never shadow a page that already works. The reverse order would mean every
     * route added to a later wave silently taking over whatever classic URL it happened to
     * resemble. `SellerCenterRouteCollisionTest` holds the line.
     *
     * Still before the web routes, so the panel's prefix wins over any catch-all the storefront
     * declares.
     */
    protected function mapSellerCenterRoutes(): void
    {
        Route::middleware('web')
            ->namespace($this->namespace)
            ->group(base_path('routes/seller/routes.php'));
    }

    protected function mapBetaAdminRoutes(): void
    {
        Route::middleware('web')
            ->namespace($this->namespace)
            ->group(base_path('routes/admin/routes.php'));
    }
    /** Integration Center and the M5AZN / Torod Point admin pages. */
    protected function mapFulfillmentAdminRoutes(): void
    {
        Route::middleware('web')
            ->namespace($this->namespace)
            ->group(base_path('routes/admin/fulfillment.php'));
    }

    /** The unified Saudi address: mode, regions, cities, the review of old addresses and the data import. */
    protected function mapAddressAdminRoutes(): void
    {
        Route::middleware('web')
            ->namespace($this->namespace)
            ->group(base_path('routes/admin/addresses.php'));
    }

    /** The storefronts of the platform: their lifecycle and their legal texts. */
    protected function mapStorefrontAdminRoutes(): void
    {
        Route::middleware('web')
            ->namespace($this->namespace)
            ->group(base_path('routes/admin/storefronts.php'));
    }

    protected function mapBetaVendorRoutes(): void
    {
        Route::middleware('web')
            ->namespace($this->namespace)
            ->group(base_path('routes/vendor/routes.php'));
    }
    protected function mapBetaWebRoutes(): void
    {
        Route::middleware(['web', 'storefront', 'storefront.session', 'logUserBrowsingNavigation'])
            ->namespace($this->namespace)
            ->group(base_path('routes/web/routes.php'));
    }

    /**
     * Configure the rate limiters for the application.
     */
    /**
     * The two limiters the whole platform throttles by.
     *
     * Both used to be literals — `throttle:20,1` repeated across six route files and a global
     * `perMinute(3000)` that v1's own routes described as "effectively none". Tightening the login
     * limiter while under attack is a posture change an operator makes in minutes, so it is a
     * setting, read at request time rather than baked into the route table at boot.
     */
    protected function configureRateLimiting(): void
    {
        // MyFatoorah retries a delivery up to five times over three minutes, and a busy store can have
        // many invoices changing state at once, so the limit is generous: it is here to stop a flood,
        // not to pace a provider that is behaving.
        RateLimiter::for('myfatoorah-webhooks', static fn (Request $request): Limit => Limit::perMinute(600)->by($request->ip()));

        // A company's own notification address (D2-09) gets a limiter of its own, with TWO limits.
        //
        // Deliveries arrive from the gateway's own hosts, so every company's address and the platform's are a handful of source addresses between them.
        // Under one bucket keyed by address alone, one market's retry storm spends the allowance of all the others and of the platform address with it.
        // The per-account limit gives each address its own allowance, so a storm at one is bounded to that one. Naming this limiter separately is what
        // takes the platform address out of the shared bucket: a named limiter's key carries its own name.
        //
        // The per-address limit stays at 600, the same ceiling as before, and that is deliberate: it is the limit a prober would meet, and a prober
        // chooses the token in the path. Keyed by the token ALONE, varying it would buy an unlimited number of requests from one address, so the
        // address ceiling is kept and the token limit sits inside it. Whether that ceiling should be raised so six markets' simultaneous retries do not
        // share one 600 is a posture number, and posture is the owner's; it is recorded on the pull request rather than chosen here.
        RateLimiter::for('payment-account-webhooks', static fn (Request $request): array => [
            Limit::perMinute(600)->by('account:' . (string) $request->route('account')),
            Limit::perMinute(600)->by('ip:' . (string) $request->ip()),
        ]);

        // The payment-status endpoint the app polls. The `api` group allows 3000 a minute, which is far
        // too generous for a route that can cost one MyFatoorah read per request. The app's own polling
        // tops out near ten requests per payment, so this leaves plenty of room for several customers
        // behind one address while cutting the ceiling by a factor of thirty.
        RateLimiter::for('myfatoorah-status', static fn (Request $request): Limit => Limit::perMinute(90)->by($request->ip()));

        /*
         * THE GATEWAY RETURN AND CALLBACK ROUTES. Forty-nine of them sit in one group in
         * routes/web/routes.php -- one `pay`, `success`, `failed`, `cancel` or `callback` per gateway
         * -- and NOT ONE carried a limit. Twelve are POST routes that stand the CSRF check down,
         * because the caller is a gateway and not a browser holding a session, so they were reachable
         * by anyone at any rate.
         *
         * 600 a minute per source address, the same posture number this file already uses for the
         * MyFatoorah webhook, and for the same reason: it is here to stop a flood, not to pace a
         * gateway that is behaving. A customer coming back from a gateway makes one or two requests; a
         * gateway retrying a callback makes a handful. Several customers behind one carrier address
         * stay far under it.
         *
         * KEYED BY SOURCE ADDRESS AND BY NOTHING THE CALLER CHOOSES. A key taken from the path or the
         * body -- the gateway name, a payment id -- would let one prober vary it and buy an unlimited
         * number of requests from one address, which is the opposite of a limit.
         */
        RateLimiter::for('public-payment-endpoints', static fn (Request $request): Limit => Limit::perMinute(600)->by($request->ip()));

        RateLimiter::for('global', function (Request $request) {
            return Limit::perMinute(app(Policy::class)->int('api_requests_per_minute'));
        });

        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(app(Policy::class)->int('auth_attempts_per_minute'))->by($request->ip());
        });
    }
}
