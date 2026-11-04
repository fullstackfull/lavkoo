<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Collectors\CollectorRegistry;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Http\Request;

/**
 * The tier in front of PHP: which server is actually answering, how many connections it is
 * holding, and whether the pool behind it has anywhere to put them.
 *
 * The page is built out of two sources that are never folded into one another. The web server's
 * own status endpoint counts every byte it served, static assets included; the shop's request
 * buckets count only what reached PHP. Adding them would put a step change in a chart the day
 * somebody exposes stub_status, so they sit in separate cards with separate provenance and the
 * page says which is which.
 *
 * Most of this section is legitimately unavailable on a fresh deployment, and that is the point.
 * Neither nginx's stub_status nor PHP-FPM's pm.status_path is on by default, so the connection and
 * pool counters arrive as one not_configured reading repeated across a dozen keys. Twelve copies
 * of one fault read as twelve faults, so the shared reason is lifted to the card that would have
 * held them and drawn once, with the exact configuration lines beside it. Nothing here is ever
 * drawn as a zero: a pool with no status page has not told us it has no queue.
 *
 * The runtime this pool executes — version, limits, debug mode, the compiled caches — is the
 * application section's subject and is named here rather than repeated, so the two pages can never
 * disagree about the same number.
 */
class WebServerPanel implements Panel
{
    /** The two collectors this section is made of. Each is read exactly once per request. */
    private const COLLECTORS = ['webserver', 'php'];

    /**
     * Why a collector that answered nothing at all answered nothing, per collector.
     *
     * "Not installed in this build" and "installed and unable to read" are different sentences for
     * different people, and an empty card cannot tell them apart on its own.
     */
    private const COLLECTOR_ABSENT = [
        'webserver' => 'The webserver collector is not installed in this build, so nothing on this page can name the server in front of PHP or read its counters.',
        'php' => 'The php collector is not installed in this build, so neither the FPM pool nor OPcache can be read here.',
    ];

    /**
     * The server types the collector classifies into.
     *
     * Published as an allowlist because the view translates this value, and translate() persists
     * any key it has not seen into the language files — a value that turned out to be free text
     * would mint a language key per host.
     *
     * @var array<int, string>
     */
    private const SERVER_TYPES = ['nginx', 'apache', 'development', 'unknown'];

    /** Identity readings, drawn as cards. `label => metric name`. */
    private const IDENTITY_METRICS = [
        'web_server' => 'server',
        'server_family' => 'server_type',
        'php_sapi' => 'sapi',
        'development_server' => 'is_development_server',
    ];

    /**
     * Everything the web server's own status endpoint feeds.
     *
     * The live numbers lead and the since-start counters follow: an operator opens this card to
     * see what is happening now, and a lifetime total placed first reads as current load.
     */
    private const CONNECTION_METRICS = [
        'active_connections' => 'active_connections',
        'requests_per_second' => 'requests_per_s',
        'accepted_per_second' => 'accepted_per_s',
        'reading_requests' => 'reading',
        'writing_responses' => 'writing',
        'idle_keep_alive_connections' => 'waiting',
        'busy_workers' => 'busy_workers',
        'idle_workers' => 'idle_workers',
        'worker_saturation' => 'worker_saturation_pct',
        'accepted_since_start' => 'accepts',
        'handled_since_start' => 'handled',
        'dropped_connections' => 'dropped_connections',
        'requests_since_start' => 'requests',
    ];

    /** What the shop itself served, from the request buckets — available with no configuration. */
    private const TRAFFIC_METRICS = [
        'requests_in_the_last_five_minutes' => 'app_requests_5m',
        'requests_per_second' => 'app_requests_per_s',
        'client_error_rate' => 'app_4xx_rate_pct',
        'server_error_rate' => 'app_5xx_rate_pct',
        'timed_out_requests' => 'app_timeouts',
    ];

