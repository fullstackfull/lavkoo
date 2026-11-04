<?php

namespace App\Services\Monitoring\Ingest;

use App\Services\Monitoring\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Turns drained counters into rows.
 *
 * This is the only place that writes monitoring buckets, so it is also the only place that has to
 * get the two hard parts right: adding to a bucket that may or may not exist yet (an upsert that
 * sums rather than replaces), and refusing to let the table explode when a route pattern turns out
 * to be unbounded after all.
 */
class BucketWriter
{
    /** Buckets whose identity starts with this are request buckets; the rest are named series. */
    public const REQUEST_PREFIX = 'req|';
    public const DEPENDENCY_PREFIX = 'dep|';
    public const SERIES_PREFIX = 'ser|';

    /**
     * Series whose label is client-supplied, and therefore the only named series with a ceiling.
     *
     * @var array<int, string>
     */
    public const CLIENT_LABELLED_SERIES = [
        'requests.by_app_version',
        'requests.by_app_version.errors',
        'app.health.sessions',
        'app.health.crashes',
        'app.health.anrs',
    ];

    private function connection(): \Illuminate\Database\Connection
    {
        return DB::connection(config('monitoring.connection', 'monitoring'));
    }

    /**
     * @param  array<int, array<string, array<string, float|int>>>  $minutes  minute => bucket => field => value
     */
    public function apply(array $minutes): void
    {
        foreach ($minutes as $minute => $buckets) {
            $requests = [];
            $dependencies = [];
            $series = [];

            foreach ($this->capSeries($buckets) as $bucket => $fields) {
                match (true) {
                    str_starts_with($bucket, self::REQUEST_PREFIX) => $requests[$bucket] = $fields,
                    str_starts_with($bucket, self::DEPENDENCY_PREFIX) => $dependencies[$bucket] = $fields,
                    str_starts_with($bucket, self::SERIES_PREFIX) => $series[$bucket] = $fields,
                    default => null,
                };
            }

            $this->writeRequests($minute, $requests);
            $this->writeDependencies($minute, $dependencies);
            $this->writeSeries($minute, $series);
        }
    }

    /**
     * Bound the number of distinct buckets written for one minute.
     *
     * Route patterns are supposed to be bounded, but a 404 catch-all, a scanner probing random
     * paths, or one unparameterised route is all it takes for "distinct routes" to become
     * "distinct URLs". Beyond the cap the busiest are kept and the tail is folded into an
     * `__other__` row — the totals stay correct, and the table cannot be made to explode by anyone
     * who can send requests.
     *
     * Each family is capped on its own terms, which is the part that used to be wrong. One shared
     * sort read `hits` or `calls`, and a named series carries neither — it counts samples in `n` —
     * so every gauge in the system sorted as zero and was evicted before a single-hit scanner
     * route. And the tail of every family was folded into one `ser|__other__` row, which added CPU
     * percent to queue-lag seconds and stored the result as a measurement.
     *
     * @param  array<string, array<string, float|int>>  $buckets
     * @return array<string, array<string, float|int>>
     */
    private function capSeries(array $buckets): array
    {
        $buckets = $this->capClientLabelledSeries($buckets);

        $limit = max(50, (int) config('monitoring.max_series_per_minute', 400));

        if (count($buckets) <= $limit) {
            return $buckets;
        }

        $families = [self::REQUEST_PREFIX => [], self::DEPENDENCY_PREFIX => [], self::SERIES_PREFIX => []];

        foreach ($buckets as $identity => $fields) {
            foreach ($families as $prefix => $_) {
                if (str_starts_with($identity, $prefix)) {
                    $families[$prefix][$identity] = $fields;
                    continue 2;
                }
            }
        }

        return array_merge(
            $this->capRequests($families[self::REQUEST_PREFIX], $limit),
            $this->capDependencies($families[self::DEPENDENCY_PREFIX], $limit),
            // Named series are not capped here. Their names come from the collectors in this
            // codebase, so the cap protects nothing and dropping one loses a gauge the whole
            // dashboard is built on. The exception is the handful whose LABEL comes from a client
            // header, and those were already bounded by capClientLabelledSeries() above.
            $families[self::SERIES_PREFIX],
        );
    }

