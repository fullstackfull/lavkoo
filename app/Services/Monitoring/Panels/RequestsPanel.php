<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Http\Request;

/**
 * Request performance: how the shop is answering, and which route to open first.
 *
 * The section is built around one uncomfortable fact — "the worst route" has four different
 * answers, and three of them are traps. The slowest route by p95 is often an export nobody runs.
 * The busiest route is usually the homepage, which is already fast. The route with the most errors
 * may be a health check returning 404 to a scanner. The route that actually costs the shop time is
 * hits multiplied by average, and it is almost never at the top of the other three lists. So all
 * four rankings are shown side by side rather than one being picked as "the" answer.
 *
 * The four tables are four orderings of ONE read. Each is sorted over the complete set of routes
 * before it is truncated — the fifteen slowest are not a subset of the fifteen busiest, so sorting
 * an already-truncated list would silently hide exactly the rows these tables exist to surface —
 * but re-reading the window once per ranking would fold every bucket and merge every latency
 * histogram four times to answer four questions about the same rows.
 */
class RequestsPanel implements Panel
{
    /**
     * The channels a request can be filed under, in the order an operator reads them.
     *
     * Fixed rather than discovered: a channel missing from the data is a fact worth showing —
     * "the API served nothing this hour" is a finding, not an empty row to be omitted. The set
     * matches RequestRecorder::channelOf(), which is what makes the share column a share of
     * everything rather than of the part we happened to list.
     */
    private const CHANNELS = ['web', 'api', 'admin', 'vendor'];

    /** Rows per ranking. Four tables of fifteen is already more than anyone reads in one sitting. */
    private const BREAKDOWN_ROWS = 15;

    /**
     * Ranking is applied to every route in the window, not to a pre-truncated head.
     *
     * The reader builds the complete set either way — the limit only decides how much of it comes
     * back — so asking for all of it costs nothing and keeps each of the four orders honest.
     */
    private const ALL_ROUTES = PHP_INT_MAX;

    private const SOURCE = 'monitoring_request_buckets';

    public function __construct(private readonly SeriesReader $reader)
    {
    }

    public function data(string $range, Request $request): array
    {
        $window = $this->reader->window($range);
        $collection = $this->collectionState();
        $comparison = $this->comparison($range);
        $current = $comparison['current'] ?? [];
        $rankings = $this->rankings($range, $collection, $window, $current);

        return [
            'window' => [
                'range' => $range,
                'minutes' => $window['minutes'],
                'resolution' => $window['resolution'],
                'since' => Clock::display($this->reader->since($range))->toDateTimeString(),
                'until' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
            ],
            'collection' => $collection,
            'summary' => $this->summary($comparison, $collection, $window),
            'readings' => $this->readings($current),
            'timeline' => $this->timeline($range, $collection, $window),
            'coverage' => $rankings['coverage'],
            'channels' => $this->channels($range, $collection, $window),
            'breakdowns' => $rankings['tables'],
        ];
    }

