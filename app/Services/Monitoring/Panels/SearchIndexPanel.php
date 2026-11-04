<?php

namespace App\Services\Monitoring\Panels;

use App\Models\Product;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Search\ProductSearchIndexer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Storefront search: whether the index behind it still describes the catalogue.
 *
 * Search runs on a normalised index rather than on the products table, kept current by a model
 * observer and rebuilt weekly. Both of those fail quietly by design — the observer swallows its own
 * errors so an index write can never fail a merchant's product save, and the rebuild is a scheduled
 * command nobody watches. Until this page there was no surface for any of it: an administrator
 * could not see how many products were searchable, whether the observer was keeping up, or that a
 * bulk import had gone in without touching the index at all. The only evidence it existed was one
 * row in the scheduler table.
 *
 * Three numbers carry the page, and each is counted rather than assumed.
 *
 * MISSING is products the rebuild would index minus products the index holds. It is the number a
 * bulk import moves, because an importer writes rows without going through the model save path the
 * observer listens on.
 *
 * STALE is products whose own row is newer than their index row. That is the observer falling
 * behind rather than never having run, and it is the failure that leaves search quietly answering
 * with last week's names.
 *
 * EMPTY NAMES is rows the indexer wrote with nothing in them. A product with no normalised name is
 * in the index and unfindable, which reads as "indexed" to every count that does not look inside.
 *
 * The counts are exact, not sampled — the index is a narrow table, and a number an operator is
 * meant to act on has to be the real one.
 */
class SearchIndexPanel implements Panel
{
    private const TABLE = 'product_search_index';

    /** Where the completion of a rebuild is written, so the page can say when one last finished. */
    public const LAST_REBUILD_SETTING = 'search_index_last_rebuild';

    public function __construct(private readonly ProductSearchIndexer $indexer)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function data(string $range, Request $request): array
    {
        if (!$this->indexer->available()) {
            return [
                'available' => false,
                'remedy' => 'run_the_migrations_the_product_search_index_table_does_not_exist',
                'metrics' => [],
                'locales' => [],
                'rebuild' => null,
                'task' => null,
            ];
        }

        $indexedProducts = $this->count(fn () => DB::table(self::TABLE)->distinct()->count('product_id'));
        $catalogue = $this->count(fn () => Product::query()->count());

        return [
            'available' => true,
            'metrics' => [
                'catalogue_products' => Metric::of($catalogue, 'products', 'products'),
                'indexed_products' => Metric::of($indexedProducts, self::TABLE, 'products'),
                'coverage' => $catalogue > 0
                    ? Metric::of(round($indexedProducts / $catalogue * 100, 1), self::TABLE, '%')
                    : Metric::noData(self::TABLE, 'there_are_no_products_to_index'),
                'missing' => Metric::of(max(0, $catalogue - $indexedProducts), self::TABLE, 'products'),
                'stale' => Metric::probe(self::TABLE, fn () => $this->staleCount(), 'products'),
                'empty_names' => Metric::probe(self::TABLE, fn () => $this->emptyNameCount(), 'rows'),
                'index_rows' => Metric::probe(self::TABLE, fn () => DB::table(self::TABLE)->count(), 'rows'),
                'newest_write' => $this->newestWrite(),
            ],
            'locales' => $this->perLocale(),
            'rebuild' => $this->lastRebuild(),
            'task' => $this->scheduledTask(),
            // Named so the page can tell an operator what to run when there is no queue worker to
            // take the request, rather than leaving the button as the only route back.
            'command' => 'php artisan search:reindex-products',
        ];
    }

    /** Products whose own row has moved on since their index row was written. */
    private function staleCount(): int
    {
        return DB::table('products')
            ->join(self::TABLE, self::TABLE . '.product_id', '=', 'products.id')
            ->where(self::TABLE . '.locale', ProductSearchIndexer::DEFAULT_LOCALE)
            ->whereColumn('products.updated_at', '>', self::TABLE . '.updated_at')
            ->count();
    }

    private function emptyNameCount(): int
    {
        return DB::table(self::TABLE)->where('name_normalized', '')->count();
    }

    private function newestWrite(): Metric
    {
        $newest = $this->quiet(fn () => DB::table(self::TABLE)->max('updated_at'));

        return $newest === null
            ? Metric::noData(self::TABLE, 'nothing_has_been_indexed_yet')
            : Metric::of((string) $newest, self::TABLE);
    }

    /**
     * @return array<int, array{locale: string, rows: int}>
     */
    private function perLocale(): array
    {
        $rows = $this->quiet(fn () => DB::table(self::TABLE)
            ->select('locale', DB::raw('count(*) as rows_count'))
            ->groupBy('locale')->orderBy('locale')->limit(40)->get());

        return collect($rows ?? [])
            ->map(fn ($row) => ['locale' => (string) $row->locale, 'rows' => (int) $row->rows_count])
            ->all();
    }

    /**
     * When a rebuild last finished, and who asked for it.
     *
     * @return array<string, mixed>|null
     */
    private function lastRebuild(): ?array
    {
        $raw = $this->quiet(fn () => Schema::hasTable('business_settings')
            ? DB::table('business_settings')->where('type', self::LAST_REBUILD_SETTING)->value('value')
            : null);

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The weekly rebuild as the scheduler actually recorded it — the same source the scheduler page
     * reads, so the two can never tell different stories about the same task.
     *
     * @return array<string, mixed>|null
     */
    private function scheduledTask(): ?array
    {
        $run = $this->quiet(fn () => Schema::hasTable('monitoring_scheduled_runs')
            ? DB::table('monitoring_scheduled_runs')
                ->where('task', 'like', '%search:reindex-products%')
                ->orderByDesc('started_at')->first()
            : null);

        if ($run === null) {
            return null;
        }

        return [
            'task' => 'search:reindex-products',
            'status' => (string) $run->status,
            'started_at' => (string) $run->started_at,
            'finished_at' => $run->finished_at ? (string) $run->finished_at : null,
            'duration_ms' => $run->duration_ms !== null ? (int) $run->duration_ms : null,
            'expected_next_at' => $run->expected_next_at ? (string) $run->expected_next_at : null,
            'age_hours' => $this->hoursSince((string) $run->started_at),
        ];
    }

    private function hoursSince(string $timestamp): ?float
    {
        try {
            return round((Clock::now()->getTimestamp() - strtotime($timestamp)) / 3600, 1);
        } catch (\Throwable) {
            return null;
        }
    }

    private function count(callable $probe): int
    {
        return (int) ($this->quiet($probe) ?? 0);
    }

    private function quiet(callable $probe): mixed
    {
        try {
            return $probe();
        } catch (\Throwable) {
            return null;
        }
    }
}
