<?php

namespace App\Services\Monitoring\Panels;

use App\Services\DeveloperPortal\ApiDoc;
use App\Services\DeveloperPortal\ApiManifest;
use App\Services\DeveloperPortal\EndpointHealthService;
use App\Services\DeveloperPortal\Support\EndpointClassifier;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Http\Request;

/**
 * This shop's own API surface: what is documented, what is actually being called, and what neither.
 *
 * Two halves that only mean something together. The Developer Portal's manifest knows every route
 * the application serves, its version, its audience and whether anybody has marked it deprecated —
 * and knows nothing about whether a single caller exists. The monitoring buckets know every request
 * that arrived on the api channel, keyed by the same route PATTERN the manifest documents — and
 * know nothing about what any of them was for. Joining them on the route is what turns "441
 * endpoints" and "two requests" into the four questions this page answers: which version carries
 * the traffic, which endpoints cost the most, which deprecated endpoint is still blocking its own
 * removal, and which endpoints nobody called at all.
 *
 * Silence is the dangerous half, and it is reported twice over. An endpoint with no traffic has no
 * error rate — not a zero — which is EndpointHealthService's own rule and the reason this panel
 * calls it rather than deriving a verdict of its own. And a whole page of silence proves nothing
 * unless SOMETHING was recorded: with collection stopped, all 441 endpoints look unused, and a
 * removal decision taken from that would delete a live endpoint. So the api-channel total is
 * published beside the silent list as its proof, and when that total is zero the list says the
 * silence is unproven rather than letting it read as evidence.
 *
 * Percentiles are never added up. A version's p95 is not the mean of its endpoints' p95s and there
 * is no per-version histogram in the store, so the version table carries the traffic-weighted mean
 * (which IS exact — it is duration_sum over hits) and the p95 of its single worst endpoint, each
 * labelled as what it is rather than one of them being passed off as the other.
 */
class ApisPanel implements Panel
{
    /** The channel RequestRecorder::channelOf() files every request under api/ as. */
    private const CHANNEL = 'api';

    private const SOURCE = 'monitoring_request_buckets (channel=api)';

    private const MANIFEST_SOURCE = 'ApiManifest (derived from the live route table)';

    /** The manifest only documents this surface; the panel routes are a different section's job. */
    private const SURFACE = 'api';

    /** Rows in the busiest and slowest tables. */
    private const TABLE_ROWS = 15;

    /** Rows in the never-called list. The count above it is complete; only the listing is cut. */
    private const MAX_SILENT_ROWS = 50;

    /**
     * Deprecated endpoints given a per-endpoint health read.
     *
     * Each one costs its own windowed query through EndpointHealthService, so the list that gets
     * that treatment is bounded. A build with more than this many deprecations has a bigger problem
     * than a truncated table, and the table says it was cut.
     */
    private const MAX_DEPRECATED = 25;

    /** Traffic to api paths the manifest does not document — a short list or a real finding. */
    private const MAX_UNMATCHED = 15;

    /**
     * Ranking is applied to every api route in the window, not to a pre-truncated head.
     *
     * The reader builds the complete set either way; the limit only decides how much comes back,
     * so the fifteen slowest are the fifteen slowest rather than the slowest of the busiest.
     */
    private const ALL_ROUTES = PHP_INT_MAX;

    /** The bucket a path with no /v{n}/ segment falls into, in the manifest's own word. */
    private const UNVERSIONED = 'unversioned';

    /** EndpointHealthService::verdict()'s vocabulary, plus its own no-traffic answer. */
    private const HEALTH_STATUSES = ['healthy', 'degraded', 'failing', 'no_traffic'];

    /** The audiences EndpointClassifier can assign — the allowlist that makes translate() safe. */
    private const AUDIENCES = [
        ApiDoc::CUSTOMER_APP, ApiDoc::VENDOR_APP, ApiDoc::DELIVERY_APP, ApiDoc::WEB,
        ApiDoc::ADMIN, ApiDoc::PARTNER, ApiDoc::PUBLIC_API, 'unclassified',
    ];

    /** ApiDoc's stability vocabulary. */
    private const STABILITIES = [ApiDoc::STABLE, ApiDoc::BETA, ApiDoc::DEPRECATED, ApiDoc::EXPERIMENTAL];

    public function __construct(
        private readonly SeriesReader $reader,
        private readonly ApiManifest $manifest,
        private readonly EndpointHealthService $health,
        private readonly EndpointClassifier $classifier,
    ) {
    }

