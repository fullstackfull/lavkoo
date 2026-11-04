<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Collectors\CollectorRegistry;
use App\Services\Monitoring\Ingest\MetricSink;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Http\Request;

/**
 * Redis, and the one question that decides whether the rest of this page matters: what on this
 * deployment is actually pointed at it.
 *
 * A Redis section is unusually easy to write dishonestly. Every figure on it can be green — a
 * 0.3 ms ping, a 90% hit ratio, nothing evicted — on a shop whose cache is on disk, whose queue is
 * in MySQL and whose sessions are files. There Redis is not on the request path at all, nobody is
 * paying for any of those numbers, and a page that shows them without saying so invites the
 * conclusion that the cache is fast. So the three drivers are read from the live configuration,
 * stated beside the server, and where nothing is pointed at Redis the page says that first.
 *
 * One thing on this deployment does use it: monitoring's own request buffer, which is why the
 * server holds keys and answers lookups at all. That row sits on the same table as the other
 * three, because without it the keyspace figures here have no explanation and read as shop traffic.
 *
 * Nothing on this page is inferred from anything else. Every value arrives from the collector
 * already carrying its state, its provenance and — where an operator could change it — the exact
 * setting that would, and it is rendered that way rather than as a zero.
 */
class RedisPanel implements Panel
{
    /**
     * Collector readings grouped the way somebody diagnoses a cache server, not the order INFO
     * prints them in.
     *
     * @var array<string, array{why: string, metrics: list<string>}>
     */
    private const GROUPS = [
        'availability' => [
            'why' => 'whether_the_server_answers_at_all_which_client_speaks_to_it_and_what_a_round_trip_costs',
            'metrics' => ['client', 'version', 'uptime_seconds', 'latency_ms'],
        ],
        'memory' => [
            'why' => 'how_much_the_server_holds_whether_it_has_a_ceiling_and_what_it_does_when_it_reaches_it',
            'metrics' => [
                'used_memory_mb', 'used_memory_peak_mb', 'maxmemory_mb', 'memory_used_pct',
                'maxmemory_policy', 'fragmentation_ratio',
            ],
        ],
        'throughput' => [
            'why' => 'what_the_server_is_being_asked_for_right_now_rather_than_since_it_started',
            'metrics' => ['ops_per_sec', 'net_input_kbps', 'net_output_kbps', 'slowlog_length'],
        ],
        'hit_ratio' => [
            'why' => 'the_lifetime_ratio_barely_moves_on_a_long_running_server_so_the_interval_ratio_beside_it_is_the_live_one',
            'metrics' => [
                'hit_ratio_interval', 'hit_ratio', 'keyspace_hits', 'keyspace_misses',
                'total_keys', 'expired_keys', 'evicted_keys',
            ],
        ],
        'persistence' => [
            'why' => 'what_survives_a_restart_which_is_a_different_question_for_a_cache_than_for_a_queue_or_a_session_store',
            'metrics' => [
                'rdb_last_save_at', 'rdb_last_save_age_minutes', 'rdb_last_save_status',
                'rdb_changes_since_last_save', 'rdb_bgsave_in_progress',
                'aof_enabled', 'aof_last_write_status',
            ],
        ],
        'clients' => [
            'why' => 'who_is_connected_and_how_close_the_server_is_to_refusing_the_next_connection',
            'metrics' => [
                'connected_clients', 'blocked_clients', 'maxclients',
                'total_connections_received', 'rejected_connections',
            ],
        ],
    ];

    /**
     * Readings the collector answers as a list. Each is drawn as its own table: there is no honest
     * one-line rendering of ten commands, and handing an array to the metric partial would print a
     * PHP warning where a value should be.
     */
    private const TABLES = ['databases', 'top_commands', 'command_latency_percentiles', 'slow_commands'];

    /** Readings that describe the application rather than the server, and are answerable with Redis down. */
    private const APPLICATION_METRICS = [
        'subsystems', 'serves_app', 'cache_driver', 'queue_driver', 'session_driver',
    ];

