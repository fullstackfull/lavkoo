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
    public const HEADER = StorefrontResolver::HEADER;

    public function handle(Request $request, Closure $next): Response
    {
        $resolver = StorefrontResolver::fromConfig();
        $context = $resolver->contextForRequest(request: $request);

        app()->instance(StorefrontContext::class, $context);

        $response = $next($request);

        // While storefronts are on, one URL can answer differently per storefront, so a shared cache must key on the header.
        if ($resolver->isEnabled()) {
            self::varyByHeader(response: $response);
        }

        return $response;
    }

    /** Says once, whichever middleware gets there first, that the answer depends on the storefront header. */
    public static function varyByHeader(Response $response): void
    {
        $listed = array_map(strtolower(...), array_map(trim(...), explode(',', implode(',', $response->headers->all('vary')))));

        if (!in_array(strtolower(self::HEADER), $listed, true) && !in_array('*', $listed, true)) {
            $response->setVary(self::HEADER, false);
        }
    }
}
