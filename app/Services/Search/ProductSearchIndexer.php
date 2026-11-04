<?php

namespace App\Services\Search;

use App\Models\Product;
use App\Models\ProductSearchIndex;
use App\Models\Translation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the normalised search rows for a product (Phase 2.1).
 *
 * One row per locale. The default row carries the product's own `name`; each translated locale
 * carries that locale's translated name, so a customer browsing in Arabic is matched against Arabic
 * text rather than against a transliteration of the Latin one.
 *
 * Everything here is derived and idempotent: reindexing a product replaces its rows wholesale, and
 * the whole table can be dropped and rebuilt from source at any time.
 *
 * Written defensively about missing tables. This runs from a product-save path, and a partially
 * migrated installation must not lose the ability to save a product because an index table is not
 * there yet — indexing is an enhancement, never a precondition.
 */
class ProductSearchIndexer
{
    public function __construct(private readonly ArabicTextNormalizer $normalizer)
    {
    }

    /** Locale used for the row built from the product's own columns. */
    public const DEFAULT_LOCALE = 'default';

    /**
     * Rebuild every locale row for one product.
     *
     * @return int rows written (0 when indexing is unavailable)
     */
    public function index(Product $product): int
    {
        if (!$this->ready()) {
            return 0;
        }

        try {
            $rows = $this->rowsFor($product);

            return DB::transaction(function () use ($product, $rows) {
                ProductSearchIndex::where('product_id', $product->id)->delete();

                foreach ($rows as $row) {
                    ProductSearchIndex::create($row);
                }

                return count($rows);
            });
        } catch (\Throwable) {
            // Never let indexing break the save that triggered it.
            return 0;
        }
    }

    /** Whether indexing can run at all — lets callers skip cleanly on a partially migrated install. */
    public function available(): bool
    {
        return $this->ready();
    }

    /** Drop a product's rows — for deletes, where the FK cascade may not run (soft deletes). */
    public function remove(int $productId): void
    {
        if (!$this->ready()) {
            return;
        }

        try {
            ProductSearchIndex::where('product_id', $productId)->delete();
        } catch (\Throwable) {
            // nothing to do; the row is derived data
        }
    }

    /**
     * Rebuild the whole index.
     *
     * Chunked because a real catalogue does not fit in memory, and it selects only the columns it
     * needs — the bug this feature replaces did `->get()->pluck('id')`, hydrating whole models just
     * to read primary keys.
     *
     * @param  callable|null  $progress  called with the running total after each chunk
     */
    public function rebuild(?callable $progress = null, int $chunkSize = 200): int
    {
        if (!$this->ready()) {
            return 0;
        }

        $total = 0;

        Product::query()
            ->select(['id', 'name', 'details'])
            ->orderBy('id')
            ->chunkById($chunkSize, function ($products) use (&$total, $progress) {
                foreach ($products as $product) {
                    $total += $this->index($product) > 0 ? 1 : 0;
                }
                if ($progress) {
                    $progress($total);
                }
            });

        return $total;
    }

    /**
     * The rows a product should have.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rowsFor(Product $product): array
    {
        $translations = $this->translationsFor($product->id);

        // The default row: the product's own STORED columns.
        $rows = [[
            'product_id'      => $product->id,
            'locale'          => self::DEFAULT_LOCALE,
            'name_normalized' => $this->clip($this->normalizer->normalize($this->rawAttribute($product, 'name'))),
            // Deliberately NOT the slug. A slug is a frozen copy of an older name, so indexing it
            // kept renamed products findable under names they no longer have — caught by a test
            // that renamed a product and still found it by its previous name. It also duplicates
            // the name column for every product that has never been renamed.
            'text_normalized' => $this->normalizer->normalize(
                strip_tags((string) $this->rawAttribute($product, 'details'))
            ),
        ]];

        foreach ($translations as $locale => $fields) {
            // A locale with no translated name adds nothing the default row does not already cover.
            if (($fields['name'] ?? '') === '' && ($fields['details'] ?? '') === '') {
                continue;
            }

            $rows[] = [
                'product_id'      => $product->id,
                'locale'          => $locale,
                'name_normalized' => $this->clip($this->normalizer->normalize($fields['name'] ?? '')),
                'text_normalized' => $this->normalizer->normalize(strip_tags($fields['details'] ?? '')),
            ];
        }

        return $rows;
    }

    /**
     * Translated name/details per locale for a product.
     *
     * @return array<string, array{name?: string, details?: string}>
     */
    private function translationsFor(int $productId): array
    {
        if (!Schema::hasTable('translations')) {
            return [];
        }

        $out = [];

        Translation::query()
            ->select(['locale', 'key', 'value'])
            ->where('translationable_type', Product::class)
            ->where('translationable_id', $productId)
            ->whereIn('key', ['name', 'details'])
            ->get()
            ->each(function ($translation) use (&$out) {
                $locale = (string) $translation->locale;
                if ($locale === '' || $locale === self::DEFAULT_LOCALE) {
                    return;
                }
                $out[$locale][$translation->key] = (string) $translation->value;
            });

        return $out;
    }

    /**
     * A column's stored value, bypassing both accessors and the original-attribute snapshot.
     *
     * Neither obvious alternative is correct here:
     *
     *   $product->name        goes through Product::getNameAttribute(), which returns
     *                         `$this->translations[0]->value ?? $name` and branches on
     *                         request()->segment(1) — so the value depends on which URL the code is
     *                         running under, and outside admin/vendor it returns a translation
     *                         rather than the product's own name. Indexing through it wrote Arabic
     *                         into the default row and made a product called "Serum" unfindable by
     *                         "serum".
     *
     *   getRawOriginal()      returns the PRE-save snapshot. Laravel syncs originals after firing
     *                         `saved`, so an observer reading it indexes the value the product had
     *                         before the edit — leaving the index permanently one edit behind.
     *                         Caught by renaming a product at runtime: the new name was unfindable,
     *                         and the old one only became findable after the NEXT save.
     *
     * getAttributes() is the raw current state, which is what should be indexed in both cases.
     */
    private function rawAttribute(Product $product, string $column): string
    {
        return (string) ($product->getAttributes()[$column] ?? '');
    }

    /** The name column is 512 chars; clip on character count, not bytes, so Arabic is not cut mid-character. */
    private function clip(string $value): string
    {
        return mb_substr($value, 0, 512);
    }

    private function ready(): bool
    {
        return Schema::hasTable('product_search_index');
    }
}
