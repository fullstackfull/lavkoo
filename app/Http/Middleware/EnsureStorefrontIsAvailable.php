<?php

namespace App\Http\Middleware;

use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontLifecycle;
use App\Services\Storefront\StorefrontResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a request that browses a storefront that a person has put in maintenance (I2-14), with the answer an app reads (503, the message and the end of the stop). It runs after the
 * storefront middleware, so it judges the storefront the request was bound to, and it is adopted only by the routes that browse a storefront: the catalogue, the cart and the theme, which
 * are the API's. The website resolves no storefront from a request yet, so it has no page to show; one is added when it does. The routes that serve what a customer already has (the orders, their
 * tracking, refund requests, payment callbacks, webhooks) and the admin panel do not carry it, so an earlier order is always served (I2-16). One storefront in maintenance does not
 * touch another, and while storefronts are off nothing is refused.
 */
class EnsureStorefrontIsAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!StorefrontResolver::fromConfig()->isEnabled()) {
            return $next($request);
        }

        $lifecycle = app(StorefrontLifecycle::class);
        $country = StorefrontContext::current()->country;

        if ($lifecycle->stopModeOf($country) !== StorefrontLifecycle::MAINTENANCE) {
            return $next($request);
        }

        $status = $lifecycle->statusOf($country);

        return response()->json([
            'code' => 'storefront_maintenance',
            'message' => $status['message'] ?? translate('storefront_is_in_maintenance'),
            'until' => $status['until'],
        ], 503);
    }
}
