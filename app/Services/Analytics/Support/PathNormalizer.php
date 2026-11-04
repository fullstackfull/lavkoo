<?php

namespace App\Services\Analytics\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Turns a URL into something worth counting.
 *
 * /product/panadol-500mg-42a1 and /product/aspirin-100mg-9c3f are two rows in a "top pages" table
 * that should have one: the page is /product/{slug}, and the interesting question about a
 * particular product is answered by product analytics, not by a page list. Left unnormalised, a
 * catalogue of ten thousand products makes the top-pages report a list of ten thousand entries
 * with one hit each, and the dimension grows without bound forever.
 *
 * The route pattern is used where Laravel knows one, because that is the application's own
 * statement of what the page IS. Where it does not — a static path, a 404, a URL from a mobile
 * client — the segments are normalised by shape.
 */
class PathNormalizer
{
    /** Segments that are clearly an identifier rather than a name. */
    private const ID_PATTERN = '/^\d+$/';
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const HASH_PATTERN = '/^[0-9a-f]{16,}$/i';
    private const MIXED_PATTERN = '/^(?=.*\d)[a-z0-9-]{12,}$/i';

    /** Paths that never belong in a visitor report: the panel, the tooling, the collector itself. */
    private const IGNORED_PREFIXES = [
        // 'login' is the ADMIN and vendor panel's sign-in page: it is registered outside the /admin
        // prefix (routes/admin/routes.php), so without naming it here every staff login was
        // recorded as a customer pageview and appeared in the shop's top-pages table.
        'admin', 'vendor', 'deliveryman', 'api', 'analytics/collect', 'login',
        '_debugbar', 'telemetry', 'livewire', 'broadcasting', 'sanctum', 'storage',
    ];

    public function fromRequest(Request $request): string
    {
        $route = $request->route();
        $pattern = $route?->uri();

        if (is_string($pattern) && $pattern !== '') {
            return $this->tidy($pattern);
        }

        return $this->normalise($request->path());
    }

    /** A raw path string, normalised by shape — for client-reported and historical paths. */
    public function normalise(string $path): string
    {
        $path = trim(parse_url($path, PHP_URL_PATH) ?? $path, '/');

        if ($path === '') {
            return '/';
        }

        $segments = array_map(function (string $segment) {
            return match (true) {
                preg_match(self::ID_PATTERN, $segment) === 1 => '{id}',
                preg_match(self::UUID_PATTERN, $segment) === 1 => '{uuid}',
                preg_match(self::HASH_PATTERN, $segment) === 1 => '{hash}',
                // A long token with digits in it is a slug with an id welded on, which is the
                // shape this project's product and shop URLs actually take.
                preg_match(self::MIXED_PATTERN, $segment) === 1 => '{slug}',
                default => Str::limit(strtolower($segment), 40, ''),
            };
        }, explode('/', $path));

        return $this->tidy(implode('/', array_slice($segments, 0, 6)));
    }

    /** True when this path is the shop's own machinery rather than a page a customer visited. */
    public function isIgnored(string $path): bool
    {
        $path = trim($path, '/');

        foreach (self::IGNORED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private function tidy(string $path): string
    {
        $path = '/' . trim($path, '/');

        return Str::limit($path === '//' ? '/' : $path, 191, '');
    }
}
