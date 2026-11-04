<?php

namespace App\Services\DeveloperPortal;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Support\Facades\DB;

/**
 * Whether an endpoint is actually working, right now, from the traffic it is actually serving.
 *
 * Nothing here collects anything. The monitoring middleware already folds every request into a
 * per-minute bucket keyed by route PATTERN — the same pattern the manifest documents — so the
 * documentation and the health numbers join on the route itself. A second collection path would
 * cost the store twice and, worse, would eventually disagree with the first: two screens quoting
 * different p95s for the same endpoint is how people stop trusting both.
 *
 * The honesty rule from the monitoring work carries over unchanged. An endpoint nobody has called
 * has NO error rate — not a zero. Zero errors and no traffic look identical on a dashboard and
 * mean opposite things, so an unmeasured endpoint says so.
 */
class EndpointHealthService
{
    public function __construct(private readonly SeriesReader $reader)
    {
    }

    /**
     * @param  array<string, mixed>  $endpoint  a manifest endpoint
     * @return array<string, mixed>
     */
    public function forEndpoint(array $endpoint, string $range = '24h'): array
    {
        $summary = $this->reader->requestSummary($range, route: $endpoint['path'], channel: $this->channel($endpoint));

        if (($summary['has_data'] ?? false) !== true) {
            return [
                'measured' => false,
                'status' => 'no_traffic',
                // Serialised rather than handed over as an object: the portal's other health
                // sources return a plain reason, and a view that has to know which of the two it
                // got is a view that breaks the first time the other one appears.
                'reason' => Metric::noData(
                    'monitoring_request_buckets',
                    'No request to this endpoint has been recorded in this window. That is not the same as zero errors — nobody has called it.',
                )->jsonSerialize(),
                'range' => $range,
            ];
        }

        $errorRate = (float) $summary['error_rate'];
        $p95 = $summary['p95'];

        return [
            'measured' => true,
            'status' => $this->verdict($errorRate, $p95),
            'range' => $range,
            'hits' => (int) $summary['hits'],
            'errors' => (int) $summary['errors'],
            'client_errors' => (int) $summary['client_errors'],
            'error_rate' => round($errorRate, 2),
            'requests_per_minute' => $summary['requests_per_minute'],
            'p50' => $summary['p50'],
            'p95' => $p95,
            'p99' => $summary['p99'],
            'avg' => $summary['avg'],
            'db_ms_avg' => $summary['db_ms_avg'],
            'last_error' => $this->lastError($endpoint['path']),
            'source' => 'monitoring_request_buckets',
        ];
    }

    /**
     * Health for a whole list at once.
     *
     * One query for the window rather than one per endpoint: an explorer listing 229 vendor
     * endpoints must not issue 229 aggregate queries to decorate them.
     *
     * @param  array<int, array<string, mixed>>  $endpoints
     * @return array<string, array<string, mixed>>  endpoint id => health
     */
    public function forMany(array $endpoints, string $range = '24h'): array
    {
        if ($endpoints === []) {
            return [];
        }

        $window = $this->reader->window($range);
        $paths = array_values(array_unique(array_column($endpoints, 'path')));

        try {
            $rows = $this->reader->connection()->table('monitoring_request_buckets')
                ->where('resolution', $window['resolution'])
                ->where('bucket_at', '>=', $this->reader->since($range))
                ->whereIn('route', $paths)
                ->get();
        } catch (\Throwable) {
            return [];
        }

        $byRoute = [];

        foreach ($rows as $row) {
            $byRoute[$row->route][] = $row;
        }

        $health = [];

        foreach ($endpoints as $endpoint) {
            $bucket = $byRoute[$endpoint['path']] ?? null;

            if ($bucket === null) {
                $health[$endpoint['id']] = ['measured' => false, 'status' => 'no_traffic'];

                continue;
            }

            $summary = $this->reader->summariseBuckets($bucket, $window['minutes']);

            $health[$endpoint['id']] = [
                'measured' => true,
                'status' => $this->verdict((float) $summary['error_rate'], $summary['p95']),
                'hits' => (int) $summary['hits'],
                'error_rate' => round((float) $summary['error_rate'], 2),
                'p95' => $summary['p95'],
            ];
        }

        return $health;
    }

