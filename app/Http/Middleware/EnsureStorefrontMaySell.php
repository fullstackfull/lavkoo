<?php

namespace App\Http\Middleware;

use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontSellingGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a request that would start a new order or payment in a storefront that may not sell yet (GCC-11). It runs after the storefront
 * middleware, so it judges the storefront the request was bound to. Refunds, cancellations, callbacks, status checks and payments on
 * orders already placed do not carry it.
 */
class EnsureStorefrontMaySell
{
    public function handle(Request $request, Closure $next): Response
    {
        app(StorefrontSellingGate::class)->assertMaySell(StorefrontContext::current());

        return $next($request);
    }
}
