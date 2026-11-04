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
            // Global, not group-scoped: a request that matches no route never enters the web or api
            // group, so every 404 — a broken link, a scanner sweeping the site — was invisible to
            // monitoring, and the __unmatched__ series the recorder documents could not be reached.
            // Out here it also measures the whole pipeline rather than the part inside the group.
            // It binds a context on first pass and skips on the second, so the group entries below
            // cost a container lookup and record nothing twice.
            \App\Http\Middleware\MonitorRequest::class,
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
            \App\Http\Middleware\ApplySeoRedirects::class,
            // A previewed draft is a real storefront response with an unpublished page in it. This
            // keeps that page out of search results and shared caches for as long as the token
            // that summoned it is alive.
            \App\Http\Middleware\NoIndexThemePreview::class,
            \App\Http\Middleware\RecordHttpTelemetry::class,
            // Operational monitoring. Separate from RecordHttpTelemetry on purpose: that one keeps
            // a row per request for visits and sources, this one only ever increments counters in
            // the current minute and never writes a row on the request path.
            \App\Http\Middleware\MonitorRequest::class,
            // Behavioural analytics. The request log above knows how much traffic there was and
            // how fast it was served; this knows what a person did, on which visit, and whether it
            // led to a sale. Both writes happen after the response has been sent.
            \App\Http\Middleware\RecordAnalytics::class,
        ]);
        $middleware->group('api', [
            'throttle:3000,1',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\RecordHttpTelemetry::class,
            \App\Http\Middleware\MonitorRequest::class,
            \App\Http\Middleware\RecordAnalytics::class,
            // What each endpoint answers with, learned from what it answers with. The API returns
            // JSON directly rather than through Resource classes, so there is no type to reflect —
            // this is the only way the portal can describe a response without somebody typing it.
            \App\Http\Middleware\RecordApiResponseShape::class,
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
            'seller_staff_access' => \App\Http\Middleware\SellerStaffAccessMiddleware::class,
            // Puts the web session's seller principal on the request, so the API's permission gate
            // and audit actor serve the panel too rather than a second authorization system.
            'seller_center' => \App\Http\Middleware\SellerCenterContext::class,
            'customer' => CustomerMiddleware::class,
            'module' => ModulePermissionMiddleware::class,
            'installation-check' => InstallationMiddleware::class,
            'actch' => ActivationCheckMiddleware::class,
            'api_lang' => APILocalizationMiddleware::class,
            'maintenance_mode' => MaintenanceModeMiddleware::class,
            'delivery_man_auth' => DeliveryManAuth::class,
            'seller_api_auth' => SellerApiAuthMiddleware::class,
            // Enforced on the route, never by hiding a menu item.
            'seller_can' => \App\Http\Middleware\EnsureSellerPermission::class,
            'seller_owner' => \App\Http\Middleware\EnsureSellerIsOwner::class,
            'deliverysyria_auth' => \App\Http\Middleware\DeliverySyriaWebhookAuthMiddleware::class,
            'guestCheck' => GuestMiddleware::class,
            'apiGuestCheck' => APIGuestMiddleware::class,
            'logUserBrowsingNavigation' => \App\Http\Middleware\LogUserBrowsingNavigationMiddleware::class,
            'detectMobile' => \App\Http\Middleware\DetectMobile::class,
        ]);
    })
    // The schedule itself lives in App\Console\ScheduleDefinition, so the monitoring page can
    // read what is defined without starting a console process. Laravel invokes this from
    // `Artisan::starting`, which a web request never reaches.
    ->withSchedule(fn (\Illuminate\Console\Scheduling\Schedule $schedule) => \App\Console\ScheduleDefinition::define($schedule))
    ->withExceptions(function (Exceptions $exceptions) {
        // You can customize exception handling here if needed
    })
    ->create();
