<?php

namespace App\Services\Monitoring\Support;

use App\Services\Monitoring\Metric;
use Illuminate\Support\Facades\DB;

/**
 * Reading side of the store: windows, series, percentiles and comparisons.
 *
 * Every panel goes through here rather than writing its own queries, for three reasons. The
 * resolution choice (minute / hour / day) has to be consistent or two charts of the same thing
 * disagree. The UTC boundary has to be crossed in exactly one place, or the timezone bug this
 * system already had comes back. And every query has to stay inside an index — a monitoring page
 * that table-scans is a monitoring page that becomes the incident.
 */
class SeriesReader
{
    /**
     * Window definitions, matched to MonitoringController::availableRanges().
     *
     * @var array<string, array{minutes: int, resolution: string, points: int}>
     */
    private const RANGES = [
        'live' => ['minutes' => 5, 'resolution' => 'minute', 'points' => 5],
        '15m' => ['minutes' => 15, 'resolution' => 'minute', 'points' => 15],
        '1h' => ['minutes' => 60, 'resolution' => 'minute', 'points' => 60],
        '6h' => ['minutes' => 360, 'resolution' => 'minute', 'points' => 72],
        '24h' => ['minutes' => 1440, 'resolution' => 'hour', 'points' => 24],
        '7d' => ['minutes' => 10080, 'resolution' => 'hour', 'points' => 84],
        '30d' => ['minutes' => 43200, 'resolution' => 'day', 'points' => 30],
        '90d' => ['minutes' => 129600, 'resolution' => 'day', 'points' => 90],
    ];

    public function connection(): \Illuminate\Database\Connection
    {
        return DB::connection(config('monitoring.connection', 'monitoring'));
    }

    /** @return array{minutes: int, resolution: string, points: int} */
    public function window(string $range): array
    {
        return self::RANGES[$range] ?? self::RANGES['1h'];
    }

    public function since(string $range): \Illuminate\Support\Carbon
    {
        return Clock::minutesAgo($this->window($range)['minutes']);
    }

    /**
     * The same window, one window earlier — the baseline every comparison is drawn against.
     *
     * "p95 is 420ms" means nothing on its own; "+18% against the previous hour" is what tells
     * somebody whether to care.
     *
     * @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon}
     */
    public function previousWindow(string $range): array
    {
        $minutes = $this->window($range)['minutes'];

        return [Clock::minutesAgo($minutes * 2), Clock::minutesAgo($minutes)];
    }

