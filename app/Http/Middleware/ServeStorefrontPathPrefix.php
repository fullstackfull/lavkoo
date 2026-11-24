<?php

namespace App\Http\Middleware;

use App\Services\Storefront\StorefrontPathPrefix;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves `/kw/shop-cart` as the Kuwaiti storefront's shop cart, with one route table (A1-05).
 *
 * It runs before routing, which is the only moment the path can still be changed: the market's segment is taken off, the market is written
 * on the request for {@see \App\Services\Storefront\StorefrontResolver::contextForRequest} to read, and the router then matches the rest
 * against the routes it already has. So a market's pages are the platform's pages -- the same controllers, the same route names, the same
 * middleware -- rather than a second copy of them, and a page that works at the root cannot be broken under a prefix by anything but itself.
 *
 * It then gives the market back to every path the application generates, so a link on a Kuwaiti page is a Kuwaiti link. The exceptions are
 * in {@see StorefrontPathPrefix}: the panel, the API and the dev paths keep the addresses they have.
 *
 * While the switch is off, or storefronts are off, it does nothing at all: no path changes, no URL changes and nothing is bound.
 */
class ServeStorefrontPathPrefix
{
    public function __construct(private readonly StorefrontPathPrefix $prefix)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $country = $this->prefix->countryOf($request->path());

        if ($country === null) {
            return $next($request);
        }

        return $this->generatingPathsWithin($country, fn (): Response => $next($this->withoutThePrefix(request: $request, country: $country)));
    }

    /**
     * Runs the rest of the request with every generated path addressed inside this market, and puts back the formatter that was there
     * before -- after a failure as well.
     *
     * The formatter lives on the URL generator, which is a singleton: one web request per process never notices, but a test, a worker and
     * a long-running server all serve one request after another in one process, and a formatter left behind would put one market's segment
     * in front of the next request's links. The same reason `StorefrontContext::serve` puts back what it found.
     *
     * @param Closure(): Response $serve
     */
    private function generatingPathsWithin(string $country, Closure $serve): Response
    {
        $urls = URL::getFacadeRoot();
        $previous = $urls->pathFormatter();

        $urls->formatPathUsing(fn (string $path): string => $this->prefix->pathWithin(path: $path, country: $country));

        try {
            return $serve();
        } finally {
            $urls->formatPathUsing($previous);
        }
    }

    /**
     * The same request addressed at the path behind the prefix.
     *
     * The request is rebuilt rather than edited because Symfony works out the path once and keeps it: a request whose `REQUEST_URI` is
     * changed underneath goes on reporting the path it was built with, and the router would match the prefix again. `createFrom` re-reads
     * the server values and carries the attributes, the session and the user over, and the rebuilt request is bound to the container as
     * well as passed on, so anything that asks the container for the request sees the path the route was matched on.
     */
    private function withoutThePrefix(Request $request, string $country): Request
    {
        $path = $this->prefix->withoutPrefix(path: $request->path(), country: $country);
        $query = (string) $request->server->get('QUERY_STRING');

        $request->server->set('REQUEST_URI', $path . ($query === '' ? '' : '?' . $query));

        $served = Request::createFrom($request);
        $served->attributes->set(StorefrontPathPrefix::SERVED_COUNTRY, $country);

        app()->instance('request', $served);

        return $served;
    }
}