    /** The pool that runs the PHP behind the server above. */
    private const FPM_METRICS = [
        'pool' => 'fpm_pool',
        'active_processes' => 'fpm_active_processes',
        'idle_processes' => 'fpm_idle_processes',
        'total_processes' => 'fpm_total_processes',
        'busiest_since_start' => 'fpm_max_active_processes',
        'listen_queue' => 'fpm_listen_queue',
        'deepest_listen_queue' => 'fpm_max_listen_queue',
        'listen_queue_capacity' => 'fpm_listen_queue_len',
        'max_children_reached' => 'fpm_max_children_reached',
        'slow_requests' => 'fpm_slow_requests',
        'accepted_connections' => 'fpm_accepted_connections',
    ];

    /** The accelerator in front of that pool. */
    private const OPCACHE_METRICS = [
        'enabled' => 'opcache_enabled',
        'hit_rate' => 'opcache_hit_rate',
        'memory_used' => 'opcache_memory_used',
        'memory_free' => 'opcache_memory_free',
        'wasted_memory' => 'opcache_wasted',
        'wasted_percentage' => 'opcache_wasted_pct',
        'cached_scripts' => 'opcache_cached_scripts',
        'max_cached_keys' => 'opcache_max_cached_keys',
        'hits' => 'opcache_hits',
        'misses' => 'opcache_misses',
        'out_of_memory_restarts' => 'opcache_oom_restarts',
        'hash_table_restarts' => 'opcache_hash_restarts',
        'manual_restarts' => 'opcache_manual_restarts',
    ];

    /**
     * Readings the php collector publishes that the application section draws.
     *
     * They are claimed rather than redrawn. Two pages printing the same memory limit under two
     * different provenances is a disagreement waiting to happen, and dropping them silently would
     * make a measured value indistinguishable from one nobody took — so they are named, with the
     * section that holds them.
     *
     * @var array<string, array<int, string>>
     */
    private const RENDERED_ELSEWHERE = [
        'webserver' => [],
        'php' => [
            'version', 'sapi', 'laravel_version', 'environment', 'debug_mode', 'timezone',
            'memory_limit', 'max_execution_time', 'upload_max_filesize', 'post_max_size', 'is_fpm',
        ],
    ];

    /** The section those readings are drawn on. */
    private const ELSEWHERE_SECTION = 'application';

    /**
     * The status classes the request buckets count, mapped to the swatch that draws each.
     *
     * The collector's own label carries a slash, which is not a CSS class; the tone is chosen here
     * from a fixed set so the view never builds a selector out of stored text.
     *
     * @var array<string, string>
     */
    private const STATUS_TONES = ['2xx/3xx' => '2xx', '4xx' => '4xx', '5xx' => '5xx'];

    /**
     * The stored gauges this section charts.
     *
     * `collector` and `source` name the live reading each one is written from, which is what lets
     * an empty chart say WHY it is empty: the sampler stores a reading only while it is OK, so a
     * flat gauge on a host with no status endpoint is a missing endpoint rather than a stopped
     * scheduler.
     *
     * @var array<string, array{metric: string, unit: string, title: string, collector: string, source: string}>
     */
    private const GAUGES = [
        'active_connections' => [
            'metric' => 'webserver.active_connections',
            'unit' => 'connections',
            'title' => 'active_connections_over_time',
            'collector' => 'webserver',
            'source' => 'active_connections',
        ],
        'requests_per_second' => [
            'metric' => 'webserver.requests_per_s',
            'unit' => 'requests/s',
            'title' => 'web_server_requests_per_second_over_time',
            'collector' => 'webserver',
            'source' => 'requests_per_s',
        ],
        'idle_keep_alive_connections' => [
            'metric' => 'webserver.waiting',
            'unit' => 'connections',
            'title' => 'idle_keep_alive_connections_over_time',
            'collector' => 'webserver',
            'source' => 'waiting',
        ],
        'fpm_active_processes' => [
            'metric' => 'php.fpm.active_processes',
            'unit' => 'processes',
            'title' => 'fpm_active_processes_over_time',
            'collector' => 'php',
            'source' => 'fpm_active_processes',
        ],
        'fpm_listen_queue' => [
            'metric' => 'php.fpm.listen_queue',
            'unit' => 'requests',
            'title' => 'fpm_listen_queue_over_time',
            'collector' => 'php',
            'source' => 'fpm_listen_queue',
        ],
        'opcache_hit_rate' => [
            'metric' => 'php.opcache.hit_rate',
            'unit' => '%',
            'title' => 'opcache_hit_rate_over_time',
            'collector' => 'php',
            'source' => 'opcache_hit_rate',
        ],
        'opcache_memory_used' => [
            'metric' => 'php.opcache.memory_used_mb',
            'unit' => 'MB',
            'title' => 'opcache_memory_used_over_time',
            'collector' => 'php',
            'source' => 'opcache_memory_used',
        ],
        'opcache_wasted_pct' => [
            'metric' => 'php.opcache.wasted_pct',
            'unit' => '%',
            'title' => 'opcache_wasted_memory_over_time',
            'collector' => 'php',
            'source' => 'opcache_wasted_pct',
        ],
    ];

