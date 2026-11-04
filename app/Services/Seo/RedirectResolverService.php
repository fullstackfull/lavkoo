<?php

namespace App\Services\Seo;

/**
 * Resolves an incoming request path against a set of redirect rules.
 *
 * Deliberately DB-free: it takes an iterable of plain rules (arrays or objects) and returns a
 * decision, so the matching logic is fully unit-testable without booting the framework. The
 * middleware/repository layer is responsible for loading (and caching) the rules.
 */
class RedirectResolverService
{
    /**
     * Normalize a path for comparison: single leading slash, no trailing slash (except root),
     * query string and fragment removed.
     */
    public function normalizePath(string $path): string
    {
        $path = trim($path);
        $path = explode('?', $path, 2)[0];
        $path = explode('#', $path, 2)[0];

        if ($path === '') {
            return '/';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        if (strlen($path) > 1) {
            $trimmed = rtrim($path, '/');
            $path = $trimmed === '' ? '/' : $trimmed;
        }
        return $path;
    }

    /**
     * Resolve a redirect for $path against $rules.
     *
     * Each rule exposes: from_path, to_path, status_code, match_type ('exact'|'prefix'), is_active.
     * Exact matches win over prefix matches; among prefix matches the longest (most specific)
     * source wins. Self-referential rules are skipped to avoid redirect loops.
     *
     * @return array{to: string, status: int}|null
     */
    public function resolve(string $path, iterable $rules): ?array
    {
        $normalized = $this->normalizePath($path);

        $exact = null;
        $prefixMatch = null;
        $prefixLen = -1;

        foreach ($rules as $rule) {
            $rule = (array) $rule;

            if (array_key_exists('is_active', $rule) && !$rule['is_active']) {
                continue;
            }

            $from = $this->normalizePath((string) ($rule['from_path'] ?? ''));
            $to   = trim((string) ($rule['to_path'] ?? ''));
            if ($from === '/' && ($rule['from_path'] ?? '') === '') {
                continue; // empty source
            }
            if ($to === '') {
                continue;
            }
            if ($this->isSelfReferential($from, $to)) {
                continue; // loop guard
            }

            $status = (int) ($rule['status_code'] ?? 301);
            if (!in_array($status, [301, 302], true)) {
                $status = 301;
            }
            $type = (string) ($rule['match_type'] ?? 'exact');

            if ($type === 'prefix') {
                $isMatch = $normalized === $from
                    || str_starts_with($normalized . '/', $from . '/'); // respect segment boundary
                if ($isMatch && strlen($from) > $prefixLen) {
                    $prefixLen = strlen($from);
                    $prefixMatch = ['to' => $to, 'status' => $status];
                }
            } elseif ($normalized === $from) {
                $exact = ['to' => $to, 'status' => $status];
            }
        }

        return $exact ?? $prefixMatch;
    }

    private function isSelfReferential(string $from, string $to): bool
    {
        if (preg_match('#^https?://#i', $to)) {
            return false; // absolute URL target, not comparable to a local path
        }
        return $this->normalizePath($to) === $from;
    }
}
