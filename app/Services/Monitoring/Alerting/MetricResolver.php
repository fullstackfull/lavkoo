<?php

namespace App\Services\Monitoring\Alerting;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\SeriesReader;

/**
 * Turns a rule's metric name into the readings it is currently producing.
 *
 * A rule says "http.error_rate above 5 for two minutes". Two things have to be true before that
 * can fire: the metric has to exist, and there has to be data in the window. This resolver returns
 * every sample in the window rather than one number, because the difference between "above the
 * line right now" and "above the line for the whole two minutes" is the entire difference between
 * an alert worth waking up for and a pager that everybody mutes.
 *
 * Three sources, one interface:
 *   - gauges and counters written to monitoring_series by the flush (server.*, db.*, queue.*, check.*)
 *   - request-derived numbers computed from the request buckets (http.*), which are aggregates and
 *     therefore cannot be stored as a single per-minute sample without lying about percentiles.
 *   - the same shape for OUTBOUND calls, from the dependency buckets (dependency.*). Without these
 *     no rule could watch a payment gateway or an SMS provider at all — the numbers were on the
 *     integrations page and unreachable from the alert engine, so the one failure a shop most wants
 *     to be woken for was the one it could only find by looking.
 */
class MetricResolver
{
    /** Request-derived metric name => the key it maps to in a request summary. */
    private const REQUEST_METRICS = [
        'http.error_rate' => 'error_rate',
        'http.client_error_rate' => 'client_error_rate',
        'http.timeout_rate' => 'timeout_rate',
        'http.p50_ms' => 'p50',
        'http.p95_ms' => 'p95',
        'http.p99_ms' => 'p99',
        'http.avg_ms' => 'avg',
        'http.requests_per_minute' => 'requests_per_minute',
        'http.db_ms_avg' => 'db_ms_avg',
    ];

    /**
     * Dependency-derived metric name => the field computed from a minute of outbound calls.
     *
     * The label on such a rule is the SERVICE — the host, as DependencyRecorder files it — so
     * "api.stripe.com above 5% errors for two minutes" is a rule somebody can write.
     */
    private const DEPENDENCY_METRICS = [
        'dependency.error_rate' => 'error_rate',
        'dependency.timeout_rate' => 'timeout_rate',
        'dependency.avg_ms' => 'avg_ms',
        'dependency.calls_per_minute' => 'calls',
    ];

    public function __construct(private readonly SeriesReader $reader)
    {
    }

    /** @return array<int, string> every metric a rule may be written against, for the editor */
    public function available(): ?array
    {
        try {
            $stored = $this->reader->connection()->table('monitoring_series')
                ->where('bucket_at', '>=', Clock::daysAgo(2))
                ->distinct()
                ->orderBy('metric')
                ->limit(500)
                ->pluck('metric')
                ->all();

            // The http.* metrics are derived from the request buckets rather than stored as series,
            // so their presence has to be checked where they actually come from. Prepending them
            // unconditionally — which is what this used to do — meant every rule about latency or
            // error rate reported "the metric is arriving" on a deployment that had never recorded
            // a single request, which is precisely the silence the column exists to expose.
            $hasRequests = $this->reader->connection()->table('monitoring_request_buckets')
                ->where('bucket_at', '>=', Clock::daysAgo(2))
                ->exists();

            // Same rule for the dependency metrics: offered only where something is actually
            // recording outbound calls, because this build can only see the ones made through
            // Laravel's HTTP client and a rule against a gateway nobody measures is a rule that
            // stays silent through the outage it was written for.
            $hasDependencies = $this->reader->connection()->table('monitoring_dependency_buckets')
                ->where('bucket_at', '>=', Clock::daysAgo(2))
                ->exists();
        } catch (\Throwable) {
            // Null, not an empty list: an unanswered question is not a negative answer, and the
            // panel renders the two differently.
            return null;
        }

        $metrics = array_merge(
            $hasRequests ? array_keys(self::REQUEST_METRICS) : [],
            $hasDependencies ? array_keys(self::DEPENDENCY_METRICS) : [],
        );

        return array_values(array_unique(array_merge($metrics, $stored)));
    }

    /**
     * Every sample of this metric inside the window, oldest first.
     *
     * An empty array means "no data", which is NOT the same as zero and must never be evaluated as
     * one: a rule that fires because the metric stopped arriving is a rule that fires every time a
     * quiet shop has a quiet minute.
     *
     * @return array<int, float>
     */
    public function samples(string $metric, string $label, int $windowSeconds, string $worst = 'max'): array
    {
        $minutes = max(1, (int) ceil($windowSeconds / 60));

        return match (true) {
            isset(self::REQUEST_METRICS[$metric]) => $this->requestSamples($metric, $label, $minutes),
            isset(self::DEPENDENCY_METRICS[$metric]) => $this->dependencySamples($metric, $label, $minutes),
            default => $this->seriesSamples($metric, $label, $minutes, $worst),
        };
    }