    /**
     * The newest bucket written to a table, for staleness checks.
     */
    public function newestBucketAt(string $table): ?string
    {
        try {
            $column = $table === 'monitoring_request_buckets' || $table === 'monitoring_series' ? 'bucket_at' : 'created_at';

            return $this->connection()->table($table)->max($column);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A named series over time, ready to chart.
     *
     * `v` IS NOT ALWAYS A COUNT, and that is the trap this signature exists to defuse. For a gauge
     * it is the bucket's last reading. For a COUNTER it is value_sum — whatever the writer chose to
     * total there, which for requests.by_platform is milliseconds and for requests.by_status is
     * literally nothing (that writer only ever increments the sample count). A caller that wants
     * "how many" must read `n` per point, or `samples` for the window; two panels reached for `v`
     * and published a millisecond total under the word "requests".
     *
     * `state` is the other half of the same honesty. This method is total — it answers an
     * unreachable store with an empty result rather than throwing — and an empty result that cannot
     * say whether it looked is indistinguishable from a quiet window, so a caller drawing "0
     * samples" over a failed read has no way to know. It is reported instead of inferred.
     *
     * @return array{points: array<int, array{t: string, v: float|null, n: int}>, latest: float|null, samples: int, state: string, note: string|null, unit: string|null}
     */
    public function series(string $metric, string $range, ?string $label = null): array
    {
        $window = $this->window($range);

        try {
            $rows = $this->connection()->table('monitoring_series')
                ->where('metric', $metric)
                ->where('resolution', $window['resolution'])
                ->where('bucket_at', '>=', $this->since($range))
                ->when($label !== null, fn ($query) => $query->where('label', $label))
                ->orderBy('bucket_at')
                ->limit(max(1, $window['points']) * 4)
                ->get(['bucket_at', 'samples', 'value_sum', 'value_last', 'value_min', 'value_max', 'label']);

            $points = [];
            $samples = 0;

            foreach ($rows as $row) {
                // A gauge's honest value for a bucket is its last reading; a counter's is its sum.
                // value_last is null for counters, which is how the two are told apart without a
                // separate column saying which kind this is.
                $rowSamples = (int) $row->samples;
                $samples += $rowSamples;

                $value = $row->value_last !== null
                    ? (float) $row->value_last
                    : ($rowSamples > 0 ? (float) $row->value_sum : null);

                $points[] = ['t' => Clock::parse($row->bucket_at)->toIso8601String(), 'v' => $value, 'n' => $rowSamples];
            }

            return [
                'points' => $points,
                'latest' => $points === [] ? null : end($points)['v'],
                'samples' => $samples,
                'state' => 'ok',
                'note' => null,
                'unit' => null,
            ];
        } catch (\Throwable $exception) {
            // Empty AND said to have failed. A caller that publishes "0 samples in this window" from
            // this branch would be reporting a measurement nobody took.
            return [
                'points' => [],
                'latest' => null,
                'samples' => 0,
                'state' => 'failed',
                'note' => Metric::describeFailure($exception),
                'unit' => null,
            ];
        }
    }

    /**
     * Request totals and percentiles for a window, optionally narrowed to one route or channel.
     *
     * @return array<string, mixed>
     */
    public function requestSummary(string $range, ?string $route = null, ?string $channel = null, ?\Illuminate\Support\Carbon $since = null, ?\Illuminate\Support\Carbon $until = null): array
    {
        $window = $this->window($range);

        try {
            $narrow = fn ($query) => $query
                ->when($until !== null, fn ($inner) => $inner->where('bucket_at', '<', $until))
                ->when($route !== null, fn ($inner) => $inner->where('route', $route))
                ->when($channel !== null, fn ($inner) => $inner->where('channel', $channel));

            $from = $since ?? $this->since($range);

            /*
             * The seam between the folded buckets and the minutes past them.
             *
             * The rollup folds the parent that is still in progress: a run at 10:03 writes an hour
             * bucket for 10:00 holding three minutes of it. Treating that bucket as complete — and
             * starting the tail at 11:00, as this used to — lost 10:04 to 10:59 from both sides of
             * the seam, up to fifty-six minutes of traffic missing from every coarse window.
             *
             * So the seam is the START of the newest folded parent: coarse buckets strictly before
             * it, minute buckets from it onward. The partial parent is skipped and its minutes are
             * read directly, so nothing is counted twice and nothing falls between.
             */
            $seam = $window['resolution'] === 'minute'
                ? null
                : $this->foldSeam($window['resolution'], $from);

            $rows = $narrow($this->connection()->table('monitoring_request_buckets')
                ->where('resolution', $window['resolution'])
                ->where('bucket_at', '>=', $from)
                ->when($seam !== null, fn ($inner) => $inner->where('bucket_at', '<', $seam)))
                ->get();

            if ($seam !== null) {
                $rows = $rows->concat($narrow($this->connection()->table('monitoring_request_buckets')
                    ->where('resolution', 'minute')
                    ->where('bucket_at', '>=', $seam->max($from)))
                    ->get());
            }

            return $this->summariseBuckets($rows, $window['minutes']);
        } catch (\Throwable) {
            return $this->emptySummary();
        }
    }

    /** A minute bucket's timestamp, rounded down to the bucket this window draws. */
    private function truncateToWindow(string $bucketAt, string $resolution): string
    {
        $moment = Clock::parse($bucketAt);

        return match ($resolution) {
            'day' => $moment->startOfDay()->toDateTimeString(),
            'hour' => $moment->startOfHour()->toDateTimeString(),
            default => $moment->startOfMinute()->toDateTimeString(),
        };
    }

    /**
     * Where the folded buckets stop and the raw minutes take over.
     *
     * The newest folded parent's own start: it may be partial, so it is read from minutes rather
     * than trusted. Null resolution means nothing has been folded at all, and every minute in the
     * window is the tail.
     */
    private function foldSeam(string $resolution, \Illuminate\Support\Carbon $from): \Illuminate\Support\Carbon
    {
        $newestFolded = $this->connection()->table('monitoring_request_buckets')
            ->where('resolution', $resolution)
            ->max('bucket_at');

        return $newestFolded !== null ? Clock::parse($newestFolded) : $from;
    }

    /**
     * Fold a set of bucket rows into one summary with real percentiles.
     *
     * @param  iterable<object>  $rows
     * @return array<string, mixed>
     */
    public function summariseBuckets(iterable $rows, int $windowMinutes): array
    {
        $histogram = new Histogram();
        $hits = 0;
        $errors = 0;
        $clientErrors = 0;
        $timeouts = 0;
        $dbMs = 0;
        $dbQueries = 0;
        $externalMs = 0;
        $responseBytes = 0;
        $requestBytes = 0;

        foreach ($rows as $row) {
            $hits += (int) $row->hits;
            $errors += (int) $row->errors;
            $clientErrors += (int) ($row->client_errors ?? 0);
            $timeouts += (int) ($row->timeouts ?? 0);
            $dbMs += (int) ($row->db_ms_sum ?? 0);
            $dbQueries += (int) ($row->db_query_count ?? 0);
            $externalMs += (int) ($row->external_ms_sum ?? 0);
            $responseBytes += (int) ($row->response_bytes_sum ?? 0);
            $requestBytes += (int) ($row->request_bytes_sum ?? 0);

            $histogram->merge(Histogram::fromState(
                json_decode((string) ($row->duration_buckets ?? '[]'), true) ?: [],
                (int) $row->hits,
                (float) ($row->duration_sum_ms ?? 0),
                isset($row->duration_min_ms) ? (float) $row->duration_min_ms : null,
                isset($row->duration_max_ms) ? (float) $row->duration_max_ms : null,
            ));
        }

        if ($hits === 0) {
            return $this->emptySummary();
        }

        $percentiles = $histogram->standardPercentiles();

        return [
            'has_data' => true,
            'hits' => $hits,
            'errors' => $errors,
            'client_errors' => $clientErrors,
            'timeouts' => $timeouts,
            'error_rate' => round(100 * $errors / $hits, 3),
            'client_error_rate' => round(100 * $clientErrors / $hits, 3),
            'timeout_rate' => round(100 * $timeouts / $hits, 3),
            'requests_per_second' => round($hits / max(1, $windowMinutes * 60), 3),
            'requests_per_minute' => round($hits / max(1, $windowMinutes), 2),
            'p50' => $percentiles['p50'],
            'p75' => $percentiles['p75'],
            'p90' => $percentiles['p90'],
            'p95' => $percentiles['p95'],
            'p99' => $percentiles['p99'],
            'max' => $percentiles['max'],
            'avg' => $percentiles['avg'],
            'db_ms_avg' => round($dbMs / $hits, 1),
            'db_queries_avg' => round($dbQueries / $hits, 1),
            'external_ms_avg' => round($externalMs / $hits, 1),
            'response_bytes_avg' => (int) round($responseBytes / $hits),
            'request_bytes_avg' => (int) round($requestBytes / $hits),
        ];
    }

    /**
     * The same summary for this window and the one before it, with the deltas already computed.
     *
     * @return array<string, mixed>
     */
    public function comparison(string $range, ?string $route = null, ?string $channel = null): array
    {
        [$previousStart, $previousEnd] = $this->previousWindow($range);

        $current = $this->requestSummary($range, $route, $channel);
        $previous = $this->requestSummary($range, $route, $channel, since: $previousStart, until: $previousEnd);

        $delta = [];
        foreach (['hits', 'error_rate', 'p95', 'p99', 'avg', 'db_ms_avg', 'requests_per_second'] as $field) {
            $now = $current[$field] ?? null;
            $before = $previous[$field] ?? null;

            // No baseline means no percentage. An unexplained "0%" reads as "nothing changed",
            // which is a different claim from "there is nothing to compare against".
            $delta[$field] = ($now === null || $before === null || $before == 0)
                ? null
                : round(100 * ($now - $before) / $before, 1);
        }

        return ['current' => $current, 'previous' => $previous, 'delta' => $delta];
    }

    /**
     * Per-route breakdown, ordered by whatever the operator is hunting.
     *
     * @param  string  $sort  hits | p95 | errors | total_time
     * @return array<int, array<string, mixed>>
     */
    public function routeBreakdown(string $range, string $sort = 'hits', int $limit = 25, ?string $channel = null): array
    {
        $window = $this->window($range);

        try {
            $from = $this->since($range);

            // The same seam requestSummary() reads across. Without it the headline on the page
            // counted everything since the last fold and the table underneath it did not, so the
            // two numbers on one screen disagreed — and the busiest route of the last hour was
            // missing from the list of the busiest routes.
            $seam = $window['resolution'] === 'minute' ? null : $this->foldSeam($window['resolution'], $from);

            $rows = $this->connection()->table('monitoring_request_buckets')
                ->where('resolution', $window['resolution'])
                ->where('bucket_at', '>=', $from)
                ->when($seam !== null, fn ($query) => $query->where('bucket_at', '<', $seam))
                ->when($channel !== null, fn ($query) => $query->where('channel', $channel))
                ->get();

            if ($seam !== null) {
                $rows = $rows->concat($this->connection()->table('monitoring_request_buckets')
                    ->where('resolution', 'minute')
                    ->where('bucket_at', '>=', $seam->max($from))
                    ->when($channel !== null, fn ($query) => $query->where('channel', $channel))
                    ->get());
            }

            $byRoute = [];
            foreach ($rows as $row) {
                $key = $row->channel . ' ' . $row->method . ' ' . $row->route;
                $byRoute[$key][] = $row;
            }

            $routes = [];
            foreach ($byRoute as $key => $group) {
                $summary = $this->summariseBuckets($group, $window['minutes']);
                if (!$summary['has_data']) {
                    continue;
                }
                [$channelName, $method, $route] = explode(' ', $key, 3);
                $routes[] = array_merge($summary, [
                    'channel' => $channelName,
                    'method' => $method,
                    'route' => $route,
                    // Total time is the honest ranking for "what should I fix": a 40ms endpoint
                    // called a million times costs more than a 4s one called twice.
                    'total_time_ms' => (int) round($summary['avg'] * $summary['hits']),
                ]);
            }

            usort($routes, match ($sort) {
                'p95' => static fn ($a, $b) => ($b['p95'] ?? 0) <=> ($a['p95'] ?? 0),
                'errors' => static fn ($a, $b) => [$b['errors'], $b['error_rate']] <=> [$a['errors'], $a['error_rate']],
                'total_time' => static fn ($a, $b) => $b['total_time_ms'] <=> $a['total_time_ms'],
                default => static fn ($a, $b) => $b['hits'] <=> $a['hits'],
            });

            return array_slice($routes, 0, $limit);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * A request-rate line for charting, split by status class.
     *
     * @return array<string, mixed>
     */
    public function requestTimeline(string $range): array
    {
        $window = $this->window($range);

        try {
            $from = $this->since($range);
            $seam = $window['resolution'] === 'minute' ? null : $this->foldSeam($window['resolution'], $from);

            $columns = [
                'bucket_at',
                DB::raw('SUM(hits) as hits'),
                DB::raw('SUM(errors) as errors'),
                DB::raw('SUM(client_errors) as client_errors'),
                DB::raw('SUM(duration_sum_ms) as duration_sum_ms'),
            ];

            $rows = $this->connection()->table('monitoring_request_buckets')
                ->where('resolution', $window['resolution'])
                ->where('bucket_at', '>=', $from)
                ->when($seam !== null, fn ($query) => $query->where('bucket_at', '<', $seam))
                ->groupBy('bucket_at')
                ->orderBy('bucket_at')
                ->limit(max(1, $window['points']) * 4)
                ->get($columns);

            // The tail, so the line ends where the traffic does rather than where the last rollup
            // ran. Read at minute resolution and folded to this window's own buckets, so the shape
            // of the chart does not change halfway along it.
            if ($seam !== null) {
                $tail = $this->connection()->table('monitoring_request_buckets')
                    ->where('resolution', 'minute')
                    ->where('bucket_at', '>=', $seam->max($from))
                    ->groupBy('bucket_at')
                    ->orderBy('bucket_at')
                    ->limit(max(1, $window['points']) * 4)
                    ->get($columns)
                    ->groupBy(fn ($row) => $this->truncateToWindow($row->bucket_at, $window['resolution']))
                    ->map(fn ($group, $bucket) => (object) [
                        'bucket_at' => $bucket,
                        'hits' => $group->sum('hits'),
                        'errors' => $group->sum('errors'),
                        'client_errors' => $group->sum('client_errors'),
                        'duration_sum_ms' => $group->sum('duration_sum_ms'),
                    ])
                    ->values();

                $rows = $rows->concat($tail);
            }

            $points = [];
            foreach ($rows as $row) {
                $hits = (int) $row->hits;
                $points[] = [
                    't' => Clock::parse($row->bucket_at)->toIso8601String(),
                    'hits' => $hits,
                    'errors' => (int) $row->errors,
                    'client_errors' => (int) $row->client_errors,
                    'avg_ms' => $hits > 0 ? round((int) $row->duration_sum_ms / $hits, 1) : null,
                ];
            }

            return ['points' => $points, 'resolution' => $window['resolution']];
        } catch (\Throwable) {
            return ['points' => [], 'resolution' => $window['resolution']];
        }
    }

    /** @return array<string, mixed> */
    private function emptySummary(): array
    {
        // Explicitly "no data", not a row of zeros: zero requests and zero measurements are
        // different facts and the UI renders them differently.
        return [
            'has_data' => false,
            'hits' => 0,
            'errors' => 0,
            'client_errors' => 0,
            'timeouts' => 0,
            'error_rate' => null,
            'client_error_rate' => null,
            'timeout_rate' => null,
            'requests_per_second' => null,
            'requests_per_minute' => null,
            'p50' => null, 'p75' => null, 'p90' => null, 'p95' => null, 'p99' => null,
            'max' => null, 'avg' => null,
            'db_ms_avg' => null, 'db_queries_avg' => null, 'external_ms_avg' => null,
            'response_bytes_avg' => null, 'request_bytes_avg' => null,
        ];
    }
}