    /**
     * Bound the series whose LABEL is supplied by the caller rather than by this codebase.
     *
     * Named series are otherwise uncapped, and that is right for a gauge whose name is a constant
     * in a collector. It is NOT right for `requests.by_app_version`, whose label is an X-App-Version
     * header, or the app-health counters, whose label is a platform and version posted to a public
     * endpoint. Both are validated to a short character set, which bounds their LENGTH and not
     * their NUMBER: a few thousand requests carrying a few thousand invented version strings would
     * write a few thousand rows a minute into a table with no ceiling.
     *
     * The tail is folded rather than dropped, so the totals stay exact and only the split loses its
     * long tail. Folding is safe here — unlike across the whole series family — because every row
     * under one of these metrics counts the same thing in the same unit.
     *
     * @param  array<string, array<string, float|int>>  $buckets
     * @return array<string, array<string, float|int>>
     */
    private function capClientLabelledSeries(array $buckets): array
    {
        $limit = max(5, (int) config('monitoring.max_labels_per_client_series', 40));
        $byMetric = [];

        foreach ($buckets as $identity => $fields) {
            // ser|metric|label
            $parts = explode('|', $identity, 3);
            if (($parts[0] ?? '') !== rtrim(self::SERIES_PREFIX, '|') || !in_array($parts[1] ?? '', self::CLIENT_LABELLED_SERIES, true)) {
                continue;
            }
            $byMetric[$parts[1]][$identity] = $fields;
        }

        foreach ($byMetric as $metric => $labels) {
            if (count($labels) <= $limit) {
                continue;
            }

            uasort($labels, static fn ($a, $b) => ($b['n'] ?? 0) <=> ($a['n'] ?? 0));

            foreach (array_slice($labels, $limit, null, true) as $identity => $fields) {
                unset($buckets[$identity]);
                $buckets = $this->fold($buckets, self::SERIES_PREFIX . $metric . '|__other__', $fields);
            }
        }

        return $buckets;
    }

    /**
     * @param  array<string, array<string, float|int>>  $buckets
     * @return array<string, array<string, float|int>>
     */
    private function capRequests(array $buckets, int $limit): array
    {
        if (count($buckets) <= $limit) {
            return $buckets;
        }

        uasort($buckets, static fn ($a, $b) => ($b['hits'] ?? 0) <=> ($a['hits'] ?? 0));

        $kept = array_slice($buckets, 0, $limit, true);

        foreach (array_slice($buckets, $limit, null, true) as $identity => $fields) {
            // req|channel|method|route — the channel and the method are kept, because folding an
            // API POST into "web GET" files traffic under a channel and a verb it did not use.
            $parts = explode('|', $identity, 4);
            $channel = $parts[1] ?? 'web';
            $method = $parts[2] ?? 'GET';

            $kept = $this->fold($kept, self::REQUEST_PREFIX . $channel . '|' . $method . '|__other__', $fields);
        }

        return $kept;
    }

    /**
     * @param  array<string, array<string, float|int>>  $buckets
     * @return array<string, array<string, float|int>>
     */
    private function capDependencies(array $buckets, int $limit): array
    {
        if (count($buckets) <= $limit) {
            return $buckets;
        }

        uasort($buckets, static fn ($a, $b) => ($b['calls'] ?? 0) <=> ($a['calls'] ?? 0));

        $kept = array_slice($buckets, 0, $limit, true);

        foreach (array_slice($buckets, $limit, null, true) as $identity => $fields) {
            // dep|service|operation
            $service = explode('|', $identity, 3)[1] ?? 'unknown';

            $kept = $this->fold($kept, self::DEPENDENCY_PREFIX . $service . '|__other__', $fields);
        }

        return $kept;
    }