    /**
     * The stored gauges the redis collector publishes, in the order this page reads them.
     *
     * @var array<string, array{metric: string, unit: string, title: string}>
     */
    private const CHARTS = [
        'hit_ratio' => ['metric' => 'redis.hit_ratio', 'unit' => '%', 'title' => 'hit_ratio_over_time'],
        'used_memory_mb' => ['metric' => 'redis.used_memory_mb', 'unit' => 'MB', 'title' => 'memory_used_over_time'],
        'latency_ms' => ['metric' => 'redis.latency_ms', 'unit' => 'ms', 'title' => 'round_trip_over_time'],
        'ops_per_sec' => ['metric' => 'redis.ops_per_sec', 'unit' => 'ops/s', 'title' => 'operations_per_second_over_time'],
        'connected_clients' => ['metric' => 'redis.connected_clients', 'unit' => 'clients', 'title' => 'connected_clients_over_time'],
        'evicted_keys' => ['metric' => 'redis.evicted_keys', 'unit' => 'keys', 'title' => 'evicted_keys_over_time'],
        'fragmentation_ratio' => ['metric' => 'redis.fragmentation_ratio', 'unit' => 'x', 'title' => 'memory_fragmentation_over_time'],
    ];

    /** The subsystems whose driver an operator changes in .env, in the order the file lists them. */
    private const SHOP_SUBSYSTEMS = ['cache', 'queue', 'session'];

    private const APPLY_COMMAND = 'php artisan config:clear';

    public function __construct(
        private readonly CollectorRegistry $collectors,
        private readonly SeriesReader $reader,
        private readonly MetricSink $sink,
    ) {
    }

