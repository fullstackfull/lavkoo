<?php

namespace App\Http\Middleware;

use App\Services\Seo\StorefrontSeo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a storefront that is not live out of search results (B-17).
 *
 * A market is reachable while it is being prepared: its catalogue is loaded, its legal texts are being written and its currency may not
 * even exist yet. Whoever is preparing it has to be able to open it, and a crawler that finds it in the meantime would record half-built
 * pages, and prices in a currency the market does not yet trade in, as what the shop is.
 *
 * The header rather than a meta tag, for two reasons: it covers every page of the storefront and not only the ones whose template
 * carries SEO tags, and the theme already writes its own robots meta, so a second one would be a contradiction rather than an
 * instruction. {@see StorefrontSeo::isIndexable()} decides; while storefronts are switched off it answers yes to everything and this
 * adds no header at all.
 */
class NoIndexUnlaunchedStorefront
{
    public function __construct(private readonly StorefrontSeo $seo)
    {
    }

    /**
     * The storefront is read on the way out, after $next, and that is not incidental: this is group middleware and the middleware that
     * binds the request's storefront is on the route, so on the way in there is no storefront to read yet. Moving the check above
     * $next would silently read the default storefront for every request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!$this->seo->isIndexable()) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
