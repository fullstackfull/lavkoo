<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Collectors\CollectorRegistry;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The database, answered from three directions — because "the database is slow" is three different
 * complaints and they have three different fixes.
 *
 * The server's own counters say whether MySQL itself is in trouble: out of connections, missing the
 * buffer pool, waiting on row locks. The slow-query ledger says which individual statement is
 * expensive enough to be worth an index. And the request buckets say which ROUTE is spending the
 * database's time — which is the only one of the three that finds an N+1, because an N+1 is a
 * couple of hundred perfectly fast queries and every per-statement view calls each one healthy.
 *
 * This shop has exactly that, and the storefront home is the route it lives on. It cannot appear in
 * the slow-query ledger by construction — no single statement is slow — and it is the first row of
 * the query-heavy table. Both are on this page so the absence in one explains the other.
 *
 * Nothing here is inferred from anything else. Where the login may not read a counter — this
 * deployment has performance_schema OFF and no PROCESS privilege — the metric arrives from the
 * collector already carrying its state and the exact grant or setting that would make it real, and
 * it is rendered that way rather than as a zero.
 */
class DatabasePanel implements Panel
{
    /**
     * Collector readings grouped the way somebody diagnoses a database, not the order the server
     * publishes them.
     *
     * @var array<string, array{why: string, metrics: list<string>}>
     */
    private const GROUPS = [
        'connection' => [
            'why' => 'whether_the_shop_can_reach_the_server_and_how_close_it_is_to_running_out_of_connections',
            'metrics' => [
                'flavour', 'version', 'database', 'uptime_seconds', 'latency_ms',
                'threads_connected', 'threads_running', 'max_connections',
                'connection_utilisation_pct', 'max_used_connections',
                'aborted_connects', 'aborted_clients',
                'processlist_count', 'long_running_queries',
            ],
        ],
        'throughput' => [
            'why' => 'rates_are_the_change_between_two_samples_never_a_lifetime_counter_divided_by_uptime',
            'metrics' => [
                'queries_per_s', 'selects_per_s', 'inserts_per_s', 'updates_per_s', 'deletes_per_s',
                'transactions_per_s', 'commits', 'rollbacks', 'slow_queries',
                'tmp_tables_created', 'tmp_disk_tables_created', 'tmp_disk_table_ratio_pct',
                'slow_query_log',
            ],
        ],
        'innodb_buffer_pool' => [
            'why' => 'the_cache_the_whole_server_depends_on_a_falling_hit_ratio_turns_every_query_into_disk_io',
            'metrics' => [
                'buffer_pool_hit_ratio', 'buffer_pool_size_mb', 'buffer_pool_pages_data',
                'buffer_pool_pages_free', 'buffer_pool_pages_dirty',
            ],
        ],
        'locks' => [
            'why' => 'contention_shows_up_as_waiting_not_as_slowness_so_it_is_measured_separately',
            'metrics' => [
                'row_lock_waits', 'row_lock_time_avg_ms', 'deadlocks',
                'innodb_engine_status', 'innodb_metrics',
            ],
        ],
        'storage' => [
            'why' => 'size_on_disk_and_the_table_handles_the_server_is_holding_open',
            'metrics' => [
                'size_mb', 'index_size_mb', 'row_estimate', 'table_count',
                'table_open_cache', 'open_tables',
            ],
        ],
    ];

    /** Rows per table. Two slow-query orderings and two route orderings is already a long page. */
    private const TABLE_ROWS = 15;

    /**
     * Routes read per ordering, per bucket resolution, before the sources are added together.
     *
     * Wider than the table so a route that sits mid-table in the folded buckets and mid-table again
     * in the minutes since the last fold still ranks correctly once the two are summed, and narrow
     * enough that every one of these queries stays a bounded index read.
     */
    private const ROUTE_CANDIDATES = 60;

    /**
     * Above this many statements for one request, the route is loading rows in a loop.
     *
     * A display threshold, not a measurement: it decides whether a row is drawn with a warning
     * pill, never what the row says. It is stated on the page so the reader can disagree with it.
     */
    private const QUERIES_PER_REQUEST_SUSPECT = 30;

    private const REQUEST_SOURCE = 'monitoring_request_buckets';