    /**
     * Add one bucket's fields into another, respecting what each field means.
     *
     * @param  array<string, array<string, float|int>>  $kept
     * @param  array<string, float|int>  $fields
     * @return array<string, array<string, float|int>>
     */
    private function fold(array $kept, string $target, array $fields): array
    {
        foreach ($fields as $field => $value) {
            if (str_ends_with($field, ':min')) {
                $kept[$target][$field] = isset($kept[$target][$field]) ? min($kept[$target][$field], $value) : $value;
            } elseif (str_ends_with($field, ':max')) {
                $kept[$target][$field] = isset($kept[$target][$field]) ? max($kept[$target][$field], $value) : $value;
            } else {
                $kept[$target][$field] = ($kept[$target][$field] ?? 0) + $value;
            }
        }

        return $kept;
    }

    /**
     * @param  array<string, array<string, float|int>>  $buckets
     */
    private function writeRequests(int $minute, array $buckets): void
    {
        if ($buckets === []) {
            return;
        }

        $bucketAt = Clock::minuteAt($minute);
        $rows = [];

        foreach ($buckets as $identity => $fields) {
            // req|channel|method|route
            $parts = explode('|', $identity, 4);
            if (count($parts) < 4) {
                continue;
            }
            [, $channel, $method, $route] = $parts;

            $rows[] = [
                'resolution' => 'minute',
                'bucket_at' => $bucketAt,
                'channel' => mb_substr($channel, 0, 12),
                'route' => mb_substr($route, 0, 191),
                'method' => mb_substr($method, 0, 8),
                'hits' => (int) ($fields['hits'] ?? 0),
                'errors' => (int) ($fields['errors'] ?? 0),
                'client_errors' => (int) ($fields['client_errors'] ?? 0),
                'timeouts' => (int) ($fields['timeouts'] ?? 0),
                'duration_buckets' => json_encode($this->histogramCounts($fields)),
                'duration_sum_ms' => (int) ($fields['dur_sum'] ?? 0),
                'duration_min_ms' => isset($fields['dur:min']) ? (int) $fields['dur:min'] : null,
                'duration_max_ms' => isset($fields['dur:max']) ? (int) $fields['dur:max'] : null,
                'db_ms_sum' => (int) ($fields['db_ms'] ?? 0),
                'db_query_count' => (int) ($fields['db_q'] ?? 0),
                'cache_ms_sum' => (int) ($fields['cache_ms'] ?? 0),
                'external_ms_sum' => (int) ($fields['ext_ms'] ?? 0),
                'external_calls' => (int) ($fields['ext_calls'] ?? 0),
                'queue_dispatches' => (int) ($fields['jobs'] ?? 0),
                'memory_peak_sum_kb' => (int) ($fields['mem_kb'] ?? 0),
                'response_bytes_sum' => (int) ($fields['res_bytes'] ?? 0),
                'request_bytes_sum' => (int) ($fields['req_bytes'] ?? 0),
            ];
        }

        if ($rows === []) {
            return;
        }

        // Chunked so one very busy minute cannot build a statement bigger than max_allowed_packet.
        foreach (array_chunk($rows, 100) as $chunk) {
            $this->connection()->table('monitoring_request_buckets')->upsert(
                $chunk,
                ['resolution', 'bucket_at', 'channel', 'route', 'method'],
                $this->summingUpdate([
                    'hits', 'errors', 'client_errors', 'timeouts', 'duration_sum_ms', 'db_ms_sum',
                    'db_query_count', 'cache_ms_sum', 'external_ms_sum', 'external_calls',
                    'queue_dispatches', 'memory_peak_sum_kb', 'response_bytes_sum', 'request_bytes_sum',
                ], extremes: ['duration_min_ms' => 'min', 'duration_max_ms' => 'max'], histogram: 'duration_buckets'),
            );
        }
    }