    /**
     * Which app versions are still calling this endpoint.
     *
     * This is what makes a deprecation safe rather than hopeful: an endpoint with four percent of
     * its traffic coming from a shipped Android build cannot be removed, whatever the plan said.
     *
     * @return array<string, mixed>
     */
    public function callers(array $endpoint, string $range = '30d'): array
    {
        // Shop-wide, and it says so. Sessions carry an app version; they do not carry the endpoints
        // that session called, and nothing else in this system records a version against a route —
        // so a genuine per-endpoint breakdown cannot be produced today. Presenting this one under
        // an endpoint's own heading, as it used to be, claimed a measurement nobody took.
        $days = max(1, (int) filter_var($range, FILTER_SANITIZE_NUMBER_INT) ?: 30);

        try {
            $rows = DB::connection(config('analytics.connection'))
                ->table('analytics_sessions')
                ->whereNotNull('app_version')
                ->where('started_at', '>=', Clock::daysAgo($days))
                ->selectRaw('app_version, COUNT(*) sessions')
                ->groupBy('app_version')
                ->orderByDesc('sessions')
                ->limit(10)
                ->get();
        } catch (\Throwable) {
            $rows = collect();
        }

        if ($rows->isEmpty()) {
            return [
                'measured' => false,
                'reason' => Metric::notConfigured(
                    'analytics_sessions.app_version',
                    'Have the mobile apps send an X-App-Version header on every request. Until they do, there is no way to tell which release is calling an endpoint, and no deprecation can be proven safe.',
                    'No request has arrived carrying an app version.',
                )->jsonSerialize(),
            ];
        }

        $total = (int) $rows->sum('sessions');

        return [
            'measured' => true,
            'range' => $range,
            'days' => $days,
            'scope' => 'shop',
            'note' => 'Across the whole shop, not this endpoint: sessions record an app version, not the endpoints they called.',
            'versions' => $rows->map(fn ($row) => [
                'version' => $row->app_version,
                'sessions' => (int) $row->sessions,
                'share' => $total > 0 ? round(100 * $row->sessions / $total, 1) : null,
            ])->all(),
        ];
    }

    /**
     * Is it safe to remove this endpoint?
     *
     * Answered from traffic, not from intention. The refusal is the useful part: "3.7% of active
     * sessions still use this" is a fact somebody can act on, where "are you sure?" is not.
     *
     * @return array<string, mixed>
     */
    public function removalSafety(array $endpoint): array
    {
        $traffic = $this->trafficSince($endpoint['path'], 30);

        // Silence is ambiguous and must be reported as ambiguous. "Nobody calls this" and
        // "monitoring was not collecting" produce identical zeroes, and only one of them makes a
        // removal safe — so the answer depends on whether ANYTHING was recorded in the window.
        if (!$traffic['collecting']) {
            return [
                'safe' => null,
                'verdict' => 'unknown',
                'message' => 'Monitoring has recorded no requests at all in the last 30 days, so silence on this endpoint proves nothing. Check that collection is running before treating it as unused.',
            ];
        }

        if ($traffic['hits'] === 0) {
            return [
                'safe' => true,
                'verdict' => 'unused',
                'message' => 'No requests in 30 days, over a window in which ' . number_format($traffic['total_hits'])
                    . ' request(s) to other endpoints were recorded — so this is genuine silence, not a gap in collection.',
            ];
        }

        return [
            'safe' => false,
            'verdict' => 'in_use',
            'hits' => $traffic['hits'],
            'message' => "Cannot safely remove: {$traffic['hits']} request(s) in the last 30 days. Deprecate it, name its replacement, give callers a sunset date, and remove it once the traffic reaches zero.",
        ];
    }

    /**
     * Traffic to one route over a window, and whether monitoring was collecting at all.
     *
     * Reads every resolution rather than one, because the rollups fold minutes into hours into
     * days on a schedule: asking only for daily buckets makes this morning's traffic invisible,
     * and asking only for minutes makes last month's invisible. The comparison total answers the
     * question that actually matters — was anything being recorded in this window.
     *
     * @return array{hits: int, total_hits: int, collecting: bool}
     */
    private function trafficSince(string $path, int $days): array
    {
        try {
            $since = Clock::daysAgo($days);

            $hits = (int) $this->reader->connection()->table('monitoring_request_buckets')
                ->where('route', $path)
                ->where('bucket_at', '>=', $since)
                ->sum('hits');

            $total = (int) $this->reader->connection()->table('monitoring_request_buckets')
                ->where('bucket_at', '>=', $since)
                ->sum('hits');

            return ['hits' => $hits, 'total_hits' => $total, 'collecting' => $total > 0];
        } catch (\Throwable) {
            return ['hits' => 0, 'total_hits' => 0, 'collecting' => false];
        }
    }

    // -------------------------------------------------------------------------------------------

    private function verdict(float $errorRate, mixed $p95): string
    {
        if ($errorRate >= 5) {
            return 'failing';
        }

        if ($errorRate >= 1 || (is_numeric($p95) && $p95 >= 2000)) {
            return 'degraded';
        }

        return 'healthy';
    }

    /** API requests are recorded on the api channel; the panel routes on their own. */
    private function channel(array $endpoint): ?string
    {
        return $endpoint['surface'] === 'api' ? 'api' : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastError(string $path): ?array
    {
        try {
            $row = $this->reader->connection()->table('monitoring_error_groups')
                ->where('route', $path)
                ->orderByDesc('last_seen_at')
                ->first(['type', 'message', 'last_seen_at', 'occurrences']);

            return $row === null ? null : [
                'type' => $row->type,
                'message' => $row->message,
                'at' => $row->last_seen_at,
                'occurrences' => (int) $row->occurrences,
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
