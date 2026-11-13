<?php

namespace App\Http\Middleware;

use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the request's StorefrontContext. It only reads the request: no session key, cookie or currency_code is written, so
 * the existing currency and locale behaviour is left alone.
 */
class ResolveStorefront
{
    public function handle(Request $request, Closure $next): Response
    {
        $explicitChoice = $request->query('storefront');

        $context = StorefrontResolver::fromConfig()->context(
            explicitChoice: is_string($explicitChoice) ? $explicitChoice : null,
            path: $request->path(),
        );

        app()->instance(StorefrontContext::class, $context);

        return $next($request);
    }
}
