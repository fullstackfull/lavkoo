<?php

namespace App\Http\Middleware;

use App\Services\Storefront\StorefrontPaymentMethods;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a request that would place an order or start a payment by a way to pay its storefront has switched off (requirement D2-V2). The route names the way to pay,
 * and it runs after the storefront middleware, so it judges the storefront the request was bound to. Hiding the choice on a page is not the control: this is.
 */
class EnsureStorefrontOffersPaymentMethod
{
    public function handle(Request $request, Closure $next, string $method): Response
    {
        app(StorefrontPaymentMethods::class)->assertNotSwitchedOff(method: $method);

        return $next($request);
    }
}
