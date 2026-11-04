<?php

namespace App\Services\Seo;

use App\Models\Redirect;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * When an entity's slug changes, the old public URL 404s and its accumulated SEO value is lost.
 * This creates the 301 that preserves it.
 *
 * Deliberately explicit (called from the places that change a slug) rather than a model observer:
 * the 6Valley product/category writers are large and partly duplicated, and a silent global hook
 * would fire in imports, seeders and bulk updates too. Callers opt in.
 */
class SlugRedirectSuggester
{
    /** URL path patterns per entity type. {slug} is substituted. */
    private const PATTERNS = [
        'product'  => '/product/{slug}',
        'category' => '/products?category_id={slug}',
        'brand'    => '/brand/{slug}',
        'vendor'   => '/vendor/{slug}',
        'blog'     => '/blog/{slug}',
    ];

    /**
     * Create a 301 from the old slug's path to the new one.
     *
     * No-ops (returns null) when: the slug did not really change, the entity type has no known URL
     * pattern, the table is missing, or a rule for that source already exists — so it is safe to
     * call unconditionally after any save.
     */
    public function suggest(string $entityType, ?string $oldSlug, ?string $newSlug): ?Redirect
    {
        $oldSlug = trim((string) $oldSlug);
        $newSlug = trim((string) $newSlug);

        if ($oldSlug === '' || $newSlug === '' || $oldSlug === $newSlug) {
            return null;
        }
        if (!isset(self::PATTERNS[$entityType])) {
            return null;
        }
        if (!Schema::hasTable('redirects')) {
            return null;
        }

        $from = str_replace('{slug}', $oldSlug, self::PATTERNS[$entityType]);
        $to   = str_replace('{slug}', $newSlug, self::PATTERNS[$entityType]);

        if (Redirect::query()->where('from_path', $from)->exists()) {
            return null; // never overwrite a rule an admin may have customized
        }

        $redirect = Redirect::create([
            'from_path'       => $from,
            'to_path'         => $to,
            'status_code'     => 301,
            'match_type'      => 'exact',
            'is_active'       => true,
            'note'            => 'Auto-created after a ' . $entityType . ' slug change',
            'created_by_type' => 'system',
        ]);

        Cache::forget(Redirect::ACTIVE_CACHE_KEY);

        return $redirect;
    }
}