    /**
     * Is anything still arriving?
     *
     * This is asked before anything else because the two failures underneath look identical on a
     * chart: a quiet hour and a stopped collector both draw a flat line at zero. Only one of them
     * is a problem, and only one of them has a remedy.
     *
     * @return array<string, mixed>
     */
    private function collectionState(): array
    {
        if (!config('monitoring.enabled', true)) {
            return [
                'state' => 'not_configured',
                'note' => 'Monitoring collection is switched off, so nothing has been recorded since it was disabled. The figures below are whatever was captured before that.',
                'remedy' => 'Set MONITORING_ENABLED=true in .env, then run `php artisan optimize:clear`.',
                'newest_bucket_at' => null,
                'age_seconds' => null,
            ];
        }

        $newest = $this->reader->newestBucketAt(self::SOURCE);

        if ($newest === null) {
            return [
                'state' => 'no_data',
                'note' => 'No request has ever been folded into a bucket on this deployment.',
                'remedy' => 'Requests are buffered per minute and written by `php artisan monitoring:flush`, scheduled every minute. Check the Laravel scheduler is actually running: `php artisan schedule:list`.',
                'newest_bucket_at' => null,
                'age_seconds' => null,
            ];
        }

        $age = (int) Clock::parse($newest)->diffInSeconds(Clock::now());
        $staleAfter = (int) config('monitoring.stale_after_seconds', 180);

        return [
            'state' => $age > $staleAfter ? 'stale' : 'ok',
            'note' => $age > $staleAfter
                ? 'The newest request bucket is ' . $age . ' seconds old, so the end of this window is not being measured.'
                : null,
            'remedy' => $age > $staleAfter
                ? 'Check `php artisan monitoring:flush` is still running every minute from cron.'
                : null,
            'newest_bucket_at' => Clock::display($newest)->toDateTimeString(),
            'age_seconds' => $age,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function comparison(string $range): array
    {
        try {
            return $this->reader->comparison($range);
        } catch (\Throwable $exception) {
            // PanelRegistry would catch this too, but it can only blank the whole section. Failing
            // one part by name leaves the other three rankings readable.
            return ['current' => [], 'previous' => [], 'delta' => [], 'failure' => $this->failureNote($exception)];
        }
    }

    /**
     * The window's headline figures, each against the same window before it.
     *
     * @return array<string, mixed>
     */
    private function summary(array $comparison, array $collection, array $window): array
    {
        if (isset($comparison['failure'])) {
            return ['state' => 'failed', 'note' => $comparison['failure'], 'current' => [], 'previous' => [], 'delta' => []];
        }

        $base = [
            'current' => $comparison['current'] ?? [],
            'previous' => $comparison['previous'] ?? [],
            'delta' => $comparison['delta'] ?? [],
            // A baseline is what turns a number into information. Without one the deltas stay
            // empty rather than being drawn as 0%, which reads as "nothing changed".
            'has_baseline' => (bool) ($comparison['previous']['has_data'] ?? false),
        ];

        if (!($comparison['current']['has_data'] ?? false)) {
            return array_merge($base, $this->emptyReason($collection, $window));
        }

        return array_merge($base, ['state' => 'ok', 'note' => null, 'remedy' => null]);
    }

    /**
     * The figures the four headline cards have no room for, as Metrics so the view can render
     * "not measured" without inventing a zero.
     *
     * @return array<string, Metric>
     */
    private function readings(array $current): array
    {
        $measured = (bool) ($current['has_data'] ?? false);
        $reading = static fn (string $key, ?string $unit = null): Metric => $measured
            ? Metric::of(value: $current[$key] ?? null, source: self::SOURCE, unit: $unit)
            : Metric::noData(source: self::SOURCE, note: 'No request was recorded in this window.');

        return [
            'requests_per_minute' => $reading('requests_per_minute', 'req/min'),
            'client_errors' => $reading('client_errors'),
            'client_error_rate' => $reading('client_error_rate', '%'),
            'timeouts' => $reading('timeouts'),
            'slowest_request' => $reading('max', 'ms'),
            'database_queries_per_request' => $reading('db_queries_avg'),
            'outbound_call_time_per_request' => $reading('external_ms_avg', 'ms'),
            'average_response_size' => $reading('response_bytes_avg', 'bytes'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function timeline(string $range, array $collection, array $window): array
    {
        try {
            $timeline = $this->reader->requestTimeline($range);
        } catch (\Throwable $exception) {
            return ['state' => 'failed', 'note' => $this->failureNote($exception), 'points' => [], 'resolution' => $window['resolution']];
        }

        if (($timeline['points'] ?? []) === []) {
            return array_merge($timeline, $this->emptyReason($collection, $window));
        }

        return array_merge($timeline, ['state' => 'ok', 'note' => null, 'remedy' => null]);
    }

    /**
     * Where the traffic came in: the storefront, the mobile API, the admin panel, a vendor.
     *
     * Worth splitting because the same p95 means different things on each. Half a second on an
     * admin report is nobody's emergency; half a second on the API is every mobile screen.
     *
     * @return array<string, mixed>
     */
    private function channels(string $range, array $collection, array $window): array
    {
        $rows = [];
        $total = 0;

        foreach (self::CHANNELS as $channel) {
            try {
                $summary = $this->reader->requestSummary($range, channel: $channel);
            } catch (\Throwable $exception) {
                $rows[] = ['channel' => $channel, 'state' => 'failed', 'note' => $this->failureNote($exception), 'summary' => null, 'share' => null];
                continue;
            }

            $total += (int) $summary['hits'];
            $rows[] = array_merge(
                [
                    'channel' => $channel,
                    'summary' => $summary,
                    'share' => null,
                    'severity' => $this->errorSeverity($summary['error_rate']),
                ],
                $summary['has_data']
                    ? ['state' => 'ok', 'note' => null, 'remedy' => null]
                    : $this->emptyReason($collection, $window, 'No request reached this channel in this window.'),
            );
        }

        // Share needs a denominator. With nothing measured anywhere the column stays empty rather
        // than printing four zeroes that would read as "all four channels served no traffic" —
        // true only if collection is healthy, which is a separate question answered above.
        foreach ($rows as $index => $row) {
            if ($total > 0 && ($row['summary']['has_data'] ?? false)) {
                $rows[$index]['share'] = round(100 * $row['summary']['hits'] / $total, 1);
            }
        }

        return ['total_hits' => $total, 'rows' => $rows];
    }

    /**
     * The four rankings, and how much of the window they can actually see.
     *
     * @return array{coverage: array<string, mixed>, tables: array<int, array<string, mixed>>}
     */
    private function rankings(string $range, array $collection, array $window, array $current): array
    {
        try {
            $routes = $this->reader->routeBreakdown($range, sort: 'hits', limit: self::ALL_ROUTES);
        } catch (\Throwable $exception) {
            $note = $this->failureNote($exception);

            return [
                // The failure belongs to the tables, which say it in their own empty state. A
                // coverage line has nothing to add here: there is no read to describe the reach of.
                'coverage' => ['state' => 'failed', 'note' => null, 'measured_hits' => null, 'window_hits' => null],
                'tables' => array_map(
                    static fn (array $definition): array => array_merge($definition, ['state' => 'failed', 'note' => $note, 'rows' => []]),
                    $this->definitions(),
                ),
            ];
        }

        return [
            'coverage' => $this->coverage($routes, $current, $window),
            'tables' => array_map(
                fn (array $definition): array => $this->table($definition, $routes, $collection, $window),
                $this->definitions(),
            ),
        ];
    }

    /**
     * What each table is ordered by, and the sentence saying why that order is worth reading.
     *
     * @return array<int, array<string, string>>
     */
    private function definitions(): array
    {
        return [
            [
                'key' => 'total_time',
                'sort' => 'total_time',
                'title' => 'where_the_time_actually_goes',
                'why' => 'hits_multiplied_by_average_a_40ms_endpoint_called_a_million_times_costs_the_shop_more_than_a_4s_one_called_twice',
            ],
            [
                'key' => 'p95',
                'sort' => 'p95',
                'title' => 'slowest_routes_by_p95',
                'why' => 'the_experience_of_the_unluckiest_one_in_twenty_requests_rather_than_the_average_nobody_actually_has',
            ],
            [
                'key' => 'errors',
                'sort' => 'errors',
                'title' => 'routes_failing_most_often',
                'why' => 'ranked_by_how_many_requests_failed_not_by_percentage_so_a_single_broken_call_does_not_outrank_a_thousand',
            ],
            [
                'key' => 'hits',
                'sort' => 'hits',
                'title' => 'most_requested_routes',
                'why' => 'what_the_shop_spends_its_day_answering_the_context_the_other_three_tables_are_read_against',
            ],
        ];
    }

    /**
     * One ranking: the whole set put in this table's order, then cut to what fits.
     *
     * @return array<string, mixed>
     */
    private function table(array $definition, array $routes, array $collection, array $window): array
    {
        if ($routes === []) {
            return array_merge($definition, $this->emptyReason($collection, $window), ['rows' => []]);
        }

        // A ranking needs something to rank. Fifteen rows of zeroes under "failing most often" is
        // an order the data does not contain, and it buries the one fact worth reading — that
        // nothing failed. This is a measured zero, not a gap, so it is said as good news.
        if ($definition['sort'] === 'errors' && array_sum(array_column($routes, 'errors')) === 0) {
            return array_merge($definition, [
                'state' => 'no_failures',
                'note' => 'No route returned a 5xx across the ' . number_format((int) array_sum(array_column($routes, 'hits')))
                    . ' requests measured on ' . count($routes) . ' routes in this window.',
                'remedy' => null,
                'rows' => [],
            ]);
        }

        return array_merge($definition, [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'rows' => array_map($this->routeRow(...), array_slice($this->rank($routes, $definition['sort']), 0, self::BREAKDOWN_ROWS)),
        ]);
    }

    /**
     * The orderings live here rather than in the reader because all four are views of one read:
     * the order a table wants is a way of looking at the set, not a reason to fetch it again.
     *
     * @param  array<int, array<string, mixed>>  $routes
     * @return array<int, array<string, mixed>>
     */
    private function rank(array $routes, string $sort): array
    {
        usort($routes, match ($sort) {
            'p95' => static fn (array $a, array $b) => ($b['p95'] ?? 0) <=> ($a['p95'] ?? 0),
            // Count first, rate as the tie-break: a route that failed nine times out of ten is
            // worse news than one that failed once out of one.
            'errors' => static fn (array $a, array $b) => [$b['errors'], $b['error_rate']] <=> [$a['errors'], $a['error_rate']],
            'total_time' => static fn (array $a, array $b) => $b['total_time_ms'] <=> $a['total_time_ms'],
            default => static fn (array $a, array $b) => $b['hits'] <=> $a['hits'],
        });

        return $routes;
    }

    /**
     * How much of the window the per-route tables and the chart can see.
     *
     * Both read one resolution. On a range longer than six hours that is the hour or day buckets
     * the rollup has folded, while the headline figures also read the minutes it has not reached
     * yet — so an hour after a busy morning the tables can be short of the headline by half the
     * window's traffic. Two totals on one screen that disagree by half, with nothing said, is how
     * a dashboard loses the only thing it has.
     *
     * @return array<string, mixed>
     */
    private function coverage(array $routes, array $current, array $window): array
    {
        $measuredHits = (int) array_sum(array_column($routes, 'hits'));
        $windowHits = ($current['has_data'] ?? false) ? (int) $current['hits'] : null;

        if ($routes === []) {
            return ['state' => 'no_data', 'measured_hits' => 0, 'window_hits' => $windowHits, 'note' => null];
        }

        if ($windowHits === null) {
            return ['state' => 'unknown', 'measured_hits' => $measuredHits, 'window_hits' => null, 'note' => null];
        }

        if ($measuredHits >= $windowHits) {
            return ['state' => 'complete', 'measured_hits' => $measuredHits, 'window_hits' => $windowHits, 'note' => null];
        }

        return [
            'state' => 'partial',
            'measured_hits' => $measuredHits,
            'window_hits' => $windowHits,
            'note' => 'The chart and the per-route tables read ' . $window['resolution'] . ' buckets, so they account for '
                . number_format($measuredHits) . ' of the ' . number_format($windowHits)
                . ' requests in this window. The remaining ' . number_format($windowHits - $measuredHits)
                . ' are still in minute buckets that the rollup has not folded into ' . $window['resolution'] . 's yet.',
        ];
    }

    /**
     * One table row, reduced to the columns the section shows.
     *
     * @return array<string, mixed>
     */
    private function routeRow(array $route): array
    {
        return [
            'route' => $route['route'],
            'method' => $route['method'],
            'channel' => $route['channel'],
            'hits' => $route['hits'],
            'avg' => $route['avg'],
            'p50' => $route['p50'],
            'p95' => $route['p95'],
            'p99' => $route['p99'],
            'errors' => $route['errors'],
            'error_rate' => $route['error_rate'],
            'db_ms_avg' => $route['db_ms_avg'],
            'total_time_ms' => $route['total_time_ms'],
            'severity' => $this->errorSeverity($route['error_rate']),
        ];
    }

    /**
     * How loudly a failure rate should be drawn, against the same thresholds the overview scores
     * against — so a route called amber here is not called green two clicks away.
     */
    private function errorSeverity(?float $errorRate): string
    {
        return match (true) {
            // Null is "not measured", which is not a quiet reading and must not be styled as one.
            $errorRate === null => 'info',
            $errorRate >= (float) config('monitoring.thresholds.error_rate_critical', 5.0) => 'critical',
            $errorRate >= (float) config('monitoring.thresholds.error_rate_warning', 1.0) => 'warning',
            default => 'ok',
        };
    }

    /**
     * Why a part of this page has nothing to show.
     *
     * Four different silences, and they are not interchangeable: collection is off, nothing has
     * ever been recorded, the collector has fallen behind, or this particular window really was
     * quiet. Only the last is a legitimate reading of zero traffic — the other three are gaps in
     * what we know, and dressing one of them up as "no requests" is the exact lie this system
     * exists to avoid.
     *
     * @return array{state: string, note: string, remedy: string|null}
     */
    private function emptyReason(array $collection, array $window, string $quietNote = 'No request was recorded in this window.'): array
    {
        $state = $collection['state'] ?? 'ok';

        if (in_array($state, ['not_configured', 'no_data'], true)) {
            return [
                'state' => $state,
                'note' => (string) $collection['note'],
                'remedy' => $collection['remedy'],
            ];
        }

        $age = (int) ($collection['age_seconds'] ?? 0);

        // Nothing has been written since before this window opened: every minute of it is on the
        // wrong side of the gap, so its emptiness says nothing at all about the traffic.
        if ($state === 'stale' && $age >= $window['minutes'] * 60) {
            return [
                'state' => 'stale',
                'note' => 'Nothing has been recorded since ' . $collection['newest_bucket_at'] . ', which is before this window begins — so this window has not been measured, and its emptiness is not a reading of the traffic.',
                'remedy' => $collection['remedy'],
            ];
        }

        if ($window['resolution'] !== 'minute') {
            // Long ranges read rolled-up buckets, which are produced by the rollup rather than by
            // traffic. So this window can be empty while the minute buckets covering the same
            // hours are full — a distinction that looks like "the shop had no visitors" unless it
            // is said out loud.
            return [
                'state' => 'no_data',
                'note' => 'This range is read from ' . $window['resolution'] . ' buckets, which are built by the monitoring rollup rather than written by traffic.',
                'remedy' => 'Choose a shorter range to read the minute buckets directly, or check that the hourly monitoring rollup is running.',
            ];
        }

        if ($state === 'stale') {
            return [
                'state' => 'stale',
                'note' => $quietNote . ' Collection is also ' . $age . ' seconds behind, so the end of the window was not measured either.',
                'remedy' => $collection['remedy'],
            ];
        }

        return ['state' => 'no_data', 'note' => $quietNote, 'remedy' => null];
    }

    private function failureNote(\Throwable $exception): string
    {
        return Metric::describeFailure($exception);
    }
}
