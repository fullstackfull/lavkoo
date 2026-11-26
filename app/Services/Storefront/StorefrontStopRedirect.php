<?php

namespace App\Services\Storefront;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\Request;

/**
 * Stop mode "redirect": where a visitor of a stopped market is sent instead, and the addresses that are never sent anywhere (I2-15).
 *
 * The mode has been stored, audited and read by the apps since the lifecycle was built; what it did to a web request was nothing, because
 * the website resolved no market from an address. It does now (A1-05, {@see StorefrontPathPrefix}), so the mode can finally mean what it
 * says: a GET of a page of a market an operator paused in this mode is answered with a redirect to the same page on the default storefront.
 *
 * Three rules, each of them load-bearing:
 *
 *  - **Only an address that NAMES the market is redirected** -- a market path (`/kw/contacts`) or an explicit `?storefront=KW`. A market
 *    that is only REMEMBERED (the visitor picked it on an earlier page, A1-06) names no address to leave, so redirecting would answer the
 *    address the visitor is already on and ask the browser for it again, for ever. That case is answered by forgetting the choice, which is
 *    what being sent to the default storefront means for a visitor whose market is in a session rather than in a URL.
 *  - **Only a GET or a HEAD.** A POST is a submission, and 302 turns a POST into a GET: the visitor would lose what they typed and the
 *    default storefront would be handed a request it never saw the form of. The criterion names POST for exactly this reason.
 *  - **Never a payment, a callback, a webhook, the panel, the seller centre, the API or the installer.** A gateway's return address is not a
 *    page a person is browsing; moving it loses the payment that was taken in the market being stopped, which is the one thing I2-16 says a
 *    stop must never do. The list is enforced against the real route table by `StorefrontStopRedirectTest`, so a gateway added later is
 *    refused by a failing test rather than by somebody remembering this paragraph.
 *
 * The default storefront is never redirected, and cannot be put in this mode in the first place ({@see StorefrontLifecycle}): a redirect of
 * the storefront everybody falls back to would strand its own visitors.
 *
 * Dormant while storefronts are off, and while no market is paused in this mode -- which is every installation until an operator chooses it.
 */
#[Singleton]
final class StorefrontStopRedirect
{
    /**
     * The first path segments this redirect never touches, beyond the ones that are not visitor pages at all.
     *
     * `login` carries the social sign-in callback (`login/{service}/callback`), whose address a provider holds and sends a person back to.
     * `update` is the upgrader, which {@see \App\Http\Middleware\ApplySeoRedirects} also excludes and which is no storefront page either.
     */
    private const NEVER_REDIRECTED = ['login', 'update'];

    /**
     * The words that mark an address as part of taking money or as a machine's rather than a person's, wherever in the path they appear.
     *
     * `payment` is read as the criterion words it -- no payment request is redirected -- rather than narrowed to the gateway group. The
     * website has five further addresses that name a payment and are not a gateway's (the checkout's payment step, the offline-payment
     * completion, the account's payments, setting a payment method and asking for one), and a redirect is the wrong instrument for every
     * one of them: a market that is stopped already refuses the sale itself, and the fail-safe answer for an address that may be mid-payment
     * is to serve it where it is rather than to move it somewhere its payment does not exist.
     */
    private const MACHINE_WORDS = ['payment', 'callback', 'webhook', 'ipn', 'notify'];

    public function __construct(
        private readonly StorefrontLifecycle $lifecycle,
        private readonly StorefrontPathPrefix $prefix,
    ) {
    }

    /**
     * The market this request would be served in, when that market is paused in mode redirect: the market the visitor is being taken out
     * of. Null when there is none, when it is the default storefront, or while storefronts are off.
     */
    public function marketBeingLeft(Request $request): ?string
    {
        $resolver = StorefrontResolver::fromConfig();

        if (!$resolver->isEnabled()) {
            return null;
        }

        $country = $resolver->contextForRequest($request)->country;

        return $country !== $resolver->defaultCountry() && $this->lifecycle->stopModeOf($country) === StorefrontLifecycle::REDIRECT
            ? $country
            : null;
    }

    /**
     * The address this request belongs at instead, or null when it is answered where it is.
     *
     * The path is the one the router matched, which is already the path behind the market's segment -- the segment was taken off before
     * routing -- so the default storefront's equivalent page is that bare path, which is the address Saudi Arabia answers at today
     * ({@see StorefrontUrl::of}). The `storefront` query is dropped because it names the market being left: kept, it would choose that
     * market again at the new address.
     */
    public function targetFor(Request $request): ?string
    {
        if ($this->marketBeingLeft($request) === null || !$request->isMethodCacheable() || !$this->addressNamesTheMarket($request)) {
            return null;
        }

        $path = $this->prefix->withoutAnyPrefix($request->path());

        if ($this->isExcluded($path)) {
            return null;
        }

        $query = $request->query();
        unset($query['storefront']);

        return $path . ($query === [] ? '' : '?' . http_build_query($query));
    }

    /** Whether this path is one the redirect never touches, whatever market is stopped. */
    public function isExcluded(string $path): bool
    {
        $segments = array_values(array_filter(explode('/', strtolower(trim($this->prefix->withoutAnyPrefix($path), '/'))), static fn (string $segment): bool => $segment !== ''));

        if ($segments === []) {
            return false;
        }

        if (in_array($segments[0], self::NEVER_REDIRECTED, true) || in_array($segments[0], StorefrontPathPrefix::notVisitorPages(), true)) {
            return true;
        }

        foreach ($segments as $segment) {
            foreach (self::MACHINE_WORDS as $word) {
                if (str_contains($segment, $word)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The answer the redirect is made with. 302 by default and on purpose: whether a stop is announced to search engines as permanent is
     * the owner's to decide (I2-15's own open question), and 302 is the answer that can be taken back -- a 301 is remembered by browsers
     * and by crawlers after the market is selling again. Anything but 301 or 302 is read as the default rather than sent.
     */
    public function status(): int
    {
        $status = (int) config('gcc.storefronts.stop.redirect_status', 302);

        return in_array($status, [301, 302], true) ? $status : 302;
    }

    /** Whether the ADDRESS of this request names the market: its path prefix, or an explicit `?storefront=`. A remembered choice does not. */
    private function addressNamesTheMarket(Request $request): bool
    {
        return is_string($request->attributes->get(StorefrontPathPrefix::SERVED_COUNTRY)) || is_string($request->query('storefront'));
    }
}