    private const SLOW_QUERY_SOURCE = 'monitoring_slow_queries';

    public function __construct(
        private readonly CollectorRegistry $collectors,
        private readonly SeriesReader $reader,
    ) {
    }

    public function data(string $range, Request $request): array
    {
        $window = $this->reader->window($range);
        $readings = $this->collectors->collect('db');

        // Slow queries are only ever written at hour resolution, so a five-minute window that asked
        // for its own left edge would step over the bucket it lives in and report an empty ledger
        // on a server that is recording perfectly.
        $slowQueriesFrom = $this->reader->since($range)->copy()->startOfHour();

        return [
            'window' => [
                'range' => $range,
                'minutes' => $window['minutes'],
                'resolution' => $window['resolution'],
                'since' => Clock::display($this->reader->since($range))->toDateTimeString(),
                'until' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
            ],
            'server' => $this->serverState($readings),
            'groups' => $this->groups($readings),
            'largest_tables' => $this->largestTables($readings),
            'query_digests' => $this->queryDigests($readings),
            'charts' => $this->charts($range, $window['resolution']),
            'slow_queries' => $this->slowQueries($slowQueriesFrom),
            'query_heavy_routes' => $this->queryHeavyRoutes($range, $window['resolution']),
            'queries_per_request_suspect' => self::QUERIES_PER_REQUEST_SUSPECT,
        ];
    }

    /**
     * Whether the collector answered at all.
     *
     * Said once, at the top, because a collector that could not reach the server produces forty
     * identical unavailable rows underneath, and forty copies of one fact reads as forty faults.
     *
     * @param  array<string, Metric>  $readings
     * @return array<string, mixed>
     */
    private function serverState(array $readings): array
    {
        if ($readings === []) {
            return [
                'state' => 'not_supported',
                'note' => 'The database collector is not installed in this build, so none of the server counters below can be read.',
                'remedy' => null,
            ];
        }

        $failure = $readings['__collector'] ?? null;
        if ($failure instanceof Metric) {
            return ['state' => 'failed', 'note' => $failure->note, 'remedy' => null];
        }

        $identity = $readings['database'] ?? null;
        if (!$identity instanceof Metric || !$identity->isOk()) {
            return [
                'state' => $identity instanceof Metric ? $identity->state : 'no_data',
                'note' => $identity instanceof Metric ? $identity->note : 'The collector returned no reading for the connected database.',
                'remedy' => $identity instanceof Metric ? $identity->remedy : null,
            ];
        }

        return ['state' => 'ok', 'note' => null, 'remedy' => null];
    }

    /**
     * The five cards, each holding only readings safe to draw as a value.
     *
     * @param  array<string, Metric>  $readings
     * @return array<int, array<string, mixed>>
     */
    private function groups(array $readings): array
    {
        $groups = [];

        foreach (self::GROUPS as $key => $definition) {
            $metrics = [];

            foreach ($definition['metrics'] as $name) {
                $metric = $readings[$name] ?? null;
                if (!$metric instanceof Metric) {
                    continue;
                }

                // An unavailable reading goes in whole: its state and its remedy are the content.
                if (!$metric->isOk()) {
                    $metrics[$name] = $metric;
                    continue;
                }

                if (is_scalar($metric->value)) {
                    $metrics[$name] = $metric;
                    continue;
                }

                // A reading the server answers as a bundle — the parsed engine report, the enabled
                // innodb_metrics counters — is split into its parts rather than dropped, keeping
                // each part's provenance. Anything not a plain value is left out entirely: there is
                // no honest one-line rendering of it.
                foreach ($this->expand($metric) as $part => $reading) {
                    $metrics[$part] = $reading;
                }
            }

            if ($metrics === []) {
                continue;
            }

            $groups[] = ['key' => $key, 'why' => $definition['why'], 'metrics' => $metrics];
        }

        return $groups;
    }

    /**
     * One composite reading as its individual parts, each keeping the source it came from.
     *
     * @return array<string, Metric>
     */
    private function expand(Metric $metric): array
    {
        $parts = [];

        foreach ((array) $metric->value as $name => $value) {
            if ($value !== null && !is_scalar($value)) {
                continue;
            }

            // A part the server did not print arrives as null, and Metric::of turns that into
            // "no data" rather than into a zero.
            $parts[(string) $name] = Metric::of(
                value: $value,
                source: $metric->source,
                unit: null,
                note: $metric->note,
            );
        }

        return $parts;
    }