    private function writeDependencies(int $minute, array $buckets): void
    {
        if ($buckets === []) {
            return;
        }

        $bucketAt = Clock::minuteAt($minute);
        $rows = [];

        foreach ($buckets as $identity => $fields) {
            // dep|service|operation
            $parts = explode('|', $identity, 3);
            if (count($parts) < 3) {
                continue;
            }
            [, $service, $operation] = $parts;

            $rows[] = [
                'resolution' => 'minute',
                'bucket_at' => $bucketAt,
                'service' => mb_substr($service, 0, 64),
                'operation' => mb_substr($operation, 0, 96),
                'calls' => (int) ($fields['calls'] ?? 0),
                'failures' => (int) ($fields['failures'] ?? 0),
                'timeouts' => (int) ($fields['timeouts'] ?? 0),
                'client_errors' => (int) ($fields['client_errors'] ?? 0),
                'server_errors' => (int) ($fields['server_errors'] ?? 0),
                'rate_limited' => (int) ($fields['rate_limited'] ?? 0),
                'duration_buckets' => json_encode($this->histogramCounts($fields)),
                'duration_sum_ms' => (int) ($fields['dur_sum'] ?? 0),
                'duration_max_ms' => isset($fields['dur:max']) ? (int) $fields['dur:max'] : null,
                // The three readings that are not counters, produced by DependencyRecorder. The two
                // stamps ride the buffer as epoch seconds under the extremes channel, because
                // numbers are all it carries; the error text cannot, so the recorder writes it
                // straight into the bucket. Null means this write has nothing to say about them —
                // never that a call succeeded silently — and the update below keeps what is stored.
                'last_success_at' => isset($fields['ok_at:max']) ? Clock::stamp((int) $fields['ok_at:max']) : null,
                'last_failure_at' => isset($fields['bad_at:max']) ? Clock::stamp((int) $fields['bad_at:max']) : null,
                'last_error' => isset($fields['err']) ? mb_substr((string) $fields['err'], 0, 191) : null,
            ];
        }

        // The error text is REPLACED only by the rows that carry one. Listing it unconditionally
        // would blank it on the next write into the same bucket — the next successful call, or the
        // flush that drains the minute's counters a minute later — leaving a row that says a call
        // failed and cannot say what it said. So the rows are written in two statements, split by
        // whether they have an error to tell.
        foreach ([false, true] as $carriesError) {
            $group = array_values(array_filter($rows, static fn (array $row) => ($row['last_error'] !== null) === $carriesError));

            foreach (array_chunk($group, 100) as $chunk) {
                $this->connection()->table('monitoring_dependency_buckets')->upsert(
                    $chunk,
                    ['resolution', 'bucket_at', 'service', 'operation'],
                    $this->summingUpdate(
                        ['calls', 'failures', 'timeouts', 'client_errors', 'server_errors', 'rate_limited', 'duration_sum_ms'],
                        // The stamps are extremes rather than replacements for the same reason the
                        // recorder sends them as numbers: GREATEST over COALESCE keeps the later of
                        // the two and never erases a stored one, so a bucket written again by a
                        // second web server, or by a call with the opposite outcome, keeps both.
                        extremes: ['duration_max_ms' => 'max', 'last_success_at' => 'max', 'last_failure_at' => 'max'],
                        replace: $carriesError ? ['last_error'] : [],
                        histogram: 'duration_buckets',
                    ),
                );
            }
        }
    }

