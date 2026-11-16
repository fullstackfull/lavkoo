<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While storefronts are on, an API v1 route that reads the catalog answers per storefront even where the storefront middleware did not
 * bind it, because the request's own choice decides. A shared cache has to key on the header, so every v1 answer says so. It reads the flag
 * and nothing else, and while storefronts are off it adds nothing.
 */
class VaryByStorefront
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (config('gcc.storefronts.enabled')) {
            $response->setVary(ResolveStorefront::HEADER, false);
        }

        return $response;
    }
}
