<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\ProductCollection;
use App\Services\Theme\ContentSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Turns one collection into the products it currently means (Phase 3.1).
 *
 * Deliberately boring at run time: the rules were validated at save, the metrics were computed on
 * the scheduler, so resolution is one indexed query — Product::active(), a handful of WHEREs from
 * the registry, an ORDER BY on a joined summary row. Everything that could be slow already
 * happened somewhere a shopper is not waiting.
 *
 * Every failure path returns an empty list, because the callers already know what that means:
 * the web's SectionReadiness hides the section, the app receives a source it treats as empty, and
 * SectionDataResolver falls back to the section's catalogue source first. A collection can be
 * misconfigured, deleted, disabled or switched off wholesale, and the page still loads (§9, §84).
 */
class CollectionResolver
{
    /**
     * How many rule-matching candidates to rank beyond the asked-for limit, so boosts have
     * something to promote and exclusions something to close over. Bounded: merchandising can
     * reorder the head of the list, never make the query unbounded.
     */
    private const POOL_FACTOR = 3;

    public function __construct(
        private readonly CollectionRuleRegistry $registry,
        private readonly MerchandisingRules $merchandising,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) config('commerce.enabled', true);
    }

    /** The collection an id names, if it is live and this installation can serve it. */
    public function find(?int $id): ?ProductCollection
    {
        if ($id === null || $id <= 0 || !$this->enabled() || !$this->ready()) {
            return null;
        }

        try {
            return ProductCollection::query()->live()->find($id);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  bool  $isFallback  set when THIS resolution is already somebody's fallback, so a
     *                            chain stops at one hop whatever the stored config says
     * @return Collection<int, Product> empty on any failure — never an exception, never null
     */
    public function resolve(ProductCollection|int|null $collection, int $limit = 10, bool $isFallback = false): Collection
    {
        $collection = $collection instanceof ProductCollection
            ? ($collection->status ? $collection : null)
            : $this->find($collection);

        if ($collection === null || !$this->enabled()) {
            return collect();
        }

        $limit = max(1, min((int) $limit, ContentSource::MAX_LIMIT));

        try {
            $config = $this->merchandising->configFor($collection);

            $candidates = $this->candidates($collection, $config, $limit);
            if ($candidates === null) {
                // A stored rule the registry no longer recognises means the collection no longer
                // says what the merchant wrote. Serving a broader list than they asked for is the
                // one wrong answer — serve nothing and let the fallback speak.
                return collect();
            }

            $ranked = $this->applyBoosts($candidates, $config['boosts']);
            $woven = $this->weavePins($ranked, $config, $limit);

            // Automatic replacement (§29): exclusions and unavailable products left the list
            // short of what the section asked for; the configured fallback tops it up. Pinned
            // items are never displaced — this only fills empty tail slots.
            if ($config['replace'] && $woven->count() < $limit && !$isFallback) {
                $woven = $this->backfill($woven, $config, $limit);
            }

            // Too thin to show (§30): the fallback decides — hide, a catalogue source, or one
            // other collection. Never a chain: a fallback's own fallback does not run.
            if ($woven->count() < $config['min_items'] && !$isFallback) {
                return $this->fallbackContent($config, $limit);
            }

            return $woven->take($limit)->values();
        } catch (\Throwable) {
            return collect();
        }
    }

    /** Whether this installation has the collection tables at all. */
    public function ready(): bool
    {
        try {
            return Schema::hasTable('product_collections');
        } catch (\Throwable) {
            return false;
        }
    }

    // ---------------------------------------------------------------------------------------

    /**
     * The rule-matching, exclusion-respecting, ranked candidate pool — or null when a stored
     * rule cannot be compiled.
     *
     * @return Collection<int, Product>|null
     */
    private function candidates(ProductCollection $collection, array $config, int $limit): ?Collection
    {
        $query = Product::active()->with('brand:id,name,slug');
        $query = $this->joinMetrics($query);

        foreach ($collection->ruleRows() as $rule) {
            $query = $this->applyRule($query, $rule);
            if ($query === null) {
                return null;
            }
        }

        if ($config['excluded'] !== []) {
            $query->whereNotIn('products.id', $config['excluded']);
        }

        return $this->applySort($query, $collection->sort_by)
            ->take(min($limit * self::POOL_FACTOR, 72))
            ->get();
    }

    /**
     * Boosts re-rank and do nothing else (§28): every candidate already passed Product::active()
     * and the rules, so a boost can promote it, never resurrect it. Deterministic: score, then
     * the ranking the sort already gave — never a coin flip (§36).
     *
     * @param  Collection<int, Product>  $candidates
     * @return Collection<int, Product>
     */
    private function applyBoosts(Collection $candidates, array $boosts): Collection
    {
        if ($boosts === []) {
            return $candidates;
        }

        $scored = $candidates->values()->map(function (Product $product, int $index) use ($boosts) {
            $score = 0.0;
            foreach ($boosts as $boost) {
                $score += match ($boost['kind']) {
                    'product'  => (int) $product->id === (int) $boost['id'] ? (float) $boost['weight'] : 0.0,
                    'brand'    => (int) $product->brand_id === (int) $boost['id'] ? (float) $boost['weight'] : 0.0,
                    'category' => (int) $product->category_id === (int) $boost['id'] ? (float) $boost['weight'] : 0.0,
                    'featured' => (int) $product->featured === 1 ? (float) $boost['weight'] : 0.0,
                    default    => 0.0,
                };
            }

            return ['product' => $product, 'score' => $score, 'index' => $index];
        });

        return $scored
            ->sort(fn (array $a, array $b) => $b['score'] <=> $a['score'] ?: $a['index'] <=> $b['index'])
            ->map(fn (array $row) => $row['product'])
            ->values();
    }

    /**
     * Fix pins into their positions and fill the rest from the ranking (§26).
     *
     * A pin that is no longer purchasable is skipped, not resurrected — the list closes up and,
     * with replacement on, the tail is refilled. Position collisions take the next free slot, so
     * two pins at #1 are #1 and #2, in the order the admin listed them.
     *
     * @param  Collection<int, Product>  $ranked
     * @return Collection<int, Product>
     */
    private function weavePins(Collection $ranked, array $config, int $limit): Collection
    {
        if ($config['pins'] === []) {
            return $ranked->take($limit)->values();
        }

        $pinIds = array_column($config['pins'], 'id');

        // Fetched through the same eligibility gate as everything else: a pin is a manual
        // addition, never an override of "this product cannot be sold".
        $pinned = Product::active()->with('brand:id,name,slug')
            ->whereIn('products.id', $pinIds)
            ->get()
            ->keyBy('id');

        // Every available pin is guaranteed a slot inside the visible list — a pin is the
        // admin's hand, and the ranking must never displace it. Positions clamp into the list;
        // a collision (or a clamp collision at the tail) takes the NEAREST free slot, searching
        // up then down, so two pins at #10 on a rail of ten are #10 and #9 — both present.
        $slots = [];
        foreach ($config['pins'] as $pin) {
            $product = $pinned->get($pin['id']);
            if ($product === null || count($slots) >= $limit) {
                continue;
            }

            $wanted = min(max(1, (int) $pin['position']), $limit);
            $position = $wanted;
            while (isset($slots[$position]) && $position < $limit) {
                $position++;
            }
            if (isset($slots[$position])) {
                $position = $wanted;
                while (isset($slots[$position]) && $position > 1) {
                    $position--;
                }
            }

            $slots[$position] = $product;
        }

        $rest = $ranked->reject(fn (Product $product) => in_array((int) $product->id, $pinIds, true))->values();

        $result = [];
        $restIndex = 0;
        $total = min($limit, count($slots) + $rest->count());

        for ($position = 1; count($result) < $total && $position <= $limit; $position++) {
            if (isset($slots[$position])) {
                $result[] = $slots[$position];
            } elseif ($restIndex < $rest->count()) {
                $result[] = $rest[$restIndex++];
            }
        }

        return collect($result);
    }

    /**
     * Top a short list up from the configured fallback, never displacing what is there (§29).
     *
     * @param  Collection<int, Product>  $current
     * @return Collection<int, Product>
     */
    private function backfill(Collection $current, array $config, int $limit): Collection
    {
        $extra = $this->fallbackContent($config, $limit);

        if ($extra->isEmpty()) {
            return $current;
        }

        $have = $current->pluck('id')->map(fn ($id) => (int) $id)->all();

        $fill = $extra
            ->reject(fn (Product $product) => in_array((int) $product->id, $have, true)
                || in_array((int) $product->id, $config['excluded'], true))
            ->take($limit - $current->count());

        return $current->concat($fill)->values();
    }

    /**
     * What the fallback names: nothing, a catalogue source, or ONE other collection — resolved
     * with its own merchandising but with its own fallback disabled, so a chain is one hop long
     * at run time however the rows were edited since the save-time cycle check (§30).
     *
     * @return Collection<int, Product>
     */
    private function fallbackContent(array $config, int $limit): Collection
    {
        $fallback = $config['fallback'];

        $content = collect();

        if ($fallback['kind'] === 'source' && $fallback['source'] !== null) {
            $content = app(\App\Services\Theme\SectionDataResolver::class)->productsFrom(
                ContentSource::fromSettings(['source' => $fallback['source'], 'limit' => $limit]),
            );
        } elseif ($fallback['kind'] === 'collection' && $fallback['id'] !== null) {
            $content = $this->resolve($fallback['id'], $limit, isFallback: true);
        }

        // The exclusions hold whichever door the products came through: a product the admin
        // banned from this collection must not walk back in as its fallback.
        return $config['excluded'] === []
            ? $content
            : $content->reject(fn (Product $product) => in_array((int) $product->id, $config['excluded'], true))->values();
    }

    private function joinMetrics(Builder $query): Builder
    {
        // LEFT join + COALESCE: a product with no metrics row is a product with zero recorded
        // engagement — true, and it keeps brand-new products inside every non-metric rule.
        return $query
            ->leftJoin('product_metrics', 'product_metrics.product_id', '=', 'products.id')
            ->select('products.*');
    }

    private function applyRule(Builder $query, array $rule): ?Builder
    {
        $definition = CollectionRuleRegistry::FIELDS[$rule['field'] ?? ''] ?? null;
        $operator = $rule['operator'] ?? '';
        $value = $rule['value'] ?? null;

        if ($definition === null || !in_array($operator, $definition['operators'], true)) {
            return null;
        }

        $column = isset($definition['metric'])
            ? 'product_metrics.' . $definition['metric']
            : 'products.' . $definition['column'];
        $metric = isset($definition['metric']);

        return match ($operator) {
            'equals'                => $this->compare($query, $column, '=', $value, $metric),
            'not_equals'            => $this->compare($query, $column, '!=', $value, $metric),
            'greater_than'          => $this->compare($query, $column, '>', $value, $metric),
            'greater_than_or_equal' => $this->compare($query, $column, '>=', $value, $metric),
            'less_than'             => $this->compare($query, $column, '<', $value, $metric),
            'less_than_or_equal'    => $this->compare($query, $column, '<=', $value, $metric),
            'between'               => is_array($value) && count($value) === 2
                ? ($metric
                    ? $query->whereRaw('COALESCE(' . $column . ', 0) BETWEEN ? AND ?', $value)
                    : $query->whereBetween($column, $value))
                : null,
            'in'                    => is_array($value) ? $this->inSet($query, $rule['field'], $column, $value, false) : null,
            'not_in'                => is_array($value) ? $this->inSet($query, $rule['field'], $column, $value, true) : null,
            'within_last_days'      => $query->where($column, '>=', now()->subDays((int) $value)),
            default                 => null,
        };
    }

    /**
     * A set rule. Category is special: the platform files a product under up to three levels
     * (category, sub, sub-sub), and a merchant who says "in Vitamins" means ANY of them — the
     * same reading every catalogue page applies. Excluding reads the same way, negated.
     */
    private function inSet(Builder $query, string $field, string $column, array $value, bool $negate): Builder
    {
        if ($field !== 'category') {
            return $negate ? $query->whereNotIn($column, $value) : $query->whereIn($column, $value);
        }

        if ($negate) {
            return $query
                ->whereNotIn('products.category_id', $value)
                ->where(fn (Builder $inner) => $inner->whereNotIn('products.sub_category_id', $value)->orWhereNull('products.sub_category_id'))
                ->where(fn (Builder $inner) => $inner->whereNotIn('products.sub_sub_category_id', $value)->orWhereNull('products.sub_sub_category_id'));
        }

        return $query->where(fn (Builder $inner) => $inner
            ->whereIn('products.category_id', $value)
            ->orWhereIn('products.sub_category_id', $value)
            ->orWhereIn('products.sub_sub_category_id', $value));
    }

    /**
     * One comparison, metric-aware. Metric columns go through COALESCE so "sales less than 5"
     * includes the product that never sold — the reading a merchant means.
     */
    private function compare(Builder $query, string $column, string $sqlOperator, mixed $value, bool $metric): ?Builder
    {
        if (!is_numeric($value) && !is_bool($value)) {
            return null;
        }

        $value = is_bool($value) ? (int) $value : $value;

        return $metric
            ? $query->whereRaw('COALESCE(' . $column . ', 0) ' . $sqlOperator . ' ?', [$value])
            : $query->where($column, $sqlOperator, $value);
    }

    private function applySort(Builder $query, ?string $sort): Builder
    {
        $sort = $this->registry->isSort($sort) ? $sort : 'sales_30d';

        return match ($sort) {
            'newest'     => $query->orderByDesc('products.id'),
            // The id tiebreak keeps equal-priced products in one order everywhere (§36 spirit:
            // never a coin flip, even the database's).
            'price_low'  => $query->orderBy('products.unit_price')->orderByDesc('products.id'),
            'price_high' => $query->orderByDesc('products.unit_price')->orderByDesc('products.id'),
            default      => $query
                ->orderByRaw('COALESCE(product_metrics.' . $sort . ', 0) DESC')
                ->orderByDesc('products.id'),
        };
    }
}
