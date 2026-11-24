<?php

namespace App\Services\Storefront;

use Illuminate\Container\Attributes\Singleton;

/**
 * The rules that let one website answer six markets under `/sa`, `/ae`, `/kw`, `/qa`, `/bh` and `/om` (A1-05).
 *
 * The web routes are mounted at the root and nothing is mounted under a country, so `/kw/shop-cart` answered 404 and a market could only be
 * reached by a header or a `?storefront=` query -- neither of which a visitor can type, share or bookmark. Rather than mount every route a
 * second time under six prefixes, the prefix is taken off the request before it is routed: one route table, one set of route names, and the
 * market taken from the segment that was removed. A page therefore behaves under `/kw` exactly as it behaves at the root, because it IS the
 * same route.
 *
 * What a link then has to do is keep the market the visitor is in, which is the other half: while a request is served under a prefix, the
 * paths the application generates are given it back ({@see pathWithin}), so every link on a Kuwaiti page stays Kuwaiti.
 *
 * Two rules keep the blast radius small, and both are deliberate:
 *
 *  - **The default storefront keeps the bare addresses it has today.** `/sa/...` is served, because the plan lists it, but nothing generates
 *    it: a Saudi visitor's links are the ones they have always been, as everywhere else in the storefront code
 *    ({@see StorefrontContext::scopedCacheKey}, {@see StorefrontUrl::of}).
 *  - **The panel and the machine paths are never given a market.** An admin screen, a seller screen, the API and the dev tools are not
 *    visitor pages; a market in their address would mean nothing and would carry one market's context into a screen that serves all of
 *    them. They are reached, from a prefixed page, at the addresses they already have.
 *
 * Off by default, and dormant while storefronts are off: nothing is stripped, nothing is added, and every address is the one of today.
 */
#[Singleton]
final class StorefrontPathPrefix
{
    /** The request attribute that carries the market a prefix named, from this middleware to the resolver. */
    public const SERVED_COUNTRY = 'storefront.served_country';

    /**
     * The first path segments that are not visitor pages, so a market is never put in front of them.
     *
     * `api` is here although some API routes do serve a storefront: an API client names its market in the `X-Storefront` header, which is
     * the contract the apps already use, and a path a client builds itself must not change under it.
     */
    private const NOT_A_VISITOR_PAGE = ['admin', 'vendor', 'api', 'install', 'sanctum', 'oauth', '_debugbar', '_ignition'];

    public function __construct(private readonly StorefrontUrl $urls)
    {
    }

    public function isEnabled(): bool
    {
        return (bool) config('gcc.storefronts.paths.enabled') && StorefrontResolver::fromConfig()->isEnabled();
    }

    /** The market whose prefix this path begins with, or null while the switch is off and for a path that names no reachable market. */
    public function countryOf(string $path): ?string
    {
        return $this->isEnabled() ? StorefrontResolver::fromConfig()->countryOfPathPrefix($path) : null;
    }

    /** The path with the market's segment taken off, which is the path the router is given: `kw/shop-cart` becomes `/shop-cart`. */
    public function withoutPrefix(string $path, string $country): string
    {
        $prefix = $this->urls->pathOf(country: $country);
        $rest = ltrim(substr(ltrim($path, '/'), strlen($prefix)), '/');

        return '/' . $rest;
    }

    /**
     * The path with whatever market's segment it begins with taken off, so that a path of one market can be addressed in another: the
     * visitor switching from `/kw/contacts` to Emirates has to be sent to `/ae/contacts`, not to `/ae/kw/contacts`.
     */
    public function withoutAnyPrefix(string $path): string
    {
        $country = $this->countryOf($path);

        return $country === null ? '/' . ltrim($path, '/') : $this->withoutPrefix(path: $path, country: $country);
    }

    /**
     * The path as it is addressed inside one market, which is what the application generates while a request is served there. A path that
     * already carries the market, one that is not a visitor page, and anything that is not a path of this installation are returned as they
     * are.
     */
    public function pathWithin(string $path, string $country): string
    {
        $prefix = $this->urls->pathOf(country: $country);
        $normalised = '/' . ltrim($path, '/');

        if ($this->alreadyWithin($normalised, $prefix) || $this->isNotAVisitorPage($normalised)) {
            return $path;
        }

        return '/' . $prefix . ($normalised === '/' ? '' : $normalised);
    }

    private function alreadyWithin(string $path, string $prefix): bool
    {
        return $path === '/' . $prefix
            || str_starts_with($path, '/' . $prefix . '/')
            || str_starts_with($path, '/' . $prefix . '?');
    }

    private function isNotAVisitorPage(string $path): bool
    {
        $firstSegment = strtolower((string) strtok(trim($path, '/'), '/'));

        return in_array($firstSegment, self::NOT_A_VISITOR_PAGE, true);
    }

    /** @return list<string> the segments {@see pathWithin} never puts a market in front of, for whoever has to check that list against the route table */
    public static function notVisitorPages(): array
    {
        return self::NOT_A_VISITOR_PAGE;
    }
}
