<?php

namespace App\Http\Middleware;

use App\Services\Storefront\StorefrontMarketRows;
use App\Services\Storefront\StorefrontPathPrefix;
use App\Services\Storefront\StorefrontResolver;
use App\Models\Redirect;
use App\Services\Seo\RedirectResolverService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies admin-managed 301/302 redirects to storefront requests (SEO Phase 1.6).
 *
 * Safety posture (this runs before the storefront responds):
 *  - Storefront only: panel and API/mobile prefixes are excluded, so it can never change an
 *    admin/vendor page or a Flutter API 404.
 *  - No-op until configured: with no active rules (or no table yet) it returns $next immediately.
 *  - Fail-open: any error is reported and the request continues untouched — a bad rule can never
 *    take a page down.
 *  - No loop is reachable: a rule, or a chain of rules, that comes back to the address being answered serves the page instead
 *    ({@see leadsBackHere()}). The fail-safe direction, because a page served is a page, and a loop is no page at all.
 *
 * The market whose rules apply is read from the REQUEST and not from the bound storefront, because nothing has bound one this early in the
 * pipeline: {@see marketBeingServed()} is the whole of it, and it is the one thing in this file worth reading before changing it.
 *
 * Data access is isolated in overridable protected methods so the redirect logic can be tested
 * without a database.
 */
class ApplySeoRedirects
{
    private const CACHE_TTL = 300; // seconds

    /** Path prefixes that must never be redirected by this middleware. */
    private const EXCLUDED_PREFIXES = ['/api', '/admin', '/vendor', '/install', '/update', '/payment'];

    /**
     * How far a chain of rules is followed before it is read as a loop rather than as a chain.
     *
     * A browser gives up at about twenty; a person writing redirects does not write ten in a row. Ten is therefore well inside what a
     * browser would follow and well outside what an operator means, so a chain that reaches it is answered as a loop -- by serving the
     * page, which is the fail-safe direction.
     */
    private const MAXIMUM_HOPS = 10;