    /** The most recent reading, whatever the window — what the alert shows as "last value". */
    public function latest(string $metric, string $label = ''): ?float
    {
        $samples = $this->samples($metric, $label, 600);

        return $samples === [] ? null : (float) end($samples);
    }

    public function describe(string $metric): Metric
    {
        if (isset(self::REQUEST_METRICS[$metric])) {
            return Metric::of($metric, 'monitoring_request_buckets');
        }

        if (isset(self::DEPENDENCY_METRICS[$metric])) {
            return Metric::of($metric, 'monitoring_dependency_buckets');
        }

        return in_array($metric, $this->available(), true)
            ? Metric::of($metric, 'monitoring_series')
            : Metric::noData('monitoring_series', "No sample of {$metric} has been recorded in the last two days.");
    }

    /**
     * @return array<int, float>
     */
    private function seriesSamples(string $metric, string $label, int $minutes, string $worst): array
    {
        try {
            $rows = $this->reader->connection()->table('monitoring_series')
                ->where('metric', $metric)
                ->where('resolution', 'minute')
                ->where('bucket_at', '>=', Clock::minutesAgo($minutes))
                ->when($label !== '', fn ($query) => $query->where('label', $label))
                ->orderBy('bucket_at')
                ->get(['bucket_at', 'samples', 'value_sum', 'value_last']);
        } catch (\Throwable) {
            return [];
        }

        // One value per minute, even when the metric carries several labels: the worst label in
        // each minute is the one that decides, in whichever direction the rule is watching.
        $perMinute = [];

        foreach ($rows as $row) {
            // value_last is a gauge's honest reading; a counter has none, and its bucket total is.
            $value = $row->value_last !== null
                ? (float) $row->value_last
                : ((int) $row->samples > 0 ? (float) $row->value_sum : null);

            if ($value === null) {
                continue;
            }

            $minute = (string) $row->bucket_at;
            $perMinute[$minute] = isset($perMinute[$minute])
                ? ($worst === 'min' ? min($perMinute[$minute], $value) : max($perMinute[$minute], $value))
                : $value;
        }

        ksort($perMinute);

        return array_values($perMinute);
    }

    /**
     * Request metrics, computed per minute so "for two minutes" means two minutes of breach and
     * not one bad minute averaged into a calm one.
     *
     * @return array<int, float>
     */
    private function requestSamples(string $metric, string $label, int $minutes): array
    {
        $field = self::REQUEST_METRICS[$metric];
        $samples = [];

        for ($ago = $minutes; $ago >= 1; $ago--) {
            $summary = $this->reader->requestSummary(
                'live',
                route: $label !== '' ? $label : null,
                since: Clock::minutesAgo($ago),
                until: Clock::minutesAgo($ago - 1),
            );

            if (($summary['has_data'] ?? false) === true && isset($summary[$field]) && is_numeric($summary[$field])) {
                $samples[] = (float) $summary[$field];
            }
        }

        return $samples;
    }

    /**
     * Outbound calls, one sample per minute.
     *
     * A minute in which this service was not called at all produces NO sample rather than a zero:
     * a rate needs calls to be a rate of, and "nobody called the gateway this minute" must never
     * evaluate as "the gateway answered everything perfectly".
     *
     * @return array<int, float>
     */
    private function dependencySamples(string $metric, string $label, int $minutes): array
    {
        try {
            $rows = $this->reader->connection()->table('monitoring_dependency_buckets')
                ->selectRaw('bucket_at, SUM(calls) AS calls, SUM(failures) AS failures, SUM(timeouts) AS timeouts, SUM(duration_sum_ms) AS duration_sum_ms')
                ->where('resolution', 'minute')
                ->where('bucket_at', '>=', Clock::minutesAgo($minutes))
                ->when($label !== '', fn ($query) => $query->where('service', $label))
                ->groupBy('bucket_at')
                ->orderBy('bucket_at')
                ->get();
        } catch (\Throwable) {
            return [];
        }

        $field = self::DEPENDENCY_METRICS[$metric];
        $samples = [];

        foreach ($rows as $row) {
            $calls = (int) $row->calls;

            if ($calls <= 0) {
                continue;
            }

            $samples[] = match ($field) {
                'error_rate' => round(100 * (int) $row->failures / $calls, 3),
                'timeout_rate' => round(100 * (int) $row->timeouts / $calls, 3),
                'avg_ms' => round((float) $row->duration_sum_ms / $calls, 3),
                default => (float) $calls,
            };
        }

        return $samples;
    }
}