    /**
     * The ten biggest tables, as a table rather than as a metric.
     *
     * @param  array<string, Metric>  $readings
     * @return array<string, mixed>
     */
    private function largestTables(array $readings): array
    {
        $metric = $readings['largest_tables'] ?? null;
        $unavailable = $this->unavailableBlock($metric, 'The catalogue was not read on this deployment.');
        if ($unavailable !== null) {
            return $unavailable;
        }

        $rows = [];
        foreach ((array) $metric->value as $table) {
            $table = (array) $table;
            $data = $this->reading($table['data_mb'] ?? null);
            $index = $this->reading($table['index_mb'] ?? null);

            $rows[] = [
                'table' => $this->name($table['table'] ?? null),
                'data_mb' => $data,
                'index_mb' => $index,
                // A total needs both halves. Adding a missing one in as zero would publish a table
                // as smaller than it is, which is the reading somebody sizes a disk against.
                'total_mb' => $data === null || $index === null ? null : round($data + $index, 2),
                'rows' => $this->reading($table['rows'] ?? null) === null ? null : (int) $table['rows'],
            ];
        }

        return ['state' => 'ok', 'note' => $metric->note, 'remedy' => null, 'source' => $metric->source, 'rows' => $rows];
    }

    /**
     * The server's own per-statement summary, which needs performance_schema switched on AND
     * granted — and on this deployment is neither.
     *
     * It is still asked for, and still shown, because the remedy is the deliverable: an operator
     * who turns it on gets the one view the application's own ledger cannot produce, namely every
     * statement rather than only the ones over the slow threshold.
     *
     * @param  array<string, Metric>  $readings
     * @return array<string, mixed>
     */
    private function queryDigests(array $readings): array
    {
        $metric = $readings['query_digests'] ?? null;
        $unavailable = $this->unavailableBlock($metric, 'No per-statement summary was read from this server.');
        if ($unavailable !== null) {
            return $unavailable;
        }

        $rows = [];
        foreach ((array) $metric->value as $digest) {
            $digest = (array) $digest;
            $rows[] = [
                'statement' => $this->name($digest['statement'] ?? null),
                'calls' => $this->reading($digest['calls'] ?? null) === null ? null : (int) $digest['calls'],
                'avg_ms' => $this->reading($digest['avg_ms'] ?? null),
                'rows_examined' => $this->reading($digest['rows_examined'] ?? null) === null ? null : (int) $digest['rows_examined'],
            ];
        }

        return ['state' => 'ok', 'note' => $metric->note, 'remedy' => null, 'source' => $metric->source, 'rows' => $rows];
    }

