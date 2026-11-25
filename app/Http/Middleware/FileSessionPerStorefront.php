<?php

namespace App\Http\Middleware;

use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Files the visitor's market-dependent session state under the storefront this request serves (A1-12).
 *
 * Runs after `storefront`, which is what binds the context, and only where there IS a session: the website. It writes nothing while
 * storefronts are switched off, which is why `ResolveStorefront` can go on promising that resolving a storefront writes no session
 * key — the filing is this middleware's doing, on the routes that carry a session, and never the resolver's.
 *
 * A request without a session (a console-driven test, a route outside the `web` group) is left alone rather than given one.
 */
class FileSessionPerStorefront
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession()) {
            app(StorefrontSession::class)->serve(country: StorefrontContext::current()->country);
        }

        return $next($request);
    }
}
