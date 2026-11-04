<?php

namespace App\Services\Telemetry;

use App\Services\DeveloperPortal\ApiManifest;
use App\Services\DeveloperPortal\ApiSnapshotService;
use App\Services\DeveloperPortal\ConsoleGuard;
use App\Services\DeveloperPortal\EndpointHealthService;
use App\Services\DeveloperPortal\Generators\CodeExampleGenerator;
use App\Services\DeveloperPortal\Generators\OpenApiGenerator;
use App\Services\DeveloperPortal\Support\AuthResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * The portal's screens, assembled from the manifest.
 *
 * This class used to BE the portal: it walked the route table itself, grouped by URI segment and
 * carried fifteen hand-written guides in a PHP array. The live-route idea was the good part and it
 * is kept — but it now sits on the manifest, which knows about authentication, permissions, rate
 * limits, validation and versions, so every screen below is derived rather than described.
 *
 * Nothing here writes. The portal is a reader of the system it documents, and a reader that could
 * change the thing it reads would eventually be blamed for an outage it caused.
 */
class DeveloperPortalService
{
    /** The quality table lists this many endpoints; the count above it says how many there are. */
    private const QUALITY_TABLE_LIMIT = 200;

    public function __construct(
        private readonly ApiManifest $manifest,
        private readonly ApiSnapshotService $snapshots,
        private readonly EndpointHealthService $health,
        private readonly OpenApiGenerator $openApi,
        private readonly CodeExampleGenerator $examples,
        private readonly AuthResolver $auth,
    ) {
    }