    public function __construct(
        private readonly CollectorRegistry $collectors,
        private readonly SeriesReader $reader,
    ) {
    }

    public function data(string $range, Request $request): array
    {
        $window = $this->reader->window($range);

        // Collected once each and passed down. The webserver collector derives its per-second rates
        // as a delta against a cached previous sample, so a second call inside the same request
        // would find its own first call's counters to subtract from and publish a server that
        // served nothing at all in the interval.
        $readings = [];
        foreach (self::COLLECTORS as $collector) {
            $readings[$collector] = $this->collectors->collect($collector);
        }

        return [
            'window' => [
                'range' => $range,
                'minutes' => $window['minutes'],
                'resolution' => $window['resolution'],
                'since' => Clock::display($this->reader->since($range))->toDateTimeString(),
                'until' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
            ],
            'collectors' => $this->collectorFaults($readings),
            'server' => $this->server($readings['webserver']),
            'connections' => $this->connections($readings['webserver']),
            'traffic' => $this->grouped(
                $readings['webserver'],
                self::TRAFFIC_METRICS,
                self::COLLECTOR_ABSENT['webserver'],
            ),
            'status_mix' => $this->statusMix($readings['webserver']),
            'fpm' => $this->grouped(
                $readings['php'],
                self::FPM_METRICS,
                self::COLLECTOR_ABSENT['php'],
            ),
            'opcache' => $this->grouped(
                $readings['php'],
                self::OPCACHE_METRICS,
                self::COLLECTOR_ABSENT['php'],
            ),
            'gauges' => $this->gauges($range, $window['resolution'], $readings),
            'elsewhere' => [
                'section' => self::ELSEWHERE_SECTION,
                'metrics' => self::RENDERED_ELSEWHERE['php'],
            ],
            'unrendered' => $this->unrendered($readings),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // A collector that could not answer at all, said once

    /**
     * @param  array<string, array<string, Metric>>  $readings
     * @return array<int, array{collector: string, state: string, note: string|null}>
     */
    private function collectorFaults(array $readings): array
    {
        $faults = [];

        foreach ($readings as $collector => $collected) {
            if ($collected === []) {
                $faults[] = [
                    'collector' => $collector,
                    'state' => 'not_supported',
                    'note' => self::COLLECTOR_ABSENT[$collector] ?? null,
                ];

                continue;
            }

            // The registry's own marker for a collector that threw. Reported here rather than as a
            // reading, because it is not one.
            $failure = $collected['__collector'] ?? null;
            if ($failure instanceof Metric) {
                $faults[] = ['collector' => $collector, 'state' => 'failed', 'note' => $failure->note];
            }
        }

        return $faults;
    }

    // -------------------------------------------------------------------------------------------
    // What is serving

    /**
     * Which server answers for this site, and how that was determined.
     *
     * The development verdict is three-valued on purpose. "This is not a development server" and
     * "what serves this site could not be identified" are different facts, and only the first is
     * reassuring — a false negative here would let a box running `php artisan serve` be read as a
     * production deployment whose every counter merely happens to be missing.
     *
     * @param  array<string, Metric>  $readings
     * @return array<string, mixed>
     */
    private function server(array $readings): array
    {
        $name = $readings['server'] ?? null;
        $type = $readings['server_type'] ?? null;
        $development = $readings['is_development_server'] ?? null;
        $endpoint = $readings['status_endpoint'] ?? null;

        return [
            'state' => $name instanceof Metric ? $name->state : 'not_supported',
            'note' => $name instanceof Metric ? $name->note : self::COLLECTOR_ABSENT['webserver'],
            'remedy' => $name instanceof Metric ? $name->remedy : null,
            'source' => $name instanceof Metric ? $name->source : null,
            'name' => $name instanceof Metric && $name->isOk() && is_scalar($name->value)
                ? (string) $name->value
                : null,
            'type' => $type instanceof Metric && $type->isOk()
                && is_string($type->value) && in_array($type->value, self::SERVER_TYPES, true)
                    ? $type->value
                    : null,
            'is_development' => $development instanceof Metric && $development->isOk() && is_bool($development->value)
                ? $development->value
                : null,
            'development_note' => $development instanceof Metric ? $development->note : null,
            'metrics' => $this->cards($readings, self::IDENTITY_METRICS),
            // Already redacted by the collector: a status URL may carry basic-auth credentials, and
            // this string is drawn on a page and served as JSON.
            'endpoint' => $endpoint instanceof Metric && $endpoint->isOk() && is_scalar($endpoint->value)
                ? (string) $endpoint->value
                : null,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // The status endpoint, the pool, the accelerator

    /**
     * Connection counters, or the single reason there are none.
     *
     * The status endpoint reading is checked first because it is the same object the collector puts
     * in all thirteen counters when the fetch fails — see sharedFault(). Lifting it here is what
     * turns thirteen identical "no status URL is set" cards into one card with the nginx and Apache
     * blocks to paste.
     *
     * @param  array<string, Metric>  $readings
     * @return array<string, mixed>
     */
    private function connections(array $readings): array
    {
        return $this->grouped($readings, self::CONNECTION_METRICS, self::COLLECTOR_ABSENT['webserver']);
    }

    /**
     * A card's worth of readings, or the one fault that emptied it.
     *
     * @param  array<string, Metric>  $readings
     * @param  array<string, string>  $labels  label => metric name
     * @return array<string, mixed>
     */
    private function grouped(array $readings, array $labels, string $absentNote): array
    {
        $shared = $this->sharedFault($readings, array_values($labels));

        if ($shared instanceof Metric) {
            return [
                'state' => $shared->state,
                'note' => $shared->note,
                'remedy' => $shared->remedy,
                'source' => $shared->source,
                'metrics' => [],
            ];
        }

        $metrics = $this->cards($readings, $labels);

        if ($metrics === []) {
            return [
                'state' => 'not_supported',
                'note' => $absentNote,
                'remedy' => null,
                'source' => null,
                'metrics' => [],
            ];
        }

        return [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'source' => $this->firstSource($metrics),
            'metrics' => $metrics,
        ];
    }

    /**
     * The one reason a whole group is unavailable, when there is exactly one.
     *
     * Both collectors mark a group unavailable by filling every key in it with the SAME Metric
     * instance — one unreachable status page becomes thirteen identical cards, and thirteen copies
     * of one fault read as thirteen faults. Identity is compared rather than the message, so a
     * group where each reading is unavailable for its own reason keeps its own cards: nginx keeping
     * no worker counts is not the same statement as nginx being unreachable.
     *
     * @param  array<string, Metric>  $readings
     * @param  array<int, string>  $names
     */
    private function sharedFault(array $readings, array $names): ?Metric
    {
        $shared = null;

        foreach ($names as $name) {
            $metric = $readings[$name] ?? null;

            if (!$metric instanceof Metric || $metric->isOk()) {
                return null;
            }

            if ($shared === null) {
                $shared = $metric;

                continue;
            }

            if ($shared !== $metric) {
                return null;
            }
        }

        return $shared;
    }

    /**
     * The readings a one-line card can honestly draw.
     *
     * An unavailable reading goes in whole — its state and its remedy ARE the content. A reading
     * that is OK but not scalar has no honest single-value rendering, and handing an array to the
     * metric partial prints a PHP warning where a value belongs.
     *
     * @param  array<string, Metric>  $readings
     * @param  array<string, string>  $labels
     * @return array<string, Metric>
     */
    private function cards(array $readings, array $labels): array
    {
        $cards = [];

        foreach ($labels as $label => $name) {
            $metric = $readings[$name] ?? null;

            if (!$metric instanceof Metric) {
                continue;
            }

            if ($metric->isOk() && !is_scalar($metric->value)) {
                continue;
            }

            $cards[$label] = $metric;
        }

        return $cards;
    }

    /** @param array<string, Metric> $metrics */
    private function firstSource(array $metrics): ?string
    {
        foreach ($metrics as $metric) {
            return $metric->source;
        }

        return null;
    }

    // -------------------------------------------------------------------------------------------
    // What the shop answered with

    /**
     * The status-class split of the last five minutes of PHP traffic.
     *
     * Kept out of the cards above because it is a proportion rather than a value, and a proportion
     * drawn as three separate percentages is three numbers an operator has to add up.
     *
     * @param  array<string, Metric>  $readings
     * @return array<string, mixed>
     */
    private function statusMix(array $readings): array
    {
        $metric = $readings['app_status_mix'] ?? null;

        if (!$metric instanceof Metric) {
            return [
                'state' => 'not_supported',
                'note' => self::COLLECTOR_ABSENT['webserver'],
                'remedy' => null,
                'source' => null,
                'classes' => [],
                'total' => null,
            ];
        }

        if (!$metric->isOk() || !is_array($metric->value)) {
            return [
                'state' => $metric->isOk() ? 'no_data' : $metric->state,
                'note' => $metric->note,
                'remedy' => $metric->remedy,
                'source' => $metric->source,
                'classes' => [],
                'total' => null,
            ];
        }

        $classes = [];
        $total = 0;

        foreach ($metric->value as $row) {
            $row = (array) $row;
            $class = trim((string) ($row['class'] ?? ''));
            if ($class === '') {
                continue;
            }

            // Absent is not zero: a class the collector did not count has not been measured at
            // zero requests, and the bar draws nothing for it rather than a segment of no width.
            $requests = is_numeric($row['requests'] ?? null) ? (int) $row['requests'] : null;
            $share = is_numeric($row['share_pct'] ?? null) ? (float) $row['share_pct'] : null;

            $classes[] = [
                'class' => $class,
                'tone' => self::STATUS_TONES[$class] ?? 'e',
                'requests' => $requests,
                'share_pct' => $share,
            ];

            $total += $requests ?? 0;
        }

        return [
            'state' => $classes === [] ? 'no_data' : 'ok',
            'note' => $classes === []
                ? 'The status mix reading carried no class rows, so no proportion can be drawn from it.'
                : $metric->note,
            'remedy' => null,
            'source' => $metric->source,
            'classes' => $classes,
            'total' => $classes === [] ? null : $total,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // The same readings over the window

    /**
     * @param  array<string, array<string, Metric>>  $readings
     * @return array<string, array<string, mixed>>
     */
    private function gauges(string $range, string $resolution, array $readings): array
    {
        $gauges = [];

        foreach (self::GAUGES as $key => $definition) {
            $live = $readings[$definition['collector']][$definition['source']] ?? null;

            $series = $this->reader->series($definition['metric'], $range);

            if ($series['state'] !== 'ok') {
                // Answered here rather than in gaugeGap, which explains only the silences of a read
                // that happened: a store nothing could reach would otherwise be blamed on the host,
                // the range or an empty window. PanelRegistry sees the same failure, but it can only
                // blank the whole section — failing one gauge by name leaves every card above it
                // readable.
                $gauges[$key] = array_merge($definition, [
                    'key' => $key,
                    'state' => $series['state'],
                    'note' => $series['note'],
                    'remedy' => null,
                    // Null, not zero: a read that failed did not find nothing, it did not look.
                    'latest' => null,
                    'samples' => null,
                    'points' => [],
                ]);

                continue;
            }

            $points = array_values(array_filter(
                $series['points'],
                static fn (array $point) => ($point['v'] ?? null) !== null,
            ));

            $gauge = array_merge($definition, [
                'key' => $key,
                'latest' => $series['latest'],
                // The window's own sample count, not the number of points it drew: one bucket holds
                // every sample taken inside it, so counting points understates a rolled-up range.
                'samples' => $series['samples'],
                'points' => $points,
            ]);

            // One point is a reading; a line needs two. Saying which of those it is stops a single
            // sample being read as a flat trend.
            $gauges[$key] = count($points) < 2
                ? array_merge($gauge, $this->gaugeGap($resolution, count($points), $live instanceof Metric ? $live : null))
                : array_merge($gauge, ['state' => 'ok', 'note' => null, 'remedy' => null]);
        }

        return $gauges;
    }

    /**
     * Why a gauge has no line.
     *
     * Four different silences with four different answers, and the empty chart they all draw looks
     * identical: collection switched off, the reading unavailable on this host, a range that reads
     * rolled-up rows, and a window nothing was written into.
     *
     * @return array{state: string, note: string, remedy: string|null}
     */
    private function gaugeGap(string $resolution, int $points, ?Metric $live): array
    {
        if (!config('monitoring.enabled', true)) {
            return [
                'state' => 'not_configured',
                'note' => 'Monitoring collection is switched off, so no gauge has been sampled since it was disabled. This is not a reading of zero.',
                'remedy' => 'Set MONITORING_ENABLED=true in .env, then run `php artisan optimize:clear`.',
            ];
        }

        if ($live instanceof Metric && !$live->isOk()) {
            // The sampler stores a reading only while it is OK, so an unavailable counter has never
            // been written at all. The gap belongs to this host, and the reading says why.
            return [
                'state' => $live->state,
                'note' => 'This gauge is stored only while the reading behind it is available, and it is not on this host. '
                    . ($live->note ?? 'The collector returned no value for it.'),
                'remedy' => $live->remedy,
            ];
        }

        if ($resolution !== 'minute') {
            // Gauges are written once a minute. A longer range reads the rolled-up rows, which the
            // rollup builds — so this window can be empty while the minute rows under it are full.
            return [
                'state' => 'no_data',
                'note' => 'This range reads ' . $resolution . ' rows, which the monitoring rollup builds from the minute samples rather than the sampler writing them directly.',
                'remedy' => 'Choose a shorter range to read the minute samples, or check the rollup is running: `php artisan schedule:list`.',
            ];
        }

        return [
            'state' => 'no_data',
            'note' => ($points === 1
                ? 'Only one sample has been stored in this window, and one point is not a line.'
                : 'No sample of this gauge has been stored in this window.')
                . ' The sample is taken by a command-line process, which loads a different php.ini from the one serving this page — a counter readable here is not necessarily readable there.',
            'remedy' => 'Gauges are sampled by `php artisan monitoring:flush`, scheduled every minute: check the scheduler is running with `php artisan schedule:list`. The OPcache gauges also need opcache.enable_cli=1 in the CLI php.ini.',
        ];
    }

    // -------------------------------------------------------------------------------------------

    /**
     * Readings this page draws nowhere.
     *
     * Normally empty. It exists so a collector that grows a reading cannot have it silently
     * disappear: an undrawn measurement is indistinguishable from an unmeasured one, and that is
     * the confusion this whole system is built to avoid. The php readings the application section
     * draws are claimed here and named on the page instead, so they count as drawn rather than lost.
     *
     * @param  array<string, array<string, Metric>>  $readings
     * @return array<int, array{collector: string, metric: string, state: string}>
     */
    private function unrendered(array $readings): array
    {
        $claimed = [
            'webserver' => array_merge(
                array_values(self::IDENTITY_METRICS),
                array_values(self::CONNECTION_METRICS),
                array_values(self::TRAFFIC_METRICS),
                self::RENDERED_ELSEWHERE['webserver'],
                ['status_endpoint', 'app_status_mix'],
            ),
            'php' => array_merge(
                array_values(self::FPM_METRICS),
                array_values(self::OPCACHE_METRICS),
                self::RENDERED_ELSEWHERE['php'],
            ),
        ];

        $unrendered = [];

        foreach ($readings as $collector => $collected) {
            foreach ($collected as $name => $metric) {
                // The registry's own failure marker, reported at the top of the page as a fault
                // rather than here as a reading the collector produced.
                if ($name === '__collector' || !$metric instanceof Metric) {
                    continue;
                }

                if (in_array($name, $claimed[$collector] ?? [], true)) {
                    continue;
                }

                $unrendered[] = ['collector' => $collector, 'metric' => $name, 'state' => $metric->state];
            }
        }

        return $unrendered;
    }
}
