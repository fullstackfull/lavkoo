<?php

namespace App\Http\Middleware;

use App\Services\Theme\StorefrontThemeRenderer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a previewed draft out of search results and out of caches.
 *
 * A preview link is meant for one merchant and one phone, but it is still a URL: it gets pasted
 * into a chat, opened in a browser that syncs history, and — if anything ever links to it — offered
 * to a crawler. A layout the merchant decided against must not become the page a search engine
 * knows the shop by.
 *
 * The token expiring is what makes the exposure temporary; this is what keeps it from being
 * recorded in the meantime.
 */
class NoIndexThemePreview
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->query(StorefrontThemeRenderer::PREVIEW_TOKEN_KEY)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