    /**
     * What this installation can actually offer, so the navigation does not advertise absences.
     *
     * @return array<string, bool>
     */
    public function capabilities(): array
    {
        return [
            'webhooks' => $this->hasInboundWebhooks(),
            'monitoring' => $this->hasMonitoring(),
            'snapshots' => $this->snapshots->ready(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $manifest = $this->manifest->get();
        $summary = $manifest['summary'];

        return [
            'summary' => $summary,
            'base_url' => $manifest['base_url'],
            'app_version' => $manifest['app_version'],
            'generated_at' => $manifest['generated_at'],
            'health' => $this->apiHealth(),
            'recent_changes' => array_slice($this->snapshots->changelog(8), 0, 8),
            'latest_snapshot' => $this->snapshots->latest(),
            'quality' => $this->qualityScore(),
        ];
    }

    /**
     * Overall API health, from the monitoring buckets rather than a second collection path.
     *
     * @return array<string, mixed>
     */
    public function apiHealth(string $range = '24h'): array
    {
        if (!$this->hasMonitoring()) {
            return [
                'measured' => false,
                'reason' => 'Monitoring is not collecting on this installation, so there are no request numbers to report. Nothing here falls back to zeros: a zero error rate and no data look identical and mean opposite things.',
            ];
        }

        $reader = app(\App\Services\Monitoring\Support\SeriesReader::class);
        $summary = $reader->requestSummary($range, channel: 'api');

        if (($summary['has_data'] ?? false) !== true) {
            return [
                'measured' => false,
                'reason' => 'No API request has been recorded in this window. Monitoring is collecting — nothing has called the API.',
            ];
        }

        return [
            'measured' => true,
            'range' => $range,
            'requests' => (int) $summary['hits'],
            'errors' => (int) $summary['errors'],
            'error_rate' => round((float) $summary['error_rate'], 2),
            'p95' => $summary['p95'],
            'p99' => $summary['p99'],
            'avg' => $summary['avg'],
            'requests_per_minute' => $summary['requests_per_minute'],
        ];
    }

    /**
     * The explorer: a filtered, health-decorated endpoint list.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function explorer(array $filters = [], int $perPage = 50, int $page = 1): array
    {
        $all = $this->manifest->endpoints($filters + ['surface' => 'api']);
        $total = count($all);
        $slice = array_slice($all, max(0, ($page - 1) * $perPage), $perPage);
        $health = $this->hasMonitoring() ? $this->health->forMany($slice) : [];

        foreach ($slice as $index => $endpoint) {
            $slice[$index]['health'] = $health[$endpoint['id']] ?? ['measured' => false, 'status' => 'no_traffic'];
        }

        return [
            'endpoints' => $slice,
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / max(1, $perPage)),
            'filters' => $filters,
            'facets' => $this->facets($all),
        ];
    }

    /**
     * One endpoint, with everything a developer needs on a single page.
     *
     * @return array<string, mixed>|null
     */
    public function endpoint(string $id): ?array
    {
        $endpoint = $this->manifest->endpoint($id);

        if ($endpoint === null) {
            return null;
        }

        $baseUrl = $this->manifest->get()['base_url'] ?: url('/');

        return $endpoint + [
            'examples' => $this->examples->all($endpoint, $baseUrl),
            'health' => $this->hasMonitoring() ? $this->health->forEndpoint($endpoint) : ['measured' => false, 'status' => 'no_monitoring'],
            'removal' => $this->health->removalSafety($endpoint),
            'callers' => $this->health->callers($endpoint),
            'related' => $this->related($endpoint),
            'changes' => $this->changesFor($endpoint['id']),
            // What this endpoint has actually been seen answering with. Derived from real
            // responses because the controllers return JSON directly and there is no type to
            // reflect — keys and types only, never a value.
            'observed' => $this->observedResponses($endpoint),
            // Whether the console may send each of this endpoint's methods, and what it needs
            // first. Decided here so the page draws the answer rather than deciding it: a button
            // that appears when it should not is how a guard gets discovered by being bypassed.
            'console' => $this->consoleVerdicts($endpoint),
            'full_url' => rtrim($baseUrl, '/') . $endpoint['path'],
        ];
    }

    /**
     * @param  array<string, mixed>  $endpoint
     * @return array<string, array<string, mixed>>
     */
    private function consoleVerdicts(array $endpoint): array
    {
        $guard = app(ConsoleGuard::class);
        $verdicts = [];

        foreach ($endpoint['methods'] as $method) {
            $verdict = $guard->verdict($endpoint, $method);

            $verdicts[$method] = $verdict + [
                'confirmation' => $guard->confirmationFor($method),
                // Translated here rather than in the page: the page receives these as JSON and a
                // sentence built in JavaScript would mint a language key per endpoint.
                'message' => $verdict['reason_key'] !== null ? translate($verdict['reason_key']) : null,
            ];
        }

        return $verdicts;
    }

    /**
     * @param  array<string, mixed>  $endpoint
     * @return array<int, array<string, mixed>>
     */
    private function observedResponses(array $endpoint): array
    {
        $recorder = app(\App\Services\DeveloperPortal\ResponseShapeRecorder::class);
        $byMethod = $recorder->all()[$endpoint['path']] ?? [];
        $found = [];

        foreach ($endpoint['methods'] as $method) {
            foreach ($byMethod[$method] ?? [] as $status => $record) {
                $found[$status] = $record + ['status' => $status, 'method' => $method];
            }
        }

        ksort($found);

        return array_values($found);
    }

    /**
     * How many endpoints have been seen answering successfully at least once.
     *
     * The response-shape gap is the only one that closes without anybody writing anything: the
     * portal learns the shape from a real 2xx. So the screen shows the progress rather than a
     * static count that looks permanent.
     *
     * @return array<string, int>
     */
    private function observedEndpointCount(): array
    {
        try {
            $shapes = app(\App\Services\DeveloperPortal\ResponseShapeRecorder::class)->all();
        } catch (\Throwable) {
            return ['endpoints' => 0, 'with_success' => 0];
        }

        $withSuccess = 0;

        foreach ($shapes as $byMethod) {
            foreach ($byMethod as $byStatus) {
                foreach (array_keys($byStatus) as $status) {
                    if ((int) $status >= 200 && (int) $status < 300) {
                        $withSuccess++;

                        break 2;
                    }
                }
            }
        }

        return ['endpoints' => count($shapes), 'with_success' => $withSuccess];
    }

    /**
     * The conventions screens, all read from the code rather than described.
     *
     * @return array<string, mixed>
     */
    public function conventions(string $section): array
    {
        return match ($section) {
            'authentication' => [
                'schemes' => $this->auth->schemes(),
                'usage' => $this->authenticationUsage(),
                'guards' => array_map(
                    static fn (array $guard) => ['driver' => $guard['driver'], 'provider' => $guard['provider']],
                    (array) config('auth.guards'),
                ),
            ],
            'errors' => [
                'envelope' => ['errors' => [['code' => 'field_or_condition', 'message' => 'A translated, human-readable explanation.']]],
                'statuses' => $this->errorCatalogue(),
                'note' => 'Validation failures on this API return HTTP 403, not 422. That is long-standing behaviour the shipped apps depend on; changing it would break them, so it is documented rather than corrected.',
            ],
            'rate_limits' => ['limits' => $this->rateLimits()],
            'pagination' => [
                'style' => 'offset',
                'envelope' => ['total_size' => 128, 'limit' => 10, 'offset' => 1, 'items' => '…'],
                'note' => 'Offset-based, and offset counts PAGES rather than records in most of this API: limit=10&offset=2 returns records 11-20. Read total_size to know when to stop.',
                'endpoints' => $this->paginatedEndpoints(),
            ],
            'uploads' => ['endpoints' => $this->uploadEndpoints()],
            default => [],
        };
    }

    /**
     * The documentation-quality screen: what this portal cannot tell a developer, and why.
     *
     * @return array<string, mixed>
     */
    public function quality(): array
    {
        $warnings = $this->openApi->warnings();
        $byReason = [];

        foreach ($warnings as $warning) {
            foreach ($warning['missing'] as $reason) {
                $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
            }
        }

        arsort($byReason);

        $summary = $this->manifest->get()['summary'];
        $observed = $this->observedEndpointCount();

        return [
            'score' => $this->qualityScore(),
            'summary' => $summary,
            'by_reason' => $byReason,
            'endpoints' => array_slice($warnings, 0, self::QUALITY_TABLE_LIMIT),
            'total_flagged' => count($warnings),
            // The screen used to headline the number of ENDPOINTS with a gap, which reads as that
            // many distinct problems. It is three gaps, each shared by hundreds of endpoints — and
            // knowing that is the difference between a hopeless list and three afternoons of work.
            'api_endpoints' => (int) ($summary['api'] ?? 0),
            'distinct_gaps' => count($byReason),
            'table_limit' => self::QUALITY_TABLE_LIMIT,
            // Response shapes are learned from live traffic, so this gap closes itself. How far
            // along that is, is a fact the screen should show rather than leave to be discovered.
            'observed' => $observed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function changelog(?string $severity = null): array
    {
        return [
            'changes' => $this->snapshots->changelog(300, $severity),
            'snapshots' => $this->snapshots->snapshots(),
            'latest' => $this->snapshots->latest(),
        ];
    }

    /**
     * Versions, with who still calls each one — the number that decides whether v1 can be retired.
     *
     * @return array<string, mixed>
     */
    public function versions(): array
    {
        $summary = $this->manifest->get()['summary'];
        $versions = [];

        foreach ($summary['by_version'] as $version => $count) {
            $endpoints = $this->manifest->endpoints(['version' => $version === 'unversioned' ? null : $version]);
            $endpoints = array_filter($endpoints, static fn (array $e) => $e['surface'] === 'api'
                && ($version === 'unversioned' ? $e['version'] === null : $e['version'] === $version));

            $versions[$version] = [
                'endpoints' => $count,
                'deprecated' => count(array_filter($endpoints, static fn (array $e) => $e['deprecated'])),
                'audiences' => array_count_values(array_column($endpoints, 'audience')),
                'traffic' => $this->versionTraffic($version),
            ];
        }

        return ['versions' => $versions];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function deprecations(): array
    {
        $deprecated = array_filter(
            $this->manifest->endpoints(),
            static fn (array $endpoint) => $endpoint['deprecated'],
        );

        return array_map(function (array $endpoint) {
            return $endpoint + ['removal' => $this->health->removalSafety($endpoint)];
        }, array_values($deprecated));
    }

    // -------------------------------------------------------------------------------------------

    /**
     * How complete the documentation is, as a number somebody can watch move.
     *
     * @return array<string, mixed>
     */
    private function qualityScore(): array
    {
        $summary = $this->manifest->get()['summary'];
        $total = max(1, $summary['api']);

        // Weighted by what a developer is actually blocked without: a described endpoint they can
        // find, a request schema they can build against, and a classification that tells them
        // whether it is even theirs to call.
        $documented = $summary['documented'] / $total;
        $schemas = $summary['with_body_schema'] / $total;
        $classified = 1 - ($summary['unclassified'] / $total);

        return [
            'score' => (int) round(100 * (0.4 * $documented + 0.4 * $schemas + 0.2 * $classified)),
            'documented_pct' => round(100 * $documented, 1),
            'schema_pct' => round(100 * $schemas, 1),
            'classified_pct' => round(100 * $classified, 1),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $endpoints
     * @return array<string, array<string, int>>
     */
    private function facets(array $endpoints): array
    {
        $facets = ['audience' => [], 'version' => [], 'group' => [], 'method' => [], 'visibility' => []];

        foreach ($endpoints as $endpoint) {
            $facets['audience'][$endpoint['audience']] = ($facets['audience'][$endpoint['audience']] ?? 0) + 1;
            $facets['version'][$endpoint['version'] ?? 'unversioned'] = ($facets['version'][$endpoint['version'] ?? 'unversioned'] ?? 0) + 1;
            $facets['group'][$endpoint['group']] = ($facets['group'][$endpoint['group']] ?? 0) + 1;
            $facets['visibility'][$endpoint['visibility']] = ($facets['visibility'][$endpoint['visibility']] ?? 0) + 1;

            foreach ($endpoint['methods'] as $method) {
                $facets['method'][$method] = ($facets['method'][$method] ?? 0) + 1;
            }
        }

        foreach ($facets as $key => $counts) {
            arsort($counts);
            $facets[$key] = $counts;
        }

        return $facets;
    }

    /**
     * Endpoints a developer is likely to need next: the rest of this resource.
     *
     * @return array<int, array<string, mixed>>
     */
    private function related(array $endpoint): array
    {
        $siblings = array_filter(
            $this->manifest->endpoints(['audience' => $endpoint['audience'], 'group' => $endpoint['group']]),
            static fn (array $candidate) => $candidate['id'] !== $endpoint['id'],
        );

        return array_slice(array_values($siblings), 0, 8);
    }

    /**
     * @return array<int, object>
     */
    private function changesFor(string $endpointId): array
    {
        if (!$this->snapshots->ready()) {
            return [];
        }

        try {
            return DB::table('api_changes')
                ->where('endpoint_id', $endpointId)
                ->orderByDesc('detected_at')
                ->limit(10)
                ->get()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Which authentication scheme is used how many times, so the Authentication page leads with
     * the one a reader is most likely to need.
     *
     * @return array<string, int>
     */
    private function authenticationUsage(): array
    {
        $usage = [];

        foreach ($this->manifest->endpoints() as $endpoint) {
            if ($endpoint['surface'] !== 'api') {
                continue;
            }

            $mechanism = $endpoint['auth']['mechanism'] ?? 'public';
            $usage[$mechanism] = ($usage[$mechanism] ?? 0) + 1;
        }

        arsort($usage);

        return $usage;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function errorCatalogue(): array
    {
        return [
            ['status' => 200, 'meaning' => 'Success.', 'note' => 'This API returns 200 for most successful writes rather than 201.'],
            ['status' => 401, 'meaning' => 'No credentials, or credentials that are not valid any more.', 'note' => 'Re-authenticate. A vendor token cannot be used on customer endpoints and the other way around.'],
            ['status' => 403, 'meaning' => 'Validation failed, OR the caller lacks a permission.', 'note' => 'Both share this status here. Read the errors array: a validation failure names the field in code, a permission failure names the module.'],
            ['status' => 404, 'meaning' => 'The record does not exist, or is not visible to this caller.', 'note' => 'Inactive products and other vendors\' records read as missing rather than forbidden, deliberately.'],
            ['status' => 429, 'meaning' => 'Rate limited.', 'note' => 'Back off and retry. The limit on each endpoint is shown on its own page.'],
            ['status' => 500, 'meaning' => 'The request failed inside the application.', 'note' => 'Keep the X-Request-Id from the response headers: it is what makes the failure findable in Monitoring.'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rateLimits(): array
    {
        $limits = [];

        foreach ($this->manifest->endpoints() as $endpoint) {
            if ($endpoint['surface'] !== 'api' || ($endpoint['rate_limit']['requests'] ?? null) === null) {
                continue;
            }

            $key = $endpoint['rate_limit']['requests'] . '/' . $endpoint['rate_limit']['minutes'];
            $limits[$key]['requests'] = $endpoint['rate_limit']['requests'];
            $limits[$key]['minutes'] = $endpoint['rate_limit']['minutes'];
            $limits[$key]['endpoints'][] = implode('|', $endpoint['methods']) . ' ' . $endpoint['path'];
        }

        uasort($limits, static fn (array $a, array $b) => $a['requests'] <=> $b['requests']);

        return array_values($limits);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function paginatedEndpoints(): array
    {
        return array_values(array_filter($this->manifest->endpoints(), static function (array $endpoint) {
            if ($endpoint['surface'] !== 'api') {
                return false;
            }

            foreach ($endpoint['body'] as $field) {
                if (in_array($field['name'], ['limit', 'offset', 'page'], true)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function uploadEndpoints(): array
    {
        return array_values(array_filter($this->manifest->endpoints(), static function (array $endpoint) {
            foreach ($endpoint['body'] as $field) {
                if (($field['type'] ?? null) === 'file') {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @return array<string, mixed>
     */
    private function versionTraffic(string $version): array
    {
        if (!$this->hasMonitoring() || $version === 'unversioned') {
            return ['measured' => false];
        }

        try {
            $hits = (int) DB::connection(config('monitoring.connection'))
                ->table('monitoring_request_buckets')
                ->where('channel', 'api')
                ->where('route', 'like', "/api/{$version}/%")
                ->where('bucket_at', '>=', now()->subDays(30))
                ->sum('hits');

            return ['measured' => true, 'hits_30d' => $hits];
        } catch (\Throwable) {
            return ['measured' => false];
        }
    }

    /** Does this installation receive webhooks from anybody? */
    private function hasInboundWebhooks(): bool
    {
        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), 'webhook') || str_contains($route->uri(), 'callback')) {
                return true;
            }
        }

        return false;
    }

    private function hasMonitoring(): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::connection(config('monitoring.connection'))
                ->hasTable('monitoring_request_buckets');
        } catch (\Throwable) {
            return false;
        }
    }
}
