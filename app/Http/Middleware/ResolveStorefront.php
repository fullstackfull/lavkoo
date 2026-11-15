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
    public const HEADER = 'X-Storefront';

    public function handle(Request $request, Closure $next): Response
    {
        $explicitChoice = $request->query('storefront');
        $headerValues = $request->headers->all(self::HEADER);
        $resolver = StorefrontResolver::fromConfig();

        $context = $resolver->context(
            explicitChoice: is_string($explicitChoice) ? $explicitChoice : null,
            path: $request->path(),
            headerChoice: count($headerValues) === 1 && is_string($headerValues[0]) ? $headerValues[0] : null,
        );

        app()->instance(StorefrontContext::class, $context);

        $response = $next($request);

        // While storefronts are on, one URL can answer differently per storefront, so a shared cache must key on the header.
        if ($resolver->isEnabled()) {
            $response->setVary(self::HEADER, false);
        }

        return $response;
    }
}