    private function writeSeries(int $minute, array $buckets): void
    {
        if ($buckets === []) {
            return;
        }

        $bucketAt = Clock::minuteAt($minute);
        $rows = [];

        foreach ($buckets as $identity => $fields) {
            // ser|metric|label
            $parts = explode('|', $identity, 3);
            if (count($parts) < 2) {
                continue;
            }
            $metric = $parts[1];
            $label = $parts[2] ?? '';

            $rows[] = [
                'resolution' => 'minute',
                'bucket_at' => $bucketAt,
                'metric' => mb_substr($metric, 0, 96),
                'label' => mb_substr($label, 0, 96),
                'samples' => (int) ($fields['n'] ?? 0),
                'value_sum' => (float) ($fields['sum'] ?? 0),
                'value_min' => $fields['v:min'] ?? null,
                'value_max' => $fields['v:max'] ?? null,
                'value_last' => $fields['last'] ?? null,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            $this->connection()->table('monitoring_series')->upsert(
                $chunk,
                ['resolution', 'bucket_at', 'metric', 'label'],
                $this->summingUpdate(['samples', 'value_sum'], extremes: ['value_min' => 'min', 'value_max' => 'max'], replace: ['value_last']),
            );
        }
    }

    /**
     * The UPDATE half of the upsert.
     *
     * Laravel's upsert REPLACES the listed columns, which for a counter would throw away
     * everything already in the bucket. Counters therefore have to be written as explicit
     * `column = column + VALUES(column)` expressions, extremes as LEAST/GREATEST, and the
     * histogram as an element-wise addition done in SQL — because two web servers can be writing
     * into the same minute at the same time and neither may clobber the other.
     *
     * @param  array<int, string>  $sums
     * @param  array<string, string>  $extremes  column => min|max
     * @param  array<int, string>  $replace  columns where last-write-wins is correct (gauges)
     * @return array<int|string, mixed>
     */
    private function summingUpdate(array $sums, array $extremes = [], array $replace = [], ?string $histogram = null): array
    {
        $update = [];
        $grammar = $this->connection()->getQueryGrammar();

        foreach ($sums as $column) {
            $wrapped = $grammar->wrap($column);
            $update[$column] = DB::raw("{$wrapped} + {$this->incoming($column)}");
        }

        foreach ($extremes as $column => $mode) {
            $wrapped = $grammar->wrap($column);
            $incoming = $this->incoming($column);
            // SQLite spells the multi-argument extremes MIN/MAX; MySQL, MariaDB and Postgres
            // spell them LEAST/GREATEST and reserve MIN/MAX for aggregates.
            $function = $this->connection()->getDriverName() === 'sqlite'
                ? ($mode === 'min' ? 'MIN' : 'MAX')
                : ($mode === 'min' ? 'LEAST' : 'GREATEST');
            // COALESCE: the stored extreme is NULL until the first value lands in the bucket, and
            // LEAST(NULL, x) is NULL in MySQL — which would erase it on every later write.
            $update[$column] = DB::raw("{$function}(COALESCE({$wrapped}, {$incoming}), COALESCE({$incoming}, {$wrapped}))");
        }

        foreach ($replace as $column) {
            $update[] = $column;
        }

        if ($histogram !== null) {
            $wrapped = $grammar->wrap($histogram);
            $incoming = $this->incoming($histogram);
            $buckets = count((array) config('monitoring.latency_buckets_ms', [])) + 1;
            $terms = [];
            for ($index = 0; $index < $buckets; $index++) {
                $terms[] = "COALESCE(JSON_EXTRACT({$wrapped}, '$[{$index}]'), 0) + COALESCE(JSON_EXTRACT({$incoming}, '$[{$index}]'), 0)";
            }
            $update[$histogram] = DB::raw('JSON_ARRAY(' . implode(', ', $terms) . ')');
        }

        return $update;
    }

    /**
     * How this database spells "the value this statement is trying to insert".
     *
     * MariaDB and MySQL say VALUES(col); SQLite and Postgres say excluded.col. The distinction
     * matters beyond portability: without it every counter update is a syntax error on any engine
     * but MySQL, which means the ingest path cannot be tested anywhere except against a live
     * MariaDB — and an ingest path nobody can test is one that breaks silently.
     */
    private function incoming(string $column): string
    {
        $grammar = $this->connection()->getQueryGrammar();
        $wrapped = $grammar->wrap($column);

        return match ($this->connection()->getDriverName()) {
            'sqlite', 'pgsql' => $grammar->wrap('excluded') . '.' . $wrapped,
            default => "VALUES({$wrapped})",
        };
    }

    /**
     * Pull hist.N counters out of the flat field map into a dense array.
     *
     * @param  array<string, float|int>  $fields
     * @return array<int, int>
     */
    private function histogramCounts(array $fields): array
    {
        $size = count((array) config('monitoring.latency_buckets_ms', [])) + 1;
        $counts = array_fill(0, $size, 0);

        foreach ($fields as $field => $value) {
            if (!str_starts_with($field, 'hist.')) {
                continue;
            }
            $index = (int) substr($field, 5);
            if ($index >= 0 && $index < $size) {
                $counts[$index] = (int) $value;
            }
        }

        return $counts;
    }
}
