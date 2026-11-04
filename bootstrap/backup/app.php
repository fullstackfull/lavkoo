<?php

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\APIGuestMiddleware;
use App\Http\Middleware\APILocalizationMiddleware;
use App\Http\Middleware\CustomerMiddleware;
use App\Http\Middleware\DatabaseRefreshMiddleware;
use App\Http\Middleware\DeliveryManAuth;
use App\Http\Middleware\GuestMiddleware;
use App\Http\Middleware\InstallationMiddleware;
use App\Http\Middleware\MaintenanceModeMiddleware;
use App\Http\Middleware\ModulePermissionMiddleware;
use App\Http\Middleware\SellerApiAuthMiddleware;
use App\Http\Middleware\SellerMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\CheckForMaintenanceMode;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Routing\Middleware\SubstituteBindings;

/*
| Asset-path mode for CLI / queue context.
|
| The web entry scripts (public/index.php, index.php) define DOMAIN_POINTED_DIRECTORY
| per deployment *before* requiring this file. Console processes (queue:work,
| schedule:run) never load those entry scripts, so the constant was undefined in
| CLI — and any asset-URL generation off the queue (e.g. a queued notification
| building a product thumbnail URL via dynamicStorage()) fatally errored. Define
| the conventional default here when a web entry hasn't already set it, so CLI
| matches web. Deployments that point the domain at the project root can override
| this by exporting DOMAIN_POINTED_DIRECTORY in the worker environment.
*/
if (!defined('DOMAIN_POINTED_DIRECTORY')) {
    define('DOMAIN_POINTED_DIRECTORY', env('DOMAIN_POINTED_DIRECTORY', 'public'));
}

return Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->use([
            TrustProxies::class,
            CheckForMaintenanceMode::class,
            ValidatePostSize::class,
            TrimStrings::class,
            ConvertEmptyStringsToNull::class,
            DatabaseRefreshMiddleware::class,
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
        $middleware->group('web', [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\Localization::class,
            \App\Http\Middleware\DetectMobile::class,
        ]);
        $middleware->group('api', [
            'throttle:3000,1',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
        /*
        |--------------------------------------------------------------------------
        | Route Middleware (Aliases)
        |--------------------------------------------------------------------------
        */
        $middleware->alias([
            'auth' => \App\Http\Middleware\Authenticate::class,
            'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
            'bindings' => SubstituteBindings::class,
            'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
            'can' => \Illuminate\Auth\Middleware\Authorize::class,
            'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
            'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
            'signed' => \Illuminate\Routing\Middleware\ValidateSignature::class,
            'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
            'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
            'admin' => AdminMiddleware::class,
            'seller' => SellerMiddleware::class,
            'customer' => CustomerMiddleware::class,
            'module' => ModulePermissionMiddleware::class,
            'installation-check' => InstallationMiddleware::class,
            'actch' => ActivationCheckMiddleware::class,
            'api_lang' => APILocalizationMiddleware::class,
            'maintenance_mode' => MaintenanceModeMiddleware::class,
            'delivery_man_auth' => DeliveryManAuth::class,
            'seller_api_auth' => SellerApiAuthMiddleware::class,
            'guestCheck' => GuestMiddleware::class,
            'apiGuestCheck' => APIGuestMiddleware::class,
            'logUserBrowsingNavigation' => \App\Http\Middleware\LogUserBrowsingNavigationMiddleware::class,
            'detectMobile' => \App\Http\Middleware\DetectMobile::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // You can customize exception handling here if needed
    })
    ->create();