    /**
     * One cell of a table row, or null where the server did not publish that part of it.
     *
     * The zero this replaces is the whole reason this system exists: a column the catalogue did not
     * answer for renders as "0 MB" or "0 calls", which reads as a measurement of nothing rather than
     * as nothing measured. The view draws null as a dash.
     */
    private function reading(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /** The same rule for the label a row is named by: an empty name is not a name. */
    private function name(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The block a table renders when its metric is not a readable list.
     *
     * Returns null when the metric IS readable, so the caller carries on with the rows.
     *
     * @return array<string, mixed>|null
     */
    private function unavailableBlock(?Metric $metric, string $missingNote): ?array
    {
        if (!$metric instanceof Metric) {
            return ['state' => 'no_data', 'note' => $missingNote, 'remedy' => null, 'source' => null, 'rows' => []];
        }

        if (!$metric->isOk() || !is_array($metric->value)) {
            return [
                'state' => $metric->isOk() ? 'no_data' : $metric->state,
                'note' => $metric->note ?? $missingNote,
                'remedy' => $metric->remedy,
                'source' => $metric->source,
                'rows' => [],
            ];
        }

        return null;
    }

    /**
     * The two stored gauges worth a line: how long a round trip takes, and how much work the server
     * is being asked to do.
     *
     * @return array<string, array<string, mixed>>
     */
    private function charts(string $range, string $resolution): array
    {
        return [
            'latency_ms' => $this->chart('db.latency_ms', $range, $resolution, 'ms'),
            'queries_per_s' => $this->chart('db.queries_per_s', $range, $resolution, 'queries/s'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function chart(string $metric, string $range, string $resolution, string $unit): array
    {
        try {
            $series = $this->reader->series($metric, $range);
        } catch (\Throwable $exception) {
            return ['state' => 'failed', 'note' => $this->failureNote($exception), 'points' => [], 'metric' => $metric, 'unit' => $unit];
        }

        $points = array_values(array_filter(
            $series['points'],
            static fn (array $point) => ($point['v'] ?? null) !== null,
        ));

        $base = ['metric' => $metric, 'unit' => $unit, 'latest' => $series['latest'], 'points' => $points];

        // One point is a reading; a line needs two. Saying which of those it is stops a single
        // sample being read as a flat trend.
        if (count($points) < 2) {
            return array_merge($base, $this->gaugeGap($resolution, count($points)));
        }

        return array_merge($base, ['state' => 'ok', 'note' => null, 'remedy' => null]);
    }

    /**
     * Why a gauge has no line.
     *
     * Three different silences with three different answers, and the flat empty chart they all draw
     * looks identical.
     *
     * @return array{state: string, note: string, remedy: string|null}
     */
    private function gaugeGap(string $resolution, int $points): array
    {
        if (!config('monitoring.enabled', true)) {
            return [
                'state' => 'not_configured',
                'note' => 'Monitoring collection is switched off, so no gauge has been sampled since it was disabled.',
                'remedy' => 'Set MONITORING_ENABLED=true in .env, then run `php artisan optimize:clear`.',
            ];
        }

        // Checked before the resolution, because one stored point is a different fact from an
        // empty rollup and saying the wrong one sends somebody to fix a job that is working.
        if ($points === 1) {
            return [
                'state' => 'no_data',
                'note' => 'Only one sample of this gauge falls inside this window, and one point is not a line.',
                'remedy' => $resolution === 'minute'
                    ? 'Wait for the next minute sample, or choose a longer range.'
                    : 'Choose a shorter range to read the minute samples directly.',
            ];
        }

        if ($resolution !== 'minute') {
            // Gauges are written once a minute. Longer ranges read rolled-up rows, which the rollup
            // produces — so this window can be empty while the minute rows under it are full.
            return [
                'state' => 'no_data',
                'note' => 'This range reads ' . $resolution . ' rows, which the monitoring rollup builds from the minute samples rather than the sampler writing directly.',
                'remedy' => 'Choose a shorter range to read the minute samples, or check the hourly monitoring rollup is running: `php artisan schedule:list`.',
            ];
        }

        return [
            'state' => 'no_data',
            'note' => 'No sample of this gauge has been stored in this window.',
            'remedy' => 'Gauges are sampled by `php artisan monitoring:sample`, scheduled every minute. Check the Laravel scheduler is running: `php artisan schedule:list`.',
        ];
    }

    /**
     * The slow-query ledger, in the two orders that answer different questions.
     *
     * By total time is what to fix; by executions is what is being called in a loop. A statement can
     * top one list and be absent from the other, which is the whole reason both are here.
     *
     * @return array<string, mixed>
     */
    private function slowQueries(\Illuminate\Support\Carbon $from): array
    {
        $threshold = (int) config('monitoring.tracing.slow_query_ms', 200);
        $covers = Clock::display($from)->toDateTimeString();

        try {
            $byTotalMs = $this->slowQueryRows($from, 'total_ms');
            $byExecutions = $this->slowQueryRows($from, 'executions');
        } catch (\Throwable $exception) {
            // PanelRegistry would catch this as well, but it can only blank the entire section.
            // Failing this part by name leaves the server counters and the route tables readable.
            return [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'remedy' => null,
                'threshold_ms' => $threshold,
                'covers_from' => $covers,
                'by_total_ms' => [],
                'by_executions' => [],
            ];
        }

        if ($byTotalMs === []) {
            return [
                // Zero rows here is a reading, not a gap: it says no single statement crossed the
                // threshold. It is also exactly what an N+1 looks like from this table, which is
                // why the query-heavy routes below are on the same page.
                'state' => 'no_data',
                'note' => 'No single statement has taken ' . $threshold . ' ms or more since ' . $covers . '. That is a measurement, not a missing one — a route issuing a hundred fast queries never appears here.',
                'remedy' => 'To record more of them, lower MONITORING_SLOW_QUERY_MS in .env (currently ' . $threshold . ') and run `php artisan optimize:clear`.',
                'threshold_ms' => $threshold,
                'covers_from' => $covers,
                'by_total_ms' => [],
                'by_executions' => [],
            ];
        }

        return [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'threshold_ms' => $threshold,
            'covers_from' => $covers,
            'by_total_ms' => $byTotalMs,
            'by_executions' => $byExecutions,
        ];
    }

    /**
     * One ordering of the ledger, folded across the hour buckets it spans.
     *
     * @param  string  $order  total_ms | executions
     * @return array<int, array<string, mixed>>
     */
    private function slowQueryRows(\Illuminate\Support\Carbon $from, string $order): array
    {
        $rows = $this->reader->connection()->table(self::SLOW_QUERY_SOURCE)
            ->where('resolution', 'hour')
            ->where('bucket_at', '>=', $from)
            ->groupBy('fingerprint')
            ->orderByDesc($order)
            ->limit(self::TABLE_ROWS)
            ->get([
                'fingerprint',
                DB::raw('MAX(sql_normalised) as sql_normalised'),
                DB::raw('MAX(primary_table) as primary_table'),
                DB::raw('SUM(executions) as executions'),
                DB::raw('SUM(total_ms) as total_ms'),
                DB::raw('MAX(max_ms) as max_ms'),
            ]);

        if ($rows->isEmpty()) {
            return [];
        }

        $routes = $this->slowQueryRoutes($from, $rows->pluck('fingerprint')->all());

        return $rows->map(static function ($row) use ($routes) {
            $executions = (int) $row->executions;

            return [
                'fingerprint' => (string) $row->fingerprint,
                'sql' => (string) $row->sql_normalised,
                'table' => $row->primary_table,
                'executions' => $executions,
                'total_ms' => (int) $row->total_ms,
                // Averaging is only defined where something was executed; the unique key guarantees
                // it, so this guard protects against a hand-edited row rather than a real division.
                'avg_ms' => $executions > 0 ? round((int) $row->total_ms / $executions, 1) : null,
                'max_ms' => (int) $row->max_ms,
                // rows_examined_sum is deliberately absent: the application's own recorder cannot
                // read how many rows a statement touched, so it stores 0 on every row, and a
                // published "0 rows examined" is a measurement nobody took.
                'route' => $routes[(string) $row->fingerprint] ?? null,
            ];
        })->all();
    }

    /**
     * The route recorded against each statement most often.
     *
     * One bounded query for every fingerprint on the page rather than one per row. The recorder
     * keeps the latest caller per hourly bucket, so this is where to start looking, not a claim
     * that nothing else runs the statement — and the view says so.
     *
     * @param  list<string>  $fingerprints
     * @return array<string, string>
     */
    private function slowQueryRoutes(\Illuminate\Support\Carbon $from, array $fingerprints): array
    {
        if ($fingerprints === []) {
            return [];
        }

        $rows = $this->reader->connection()->table(self::SLOW_QUERY_SOURCE)
            ->where('resolution', 'hour')
            ->where('bucket_at', '>=', $from)
            ->whereIn('fingerprint', $fingerprints)
            ->whereNotNull('route')
            ->groupBy('fingerprint', 'route')
            ->orderByDesc('executions')
            ->limit(self::TABLE_ROWS * 8)
            ->get(['fingerprint', 'route', DB::raw('SUM(executions) as executions')]);

        $best = [];
        foreach ($rows as $row) {
            // Already ordered by weight, so the first sighting of a fingerprint is its top route.
            $best[(string) $row->fingerprint] ??= (string) $row->route;
        }

        return $best;
    }

    /**
     * Which route is spending the database's time, and which one is talking to it too often.
     *
     * The second table is the N+1 detector, and it is the reason this section is not just the
     * slow-query ledger: a route issuing a hundred and forty-five perfectly fast statements has no
     * slow query to find, and every per-statement view on this page calls it healthy.
     *
     * @return array<string, mixed>
     */
    private function queryHeavyRoutes(string $range, string $resolution): array
    {
        try {
            $routes = $this->routeTotals($range, $resolution);
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'remedy' => null,
                'by_db_time' => [],
                'by_queries_per_request' => [],
            ];
        }

        if ($routes === []) {
            return array_merge(
                ['by_db_time' => [], 'by_queries_per_request' => []],
                $this->requestGap($resolution),
            );
        }

        return [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'by_db_time' => $this->rankRoutes($routes, 'db_ms_total'),
            'by_queries_per_request' => $this->rankRoutes($routes, 'queries_per_request'),
        ];
    }

    /**
     * Every route in the window, summed across every bucket that covers it.
     *
     * @return array<string, array<string, int|string>>  raw totals keyed by channel, method and route
     */
    private function routeTotals(string $range, string $resolution): array
    {
        $since = $this->reader->since($range);
        $totals = [];

        /*
         * One pass per resolution, coarsest first, each covering exactly the span the next one
         * cannot see yet.
         *
         * The rollup folds the parent that is still in progress — a run at 10:03 writes an hour
         * bucket holding three minutes of it — so a boundary at the END of the newest folded parent
         * loses everything between the run and the end of that hour. On this installation that was
         * 17% of a day's requests, missing from both sides of the seam. Each level therefore stops
         * where the next one starts: the newest folded parent is skipped and read from its own
         * finer buckets instead. A bucket belongs to exactly one pass, so nothing is counted twice.
         */
        $before = null;

        foreach ($this->resolutionChain($resolution) as $level) {
            $seam = $level === 'minute' ? null : $this->unfoldedFrom($level, $since);

            foreach ($this->routeCandidates($level, $before ?? $since, $seam) as $key => $route) {
                foreach (['hits', 'db_ms_total', 'db_query_total', 'duration_total'] as $field) {
                    $route[$field] += (int) ($totals[$key][$field] ?? 0);
                }
                $totals[$key] = $route;
            }

            if ($seam === null) {
                break;
            }

            $before = $seam;
        }

        return $totals;
    }

    /**
     * This range's own resolution, then every finer one down to minutes.
     *
     * @return array<int, string>
     */
    private function resolutionChain(string $resolution): array
    {
        return match ($resolution) {
            'day' => ['day', 'hour', 'minute'],
            'hour' => ['hour', 'minute'],
            default => ['minute'],
        };
    }

    /**
     * The first moment the rollup has not folded into the given resolution yet.
     */
    private function unfoldedFrom(string $resolution, \Illuminate\Support\Carbon $since): \Illuminate\Support\Carbon
    {
        $newestFolded = $this->reader->connection()->table(self::REQUEST_SOURCE)
            ->where('resolution', $resolution)
            ->max('bucket_at');

        return $newestFolded !== null ? Clock::parse($newestFolded)->max($since) : $since;
    }

    /**
     * The routes worth ranking at one resolution, read in both orders.
     *
     * Two bounded queries rather than one: the ranking that finds an N+1 must not be capped by the
     * ranking that finds the expensive route, or a cheap endpoint issuing two hundred statements
     * would never be read at all.
     *
     * @return array<string, array<string, int|string>>
     */
    private function routeCandidates(string $resolution, \Illuminate\Support\Carbon $from, ?\Illuminate\Support\Carbon $before = null): array
    {
        $candidates = [];

        foreach (['db_ms_total', 'queries_per_request'] as $order) {
            $query = $this->reader->connection()->table(self::REQUEST_SOURCE)
                ->where('resolution', $resolution)
                ->where('bucket_at', '>=', $from)
                // The in-progress parent is read from its own minutes instead, so it is excluded
                // here rather than counted twice.
                ->when($before !== null, fn ($inner) => $inner->where('bucket_at', '<', $before))
                ->groupBy('channel', 'method', 'route')
                ->havingRaw('SUM(hits) > 0')
                ->limit(self::ROUTE_CANDIDATES);

            // Per request, not in total: the ranking that finds an N+1 is the one a busy homepage
            // cannot win simply by being busy.
            if ($order === 'queries_per_request') {
                $query->orderByRaw('SUM(db_query_count) / SUM(hits) DESC');
            } else {
                $query->orderByDesc('db_ms_total');
            }

            $rows = $query->get([
                'channel', 'method', 'route',
                DB::raw('SUM(hits) as hits'),
                DB::raw('SUM(db_ms_sum) as db_ms_total'),
                DB::raw('SUM(db_query_count) as db_query_total'),
                DB::raw('SUM(duration_sum_ms) as duration_total'),
            ]);

            foreach ($rows as $row) {
                // The two orderings return the same totals for a route they both name, so a second
                // sighting replaces the first rather than being added to it.
                $candidates[$row->channel . ' ' . $row->method . ' ' . $row->route] = [
                    'channel' => (string) $row->channel,
                    'method' => (string) $row->method,
                    'route' => (string) $row->route,
                    'hits' => (int) $row->hits,
                    'db_ms_total' => (int) $row->db_ms_total,
                    'db_query_total' => (int) $row->db_query_total,
                    'duration_total' => (int) $row->duration_total,
                ];
            }
        }

        return $candidates;
    }

    /**
     * One ranking of the merged totals, cut to the rows the table shows.
     *
     * @param  array<string, array<string, int|string>>  $routes
     * @param  string  $order  db_ms_total | queries_per_request
     * @return array<int, array<string, mixed>>
     */
    private function rankRoutes(array $routes, string $order): array
    {
        $rows = array_map(fn (array $route) => $this->routeRow($route), array_values($routes));

        usort($rows, static fn (array $first, array $second) => $second[$order] <=> $first[$order]);

        return array_slice($rows, 0, self::TABLE_ROWS);
    }

    /**
     * One route's totals as the table states them.
     *
     * Dividing is safe here and only here: every candidate came back through `SUM(hits) > 0`, so a
     * per-request figure always has requests behind it.
     *
     * @param  array<string, int|string>  $route
     * @return array<string, mixed>
     */
    private function routeRow(array $route): array
    {
        $hits = (int) $route['hits'];
        $dbMs = (int) $route['db_ms_total'];
        $queries = (int) $route['db_query_total'];
        $duration = (int) $route['duration_total'];
        $queriesPerRequest = round($queries / $hits, 1);

        return [
            'channel' => (string) $route['channel'],
            'method' => (string) $route['method'],
            'route' => (string) $route['route'],
            'hits' => $hits,
            'db_ms_total' => $dbMs,
            'db_ms_per_request' => round($dbMs / $hits, 1),
            'queries_total' => $queries,
            'queries_per_request' => $queriesPerRequest,
            'ms_per_query' => $queries > 0 ? round($dbMs / $queries, 2) : null,
            // Without a measured total duration there is no share to state, and a share of the
            // unknown is not zero.
            'db_share_pct' => $duration > 0 ? round(100 * $dbMs / $duration, 1) : null,
            'suspect' => $queriesPerRequest >= self::QUERIES_PER_REQUEST_SUSPECT,
        ];
    }

    /**
     * Why the route tables have nothing in them.
     *
     * @return array{state: string, note: string, remedy: string|null}
     */
    private function requestGap(string $resolution): array
    {
        if (!config('monitoring.enabled', true)) {
            return [
                'state' => 'not_configured',
                'note' => 'Monitoring collection is switched off, so no request has been bucketed since it was disabled.',
                'remedy' => 'Set MONITORING_ENABLED=true in .env, then run `php artisan optimize:clear`.',
            ];
        }

        if ($resolution !== 'minute') {
            // The tables read the folded rows AND the minutes since the last fold, so an empty
            // result no longer means "the rollup has not caught up"; it means neither source has a
            // request in this window. Minute rows are only kept for a week, though, which is the one
            // way a long range can look empty while the traffic did happen.
            return [
                'state' => 'no_data',
                'note' => 'Neither the ' . $resolution . ' buckets this range reads nor the minutes recorded since the last rollup hold a request in this window.',
                'remedy' => 'Check the monitoring rollup is building the ' . $resolution . ' rows: `php artisan schedule:list`. For a window inside the last week, a shorter range reads the minute buckets directly.',
            ];
        }

        return [
            'state' => 'no_data',
            'note' => 'No request was recorded in this window, so there is no route to attribute database time to.',
            'remedy' => null,
        ];
    }

    private function failureNote(\Throwable $exception): string
    {
        return Metric::describeFailure($exception);
    }
}