    public function __construct(
        private readonly RedirectResolverService $resolver,
        private readonly StorefrontPathPrefix $prefix,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $path = '/' . ltrim($request->path(), '/');

            if ($this->isExcludedPath($path)) {
                return $next($request);
            }

            // The cached set first, and the market only if there is anything to narrow: a platform with no redirect rules -- which is most
            // of them -- reads no storefront configuration and resolves no market, so this middleware costs exactly what it cost before.
            $active = $this->activeRules();
            if (empty($active)) {
                return $next($request);
            }

            $market = $this->marketBeingServed($request);
            $rules = $this->rulesServedIn($active, $market);
            if (empty($rules)) {
                return $next($request);
            }

            $match = $this->resolver->resolve($path, $rules);
            if ($match !== null && !$this->leadsBackHere(path: $path, target: (string) $match['to'], rules: $rules)) {
                $this->recordHit($path, $market);
                return redirect()->to($match['to'], $match['status']);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $next($request);
    }

    protected function isExcludedPath(string $path): bool
    {
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * The active rules that apply to the storefront being served (A1-V2).
     *
     * A redirect maps one path of the shop to another, and with six markets the same path means a different page in each: `/offers` in
     * Kuwait is not `/offers` in Riyadh, and one rule applied to every market sends a shopper of another market to a page that is not theirs
     * -- with a 301, which a search engine and a browser both remember.
     *
     * ONE cache entry still holds every active rule, and the market is chosen from it per request. Keying the cache per market instead would
     * have been faster and wrong: the admin screens forget this one key when a rule is saved, so a per-market key would leave five markets
     * serving a rule an operator had just deleted. The rule set is small and read as a whole, so filtering it in PHP costs nothing worth
     * measuring.
     *
     * Which market a rule belongs to is `StorefrontMarketRows`' rule and is not restated here: a rule that names no market is the DEFAULT
     * storefront's, which is every rule written before this column existed, and while storefronts are off every rule applies as it always
     * has.
     *
     * The market is PASSED IN, and that is the whole of the A1-V2 defect this round found: see {@see marketBeingServed()}. Null means "the
     * market bound to this request", which is what a queue, a console command or a caller that has already bound a storefront means.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function loadRules(?string $market = null): array
    {
        return $this->rulesServedIn($this->activeRules(), $market);
    }

    /**
     * Every active rule of the whole platform, from the one cache entry.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function activeRules(): array
    {
        $rows = app(StorefrontMarketRows::class);

        return Cache::remember(Redirect::ACTIVE_CACHE_KEY, self::CACHE_TTL, function () use ($rows): array {
            if (!Schema::hasTable('redirects')) {
                return [];
            }

            $columns = ['from_path', 'to_path', 'status_code', 'match_type', 'is_active'];

            if ($rows->tableHasTheColumn('redirects')) {
                $columns[] = StorefrontMarketRows::COLUMN;
            }

            return Redirect::query()->where('is_active', true)->get($columns)->toArray();
        });
    }

    /**
     * The rules of that set which are served in the market given, or in the market bound to the request when none is.
     *
     * @param array<int, array<string, mixed>> $active
     *
     * @return array<int, array<string, mixed>>
     */
    private function rulesServedIn(array $active, ?string $market): array
    {
        $rows = app(StorefrontMarketRows::class);

        return array_values(array_filter(
            $active,
            static fn (array $rule): bool => $rows->rowIsServedIn('redirects', $rule[StorefrontMarketRows::COLUMN] ?? null, $market),
        ));
    }

    /**
     * The market this request is being served in -- read from the REQUEST, because at this point in the pipeline nothing has bound one yet.
     *
     * **This is the A1-V2 defect, and it made the whole per-market narrowing inert on the website.** This is `web` GROUP middleware and
     * {@see ResolveStorefront} -- the only thing that binds `StorefrontContext` -- is ROUTE middleware (`storefront`, applied to the web
     * route file in `RouteServiceProvider::mapBetaWebRoutes`), so it runs AFTER the whole group. `StorefrontContext::current()` answers the
     * DEFAULT storefront when nothing is bound, so every web request filtered the rules against Saudi Arabia: a Kuwaiti visitor was served
     * the Saudi shop's rules and never their own, in both directions, with a 301 that a browser and a crawler both remember.
     * `StorefrontSeoRedirectWebsiteTest` drives the real route and fails without this.
     *
     * {@see NoIndexUnlaunchedStorefront} answers the same trap by reading AFTER `$next`. A redirect cannot: by then the page has been built.
     * So the market is resolved from the request the way {@see RedirectStoppedStorefront} -- the middleware immediately above this one in
     * the group -- already resolves it, which is the same answer `ResolveStorefront` binds a few middlewares later.
     *
     * **Adding `storefront` to the `web` group instead would be a much larger change and the wrong one:** the admin and vendor panels are
     * mounted on the same group, so it would bind a market to a panel request and narrow screens that deliberately serve all six
     * ({@see \App\Services\Storefront\StorefrontCatalog}, whose country must stay null for a panel).
     *
     * **A bound context is deliberately NOT preferred, and that was measured.** `ResolveStorefront` binds its instance and never forgets
     * it, so in any process that serves more than one request -- a test, a queue worker, a long-running server -- the container still holds
     * the PREVIOUS request's market when this middleware runs. Reading it gave each request the market of the one before it: the first
     * version of this fix did prefer it, and the website test showed a Kuwaiti address being served the Saudi rule because the request
     * before it had been Saudi. The request is the only authority here, and it is the same answer `ResolveStorefront` binds a few
     * middlewares later -- which is also why {@see RedirectStoppedStorefront}, one middleware above this one, reads the request and not the
     * container.
     *
     * Null while storefronts are off, so a platform that is not using them does no extra work and reads no storefront configuration at all.
     */
    private function marketBeingServed(Request $request): ?string
    {
        if (!(bool) config('gcc.storefronts.enabled', false)) {
            return null;
        }

        return StorefrontResolver::fromConfig()->contextForRequest(request: $request)->country;
    }

    /**
     * Whether following this rule, and then the rules that would match where it lands, comes back to the address being answered.
     *
     * {@see RedirectResolverService} already refuses a rule whose target IS its source, and that is not enough once a market can be part of
     * an address (A1-05). Three loops this answers, each of them reachable with rules an operator can write today:
     *
     *  - **the market put back.** A rule of the Kuwaiti shop reading `/offers -> /kw/offers`. The router matched `/offers`, because the
     *    market's segment was taken off before routing, so the two paths are not equal as strings -- and `/kw/offers` is the address the
     *    visitor is already on.
     *  - **a rule pointing into a market that is stopped in mode redirect.** `/offers -> /kw/offers` in the Saudi shop, with Kuwait paused:
     *    {@see RedirectStoppedStorefront} answers `/kw/offers` by sending the visitor to `/offers`, and this rule sends them back.
     *  - **a cycle of two or more rules**, `/a -> /b` and `/b -> /a`, which no single-rule check can see.
     *
     * Every target is reduced to the path the ROUTER would match -- its market segment taken off -- which is both how the prefix is served
     * and exactly what a stop-mode redirect does to an address, so one reduction models both hops. An absolute URL is left alone, as the
     * resolver leaves it: it is not comparable to a path of this installation.
     *
     * The walk runs only when a rule has already matched, so an ordinary page costs nothing for it, and the rule set it walks is the one
     * already in hand. What it cannot see is a cycle whose hops alternate between two markets' OWN rule sets, because only the rules of the
     * market being served are in hand; a rule of another market is not something this request can read.
     *
     * @param array<int, array<string, mixed>> $rules
     */
    private function leadsBackHere(string $path, string $target, array $rules): bool
    {
        $seen = [$this->resolver->normalizePath($path) => true];
        $next = $target;

        for ($hop = 0; $hop < self::MAXIMUM_HOPS; $hop++) {
            if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', trim($next)) === 1) {
                return false;
            }

            $here = $this->resolver->normalizePath($this->prefix->withoutAnyPrefix($next));

            if (isset($seen[$here])) {
                return true;
            }

            $seen[$here] = true;
            $match = $this->resolver->resolve($here, $rules);

            if ($match === null) {
                return false;
            }

            $next = (string) $match['to'];
        }

        return true;
    }

    protected function recordHit(string $path, ?string $market = null): void
    {
        try {
            // Narrowed to the market being served as well: with six markets two rules can share a path, and the hit of one market would
            // otherwise be counted against another market's rule -- the one an operator reads to decide whether a rule is still used. The
            // market is the one `marketBeingServed()` read off the request, for the reason written there.
            app(StorefrontMarketRows::class)->narrow(
                Redirect::query()->where('is_active', true)->where('from_path', $path),
                'redirects',
                $market,
            )->limit(1)->update(['hits' => DB::raw('hits + 1'), 'last_hit_at' => now()]);
        } catch (\Throwable $e) {
            // hit accounting is best-effort; never affects the redirect itself
        }
    }
}