    public function data(string $range, Request $request): array
    {
        $window = $this->reader->window($range);
        $collection = $this->collection();
        $documented = $this->documented();
        $traffic = $this->traffic($range, $collection, $window);
        $measured = $this->measured($range, $collection, $window);
        $joined = $this->join($documented, $measured, $window['minutes']);
        // Whether this window was watched at all. A quiet window that was watched gives a measured
        // zero; a window nothing was recorded for gives no number, because a zero would be a claim
        // about callers made out of a gap in collection.
        $counted = $measured['state'] !== 'failed' && in_array($traffic['state'], ['ok', 'no_data'], true);
        $versions = $this->versions($documented, $joined, $window, $counted);
        $filters = $this->filters($request, $versions);

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
            'manifest' => $this->manifestBlock($documented),
            'traffic' => $traffic,
            'headline' => $this->headline($documented, $traffic, $joined, $counted),
            'coverage' => $this->coverage($joined, $traffic, $window),
            'filters' => $filters,
            'versions' => $versions,
            'busiest' => $this->ranked($joined, $measured, $filters, 'hits'),
            'slowest' => $this->ranked($joined, $measured, $filters, 'p95'),
            'deprecated' => $this->deprecated($documented, $traffic, $filters, $range, $counted),
            'silent' => $this->silent($documented, $joined, $traffic, $filters),
            'unmatched' => $this->unmatched($joined, $measured, $documented),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Is anything arriving at all

    /**
     * Whether requests are still being folded into buckets.
     *
     * Asked before anything else, because every silence on this page has two readings: an endpoint
     * nobody calls and an endpoint nobody measured look identical, and only one of them makes a
     * removal safe. This mirrors RequestsPanel::collectionState() deliberately — the same fault
     * must not be described in two different ways on two pages — and there is no shared home for it
     * that does not mean editing a file this panel is not allowed to touch.
     *
     * @return array<string, mixed>
     */
    private function collection(): array
    {
        if (!config('monitoring.enabled', true)) {
            return [
                'state' => 'not_configured',
                'note' => 'Monitoring collection is switched off, so nothing has been recorded since it was disabled. Anything below is whatever was captured before that, and an endpoint with no traffic here may simply never have been watched.',
                'remedy' => 'Set MONITORING_ENABLED=true in .env, then run `php artisan optimize:clear`.',
                'newest_bucket_at' => null,
                'age_seconds' => null,
            ];
        }

        $newest = $this->reader->newestBucketAt('monitoring_request_buckets');

        if ($newest === null) {
            return [
                'state' => 'no_data',
                'note' => 'No request of any kind has ever been folded into a bucket on this deployment, so nothing here can say whether an endpoint is used.',
                'remedy' => 'Requests are buffered per minute and written by `php artisan monitoring:flush`, scheduled every minute. Check the Laravel scheduler is running: `php artisan schedule:list`.',
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

    // -------------------------------------------------------------------------------------------
    // The documentation half

    /**
     * The API surface as the Developer Portal knows it.
     *
     * Read through ApiManifest rather than off the route table directly: the manifest is what the
     * portal documents, it is cached against a fingerprint of the live routes, and a second reading
     * of the same routes would eventually disagree with the first — which is how two screens end up
     * quoting different endpoint counts for one application.
     *
     * @return array<string, mixed>
     */
    private function documented(): array
    {
        $empty = [
            'source' => self::MANIFEST_SOURCE,
            'generated_at' => null,
            'app_version' => null,
            'endpoints' => [],
            'by_path' => [],
            'total' => null,
            'documented' => null,
            'deprecated' => null,
            'rate_limited' => null,
            'unclassified' => null,
            'by_version' => [],
        ];

        try {
            $manifest = $this->manifest->get();
        } catch (\Throwable $exception) {
            // Caught here rather than left to PanelRegistry: without the manifest this page loses
            // the documentation half, while the traffic half — which is a different read of a
            // different store — is still perfectly readable and still worth drawing.
            return array_merge($empty, [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'remedy' => 'The manifest is built from the live route table by app/Services/DeveloperPortal/ApiManifest.php. Rebuild it from Developer Portal → Refresh, or clear its cache with `php artisan cache:clear`.',
            ]);
        }

        $endpoints = [];
        $byPath = [];

        foreach ($manifest['endpoints'] ?? [] as $endpoint) {
            if (!is_array($endpoint) || ($endpoint['surface'] ?? null) !== self::SURFACE) {
                continue;
            }

            $row = $this->documentedRow($endpoint);
            $endpoints[] = $row;
            // One entry per path, not per endpoint: the buckets are keyed by path and method, and
            // two verbs on one path are one thing to document and two things to measure.
            $byPath[$row['path']] ??= $row;
        }

        $summary = is_array($manifest['summary'] ?? null) ? $manifest['summary'] : [];

        return array_merge($empty, [
            'state' => $endpoints === [] ? 'no_data' : 'ok',
            'note' => $endpoints === []
                ? 'The manifest was built and holds no endpoint under api/, so there is no documented API surface to compare traffic against.'
                : null,
            'remedy' => null,
            'generated_at' => $this->shortText($manifest['generated_at'] ?? null, 32),
            'app_version' => $this->shortText($manifest['app_version'] ?? null, 32),
            'endpoints' => $endpoints,
            'by_path' => $byPath,
            'total' => count($endpoints),
            'documented' => $this->integerOrNull($summary['documented'] ?? null),
            'deprecated' => $this->integerOrNull($summary['deprecated'] ?? null),
            'rate_limited' => $this->integerOrNull($summary['rate_limited'] ?? null),
            'unclassified' => $this->integerOrNull($summary['unclassified'] ?? null),
            'by_version' => $this->countsByVersion($endpoints),
        ]);
    }

    /**
     * The manifest block as the page publishes it: the counts, without the four hundred rows.
     *
     * The endpoint list is read to build the tables and then dropped. The same payload is served as
     * JSON on every refresh of this page, and shipping the whole documented surface through it
     * would send three quarters of a megabyte down the wire to draw a handful of numbers.
     *
     * @param  array<string, mixed>  $documented
     * @return array<string, mixed>
     */
    private function manifestBlock(array $documented): array
    {
        unset($documented['endpoints'], $documented['by_path']);

        return $documented;
    }

    /**
     * One manifest endpoint, reduced to what this page draws.
     *
     * Every free string is bounded, and the two that a view might want to translate carry a flag
     * saying whether they are one of ours: translate() persists any key it has not seen, so a
     * group name derived from a controller class must never reach it.
     *
     * @param  array<string, mixed>  $endpoint
     * @return array<string, mixed>
     */
    private function documentedRow(array $endpoint): array
    {
        $audience = (string) ($endpoint['audience'] ?? 'unclassified');
        $stability = (string) ($endpoint['stability'] ?? ApiDoc::STABLE);

        return [
            'id' => $this->shortText($endpoint['id'] ?? null, 32),
            'path' => (string) ($endpoint['path'] ?? ''),
            'methods' => array_values(array_filter(
                array_map(fn ($method) => $this->shortText($method, 8), (array) ($endpoint['methods'] ?? [])),
            )),
            'version' => $this->versionKey($endpoint['version'] ?? null),
            'group' => $this->shortText($endpoint['group'] ?? null, 48),
            'audience' => $audience,
            'audience_known' => in_array($audience, self::AUDIENCES, true),
            'stability' => $stability,
            'stability_known' => in_array($stability, self::STABILITIES, true),
            'deprecated' => (bool) ($endpoint['deprecated'] ?? false),
            'deprecated_since' => $this->shortText($endpoint['deprecated_since'] ?? null, 32),
            'sunset_at' => $this->shortText($endpoint['sunset_at'] ?? null, 32),
            'replaced_by' => $this->shortText($endpoint['replaced_by'] ?? null, 191),
            'documented' => (bool) ($endpoint['documented'] ?? false),
            'summary' => $this->shortText($endpoint['summary'] ?? null, 120),
            'auth_required' => isset($endpoint['auth']['required']) ? (bool) $endpoint['auth']['required'] : null,
            'rate_limit' => $this->integerOrNull($endpoint['rate_limit']['requests'] ?? null),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // The traffic half

    /**
     * Everything that arrived on the api channel in this window, as one summary.
     *
     * @param  array<string, mixed>  $collection
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>
     */
    private function traffic(string $range, array $collection, array $window): array
    {
        try {
            $summary = $this->reader->requestSummary($range, channel: self::CHANNEL);
        } catch (\Throwable $exception) {
            return array_merge($this->unmeasuredSummary(), [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'remedy' => null,
                'source' => self::SOURCE,
            ]);
        }

        return array_merge(
            $summary,
            ['source' => self::SOURCE],
            ($summary['has_data'] ?? false)
                ? ['state' => 'ok', 'note' => null, 'remedy' => null]
                : $this->emptyReason($collection, $window, 'No request reached the api channel in this window.'),
        );
    }

    /**
     * Every api route with traffic in the window, folded once and ordered four different ways later.
     *
     * Rides monitoring_request_bucket_window (resolution, bucket_at) — the read is narrowed to one
     * resolution and one window start, then to the api channel, and the reader crosses the rollup
     * seam so a coarse window still sees the minutes the rollup has not folded yet. That seam is
     * the reason this is not a hand-rolled query: without it the table under the headline is short
     * of it by up to fifty-six minutes of traffic, and two numbers on one screen disagree.
     *
     * @param  array<string, mixed>  $collection
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>
     */
    private function measured(string $range, array $collection, array $window): array
    {
        try {
            $rows = $this->reader->routeBreakdown($range, sort: 'hits', limit: self::ALL_ROUTES, channel: self::CHANNEL);
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'remedy' => null,
                'source' => self::SOURCE,
                'rows' => [],
            ];
        }

        // The reader answers its own failed read with an empty list, so "nothing was called" and
        // "the per-route read did not work" arrive here identical. They are told apart one block
        // down, by comparing this read's total with the window total taken by a separate query —
        // which is what the coverage line exists to say out loud.
        return array_merge(
            ['source' => self::SOURCE, 'rows' => $rows],
            $rows === []
                ? $this->emptyReason($collection, $window, 'No api route recorded a request in this window.')
                : ['state' => 'ok', 'note' => null, 'remedy' => null],
        );
    }

    // -------------------------------------------------------------------------------------------
    // The join

    /**
     * Traffic rows and manifest rows against each other, on the route pattern they share.
     *
     * @param  array<string, mixed>  $documented
     * @param  array<string, mixed>  $measured
     * @return array<string, mixed>
     */
    private function join(array $documented, array $measured, int $minutes): array
    {
        $byPath = $documented['by_path'];
        $known = $documented['state'] === 'ok';

        $rows = [];
        $calledPaths = [];
        $undocumentedPaths = [];

        foreach ($measured['rows'] as $route) {
            $path = (string) ($route['route'] ?? '');
            $endpoint = $byPath[$path] ?? null;
            $calledPaths[$path] = true;

            if ($endpoint === null && $known) {
                $undocumentedPaths[$path] = true;
            }

            $rows[] = $this->endpointRow($route, $endpoint, $known, $minutes);
        }

        return [
            'rows' => $rows,
            'called_paths' => array_keys($calledPaths),
            'undocumented_paths' => array_keys($undocumentedPaths),
            'hits' => (int) array_sum(array_column($rows, 'hits')),
        ];
    }

    /**
     * One measured route, wearing whatever the manifest knows about it.
     *
     * @param  array<string, mixed>  $route
     * @param  array<string, mixed>|null  $endpoint
     * @return array<string, mixed>
     */
    private function endpointRow(array $route, ?array $endpoint, bool $manifestReadable, int $minutes): array
    {
        $path = (string) ($route['route'] ?? '');
        $hits = (int) ($route['hits'] ?? 0);

        return [
            'path' => $path,
            'method' => (string) ($route['method'] ?? ''),
            // Synthetic keys the recorder writes when a request matched no route, and the folded
            // key the cardinality guard writes. Neither is a path, so neither can be looked up.
            'linkable' => $path !== '' && !str_starts_with($path, '__'),
            // All three of these are three-valued for the same reason. False is "the manifest was
            // read and does not carry this"; null is "there was no manifest to ask". Flattening the
            // second into the first would report an undocumented, undeprecated endpoint — two
            // findings — out of a read that never happened.
            'in_manifest' => $manifestReadable ? ($endpoint !== null) : null,
            'documented' => $endpoint !== null ? $endpoint['documented'] : ($manifestReadable ? false : null),
            'deprecated' => $endpoint !== null ? $endpoint['deprecated'] : ($manifestReadable ? false : null),
            'sunset_at' => $endpoint['sunset_at'] ?? null,
            'replaced_by' => $endpoint['replaced_by'] ?? null,
            // A route with no manifest entry still has a version in its own path, and reading it
            // with the classifier the portal uses keeps the two answers the same answer.
            'version' => $endpoint['version'] ?? $this->versionOfPath($path),
            'version_from_path' => $endpoint === null,
            'group' => $endpoint['group'] ?? null,
            'audience' => $endpoint['audience'] ?? null,
            'audience_known' => (bool) ($endpoint['audience_known'] ?? false),
            'summary' => $endpoint['summary'] ?? null,
            'auth_required' => $endpoint['auth_required'] ?? null,
            'hits' => $hits,
            'errors' => (int) ($route['errors'] ?? 0),
            'client_errors' => (int) ($route['client_errors'] ?? 0),
            'timeouts' => (int) ($route['timeouts'] ?? 0),
            'error_rate' => $this->floatOrNull($route['error_rate'] ?? null),
            'client_error_rate' => $this->floatOrNull($route['client_error_rate'] ?? null),
            // Recomputed at three decimals rather than taken at the reader's two: two requests
            // across a day is 0.001/min, and a rate printed as 0.00 next to a count of 2 is the
            // page contradicting itself in adjacent columns.
            'requests_per_minute' => round($hits / max(1, $minutes), 3),
            'avg' => $this->floatOrNull($route['avg'] ?? null),
            'p50' => $this->floatOrNull($route['p50'] ?? null),
            'p95' => $this->floatOrNull($route['p95'] ?? null),
            'p99' => $this->floatOrNull($route['p99'] ?? null),
            'db_ms_avg' => $this->floatOrNull($route['db_ms_avg'] ?? null),
            'total_time_ms' => $this->integerOrNull($route['total_time_ms'] ?? null),
            'severity' => $this->errorSeverity($this->floatOrNull($route['error_rate'] ?? null)),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Per version

    /**
     * v1 against v2 against v3: how much each carries and how badly each is failing.
     *
     * Version is a grouping of route patterns rather than a column — nothing in the bucket key
     * records it — so this is a fold of the same per-route read the tables below use, and it agrees
     * with them by construction. The mean is exact (duration_sum over hits, weighted by traffic);
     * the p95 belongs to one named endpoint, because percentiles do not add.
     *
     * @param  array<string, mixed>  $documented
     * @param  array<string, mixed>  $joined
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>
     */
    private function versions(array $documented, array $joined, array $window, bool $counted): array
    {
        $folded = [];

        foreach ($documented['by_version'] as $version => $count) {
            $folded[$version] = $this->emptyVersion($version, $count);
        }

        foreach ($joined['rows'] as $row) {
            $version = $row['version'];
            $folded[$version] ??= $this->emptyVersion($version, $documented['state'] === 'ok' ? 0 : null);

            $folded[$version]['hits'] += $row['hits'];
            $folded[$version]['errors'] += $row['errors'];
            $folded[$version]['client_errors'] += $row['client_errors'];
            $folded[$version]['timeouts'] += $row['timeouts'];
            $folded[$version]['total_time_ms'] += (int) $row['total_time_ms'];
            $folded[$version]['called_paths'][$row['path']] = true;

            if ($row['in_manifest'] === false) {
                $folded[$version]['undocumented_paths'][$row['path']] = true;
            }

            if ($row['p95'] !== null && ($folded[$version]['worst_p95_ms'] === null || $row['p95'] > $folded[$version]['worst_p95_ms'])) {
                $folded[$version]['worst_p95_ms'] = $row['p95'];
                $folded[$version]['worst_p95_path'] = $row['path'];
                $folded[$version]['worst_p95_method'] = $row['method'];
                $folded[$version]['worst_p95_linkable'] = $row['linkable'];
            }
        }

        $totalHits = max(0, $joined['hits']);
        $minutes = max(1, $window['minutes']);
        $rows = [];

        foreach ($folded as $version => $entry) {
            $hits = $entry['hits'];
            $called = count($entry['called_paths']);
            $documentedCount = $entry['documented_endpoints'];

            $rows[] = [
                'version' => $version,
                'documented_endpoints' => $documentedCount,
                // Null rather than zero when the window was never watched: "no endpoint in v2 was
                // called" and "nothing was recorded" are the same empty fold and opposite findings.
                'endpoints_called' => $counted ? $called : null,
                'endpoints_called_undocumented' => $counted ? count($entry['undocumented_paths']) : null,
                // Never negative, and null when there is no documented count to subtract from:
                // more paths can be called than are documented when traffic hits a route the
                // manifest does not carry, and a negative "never called" would be nonsense.
                'endpoints_silent' => $documentedCount === null || !$counted
                    ? null
                    : max(0, $documentedCount - ($called - count($entry['undocumented_paths']))),
                'hits' => $counted ? $hits : null,
                'errors' => $counted ? $entry['errors'] : null,
                'client_errors' => $counted ? $entry['client_errors'] : null,
                'timeouts' => $counted ? $entry['timeouts'] : null,
                // No requests means no rate. A zero here would say this version answers everything
                // perfectly, which is a claim about traffic that did not happen.
                'error_rate' => $hits > 0 ? round(100 * $entry['errors'] / $hits, 3) : null,
                'client_error_rate' => $hits > 0 ? round(100 * $entry['client_errors'] / $hits, 3) : null,
                'requests_per_minute' => $hits > 0 ? round($hits / $minutes, 3) : null,
                'share' => $counted && $totalHits > 0 ? round(100 * $hits / $totalHits, 1) : null,
                'avg_ms' => $hits > 0 ? round($entry['total_time_ms'] / $hits, 1) : null,
                'worst_p95_ms' => $entry['worst_p95_ms'],
                'worst_p95_path' => $entry['worst_p95_path'],
                'worst_p95_method' => $entry['worst_p95_method'],
                'worst_p95_linkable' => $entry['worst_p95_linkable'],
                'severity' => $this->errorSeverity($hits > 0 ? round(100 * $entry['errors'] / $hits, 3) : null),
            ];
        }

        // Versions in their own order — v1, v2, v3 — with the unnumbered bucket last, because it is
        // the exception rather than the beginning of the sequence.
        usort($rows, static function (array $a, array $b) {
            $order = static fn (string $version) => preg_match('/^v(\d+)$/', $version, $matches) === 1
                ? (int) $matches[1]
                : PHP_INT_MAX;

            return [$order($a['version']), $a['version']] <=> [$order($b['version']), $b['version']];
        });

        return [
            'state' => $rows === [] ? ($documented['state'] === 'failed' ? 'failed' : 'no_data') : 'ok',
            'note' => $rows === []
                ? ($documented['state'] === 'failed'
                    ? $documented['note']
                    : 'Neither the manifest nor the window holds an api endpoint to group by version.')
                : null,
            'remedy' => $rows === [] && $documented['state'] === 'failed' ? $documented['remedy'] : null,
            'source' => self::SOURCE . ' + ' . self::MANIFEST_SOURCE,
            'rows' => $rows,
            'measured' => $counted,
            'total_hits' => $counted ? $totalHits : null,
            // Said in the payload rather than only in the view: a JSON consumer reading avg_ms
            // beside worst_p95_ms has no column heading to tell it these are different animals.
            'mean_definition' => 'The mean is traffic-weighted and exact: total duration over hits, folded from the same per-route buckets as the tables below.',
            'percentile_caveat' => 'There is no per-version percentile in the store — percentiles cannot be added across endpoints — so the column names the single slowest endpoint in the version instead of pretending to a p95 for the whole of it.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyVersion(string $version, ?int $documentedEndpoints): array
    {
        return [
            'version' => $version,
            'documented_endpoints' => $documentedEndpoints,
            'hits' => 0,
            'errors' => 0,
            'client_errors' => 0,
            'timeouts' => 0,
            'total_time_ms' => 0,
            'called_paths' => [],
            'undocumented_paths' => [],
            'worst_p95_ms' => null,
            'worst_p95_path' => null,
            'worst_p95_method' => null,
            'worst_p95_linkable' => false,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // The filter

    /**
     * The one filter this page offers: a version.
     *
     * Clamped against the versions that actually exist on this deployment, and applied in PHP over
     * rows already read — it never reaches a query, so it can change what is listed and can never
     * change what is scanned.
     *
     * @param  array<string, mixed>  $versions
     * @return array<string, mixed>
     */
    private function filters(Request $request, array $versions): array
    {
        $choices = array_column($versions['rows'], 'version');
        $version = $this->queryString($request, 'version');

        if (!in_array($version, $choices, true)) {
            $version = 'all';
        }

        return [
            'version' => $version,
            'choices' => $choices,
            'narrowed' => $version !== 'all',
        ];
    }

    /**
     * One query value, or 'all' when it is not a single string.
     *
     * `?version[]=v1` hands the request an array, and casting one to string is a warning the error
     * handler turns into a throw — which would take the whole section down with an "Array to string
     * conversion" card. A filter nobody can spell is simply not applied.
     */
    private function queryString(Request $request, string $key): string
    {
        $value = $request->query($key, 'all');

        return is_string($value) ? mb_substr(trim($value), 0, 32) : 'all';
    }

    // -------------------------------------------------------------------------------------------
    // The tables

    /**
     * The busiest, or the slowest, out of everything measured.
     *
     * Two orderings of one read. Ranking happens over every api route in the window and the cut
     * comes afterwards, because the fifteen slowest are not a subset of the fifteen busiest.
     *
     * @param  array<string, mixed>  $joined
     * @param  array<string, mixed>  $measured
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function ranked(array $joined, array $measured, array $filters, string $sort): array
    {
        if ($measured['state'] !== 'ok') {
            return [
                'state' => $measured['state'],
                'note' => $measured['note'],
                'remedy' => $measured['remedy'],
                'source' => self::SOURCE,
                'sort' => $sort,
                'rows' => [],
                'truncated' => false,
                'limit' => self::TABLE_ROWS,
            ];
        }

        $rows = $this->narrow($joined['rows'], $filters);

        usort($rows, match ($sort) {
            // A route whose p95 could not be interpolated sorts last rather than first: null is the
            // absence of a measurement, and -1 keeps it out of the place the worst offender sits.
            'p95' => static fn (array $a, array $b) => ($b['p95'] ?? -1) <=> ($a['p95'] ?? -1),
            default => static fn (array $a, array $b) => [$b['hits'], $b['total_time_ms'] ?? 0] <=> [$a['hits'], $a['total_time_ms'] ?? 0],
        });

        return [
            'state' => $rows === [] ? 'no_data' : 'ok',
            'note' => $rows === []
                ? ($filters['narrowed']
                    ? 'No api route in the selected version recorded a request in this window.'
                    : 'No api route recorded a request in this window.')
                : null,
            'remedy' => null,
            'source' => self::SOURCE,
            'sort' => $sort,
            'rows' => array_slice($rows, 0, self::TABLE_ROWS),
            'truncated' => count($rows) > self::TABLE_ROWS,
            'limit' => self::TABLE_ROWS,
            'measured_routes' => count($rows),
        ];
    }

    /**
     * Endpoints marked deprecated in the documentation that callers have not stopped calling.
     *
     * The removal-blocking list, and the one place this page asks EndpointHealthService directly
     * rather than folding the shared read: that service is where "an endpoint nobody called has no
     * error rate" is already written, and its answer for a deprecated endpoint is exactly the
     * answer this table needs. It costs one windowed query per endpoint, which is why the list it
     * is asked about is capped.
     *
     * @param  array<string, mixed>  $documented
     * @param  array<string, mixed>  $traffic
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function deprecated(array $documented, array $traffic, array $filters, string $range, bool $counted): array
    {
        $base = [
            'source' => self::MANIFEST_SOURCE . ' + ' . self::SOURCE,
            'rows' => [],
            'truncated' => false,
            'limit' => self::MAX_DEPRECATED,
            'still_called' => null,
            'proof' => $this->silenceProof($traffic),
        ];

        if ($documented['state'] === 'failed') {
            return array_merge($base, [
                'state' => 'failed',
                'note' => $documented['note'],
                'remedy' => $documented['remedy'],
            ]);
        }

        $deprecated = $this->narrow(
            array_values(array_filter($documented['endpoints'], static fn (array $endpoint) => $endpoint['deprecated'])),
            $filters,
        );

        if ($deprecated === []) {
            // A measured zero over a real read of every route, not an empty table. The manifest
            // looked at all of them and none carries a deprecation, which is good news and has to
            // be drawn as good news rather than as a gap.
            return array_merge($base, [
                'state' => 'none_deprecated',
                'note' => $filters['narrowed']
                    ? 'No endpoint in the selected version is documented as deprecated.'
                    : 'No endpoint on this build is documented as deprecated, across ' . (int) $documented['total'] . ' api endpoints read from the live route table.',
                'remedy' => 'Deprecation is declared in code: add #[ApiDoc(stability: ApiDoc::DEPRECATED, deprecatedSince: ..., sunsetAt: ..., replacedBy: ...)] to the controller method. Until one carries it, nothing can appear here — this list cannot find a deprecation nobody wrote down.',
                'still_called' => 0,
            ]);
        }

        $rows = [];
        $stillCalled = 0;

        foreach (array_slice($deprecated, 0, self::MAX_DEPRECATED) as $endpoint) {
            $health = $this->endpointHealth($endpoint, $range, $counted);
            $rows[] = array_merge($endpoint, $health);

            if ($health['still_called'] === true) {
                $stillCalled++;
            }
        }

        return array_merge($base, [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'rows' => $rows,
            'truncated' => count($deprecated) > self::MAX_DEPRECATED,
            // Null, not zero. With nothing recorded in the window, "none of them is still called"
            // is the sentence that gets an endpoint deleted while its callers are still calling it.
            'still_called' => $counted ? $stillCalled : null,
        ]);
    }

    /**
     * One endpoint's traffic, asked of the service that owns the question.
     *
     * `last_error` comes back from it and is deliberately dropped: monitoring_error_groups has no
     * writer in this build, and the service's own query for it names a column that table does not
     * have — so it can only ever be null, and a permanently blank "last error" column reads as an
     * endpoint that has never failed.
     *
     * @param  array<string, mixed>  $endpoint
     * @return array<string, mixed>
     */
    private function endpointHealth(array $endpoint, string $range, bool $counted): array
    {
        try {
            $health = $this->health->forEndpoint(
                ['path' => $endpoint['path'], 'surface' => self::SURFACE],
                $range,
            );
        } catch (\Throwable $exception) {
            return [
                'measured' => false,
                'status' => 'failed',
                'status_known' => false,
                'still_called' => null,
                'reason' => null,
                'note' => $this->failureNote($exception),
                'hits' => null, 'error_rate' => null, 'p95' => null, 'requests_per_minute' => null,
            ];
        }

        $measured = (bool) ($health['measured'] ?? false);
        $status = (string) ($health['status'] ?? 'no_traffic');

        return [
            'measured' => $measured,
            'status' => $status,
            'status_known' => in_array($status, self::HEALTH_STATUSES, true),
            // Three-valued. False is "nobody called it in this window"; null is "nothing was
            // recorded at all", and only the first of those is an argument for removing it.
            'still_called' => $measured ? ((int) ($health['hits'] ?? 0)) > 0 : ($counted ? false : null),
            // The service's own words for why there is no number, kept whole rather than reworded.
            'reason' => is_array($health['reason'] ?? null) ? $health['reason'] : null,
            'note' => null,
            'hits' => $measured ? (int) ($health['hits'] ?? 0) : null,
            'error_rate' => $measured ? $this->floatOrNull($health['error_rate'] ?? null) : null,
            'p95' => $measured ? $this->floatOrNull($health['p95'] ?? null) : null,
            'requests_per_minute' => $measured ? $this->floatOrNull($health['requests_per_minute'] ?? null) : null,
        ];
    }

    /**
     * Documented endpoints that recorded nothing at all in this window.
     *
     * The count is complete; only the listing is cut. And the count is worthless on its own, which
     * is why the block carries its proof: with no api request recorded anywhere in the window,
     * every endpoint is silent and none of that silence is evidence.
     *
     * @param  array<string, mixed>  $documented
     * @param  array<string, mixed>  $joined
     * @param  array<string, mixed>  $traffic
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function silent(array $documented, array $joined, array $traffic, array $filters): array
    {
        $base = [
            'source' => self::MANIFEST_SOURCE . ' + ' . self::SOURCE,
            'rows' => [],
            'total' => null,
            'truncated' => false,
            'limit' => self::MAX_SILENT_ROWS,
            'proof' => $this->silenceProof($traffic),
            'window_only' => 'Silence here covers the selected window only. The Developer Portal answers the removal question over thirty days, which is the span a decision to delete an endpoint needs.',
        ];

        if ($documented['state'] !== 'ok') {
            return array_merge($base, [
                'state' => $documented['state'],
                'note' => $documented['note'] ?? 'The documented API surface could not be read, so endpoints without traffic cannot be named.',
                'remedy' => $documented['remedy'],
            ]);
        }

        $called = array_flip($joined['called_paths']);
        $silent = $this->narrow(
            array_values(array_filter(
                $documented['endpoints'],
                static fn (array $endpoint) => !isset($called[$endpoint['path']]),
            )),
            $filters,
        );

        usort($silent, static fn (array $a, array $b) => [$a['version'], $a['path']] <=> [$b['version'], $b['path']]);

        return array_merge($base, [
            'state' => $silent === [] ? 'all_called' : 'ok',
            'note' => $silent === []
                ? 'Every documented api endpoint in this selection recorded at least one request in this window.'
                : null,
            'remedy' => null,
            'rows' => array_slice($silent, 0, self::MAX_SILENT_ROWS),
            'total' => count($silent),
            'truncated' => count($silent) > self::MAX_SILENT_ROWS,
        ]);
    }

    /**
     * Traffic on the api channel that the manifest does not document.
     *
     * Small and boring on a healthy build, and a real finding when it is not: a path here is either
     * a route the portal cannot describe, a request that matched no route at all — the recorder
     * files those under __unmatched__ — or an endpoint removed while callers still call it.
     *
     * @param  array<string, mixed>  $joined
     * @param  array<string, mixed>  $measured
     * @param  array<string, mixed>  $documented
     * @return array<string, mixed>
     */
    private function unmatched(array $joined, array $measured, array $documented): array
    {
        if ($documented['state'] !== 'ok') {
            // Without the documented surface there is nothing to be undocumented against, and
            // "every request went to a documented route" would be a claim made out of a missing read.
            return [
                'state' => $documented['state'],
                'note' => $documented['note'] ?? 'The documented API surface could not be read, so traffic cannot be checked against it.',
                'remedy' => $documented['remedy'] ?? null,
                'source' => self::SOURCE,
                'rows' => [],
                'truncated' => false,
                'limit' => self::MAX_UNMATCHED,
                'hits' => null,
            ];
        }

        if ($measured['state'] !== 'ok') {
            return [
                'state' => $measured['state'],
                'note' => $measured['note'],
                'remedy' => $measured['remedy'],
                'source' => self::SOURCE,
                'rows' => [],
                'truncated' => false,
                'limit' => self::MAX_UNMATCHED,
                'hits' => null,
            ];
        }

        $rows = array_values(array_filter($joined['rows'], static fn (array $row) => $row['in_manifest'] === false));

        usort($rows, static fn (array $a, array $b) => $b['hits'] <=> $a['hits']);

        return [
            'state' => $rows === [] ? 'no_data' : 'ok',
            'note' => $rows === []
                ? 'Every api request in this window went to a route the Developer Portal documents.'
                : null,
            'remedy' => null,
            'source' => self::SOURCE,
            'rows' => array_slice($rows, 0, self::MAX_UNMATCHED),
            'truncated' => count($rows) > self::MAX_UNMATCHED,
            'limit' => self::MAX_UNMATCHED,
            'hits' => (int) array_sum(array_column($rows, 'hits')),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // The numbers above the tables

    /**
     * @param  array<string, mixed>  $documented
     * @param  array<string, mixed>  $traffic
     * @param  array<string, mixed>  $joined
     * @return array<string, Metric>
     */
    private function headline(array $documented, array $traffic, array $joined, bool $counted): array
    {
        $headline = [];
        $measured = (bool) ($traffic['has_data'] ?? false);

        // A watched window that stayed quiet is still a measurement, and its zero is a real zero —
        // but it is a zero about a window in which nothing happened, so the sentence travels with
        // the number instead of being left in a banner somebody scrolls past.
        $blind = $measured
            ? null
            : 'No api request was recorded in this window, so this counts what monitoring saw rather than what callers did.';

        if ($traffic['state'] !== 'failed') {
            $headline['api_requests'] = $measured
                ? Metric::of(value: (int) $traffic['hits'], source: self::SOURCE)
                : Metric::noData(source: self::SOURCE, note: $traffic['note']);
            $headline['api_error_rate'] = $measured
                ? Metric::of(value: $traffic['error_rate'], source: self::SOURCE, unit: '%', note: '5xx only; 4xx is counted separately.')
                : Metric::noData(source: self::SOURCE, note: 'No request means no rate — this is not a clean window, it is an unmeasured one.');
            $headline['api_client_error_rate'] = $measured
                ? Metric::of(value: $traffic['client_error_rate'], source: self::SOURCE, unit: '%')
                : Metric::noData(source: self::SOURCE, note: 'No request means no rate.');
            $headline['api_p95_response_time'] = $measured
                ? Metric::of(value: $traffic['p95'], source: self::SOURCE, unit: 'ms', note: 'Interpolated from the stored latency histogram, over every api route together.')
                : Metric::noData(source: self::SOURCE, note: 'Nothing was timed on the api channel in this window.');
        }

        if ($documented['state'] === 'failed') {
            return $headline;
        }

        $headline['documented_api_endpoints'] = Metric::of(
            value: (int) $documented['total'],
            source: self::MANIFEST_SOURCE,
            unit: null,
            note: 'Every route under api/ the application serves, whether or not anybody has written an ApiDoc attribute for it.',
        );

        if (!$counted) {
            // Nothing was watched, so "441 endpoints had no traffic" would be a finding about
            // callers assembled entirely out of our own blindness.
            $headline['endpoints_called_in_this_window'] = $this->unmeasuredMetric($traffic, self::SOURCE);
            $headline['endpoints_with_no_traffic_in_this_window'] = $this->unmeasuredMetric($traffic, self::MANIFEST_SOURCE . ' + ' . self::SOURCE);

            return $headline;
        }

        $documentedCalled = count(array_diff($joined['called_paths'], $joined['undocumented_paths']));

        $headline['endpoints_called_in_this_window'] = Metric::of(
            value: $documentedCalled,
            source: self::SOURCE,
            unit: null,
            note: $blind,
        );
        $headline['endpoints_with_no_traffic_in_this_window'] = Metric::of(
            value: max(0, (int) $documented['total'] - $documentedCalled),
            source: self::MANIFEST_SOURCE . ' + ' . self::SOURCE,
            unit: null,
            note: $blind ?? 'Silent in this window only, which is not the same as unused.',
        );

        return $headline;
    }

    /**
     * The reading that could not be taken, in the vocabulary of why.
     *
     * Collection switched off is actionable and says so; a collector that has fallen behind is a
     * different job from one that was never started, and neither is "no data".
     *
     * @param  array<string, mixed>  $traffic
     */
    private function unmeasuredMetric(array $traffic, string $source): Metric
    {
        $note = (string) ($traffic['note'] ?? 'The api channel was not measured in this window.');
        $remedy = is_string($traffic['remedy'] ?? null) ? $traffic['remedy'] : null;

        return match ((string) ($traffic['state'] ?? 'no_data')) {
            'not_configured' => Metric::notConfigured(
                source: $source,
                remedy: $remedy ?? 'Set MONITORING_ENABLED=true in .env, then run `php artisan optimize:clear`.',
                note: $note,
            ),
            'stale' => Metric::collectorOffline(source: $source, note: $note, remedy: $remedy),
            default => Metric::noData(source: $source, note: $note),
        };
    }

    /**
     * How much of the window's api traffic the per-route tables can actually see.
     *
     * Two reads of one store answer this page: a summary of the channel and a breakdown per route.
     * They can differ — and when they do, saying so is the difference between a page that looks
     * broken and a page that explains itself.
     *
     * @param  array<string, mixed>  $joined
     * @param  array<string, mixed>  $traffic
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>
     */
    private function coverage(array $joined, array $traffic, array $window): array
    {
        $measuredHits = (int) $joined['hits'];
        $windowHits = ($traffic['has_data'] ?? false) ? (int) $traffic['hits'] : null;

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
            'note' => 'The per-endpoint tables account for ' . number_format($measuredHits) . ' of the '
                . number_format($windowHits) . ' api requests this window holds. The rest are in buckets the '
                . $window['resolution'] . ' read did not group, so an endpoint can be missing from the tables '
                . 'while its traffic is still in the total above.',
        ];
    }

    // -------------------------------------------------------------------------------------------

    /**
     * Whether silence in this window is evidence of anything.
     *
     * The same refusal EndpointHealthService::removalSafety() makes over thirty days, applied to
     * the window on screen: with nothing recorded, "nobody called this" and "nobody was listening"
     * are the same picture, and only one of them makes a removal safe.
     *
     * @param  array<string, mixed>  $traffic
     * @return array<string, mixed>
     */
    private function silenceProof(array $traffic): array
    {
        if (($traffic['has_data'] ?? false) === true) {
            return [
                'state' => 'ok',
                'recorded_hits' => (int) $traffic['hits'],
                'note' => number_format((int) $traffic['hits']) . ' api request(s) were recorded in this window, so an endpoint with none of them was genuinely not called.',
            ];
        }

        return [
            'state' => 'unproven',
            'recorded_hits' => null,
            'note' => 'No api request at all was recorded in this window, so silence on any single endpoint proves nothing about it. ' . (string) ($traffic['note'] ?? ''),
        ];
    }

    /**
     * Narrow a list of rows to the selected version.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function narrow(array $rows, array $filters): array
    {
        if (!$filters['narrowed']) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            static fn (array $row) => ($row['version'] ?? null) === $filters['version'],
        ));
    }

    /**
     * Documented endpoints per version, which is the denominator every silence count needs.
     *
     * @param  array<int, array<string, mixed>>  $endpoints
     * @return array<string, int>
     */
    private function countsByVersion(array $endpoints): array
    {
        $counts = [];

        foreach ($endpoints as $endpoint) {
            $counts[$endpoint['version']] = ($counts[$endpoint['version']] ?? 0) + 1;
        }

        return $counts;
    }

    /** The manifest's own word for a path with no version segment, never an empty label. */
    private function versionKey(mixed $version): string
    {
        return is_string($version) && trim($version) !== '' ? mb_substr(trim($version), 0, 12) : self::UNVERSIONED;
    }

    /**
     * The version of a path the manifest does not carry.
     *
     * Read with the portal's own classifier rather than a second regular expression here: two rules
     * for one question drift, and the day they disagree the same endpoint sits in two versions.
     */
    private function versionOfPath(string $path): string
    {
        try {
            return $this->versionKey($this->classifier->version(ltrim($path, '/')));
        } catch (\Throwable) {
            return self::UNVERSIONED;
        }
    }

    /**
     * How loudly a failure rate is drawn, against the same thresholds the overview scores against —
     * so an endpoint called amber here is not called green two clicks away.
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
     * ever been recorded, the collector has fallen behind, or the API really did serve nothing.
     * Only the last is a reading of the traffic.
     *
     * @param  array<string, mixed>  $collection
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array{state: string, note: string, remedy: string|null}
     */
    private function emptyReason(array $collection, array $window, string $quietNote): array
    {
        $state = $collection['state'] ?? 'ok';

        if (in_array($state, ['not_configured', 'no_data'], true)) {
            return ['state' => $state, 'note' => (string) $collection['note'], 'remedy' => $collection['remedy']];
        }

        $age = (int) ($collection['age_seconds'] ?? 0);

        if ($state === 'stale' && $age >= $window['minutes'] * 60) {
            return [
                'state' => 'stale',
                'note' => 'Nothing has been recorded since ' . (string) $collection['newest_bucket_at']
                    . ', which is before this window begins — so this window has not been measured, and its emptiness is not a reading of the API.',
                'remedy' => $collection['remedy'],
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

    /**
     * A summary shaped like the reader's, with every figure explicitly absent.
     *
     * @return array<string, mixed>
     */
    private function unmeasuredSummary(): array
    {
        return [
            'has_data' => false,
            'hits' => 0, 'errors' => 0, 'client_errors' => 0, 'timeouts' => 0,
            'error_rate' => null, 'client_error_rate' => null, 'timeout_rate' => null,
            'requests_per_second' => null, 'requests_per_minute' => null,
            'p50' => null, 'p75' => null, 'p90' => null, 'p95' => null, 'p99' => null,
            'max' => null, 'avg' => null,
            'db_ms_avg' => null, 'db_queries_avg' => null, 'external_ms_avg' => null,
            'response_bytes_avg' => null, 'request_bytes_avg' => null,
        ];
    }

    private function failureNote(\Throwable $exception): string
    {
        return class_basename($exception) . ': ' . mb_substr($exception->getMessage(), 0, 300);
    }

    /**
     * A count, or null when there was none to give.
     *
     * Null is preserved rather than cast: (int) null is 0, and a zero in a rate-limit column would
     * say this endpoint allows no requests at all.
     */
    private function integerOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function shortText(mixed $value, int $length): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $length);
    }
}
