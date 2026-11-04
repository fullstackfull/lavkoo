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

        //$this->mapInstallRoutes();
        //$this->mapUpdateRoutes();

        $this->mapBetaAdminRoutes();
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
    protected function mapBetaVendorRoutes(): void
    {
        Route::middleware('web')
            ->namespace($this->namespace)
            ->group(base_path('routes/vendor/routes.php'));
    }
    protected function mapBetaWebRoutes(): void
    {
        Route::middleware(['web', 'logUserBrowsingNavigation'])
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
        RateLimiter::for('global', function (Request $request) {
            return Limit::perMinute(app(Policy::class)->int('api_requests_per_minute'));
        });

        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(app(Policy::class)->int('auth_attempts_per_minute'))->by($request->ip());
        });
    }
}
