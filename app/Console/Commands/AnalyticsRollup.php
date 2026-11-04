<?php

namespace App\Console\Commands;

use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Support\AnalyticsPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Compresses a day of behaviour into the rows every chart older than today reads.
 *
 * Two reasons this exists rather than the reports querying events directly. The obvious one is
 * cost: a year of "traffic by source" is a few thousand rollup rows or tens of millions of events,
 * and only one of those draws a chart while somebody is looking at it. The less obvious one is
 * correctness — every screen reading the same precomputed rows cannot disagree with another screen
 * about what last Tuesday's bounce rate was, which is exactly what happens when six pages each
 * write their own aggregate query.
 *
 * Bots and staff are excluded from the rollups, and BOTH are also written as their own dimension
 * so the exclusion is visible rather than silent. A merchant must be able to see that a third of
 * their raw traffic was crawlers; they must not have to take it on faith that somebody filtered it.
 */
class AnalyticsRollup extends Command
{
    /** The row every dimension's tail is folded into, as config/analytics.php promises. */
    private const OTHER_KEY = '__other__';

    /**
     * Measures that may be summed across keys.
     *
     * Deliberately excludes `visitors` and `new_visitors`: those are COUNT(DISTINCT visitor_id) per
     * key, and adding them across keys counts one person once per key they appear under.
     */
    /** Distinct counts, which cannot be added across keys — the folded row leaves them null. */
    private const UNSUMMABLE_MEASURES = ['visitors', 'new_visitors'];

    private const ADDITIVE_MEASURES = [
        'sessions', 'pageviews', 'events', 'bounces', 'engaged_sessions',
        'duration_seconds', 'cart_adds', 'checkouts', 'orders',
    ];

    protected $signature = 'analytics:rollup
                            {--date= : YYYY-MM-DD, defaults to today}
                            {--days=1 : Roll up this many days back from --date}
                            {--prune : Also delete raw rows past their retention}';

    protected $description = 'Aggregate analytics events and sessions into daily rollups';

    /** Session dimensions that are a column on the session row. */
    private const SESSION_DIMENSIONS = [
        'source' => 'source',
        'medium' => 'medium',
        'campaign' => 'campaign',
        'device' => 'device',
        'os' => 'os',
        'browser' => 'browser',
        'country' => 'country',
        'language' => 'language',
        'app_version' => 'app_version',
        'landing_path' => 'landing_path',
        'attribution_basis' => 'attribution_basis',
    ];