    public function data(string $range, Request $request): array
    {
        $window = $this->reader->window($range);
        $readings = $this->collectors->collect('redis');
        $server = $this->serverState($readings);

        return [
            'window' => [
                'range' => $range,
                'minutes' => $window['minutes'],
                'resolution' => $window['resolution'],
                'since' => Clock::display($this->reader->since($range))->toDateTimeString(),
                'until' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
            ],
            'server' => $server,
            'usage' => $this->usage($readings),
            'groups' => $this->groups($readings),
            'tables' => $this->tables($readings),
            'charts' => $this->charts($range, $window['resolution'], $server['reachable']),
            'unrendered' => $this->unrendered($readings),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // The server

    /**
     * Whether the collector reached the server, said once at the top.
     *
     * Reachability and readability are two different facts and this deployment can have either
     * without the other: a managed Redis answers PING and refuses INFO. So the ping decides
     * `reachable` — which is what the "nothing uses this server" note is gated on — and INFO
     * decides the state of everything drawn below it.
     *
     * @param  array<string, Metric>  $readings
     * @return array{state: string, note: string|null, remedy: string|null, reachable: bool}
     */
    private function serverState(array $readings): array
    {
        if ($readings === []) {
            return [
                'state' => 'not_supported',
                'note' => 'The Redis collector is not installed in this build, so nothing on this page can be read from a server.',
                'remedy' => null,
                'reachable' => false,
            ];
        }

        $failure = $readings['__collector'] ?? null;
        if ($failure instanceof Metric) {
            return ['state' => 'failed', 'note' => $failure->note, 'remedy' => null, 'reachable' => false];
        }

        $ping = $readings['latency_ms'] ?? null;
        $reachable = $ping instanceof Metric && $ping->isOk();

        $info = $readings['version'] ?? null;
        if (!$info instanceof Metric || !$info->isOk()) {
            return [
                'state' => $info instanceof Metric ? $info->state : 'no_data',
                'note' => $info instanceof Metric ? $info->note : 'The collector returned no reading for this server.',
                'remedy' => $info instanceof Metric ? $info->remedy : null,
                'reachable' => $reachable,
            ];
        }

        return ['state' => 'ok', 'note' => null, 'remedy' => null, 'reachable' => true];
    }

    /**
     * The six cards, each holding only readings that can honestly be drawn as one value.
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
                if ($metric->isOk() && !is_scalar($metric->value)) {
                    continue;
                }

                $metrics[$name] = $name === 'rdb_last_save_at' ? $this->inDisplayTimezone($metric) : $metric;
            }

            if ($metrics === []) {
                continue;
            }

            $groups[] = ['key' => $key, 'why' => $definition['why'], 'metrics' => $metrics];
        }

        return $groups;
    }

    /**
     * A stored UTC timestamp, converted once for rendering.
     *
     * The collector stamps in UTC as everything in monitoring does. Drawn raw beside a page whose
     * every other time is in the display timezone, it would be three hours out on this deployment
     * and look like a snapshot that never happened.
     */
    private function inDisplayTimezone(Metric $metric): Metric
    {
        if (!$metric->isOk() || !is_string($metric->value)) {
            return $metric;
        }

        return Metric::of(
            value: Clock::display($metric->value)->toDateTimeString(),
            source: $metric->source,
            unit: null,
            note: trim(($metric->note ?? '') . ' Shown in ' . Clock::displayTimezone() . ', stored in UTC.'),
        );
    }

    // -------------------------------------------------------------------------------------------
    // What actually uses this server

    /**
     * The subsystems that could be served by Redis, and whether they are.
     *
     * This is the part of the page that keeps the rest of it honest, so it is read from the live
     * configuration through the collector rather than restated here — one definition of "the cache
     * driver", not two that can disagree.
     *
     * @param  array<string, Metric>  $readings
     * @return array<string, mixed>
     */
    private function usage(array $readings): array
    {
        $subsystems = $readings['subsystems'] ?? null;
        $serves = $readings['serves_app'] ?? null;
        $buffer = $this->bufferRow();

        $readable = $subsystems instanceof Metric && $subsystems->isOk() && is_array($subsystems->value);

        $rows = [];
        if ($readable) {
            foreach ($subsystems->value as $entry) {
                $entry = (array) $entry;
                $rows[] = [
                    'subsystem' => (string) ($entry['subsystem'] ?? ''),
                    'setting' => (string) ($entry['setting'] ?? ''),
                    'configured' => (string) ($entry['configured'] ?? ''),
                    'driver' => (string) ($entry['driver'] ?? ''),
                    'uses_redis' => (bool) ($entry['uses_redis'] ?? false),
                    'note' => null,
                ];
            }
        }

        // Monitoring's own row goes on whether or not the shop's three could be read, so `rows` is
        // never empty and the table's own emptiness cannot carry the failure. The state and the
        // reason are therefore separate keys the view renders beside the table: a table holding
        // only the buffer row, with nothing saying why, reads as a shop that has no cache, no queue
        // and no session store rather than as three readings that did not arrive.
        $rows[] = $buffer;

        return [
            'state' => $readable ? 'ok' : ($subsystems instanceof Metric && !$subsystems->isOk() ? $subsystems->state : 'no_data'),
            'reason' => $readable ? null : ($subsystems instanceof Metric
                ? ($subsystems->note ?? 'The collector returned no usable reading for the application drivers.')
                : 'The collector returned no reading for the application drivers.'),
            'note' => $readable && $serves instanceof Metric ? $serves->note : null,
            // Only claimed where there is a reading to claim it for. On the collector's blanket
            // failure path every metric carries "Redis INFO" as its source, and printing that under
            // a table built from cache.default, queue.default and session.driver would credit the
            // server with three numbers it never answered.
            'source' => $readable ? $subsystems->source : null,
            'rows' => $rows,
            // Null, not false: "no subsystem uses Redis" and "we could not read the drivers" are
            // different claims, and only the first one earns the note that follows from it.
            'serves_shop' => $serves instanceof Metric && $serves->isOk() ? (bool) $serves->value : null,
            'serves_monitoring' => $buffer['uses_redis'],
            'env_lines' => $this->envLines($rows),
            'apply_command' => self::APPLY_COMMAND,
            'caveats' => $this->caveats($readings),
        ];
    }

    /**
     * Monitoring's own buffer, on the same table as the shop's three subsystems.
     *
     * It is the reason this server holds keys at all on a deployment where nothing in the storefront
     * talks to it. Left off the table, the keyspace and hit-ratio figures above have no explanation
     * and read as shop traffic — which is the exact misreading this page exists to prevent.
     *
     * @return array<string, mixed>
     */
    private function bufferRow(): array
    {
        $driver = $this->sink->driver();
        $usesRedis = $driver === MetricSink::DRIVER_REDIS;

        return [
            'subsystem' => 'monitoring_buffer',
            'setting' => 'MONITORING_BUFFER',
            'configured' => (string) config('monitoring.buffer', 'auto'),
            'driver' => $driver,
            'uses_redis' => $usesRedis,
            'note' => $usesRedis
                ? 'Monitoring\'s own request counters, not the shop\'s. This is what the keys and lookups on this page are.'
                : $this->sink->describe(),
        ];
    }

    /**
     * The .env lines that would put a subsystem on Redis, for the ones not on it.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private function envLines(array $rows): array
    {
        $lines = [];

        foreach ($rows as $row) {
            if (!in_array($row['subsystem'], self::SHOP_SUBSYSTEMS, true) || $row['uses_redis'] || $row['setting'] === '') {
                continue;
            }

            $lines[] = $row['setting'] . '=redis';
        }

        return $lines;
    }

    /**
     * What this particular server would do to a subsystem moved onto it.
     *
     * Every line is conditioned on a reading taken from this server, never on a general opinion
     * about Redis: an operator following the .env lines above should know what THIS configuration
     * does to a queue and to a session store before they do it.
     *
     * @param  array<string, Metric>  $readings
     * @return array<int, string>
     */
    private function caveats(array $readings): array
    {
        $caveats = [];

        $policy = $readings['maxmemory_policy'] ?? null;
        if ($policy instanceof Metric && $policy->isOk()) {
            $caveats[] = str_starts_with((string) $policy->value, 'noeviction')
                ? 'This server evicts nothing (maxmemory-policy noeviction): as a cache it answers writes with an OOM error once it is full rather than making room.'
                : 'This server may drop any key under memory pressure (maxmemory-policy ' . $policy->value . '), which for a session store means signing shoppers out and for a queue means losing jobs.';
        }

        $aof = $readings['aof_enabled'] ?? null;
        if ($aof instanceof Metric && $aof->isOk() && $aof->value === false) {
            $caveats[] = 'The append-only file is off, so everything written since the last snapshot is lost if this server stops abruptly — acceptable for a cache, not for a queue.';
        }

        $caveats[] = 'A queue moved to Redis needs a worker running against it (php artisan queue:work redis); changing the driver alone stops jobs being processed.';

        return $caveats;
    }

    // -------------------------------------------------------------------------------------------
    // Readings the server answers as lists

    /**
     * @param  array<string, Metric>  $readings
     * @return array<string, array<string, mixed>>
     */
    private function tables(array $readings): array
    {
        return [
            'databases' => $this->table(
                $readings['databases'] ?? null,
                static fn (array $row) => [
                    'database' => (int) ($row['database'] ?? 0),
                    'keys' => (int) ($row['keys'] ?? 0),
                    'expiring' => (int) ($row['expiring'] ?? 0),
                    'avg_ttl_seconds' => (int) ($row['avg_ttl_seconds'] ?? 0),
                ],
                'This server did not report a keyspace section.',
            ),
            'top_commands' => $this->table(
                $readings['top_commands'] ?? null,
                static fn (array $row) => [
                    'command' => (string) ($row['command'] ?? ''),
                    'calls' => (int) ($row['calls'] ?? 0),
                    // Two decimals, not one: a command that has cost the server forty microseconds
                    // in total rounds to 0.0 at one and prints as a flat "0 ms", which on this page
                    // is the one thing a real measurement must never look like.
                    'total_ms' => round((int) ($row['total_usec'] ?? 0) / 1000, 2),
                    'usec_per_call' => (float) ($row['usec_per_call'] ?? 0),
                    'failed_calls' => (int) ($row['failed_calls'] ?? 0),
                ],
                'This server reported no per-command statistics.',
            ),
            'command_latency_percentiles' => $this->table(
                $readings['command_latency_percentiles'] ?? null,
                static fn (array $row) => [
                    'command' => (string) ($row['command'] ?? ''),
                    'p50_ms' => (float) ($row['p50_ms'] ?? 0),
                    'p99_ms' => (float) ($row['p99_ms'] ?? 0),
                    'p99_9_ms' => (float) ($row['p99_9_ms'] ?? 0),
                ],
                'This server reported no per-command latency percentiles.',
            ),
            'slow_commands' => $this->table(
                $readings['slow_commands'] ?? null,
                fn (array $row) => [
                    'command' => (string) ($row['command'] ?? ''),
                    'microseconds' => (int) ($row['microseconds'] ?? 0),
                    'arguments_redacted' => (int) ($row['arguments_redacted'] ?? 0),
                    // Stamped in UTC by the collector like everything else monitoring stores.
                    'at' => ($row['at'] ?? null) === null ? null : Clock::display($row['at'])->toDateTimeString(),
                ],
                'This server did not answer the slow log.',
            ),
        ];
    }

    /**
     * One list reading, as rows plus the state it arrived in.
     *
     * An empty list stays `ok`: no slow command and no key are readings, and rendering them as
     * "no data" would turn a measured silence into an unmeasured one.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function table(?Metric $metric, callable $row, string $missingNote): array
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

        return [
            'state' => 'ok',
            'note' => $metric->note,
            'remedy' => null,
            'source' => $metric->source,
            'rows' => array_map(static fn ($entry) => $row((array) $entry), array_values($metric->value)),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Stored gauges

    /**
     * Every redis.* gauge in the window, each carrying why it has no line when it has none.
     *
     * @return array<string, array<string, mixed>>
     */
    private function charts(string $range, string $resolution, bool $reachable): array
    {
        $charts = [];

        foreach (self::CHARTS as $key => $definition) {
            $charts[$key] = $this->chart($key, $definition, $range, $resolution, $reachable);
        }

        return $charts;
    }

    /**
     * @param  array{metric: string, unit: string, title: string}  $definition
     * @return array<string, mixed>
     */
    private function chart(string $key, array $definition, string $range, string $resolution, bool $reachable): array
    {
        try {
            $series = $this->reader->series($definition['metric'], $range);
        } catch (\Throwable $exception) {
            // PanelRegistry would catch this too, but it can only blank the whole section. Failing
            // one gauge by name leaves the six cards and the tables readable.
            return array_merge($definition, [
                'key' => $key,
                'state' => 'failed',
                'note' => Metric::describeFailure($exception),
                'remedy' => null,
                'latest' => null,
                // Null, not zero: a read that failed did not find nothing, it did not look.
                'stored_points' => null,
                'points' => [],
            ]);
        }

        $points = array_values(array_filter(
            $series['points'],
            static fn (array $point) => ($point['v'] ?? null) !== null,
        ));

        $base = array_merge($definition, [
            'key' => $key,
            'latest' => $series['latest'],
            // Points, not samples. One stored row is a bucket, and at hour or day resolution a
            // bucket is a rollup of sixty or of fourteen hundred samples — so calling this a
            // sample count would understate a week's collection by two orders of magnitude.
            'stored_points' => count($points),
            'points' => $points,
        ]);

        // One point is a reading; a line needs two. Saying which of those it is stops a single
        // sample being read as a flat trend.
        if (count($points) < 2) {
            return array_merge($base, $this->gaugeGap($resolution, count($points), $reachable));
        }

        return array_merge($base, ['state' => 'ok', 'note' => null, 'remedy' => null]);
    }

    /**
     * Why a gauge has no line.
     *
     * Four different silences with four different answers, and the flat empty chart they all draw
     * looks identical.
     *
     * @return array{state: string, note: string, remedy: string|null}
     */
    private function gaugeGap(string $resolution, int $points, bool $reachable): array
    {
        if (!config('monitoring.enabled', true)) {
            return [
                'state' => 'not_configured',
                'note' => 'Monitoring collection is switched off, so no gauge has been sampled since it was disabled.',
                'remedy' => 'Set MONITORING_ENABLED=true in .env, then run `php artisan optimize:clear`.',
            ];
        }

        if (!$reachable) {
            return [
                'state' => 'no_data',
                'note' => 'These gauges are only written when the collector can reach Redis, and it cannot — so the gap is the server being unreachable, not the sampler failing. The reason is at the top of this page.',
                'remedy' => null,
            ];
        }

        if ($resolution !== 'minute') {
            // Gauges are written once a minute. Longer ranges read rolled-up rows, which the rollup
            // produces — so this window can be empty while the minute rows under it are full.
            return [
                'state' => 'no_data',
                'note' => 'This range reads ' . $resolution . ' rows, which the monitoring rollup builds from the minute samples rather than the sampler writing directly.',
                'remedy' => 'Choose a shorter range to read the minute samples, or check the hourly rollup is running: `php artisan schedule:list`.',
            ];
        }

        return [
            'state' => 'no_data',
            'note' => $points === 1
                ? 'Only one sample has been stored in this window, and one point is not a line.'
                : 'No sample of this gauge has been stored in this window.',
            'remedy' => 'Gauges are sampled by `php artisan monitoring:flush`, scheduled every minute. Check the Laravel scheduler is running: `php artisan schedule:list`.',
        ];
    }

    // -------------------------------------------------------------------------------------------

    /**
     * Readings this page does not draw anywhere.
     *
     * Normally empty. It exists so that a collector which grows a reading cannot have it silently
     * disappear from the page: an undrawn measurement is indistinguishable from an unmeasured one,
     * and that is the confusion this whole system is built to avoid.
     *
     * @param  array<string, Metric>  $readings
     * @return array<int, array{metric: string, state: string}>
     */
    private function unrendered(array $readings): array
    {
        $grouped = array_merge(...array_column(self::GROUPS, 'metrics'));
        $claimed = array_merge($grouped, self::TABLES, self::APPLICATION_METRICS, ['__collector']);

        $unrendered = [];
        foreach ($readings as $name => $metric) {
            if (in_array($name, $claimed, true) || !$metric instanceof Metric) {
                continue;
            }

            $unrendered[] = ['metric' => $name, 'state' => $metric->state];
        }

        return $unrendered;
    }
}
