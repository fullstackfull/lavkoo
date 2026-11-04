<?php

namespace App\Http\Middleware;

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
 *
 * Data access is isolated in overridable protected methods so the redirect logic can be tested
 * without a database.
 */
class ApplySeoRedirects
{
    private const CACHE_TTL = 300; // seconds

    /** Path prefixes that must never be redirected by this middleware. */
    private const EXCLUDED_PREFIXES = ['/api', '/admin', '/vendor', '/install', '/update', '/payment'];

    public function __construct(private readonly RedirectResolverService $resolver)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $path = '/' . ltrim($request->path(), '/');

            if ($this->isExcludedPath($path)) {
                return $next($request);
            }

            $rules = $this->loadRules();
            if (empty($rules)) {
                return $next($request);
            }

            $match = $this->resolver->resolve($path, $rules);
            if ($match !== null) {
                $this->recordHit($path);
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

    /** @return array<int, array<string, mixed>> */
    protected function loadRules(): array
    {
        return Cache::remember(Redirect::ACTIVE_CACHE_KEY, self::CACHE_TTL, function () {
            if (!Schema::hasTable('redirects')) {
                return [];
            }
            return Redirect::query()
                ->where('is_active', true)
                ->get(['from_path', 'to_path', 'status_code', 'match_type', 'is_active'])
                ->toArray();
        });
    }

    protected function recordHit(string $path): void
    {
        try {
            Redirect::query()
                ->where('is_active', true)
                ->where('from_path', $path)
                ->limit(1)
                ->update(['hits' => DB::raw('hits + 1'), 'last_hit_at' => now()]);
        } catch (\Throwable $e) {
            // hit accounting is best-effort; never affects the redirect itself
        }
    }
}
