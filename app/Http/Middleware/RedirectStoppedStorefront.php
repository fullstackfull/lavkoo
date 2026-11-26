<?php

namespace App\Http\Middleware;

use App\Services\Storefront\StorefrontChoice;
use App\Services\Storefront\StorefrontStopRedirect;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends the visitors of a market an operator paused in mode redirect to the same page on the default storefront (I2-15).
 *
 * The rules -- which addresses are moved, which are never moved, and why a remembered market is forgotten rather than redirected -- are in
 * {@see StorefrontStopRedirect}. This is the web half: it is group middleware, so it runs after the route was matched and before the page is
 * built, and a market that is selling pays one container lookup for it.
 *
 * It sits in front of {@see ApplySeoRedirects} deliberately. An operator's redirect rule maps one path of a market to another path of that
 * market, and a market that is sending its visitors away has no page for either end of such a rule: the visitor is taken to the default
 * storefront's address, where that storefront's own rules apply on the next request.
 *
 * Nothing happens while storefronts are off, and nothing happens until an operator pauses a market in this mode.
 */
class RedirectStoppedStorefront
{
    public function __construct(
        private readonly StorefrontStopRedirect $redirect,
        private readonly StorefrontChoice $choice,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->redirect->marketBeingLeft($request) === null) {
            return $next($request);
        }

        // Worked out BEFORE the choice is dropped, and the order is load-bearing: dropping it first would change the answer
        // `StorefrontStopRedirect` gives, so the rule that a remembered market is not redirected would be enforced by this line's
        // side effect rather than by the rule itself -- and a reader could remove the rule without any test noticing.
        $target = $this->redirect->targetFor($request);

        // A market kept in the session is not an address, so there is nothing to redirect to: it is dropped, and the resolver -- which runs
        // on the route, after this -- then serves the default storefront. Dropped for a request that IS redirected as well, so the visitor
        // does not arrive at the default storefront still carrying the market they were just taken out of.
        $this->choice->forget();

        return $target === null ? $next($request) : new RedirectResponse($this->addressOutsideTheMarket($target), $this->redirect->status());
    }

    /**
     * The target as a full address, built with the market's path formatter suspended.
     *
     * {@see ServeStorefrontPathPrefix} hangs a formatter on the URL generator that gives every path it builds the market back, so that a
     * link on a Kuwaiti page stays Kuwaiti. That is right for a link and wrong for exactly one address: this one, whose whole purpose is to
     * leave the market. Without the suspension `url('/contacts')` answers `/kw/contacts` and the browser is sent to the address it is
     * already on, for ever. The formatter is put back afterwards, failure included, for the reason that middleware states: the generator is
     * a singleton and a formatter left off would strip the market from the rest of this request's links.
     */
    private function addressOutsideTheMarket(string $target): string
    {
        $urls = URL::getFacadeRoot();
        $previous = $urls->pathFormatter();

        $urls->formatPathUsing(static fn (string $path): string => $path);

        try {
            return $urls->to($target);
        } finally {
            $urls->formatPathUsing($previous);
        }
    }
}
