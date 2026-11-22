<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While storefronts are on, an API v1 route that reads the catalog answers per storefront even where the storefront middleware did not
 * bind it, because the request's own choice decides. A shared cache has to key on the header, so every answer that reaches the route says so.
 * An answer that middleware ahead of this one produced (an unauthenticated 401, a throttled 429) is not cacheable and says nothing.
 * It reads the flag and nothing else, and while storefronts are off it adds nothing.
 */
class VaryByStorefront
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (config('gcc.storefronts.enabled')) {
            ResolveStorefront::varyByHeader(response: $response);
        }

        return $response;
    }
}
