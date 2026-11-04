<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves a customer's search term to ranked product IDs (Phase 2.1).
 *
 * Returns IDs rather than models on purpose. The callers already know how to load, filter and
 * paginate products with their own visibility rules — vendor status, approval, digital/physical
 * settings — and duplicating any of that here would be a second source of truth for what a customer
 * may see. This decides *which products match and in what order*, nothing else.
 *
 * It also avoids the pattern it replaces: `->get()->pluck('id')` hydrated every matching Product
 * model purely to read its primary key.
 *
 * Ranking, highest first:
 *
 *   1. the whole query equals the name          "سيروم" -> a product actually called سيروم
 *   2. the name starts with the query           "medee" -> every MEDEE product, in one group
 *   3. every term appears in the name
 *   4. every term appears in the name or body text
 *
 * Tiers 1 and 2 are anchored, so the prefix index serves them. Tier 3 and 4 scan, but they scan
 * `product_search_index` — two short text columns — rather than the full `products` table.
 */
class ProductSearchService
{
    /** Cap on how many IDs a single search resolves, so one broad term cannot load the catalogue. */
    public const MAX_RESULTS = 500;

    public function __construct(private readonly ArabicTextNormalizer $normalizer)
    {
    }

    /**
     * Ranked product IDs for a query.
     *
     * @return array<int, int> best match first; empty when the query has no usable terms
     */
    public function productIds(?string $query, ?string $locale = null, int $limit = self::MAX_RESULTS): array
    {
        if (!$this->ready()) {
            return [];
        }

        $normalized = $this->normalizer->normalize($query);
        $terms = $this->normalizer->terms($query);

        if ($normalized === '' || $terms === []) {
            return [];
        }

        try {
            return $this->rankedIds($normalized, $terms, $locale, max(1, min($limit, self::MAX_RESULTS)));
        } catch (\Throwable) {
            // A search must never 500 the storefront; an empty result lets the caller fall back.
            return [];
        }
    }

    /** Whether this service can answer at all — lets callers keep their existing path otherwise. */
    public function available(): bool
    {
        return $this->ready();
    }

    /**
     * Terms of a query that match nothing, so the UI can say WHICH word failed rather than
     * "no results" — the difference between a dead end and a correction the customer can make.
     *
     * @return array<int, string>
     */
    public function unmatchedTerms(?string $query, ?string $locale = null): array
    {
        if (!$this->ready()) {
            return [];
        }

        $unmatched = [];

        foreach ($this->normalizer->terms($query) as $term) {
            $exists = $this->baseQuery($locale)
                ->where(function ($q) use ($term) {
                    $this->whereTermMatches($q, 'name_normalized', $term);
                    $q->orWhere(fn ($sub) => $this->whereTermMatches($sub, 'text_normalized', $term));
                })
                ->limit(1)
                ->exists();

            if (!$exists) {
                $unmatched[] = $term;
            }
        }

        return $unmatched;
    }

    /**
     * @param  array<int, string>  $terms
     * @return array<int, int>
     */
    private function rankedIds(string $normalized, array $terms, ?string $locale, int $limit): array
    {
        $escaped = $this->escapeLike($normalized);

        $query = $this->baseQuery($locale)
            ->select('product_id')
            // MAX so a product matching in several locales takes its best score, and each product
            // appears once regardless of how many locale rows it has.
            ->selectRaw(
                'MAX(CASE
                    WHEN name_normalized = ?      THEN 100
                    WHEN name_normalized LIKE ?   THEN 75
                    ELSE 0
                END) AS score',
                [$normalized, $escaped . '%']
            );

        // Every term must appear, as a WORD rather than as a substring. Applied as AND across
        // terms, OR across the two columns.
        foreach ($terms as $term) {
            $query->where(function ($q) use ($term) {
                $this->whereTermMatches($q, 'name_normalized', $term);
                $q->orWhere(fn ($sub) => $this->whereTermMatches($sub, 'text_normalized', $term));
            });
        }

        // A name hit outranks a body hit at equal score, and id keeps the order stable between
        // identical runs — without it, pagination can repeat or skip rows.
        return $query->groupBy('product_id')
            ->orderByDesc('score')
            ->orderByRaw('MAX(CASE WHEN name_normalized LIKE ? THEN 1 ELSE 0 END) DESC', ['%' . $escaped . '%'])
            ->orderBy('product_id')
            ->limit($limit)
            ->pluck('product_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Constrain a query so a term matches as a WORD, not as any substring.
     *
     * Substring matching looked fine until it was tested: searching "vitamin c" returned
     * "Vitamin E Cream", because the single letter "c" occurs inside "cream". A customer reads that
     * as a broken search, and the noise grows with catalogue size.
     *
     * The rule, chosen to stay useful rather than merely strict:
     *   - a term always matches a whole word            "c"   -> "vitamin c"
     *   - a term of 3+ characters also matches a word PREFIX, so partial typing still works
     *                                                    "vit" -> "vitamin", but "c" !-> "cream"
     *
     * Expressed as plain LIKE patterns covering each position a word can occupy — whole value,
     * first word, last word, middle — rather than padding the column with CONCAT. CONCAT does not
     * exist in SQLite, which is what the test suite runs on, so the padded version passed against
     * MariaDB and silently matched nothing under test. Portable SQL keeps both honest.
     *
     * Values are already normalised to single-space-separated words, so a space is a reliable
     * boundary.
     */
    private function whereTermMatches($query, string $column, string $term)
    {
        $t = $this->escapeLike($term);

        $query->where($column, '=', $term)          // the whole value is the term
              ->orWhere($column, 'like', $t . ' %')  // first word
              ->orWhere($column, 'like', '% ' . $t)  // last word
              ->orWhere($column, 'like', '% ' . $t . ' %'); // middle

        // Partial typing should still work, but only once a term is long enough that a prefix means
        // something: "vit" -> "vitamin", while "c" must not reach "cream".
        if (mb_strlen($term) >= 3) {
            $query->orWhere($column, 'like', $t . '%')        // prefix of the first word
                  ->orWhere($column, 'like', '% ' . $t . '%'); // prefix of any later word
        }

        return $query;
    }

    private function baseQuery(?string $locale)
    {
        $query = DB::table('product_search_index');

        // A locale filter narrows the scan, but only when that locale is actually indexed —
        // otherwise a customer browsing in a language with no translations would get nothing.
        if ($locale !== null && $locale !== '' && $this->localeIsIndexed($locale)) {
            $query->whereIn('locale', [$locale, ProductSearchIndexer::DEFAULT_LOCALE]);
        }

        return $query;
    }

    private function localeIsIndexed(string $locale): bool
    {
        try {
            return DB::table('product_search_index')->where('locale', $locale)->limit(1)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Escape LIKE wildcards in user input.
     *
     * Without this a customer typing "50%" turns the % into "match anything", quietly returning the
     * whole catalogue instead of the products actually named that.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    private function ready(): bool
    {
        return Schema::hasTable('product_search_index');
    }
}