    public function handle(): int
    {
        if (!app(AnalyticsPolicy::class)->enabled()) {
            $this->warn('Analytics is disabled (ANALYTICS_ENABLED=false); nothing was rolled up.');

            return self::SUCCESS;
        }

        $date = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::today();
        $days = max(1, (int) $this->option('days'));
        $written = 0;

        for ($offset = 0; $offset < $days; $offset++) {
            $day = $date->copy()->subDays($offset);
            $written += $this->rollupDay($day);
            $this->line('  ' . $day->toDateString() . ' rolled up.');
        }

        $this->info("Wrote {$written} rollup row(s).");

        if ($this->option('prune')) {
            $this->info($this->prune() . ' row(s) pruned past retention.');
        }

        $this->recordRun();

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------------------------

    private function rollupDay(Carbon $day): int
    {
        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();
        $written = 0;

        $written += $this->rollupTotals($day, $from, $to);
        $written += $this->rollupTraffic($day, $from, $to);

        foreach (self::SESSION_DIMENSIONS as $dimension => $column) {
            $written += $this->rollupSessionDimension($day, $from, $to, $dimension, $column);
        }

        $written += $this->rollupNewVersusReturning($day, $from, $to);
        $written += $this->rollupHours($day, $from, $to);
        $written += $this->rollupPages($day, $from, $to);
        $written += $this->rollupEventNames($day, $from, $to);
        $written += $this->rollupEntities($day, $from, $to);
        $written += $this->rollupSearchTerms($day, $from, $to);
        $written += $this->rollupCampaignPerformance($day, $from, $to);

        return $written;
    }

    /** The day's headline numbers, computed once so every screen agrees on them. */
    private function rollupTotals(Carbon $day, Carbon $from, Carbon $to): int
    {
        $totals = $this->realSessions($from, $to)
            ->selectRaw('COUNT(*) sessions')
            ->selectRaw('COUNT(DISTINCT visitor_id) visitors')
            ->selectRaw('SUM(is_new_visitor) new_visitors')
            ->selectRaw('SUM(pageviews) pageviews')
            ->selectRaw('SUM(events) events')
            ->selectRaw('SUM(is_bounce) bounces')
            ->selectRaw('SUM(is_engaged) engaged_sessions')
            ->selectRaw('SUM(duration_seconds) duration_seconds')
            ->selectRaw('SUM(cart_adds) cart_adds')
            ->selectRaw('SUM(checkouts) checkouts')
            ->selectRaw('SUM(orders) orders')
            ->selectRaw('COALESCE(SUM(revenue), 0) revenue')
            ->first();

        return $this->put($day, 'totals', 'all', (array) $totals);
    }

    /**
     * How much of the raw traffic was excluded, and why.
     *
     * Recorded as its own dimension precisely because the reports exclude it: a filter nobody can
     * see the size of is indistinguishable from a filter that stopped working.
     */
    private function rollupTraffic(Carbon $day, Carbon $from, Carbon $to): int
    {
        $written = 0;

        foreach (['bot' => 'is_bot', 'internal' => 'is_internal'] as $key => $column) {
            $totals = $this->connection()->table('analytics_sessions')
                ->whereBetween('started_at', [$from, $to])
                ->where($column, true)
                ->selectRaw('COUNT(*) sessions, COUNT(DISTINCT visitor_id) visitors, SUM(pageviews) pageviews, SUM(events) events')
                ->first();

            $written += $this->put($day, 'excluded_traffic', $key, (array) $totals);
        }

        return $written;
    }

    private function rollupSessionDimension(Carbon $day, Carbon $from, Carbon $to, string $dimension, string $column): int
    {
        $rows = $this->realSessions($from, $to)
            ->selectRaw("COALESCE(NULLIF({$column}, ''), '(not set)') dimension_key")
            ->selectRaw('COUNT(*) sessions')
            ->selectRaw('COUNT(DISTINCT visitor_id) visitors')
            ->selectRaw('SUM(is_new_visitor) new_visitors')
            ->selectRaw('SUM(pageviews) pageviews')
            ->selectRaw('SUM(is_bounce) bounces')
            ->selectRaw('SUM(is_engaged) engaged_sessions')
            ->selectRaw('SUM(duration_seconds) duration_seconds')
            ->selectRaw('SUM(cart_adds) cart_adds')
            ->selectRaw('SUM(checkouts) checkouts')
            ->selectRaw('SUM(orders) orders')
            ->selectRaw('COALESCE(SUM(revenue), 0) revenue')
            ->groupBy('dimension_key')
            ->orderByDesc('sessions')
            ->limit($this->readCeiling())
            ->get();

        return $this->putMany($day, $dimension, $rows);
    }

    /**
     * New against returning, which is the one split that tells a merchant whether growth is coming
     * from reach or from loyalty — and the two need completely different work.
     */
    private function rollupNewVersusReturning(Carbon $day, Carbon $from, Carbon $to): int
    {
        $rows = $this->realSessions($from, $to)
            ->selectRaw("CASE WHEN is_new_visitor = 1 THEN 'new' ELSE 'returning' END dimension_key")
            ->selectRaw('COUNT(*) sessions, COUNT(DISTINCT visitor_id) visitors, SUM(pageviews) pageviews')
            ->selectRaw('SUM(is_bounce) bounces, SUM(is_engaged) engaged_sessions, SUM(duration_seconds) duration_seconds')
            ->selectRaw('SUM(orders) orders, COALESCE(SUM(revenue), 0) revenue')
            ->groupBy('dimension_key')
            ->get();

        return $this->putMany($day, 'new_vs_returning', $rows);
    }

    /** By hour and by weekday: when this shop is actually busy, which is when to run a promotion. */
    private function rollupHours(Carbon $day, Carbon $from, Carbon $to): int
    {
        $rows = $this->realSessions($from, $to)
            ->selectRaw($this->hourOfDay('started_at') . ' dimension_key')
            ->selectRaw('COUNT(*) sessions, COUNT(DISTINCT visitor_id) visitors, SUM(pageviews) pageviews')
            ->selectRaw('SUM(orders) orders, COALESCE(SUM(revenue), 0) revenue')
            ->groupBy('dimension_key')
            ->get();

        $written = $this->putMany($day, 'hour', $rows);

        $weekday = $this->realSessions($from, $to)
            ->selectRaw('COUNT(*) sessions, COUNT(DISTINCT visitor_id) visitors, SUM(pageviews) pageviews, SUM(orders) orders, COALESCE(SUM(revenue), 0) revenue')
            ->first();

        return $written + $this->put($day, 'weekday', (string) $day->dayOfWeek, (array) $weekday);
    }

    /**
     * The hour of a timestamp, zero-padded, in the dialect the active connection speaks.
     *
     * HOUR() and LPAD() are MySQL's. Writing them literally meant the rollup — the command every
     * chart on the analytics screens is drawn from — could not run anywhere else, and so could not
     * be exercised by a test at all.
     */
    private function hourOfDay(string $column): string
    {
        $grammar = $this->connection()->getQueryGrammar();
        $wrapped = $grammar->wrap($column);

        return match ($this->connection()->getDriverName()) {
            'sqlite' => "strftime('%H', {$wrapped})",
            'pgsql' => "to_char({$wrapped}, 'HH24')",
            'sqlsrv' => "RIGHT('0' + CAST(DATEPART(hour, {$wrapped}) AS varchar(2)), 2)",
            default => "LPAD(HOUR({$wrapped}), 2, '0')",
        };
    }

    /** Top pages, by normalised path — the raw URL was never stored, so this cannot explode. */
    private function rollupPages(Carbon $day, Carbon $from, Carbon $to): int
    {
        $rows = $this->realEvents($from, $to)
            ->where('name', AnalyticsEvent::PAGE_VIEWED)
            ->selectRaw("COALESCE(NULLIF(path, ''), '(not set)') dimension_key")
            ->selectRaw('COUNT(*) pageviews, COUNT(DISTINCT visitor_id) visitors, COUNT(DISTINCT session_id) sessions')
            ->groupBy('dimension_key')
            ->orderByDesc('pageviews')
            ->limit($this->readCeiling())
            ->get();

        return $this->putMany($day, 'path', $rows);
    }

    private function rollupEventNames(Carbon $day, Carbon $from, Carbon $to): int
    {
        $rows = $this->realEvents($from, $to)
            ->selectRaw('name dimension_key')
            ->selectRaw('COUNT(*) events, COUNT(DISTINCT visitor_id) visitors, COUNT(DISTINCT session_id) sessions')
            // Only money events contribute revenue. `value` on a product view is that product's
            // price, which is a legitimate thing to record and NOT a sale — summing it into a
            // revenue column would have the catalogue inflating the day's takings.
            ->selectRaw('COALESCE(SUM(CASE WHEN name IN (?, ?) THEN value ELSE 0 END), 0) revenue', [
                AnalyticsEvent::ORDER_PLACED,
                AnalyticsEvent::PAYMENT_SUCCEEDED,
            ])
            ->groupBy('dimension_key')
            ->get();

        return $this->putMany($day, 'event', $rows);
    }

    /**
     * Products, categories and shops: views, carts and orders per entity.
     *
     * One pass per entity kind, keyed on what the event was about. The conversion rate a product
     * screen shows is then a division of two numbers measured the same way, rather than a view
     * count from one table divided by an order count from another that counted different people.
     */
    private function rollupEntities(Carbon $day, Carbon $from, Carbon $to): int
    {
        $map = [
            'product' => [AnalyticsEvent::PRODUCT_VIEWED, AnalyticsEvent::CART_ADDED],
            'category' => [AnalyticsEvent::CATEGORY_VIEWED],
            'shop' => [AnalyticsEvent::SHOP_VIEWED],
            'brand' => [AnalyticsEvent::BRAND_VIEWED],
            // Which banner was taken, per banner. The merchant chooses the picture, the slot and
            // the link; without this the only honest answer to "is that banner working?" was that
            // nobody had ever counted.
            'banner' => [AnalyticsEvent::BANNER_CLICKED],
            // Which composed sections shoppers actually reach. Rolled up per section so the
            // builder can put a number on the row it belongs to.
            'theme_section' => [AnalyticsEvent::SECTION_VIEWED],
            'gateway' => [AnalyticsEvent::PAYMENT_SUCCEEDED, AnalyticsEvent::PAYMENT_FAILED],
        ];

        $written = 0;

        foreach ($map as $entity => $names) {
            $rows = $this->realEvents($from, $to)
                ->where('entity_type', $entity)
                ->whereIn('name', $names)
                ->selectRaw('entity_id dimension_key')
                ->selectRaw('COUNT(*) events')
                ->selectRaw('COUNT(DISTINCT visitor_id) visitors')
                ->selectRaw('COUNT(DISTINCT session_id) sessions')
                ->selectRaw('SUM(CASE WHEN name = ? THEN 1 ELSE 0 END) pageviews', [$names[0]])
                ->selectRaw('SUM(CASE WHEN name = ? THEN 1 ELSE 0 END) cart_adds', [AnalyticsEvent::CART_ADDED])
                ->groupBy('dimension_key')
                ->orderByDesc('events')
                ->limit($this->readCeiling())
                ->get();

            $written += $this->putMany($day, $entity, $rows);
        }

        // Orders per vendor, so a vendor's own analytics is a filter on a rollup rather than a
        // separate pipeline that can drift away from the admin's numbers.
        $vendorRows = $this->realEvents($from, $to)
            ->where('name', AnalyticsEvent::ORDER_PLACED)
            ->whereNotNull('vendor_id')
            ->selectRaw('vendor_id dimension_key')
            ->selectRaw('COUNT(*) orders, COUNT(DISTINCT visitor_id) visitors, COALESCE(SUM(value), 0) revenue')
            ->groupBy('dimension_key')
            ->orderByDesc('orders')
            ->limit($this->readCeiling())
            ->get();

        return $written + $this->putMany($day, 'vendor', $vendorRows);
    }

    /**
     * Search terms, with the ones that found nothing kept separate.
     *
     * A term with volume and no results is a stocking decision waiting to be made, and it is the
     * single most actionable thing on an analytics screen for a shop this size.
     */
    private function rollupSearchTerms(Carbon $day, Carbon $from, Carbon $to): int
    {
        $written = 0;

        foreach ([
            'search_term' => AnalyticsEvent::SEARCH_PERFORMED,
            'search_no_results' => AnalyticsEvent::SEARCH_NO_RESULTS,
        ] as $dimension => $name) {
            $rows = $this->realEvents($from, $to)
                ->where('name', $name)
                ->selectRaw('entity_id dimension_key')
                ->selectRaw('COUNT(*) events, COUNT(DISTINCT visitor_id) visitors, COUNT(DISTINCT session_id) sessions')
                ->groupBy('dimension_key')
                ->orderByDesc('events')
                ->limit($this->readCeiling())
                ->get();

            $written += $this->putMany($day, $dimension, $rows);
        }

        return $written;
    }

    /**
     * Campaign performance, taken from sessions rather than from clicks.
     *
     * A click is a promise; a session is a visit. Crediting a campaign by its click count is how a
     * bot farm becomes the best-performing campaign of the month.
     */
    private function rollupCampaignPerformance(Carbon $day, Carbon $from, Carbon $to): int
    {
        $rows = $this->realSessions($from, $to)
            ->whereNotNull('campaign_id')
            ->selectRaw('campaign_id dimension_key')
            ->selectRaw('COUNT(*) sessions, COUNT(DISTINCT visitor_id) visitors, SUM(pageviews) pageviews')
            ->selectRaw('SUM(is_bounce) bounces, SUM(cart_adds) cart_adds, SUM(checkouts) checkouts')
            ->selectRaw('SUM(orders) orders, COALESCE(SUM(revenue), 0) revenue')
            ->groupBy('dimension_key')
            ->get();

        $this->refreshCampaignTotals();

        return $this->putMany($day, 'campaign_link', $rows);
    }

    /**
     * The counters denormalised onto the campaign row, recomputed over the campaign's whole life.
     *
     * These used to be written from the day being rolled up, which put a single day's sessions,
     * orders and revenue next to a `clicks` counter that accumulates for life — so the campaigns
     * screen divided a lifetime numerator by a one-day denominator and called the result a
     * conversion rate. Worse, a campaign with no sessions that day was not in the day's result set
     * at all, so it silently kept whatever numbers the last day it appeared in had left behind.
     *
     * Every campaign is written, including the ones with nothing: a campaign that has never
     * converted has to read as zero rather than as last week.
     */
    private function refreshCampaignTotals(): void
    {
        $totals = $this->realSessions(Carbon::createFromTimestamp(0), Carbon::now())
            ->whereNotNull('campaign_id')
            ->selectRaw('campaign_id, COUNT(*) sessions, SUM(orders) orders, COALESCE(SUM(revenue), 0) revenue')
            ->groupBy('campaign_id')
            ->get()
            ->keyBy('campaign_id');

        $now = Carbon::now();

        foreach ($this->connection()->table('analytics_campaigns')->pluck('id') as $campaignId) {
            $row = $totals->get($campaignId);

            $this->connection()->table('analytics_campaigns')->where('id', $campaignId)->update([
                'sessions' => (int) ($row->sessions ?? 0),
                'orders' => (int) ($row->orders ?? 0),
                'revenue' => (float) ($row->revenue ?? 0),
                'updated_at' => $now,
            ]);
        }
    }

    // -------------------------------------------------------------------------------------------

    /** Sessions that belong in a merchant's reports: people, not crawlers and not staff. */
    private function realSessions(Carbon $from, Carbon $to): \Illuminate\Database\Query\Builder
    {
        $query = $this->connection()->table('analytics_sessions')->whereBetween('started_at', [$from, $to]);

        if (app(AnalyticsPolicy::class)->excludeBots()) {
            $query->where('is_bot', false);
        }

        if (app(AnalyticsPolicy::class)->excludeInternal()) {
            $query->where('is_internal', false);
        }

        return $query;
    }

    private function realEvents(Carbon $from, Carbon $to): \Illuminate\Database\Query\Builder
    {
        $query = $this->connection()->table('analytics_events')->whereBetween('occurred_at', [$from, $to]);

        if (app(AnalyticsPolicy::class)->excludeBots()) {
            $query->where('is_bot', false);
        }

        if (app(AnalyticsPolicy::class)->excludeInternal()) {
            $query->where('is_internal', false);
        }

        return $query;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     */
    /**
     * Write the top keys, and fold everything below them into one `__other__` row.
     *
     * config/analytics.php has always promised this — "the rest are folded into __other__ and the
     * fold is reported rather than hidden" — and the rollup applied a LIMIT and wrote no such row,
     * so on any dimension with a long tail the day's total silently did not add up. A dimension
     * table whose rows sum to less than the total, with nothing saying why, is worse than a
     * truncated list: every percentage computed from it is wrong.
     */
    private function putMany(Carbon $day, string $dimension, $rows): int
    {
        $rows = collect($rows);
        $kept = $rows->take($this->cap());
        $folded = $rows->slice($this->cap());

        $written = 0;
        $keys = [];

        foreach ($kept as $row) {
            $values = (array) $row;
            $key = (string) ($values['dimension_key'] ?? '');
            unset($values['dimension_key']);

            if ($key === '') {
                continue;
            }

            $keys[] = mb_substr($key, 0, 191);
            $written += $this->put($day, $dimension, $key, $values);
        }

        if ($folded->isNotEmpty()) {
            $keys[] = self::OTHER_KEY;
            $written += $this->put($day, $dimension, self::OTHER_KEY, $this->foldTail($folded));
        }

        // A rebuild has to remove as well as write. Each dimension is capped at a top-N, so a key
        // that was in yesterday's cut and is not in today's kept its old row for ever — a search
        // term or a product that stopped appearing stayed in the table with the figures it had the
        // last time it made the list, and the day stopped adding up.
        $this->connection()->table('analytics_daily')
            ->where('date', $day->toDateString())
            ->where('dimension', $dimension)
            ->when($keys !== [], fn ($query) => $query->whereNotIn('dimension_key', $keys))
            ->delete();

        return $written;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    /**
     * The tail as one row.
     *
     * Only the additive measures are summed. `visitors` and `new_visitors` are COUNT(DISTINCT ...)
     * per key, and adding those across keys counts the same person once per key they appear under —
     * so they are left null, which renders as "—". An honest dash beats a confident overstatement
     * on a page whose whole purpose is to be believed.
     *
     * @param  \Illuminate\Support\Collection  $folded
     * @return array<string, mixed>
     */
    private function foldTail($folded): array
    {
        $summed = ['revenue' => 0.0];

        foreach (self::ADDITIVE_MEASURES as $measure) {
            $summed[$measure] = 0;
        }

        foreach ($folded as $row) {
            $values = (array) $row;

            foreach (self::ADDITIVE_MEASURES as $measure) {
                $summed[$measure] += (int) ($values[$measure] ?? 0);
            }

            $summed['revenue'] += (float) ($values['revenue'] ?? 0);
        }

        return $summed + ['visitors' => null, 'new_visitors' => null];
    }

    private function put(Carbon $day, string $dimension, string $key, array $values): int
    {
        $row = ['computed_at' => Carbon::now()];

        foreach ([
            'sessions', 'visitors', 'new_visitors', 'pageviews', 'events', 'bounces',
            'engaged_sessions', 'duration_seconds', 'cart_adds', 'checkouts', 'orders',
        ] as $column) {
            // Null survives for the two distinct counts only, and only when the caller passed it
            // deliberately. The folded __other__ row cannot sum COUNT(DISTINCT visitor_id) across
            // keys, and casting that to 0 would print a confident zero where the truthful answer is
            // "not countable from here". Every other measure is additive and a missing one is 0.
            $row[$column] = in_array($column, self::UNSUMMABLE_MEASURES, true) && ($values[$column] ?? 0) === null
                ? null
                : (int) ($values[$column] ?? 0);
        }

        $row['revenue'] = round((float) ($values['revenue'] ?? 0), 2);

        // Replaced rather than added to: a rollup is a REBUILD of the day from its source rows, so
        // running it twice for the same day — which the hourly schedule does all day long — must
        // produce the same numbers rather than doubling them.
        $this->connection()->table('analytics_daily')->updateOrInsert(
            ['date' => $day->toDateString(), 'dimension' => $dimension, 'dimension_key' => mb_substr($key, 0, 191)],
            $row,
        );

        return 1;
    }

    private function prune(): int
    {
        // Through the policy, so a retention window changed on the Analytics settings page is the
        // window this prune actually applies — the page promised that and read config().
        $policy = app(AnalyticsPolicy::class);
        $retention = [
            'event_days' => $policy->retentionDays('event_days'),
            'session_days' => $policy->retentionDays('session_days'),
            'daily_days' => $policy->retentionDays('daily_days'),
        ] + (array) config('analytics.retention', []);
        $deleted = 0;

        $deleted += $this->deleteInChunks('analytics_events', 'occurred_at', (int) ($retention['event_days'] ?? 90));
        $deleted += $this->deleteInChunks('analytics_sessions', 'started_at', (int) ($retention['session_days'] ?? 400));
        $deleted += $this->deleteInChunks('analytics_campaign_clicks', 'clicked_at', (int) ($retention['click_days'] ?? 400));
        $deleted += $this->deleteInChunks('analytics_daily', 'date', (int) ($retention['daily_days'] ?? 1100));

        return $deleted;
    }

    private function deleteInChunks(string $table, string $column, int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $cutoff = Carbon::now()->subDays($days);
        $deleted = 0;

        // Chunked: one DELETE covering months of rows locks the table for as long as it takes, and
        // this runs on the same database the shop is serving from.
        do {
            $removed = $this->connection()->table($table)->where($column, '<', $cutoff)->limit(2000)->delete();
            $deleted += $removed;
        } while ($removed > 0);

        return $deleted;
    }

    /** So the Data quality screen can say when the rollups last ran, and notice when they stop. */
    private function recordRun(): void
    {
        $now = Carbon::now();

        $updated = $this->connection()->table('analytics_health')->where('signal', 'rollup_ran')->update([
            'count' => DB::raw('`count` + 1'),
            'occurred_at' => $now,
            'updated_at' => $now,
        ]);

        if ($updated === 0) {
            $this->connection()->table('analytics_health')->insertOrIgnore([
                'signal' => 'rollup_ran',
                'count' => 1,
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function cap(): int
    {
        return max(50, (int) config('analytics.max_keys_per_dimension', 500));
    }

    /**
     * How many grouped rows a dimension may return before the fold stops being exact.
     *
     * The rollup reads past the cap so the tail can be summed rather than dropped. This is the
     * safety ceiling on that read — a grouped aggregate, so it is distinct keys per day rather than
     * raw rows, and a shop that exceeds it has a cardinality problem the normalisation is supposed
     * to prevent.
     */
    private function readCeiling(): int
    {
        return max($this->cap() * 100, 50000);
    }

    private function connection(): \Illuminate\Database\Connection
    {
        return DB::connection(config('analytics.connection'));
    }
}
