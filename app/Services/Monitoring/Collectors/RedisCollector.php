<?php

namespace App\Services\Monitoring\Collectors;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\Environment;
use Carbon\CarbonInterval;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Redis: what the server is doing, and what — if anything — this application actually asks of it.
 *
 * Four things this deliberately does NOT do, because each is a standard way a Redis panel misleads
 * the person reading it:
 *
 * 1. It does not let a green Redis stand in for a fast shop. Redis is only ever as important as
 *    the subsystems pointed at it, and where CACHE_DRIVER, QUEUE_CONNECTION and SESSION_DRIVER are
 *    file, database and file, a perfect hit ratio here says nothing about how the store caches,
 *    queues or logs anyone in — Redis is not on the request path at all. The three drivers are
 *    read from the live config and reported beside the server, so the page cannot imply a
 *    dependency that does not exist.
 *
 * 2. It does not report a hit ratio that cannot move. keyspace_hits and keyspace_misses are totals
 *    since the server started; on a Redis up for a month, the lifetime ratio is a monument to last
 *    month and stays at 99% through an afternoon of solid misses. The lifetime figure is reported
 *    as what it is, and the ratio over the interval between two samples is reported beside it —
 *    "no data" on the first sample rather than a number with nothing to subtract from.
 *
 * 3. It does not store what commands were called with. A SLOWLOG entry carries the full argument
 *    list, which is where the cache key, the session payload and the password-reset token live, so
 *    only the command name, its duration and the number of arguments that were dropped survive.
 *
 * 4. It does not let an unreachable Redis hang the page it is drawn on. The application's own
 *    connection is left with the application's own timeouts; monitoring dials a copy of it with
 *    sub-second bounds, once per collection, and reports a refusal as not_configured carrying the
 *    .env lines to fix it — never as zero keys and a 0 ms ping, which read as a healthy server.
 */
class RedisCollector implements Collector
{
    private const INFO_SOURCE = 'Redis INFO';
    private const PING_SOURCE = 'Redis PING';
    private const SLOWLOG_SOURCE = 'Redis SLOWLOG GET';
    private const COMMANDSTATS_SOURCE = 'Redis INFO commandstats';
    private const LATENCYSTATS_SOURCE = 'Redis INFO latencystats';
    private const APP_SOURCE = 'Laravel config cache.default, queue.default, session.driver';

    /** The name monitoring's own short-timeout copy of the default connection is dialled under. */
    private const CONNECTION = 'monitoring';

    private const CONNECT_TIMEOUT_SECONDS = 0.5;
    private const READ_TIMEOUT_SECONDS = 1.0;

    private const PREVIOUS_KEY = 'monitoring:redis:previous';
    private const SAMPLE_TTL_SECONDS = 600;

    /** Below this the window is short enough that sampling jitter, not traffic, sets the ratio. */
    private const MIN_INTERVAL_SECONDS = 1.0;

    private const SLOWLOG_ENTRIES = 10;
    private const TOP_COMMANDS = 10;
    private const MEGABYTE = 1048576;

    /**
     * Under this much data the fragmentation ratio measures Redis's own baseline rather than
     * fragmentation: a 1 MB dataset in a 13 MB process reads as 10x fragmented and is simply empty.
     */
    private const FRAGMENTATION_FLOOR_BYTES = 52428800;

    /**
     * Commands whose second word is a fixed keyword rather than a value, so it is safe to keep.
     * Everything else in an argument list is a key or a payload and is dropped.
     */
    private const CONTAINER_COMMANDS = [
        'ACL', 'CLIENT', 'CLUSTER', 'COMMAND', 'CONFIG', 'FUNCTION', 'LATENCY', 'MEMORY',
        'OBJECT', 'PUBSUB', 'SCRIPT', 'SLOWLOG', 'XGROUP', 'XINFO',
    ];

    /** Everything the server itself feeds, so one refusal marks exactly these unavailable. */
    private const SERVER_METRICS = [
        'version', 'uptime_seconds',
        'connected_clients', 'blocked_clients', 'maxclients', 'total_connections_received',
        'rejected_connections',
        'used_memory_mb', 'used_memory_peak_mb', 'maxmemory_mb', 'memory_used_pct',
        'maxmemory_policy', 'fragmentation_ratio',
        'rdb_last_save_at', 'rdb_last_save_age_minutes', 'rdb_last_save_status',
        'rdb_changes_since_last_save', 'rdb_bgsave_in_progress', 'aof_enabled',
        'aof_last_write_status',
        'ops_per_sec', 'net_input_kbps', 'net_output_kbps',
        'total_keys', 'databases', 'keyspace_hits', 'keyspace_misses', 'hit_ratio',
        'hit_ratio_interval', 'evicted_keys', 'expired_keys',
        'latency_ms', 'slowlog_length', 'slow_commands', 'top_commands',
        'command_latency_percentiles',
    ];

    /** Everything the application's own configuration feeds, answerable with Redis down. */
    private const APPLICATION_METRICS = [
        'subsystems', 'serves_app', 'cache_driver', 'queue_driver', 'session_driver',
    ];

    private Connection|Metric|null $connection = null;

    /** @var array<string, Metric>|null */
    private ?array $readings = null;

    public function __construct(private readonly Environment $environment)
    {
    }

    public function key(): string
    {
        return 'redis';
    }

    public function collect(): array
    {
        if ($this->readings !== null) {
            return $this->readings;
        }

        try {
            // Sampled once per instance. The interval hit ratio is a delta against a cached
            // previous reading, so collecting twice in one request — once for the panel, once for
            // gauges() — would leave the second pass with a few microseconds of window and no
            // ratio at all.
            return $this->readings = $this->read();
        } catch (\Throwable $exception) {
            $failed = Metric::failed(self::INFO_SOURCE, $exception);

            return $this->readings = array_fill_keys(
                [...self::SERVER_METRICS, 'client', ...self::APPLICATION_METRICS],
                $failed,
            );
        }
    }

    public function gauges(): array
    {
        $collected = $this->collect();

        return array_filter([
            'redis.latency_ms' => $collected['latency_ms'],
            'redis.used_memory_mb' => $collected['used_memory_mb'],
            // The windowed ratio, not the lifetime one. keyspace_hits and keyspace_misses are
            // totals since the server started, so charting the ratio of them draws a flat line
            // that a reader takes for the current hit rate — it cannot fall during an afternoon of
            // solid misses. The stored series keeps its name; only its source changes, and it is
            // absent rather than flat until there are two samples to subtract.
            'redis.hit_ratio' => $collected['hit_ratio_interval'],
            'redis.connected_clients' => $collected['connected_clients'],
            'redis.evicted_keys' => $collected['evicted_keys'],
            'redis.ops_per_sec' => $collected['ops_per_sec'],
            'redis.fragmentation_ratio' => $collected['fragmentation_ratio'],
        ], fn (Metric $metric) => $metric->isOk() && is_numeric($metric->value));
    }

    // -------------------------------------------------------------------------------------------

    /** @return array<string, Metric> */
    private function read(): array
    {
        $connection = $this->connection();

        // The driver readings come from config, not from the server, so they are answerable — and
        // are the most useful thing on the page — even when nothing is listening on the port.
        if ($connection instanceof Metric) {
            return array_merge(
                array_fill_keys(self::SERVER_METRICS, $connection),
                ['client' => $this->client()],
                $this->applicationReadings(),
            );
        }

        $latency = $this->latency($connection);
        $info = $this->info($connection);

        if ($info instanceof Metric) {
            return array_merge(
                array_fill_keys(self::SERVER_METRICS, $info),
                ['client' => $this->client(), 'latency_ms' => $latency],
                $this->applicationReadings(),
            );
        }

        return array_merge(
            [
                'client' => $this->client(),
                'version' => $this->text($info, 'redis_version'),
                'uptime_seconds' => $this->number($info, 'uptime_in_seconds', 'seconds'),
                'latency_ms' => $latency,
            ],
            $this->clientReadings($info),
            $this->memoryReadings($info),
            $this->persistenceReadings($info),
            $this->throughputReadings($info),
            $this->keyspaceReadings($info),
            $this->slowlogReadings($connection),
            [
                'top_commands' => $this->topCommands($connection),
                'command_latency_percentiles' => $this->latencyPercentiles($connection, $info),
            ],
            $this->applicationReadings(),
        );
    }

    // -------------------------------------------------------------------------------------------
    // Connection

    private function connection(): Connection|Metric
    {
        return $this->connection ??= $this->openConnection();
    }

    private function openConnection(): Connection|Metric
    {
        $client = (string) config('database.redis.client', 'phpredis');

        if ($client === 'predis' && !$this->environment->has('predis')) {
            return Metric::notConfigured(
                self::INFO_SOURCE,
                'Install the client with composer require predis/predis, or set REDIS_CLIENT=phpredis in .env if the extension is present.',
                'REDIS_CLIENT is set to predis, which is not installed, so nothing here can speak to a Redis server.',
            );
        }

        if ($client !== 'predis' && !$this->environment->has('redis_ext')) {
            $series = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

            return Metric::notConfigured(
                self::INFO_SOURCE,
                "Install the extension with sudo apt install php{$series}-redis and reload PHP, or run composer require predis/predis and set REDIS_CLIENT=predis in .env.",
                'Neither the phpredis extension nor the predis package is available, so nothing here can speak to a Redis server.',
            );
        }

        try {
            return $this->manager()->connection(self::CONNECTION);
        } catch (\Throwable $exception) {
            return $this->unreachable($exception);
        }
    }

    /**
     * Monitoring's own Redis manager, built from the application's configuration with bounded
     * timeouts.
     *
     * Laravel's shared manager captures database.redis at boot, so a connection added to the config
     * afterwards is never seen — and rewriting the application's own connection would hand the
     * store's cache and queue traffic a half-second timeout it never asked for. A separate manager
     * over a copy of the same settings keeps the bound where it belongs: on the dashboard.
     */
    private function manager(): RedisManager
    {
        $config = (array) config('database.redis', []);
        $driver = (string) Arr::pull($config, 'client', 'phpredis');

        $config[self::CONNECTION] = array_merge((array) ($config['default'] ?? []), [
            'timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'read_timeout' => self::READ_TIMEOUT_SECONDS,
            'retry_interval' => 0,
            // A persistent connection would be shared with the application's own traffic, which
            // would then inherit these timeouts.
            'persistent' => false,
        ]);

        return new RedisManager(app(), $driver, $config);
    }

    private function unreachable(\Throwable $exception): Metric
    {
        $message = $this->withoutPassword(trim($exception->getMessage()));
        $target = $this->target();

        // A server that answers and then rejects the credential is a different problem from one
        // that is not there, and phpredis raises both as the same exception — the error text is
        // the only thing that separates them. Bare AUTH is in the pattern because a password set
        // here against a server that has none fails with "ERR AUTH <password> called without any
        // password configured", which is a credential problem wearing an ERR and would otherwise
        // be answered with "point REDIS_HOST at a running server" while the server is right there.
        if (preg_match('/NOAUTH|WRONGPASS|\bAUTH\b|invalid password|authentication/i', $message) === 1) {
            return Metric::permissionDenied(
                self::INFO_SOURCE,
                "Redis at {$target} answered but refused the credential: {$message}",
                'Set REDIS_PASSWORD in .env to the requirepass value from redis.conf, or leave it empty when the server has none, then run php artisan config:clear. '
                    . 'An ACL user takes more than an .env line here: config/database.php carries no username key, so add \'username\' => env(\'REDIS_USERNAME\'), to database.redis.default before REDIS_USERNAME can have any effect.',
            );
        }

        return Metric::notConfigured(
            self::INFO_SOURCE,
            'Point REDIS_HOST and REDIS_PORT in .env at a running Redis server, set REDIS_PASSWORD when it needs one, then run php artisan config:clear. Install one with sudo apt install redis-server. Once it answers, CACHE_DRIVER=redis, QUEUE_CONNECTION=redis and SESSION_DRIVER=redis can be pointed at it.',
            "No Redis server answered at {$target} within " . self::CONNECT_TIMEOUT_SECONDS . 's: ' . $message,
        );
    }

    /** Where this deployment expects Redis to be, for the message that says it is not there. */
    private function target(): string
    {
        $config = (array) config('database.redis.default', []);
        $url = (string) ($config['url'] ?? '');

        if ($url !== '') {
            $parts = parse_url($url) ?: [];

            return ($parts['host'] ?? 'the configured host') . ':' . ($parts['port'] ?? 6379);
        }

        return ($config['host'] ?? '127.0.0.1') . ':' . ($config['port'] ?? 6379);
    }

    /**
     * Notes are stored and drawn on a page, and a client library is free to quote the DSN it was
     * handed — password included — back in its exception message.
     */
    private function withoutPassword(string $message): string
    {
        $password = (string) (config('database.redis.default.password') ?? '');

        return $password === '' ? $message : str_replace($password, '***', $message);
    }

    private function client(): Metric
    {
        $configured = (string) config('database.redis.client', 'phpredis');

        if ($configured === 'predis') {
            return Metric::of(
                'predis',
                'Laravel config database.redis.client',
                note: $this->environment->has('predis') ? null : 'REDIS_CLIENT is predis, but the package is not installed.',
            );
        }

        $version = phpversion('redis');

        return $this->environment->has('redis_ext')
            ? Metric::of('phpredis ' . ($version === false ? 'unknown version' : $version), "PHP extension_loaded('redis')")
            : Metric::notConfigured(
                "PHP extension_loaded('redis')",
                'Install the extension with sudo apt install php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-redis and reload PHP.',
                'REDIS_CLIENT is phpredis, but the extension is not loaded.',
            );
    }

    // -------------------------------------------------------------------------------------------
    // Server readings

    /**
     * INFO, as one flat map of field to value.
     *
     * @return array<string, mixed>|Metric
     */
    private function info(Connection $connection): array|Metric
    {
        try {
            $info = $connection->command('info', []);
        } catch (\Throwable $exception) {
            return $this->refused($exception, self::INFO_SOURCE, $this->infoRemedy())
                ?? Metric::failed(self::INFO_SOURCE, $exception);
        }

        if (!is_array($info) || $info === []) {
            return Metric::permissionDenied(
                self::INFO_SOURCE,
                'The server did not answer INFO. It is commonly renamed or removed by ACL on a managed Redis.',
                $this->infoRemedy(),
            );
        }

        return $this->flatten($info);
    }

    /**
     * phpredis returns INFO flat; predis groups it by section. Section names are the only keys that
     * are a single capitalised word, which is what separates a section from a `db0` or a
     * `cmdstat_get` whose own value predis has already parsed into an array.
     *
     * @param  array<string, mixed>  $info
     * @return array<string, mixed>
     */
    private function flatten(array $info): array
    {
        $flat = [];

        foreach ($info as $key => $value) {
            if (is_array($value) && preg_match('/^[A-Z][A-Za-z]+$/', (string) $key) === 1) {
                $flat = array_merge($flat, $value);
                continue;
            }

            $flat[$key] = $value;
        }

        return $flat;
    }

    /**
     * A real round trip to the server, in milliseconds.
     *
     * The connection is opened before the clock starts, so this times a PING and not a TCP
     * handshake — the number an application actually pays on every cache read.
     */
    private function latency(Connection $connection): Metric
    {
        return Metric::probe(self::PING_SOURCE, function () use ($connection) {
            $started = hrtime(true);
            $reply = $connection->command('ping', []);
            $elapsed = (hrtime(true) - $started) / 1e6;

            if ($reply === false || $reply === null) {
                return Metric::noData(self::PING_SOURCE, 'The server did not answer PING.');
            }

            return round($elapsed, 2);
        }, 'ms');
    }

    /**
     * @param  array<string, mixed>  $info
     * @return array<string, Metric>
     */
    private function clientReadings(array $info): array
    {
        $rejected = $this->integer($info, 'rejected_connections');
        $maxclients = $info['maxclients'] ?? 'the configured limit';

        return [
            'connected_clients' => $this->number($info, 'connected_clients', 'clients'),
            'blocked_clients' => $this->number($info, 'blocked_clients', 'clients', 'Clients parked inside a blocking command such as BLPOP — a queue worker waiting for a job looks exactly like this.'),
            'maxclients' => $this->number($info, 'maxclients', 'clients'),
            'total_connections_received' => $this->number($info, 'total_connections_received', 'connections', $this->sinceStart($info)),
            'rejected_connections' => $this->number(
                $info,
                'rejected_connections',
                'connections',
                $rejected > 0
                    ? "Redis turned {$rejected} connections away because maxclients ({$maxclients}) was already reached. Raise maxclients, or find what is opening connections without closing them."
                    : $this->sinceStart($info),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $info
     * @return array<string, Metric>
     */
    private function memoryReadings(array $info): array
    {
        $maxmemory = $this->integer($info, 'maxmemory');
        $used = $this->integer($info, 'used_memory');

        return [
            'used_memory_mb' => $this->megabytes($info, 'used_memory'),
            'used_memory_peak_mb' => $this->megabytes($info, 'used_memory_peak', 'The high-water mark since this server started; Redis does not return freed memory to the OS, so RSS follows the peak, not the current size.'),
            'maxmemory_mb' => $maxmemory > 0
                ? $this->megabytes($info, 'maxmemory')
                : $this->unbounded('maxmemory is 0, so this server has no ceiling — it grows until the machine runs out of memory and the kernel kills it.'),
            'memory_used_pct' => match (true) {
                $maxmemory <= 0 => $this->unbounded('There is no ceiling to measure usage against: maxmemory is 0.'),
                $used <= 0 => Metric::noData(self::INFO_SOURCE, 'INFO did not report how much memory is in use.'),
                default => Metric::of(round(100 * $used / $maxmemory, 1), self::INFO_SOURCE, '%'),
            },
            'maxmemory_policy' => $this->text($info, 'maxmemory_policy', $this->policyNote($info, $maxmemory)),
            'fragmentation_ratio' => $this->number($info, 'mem_fragmentation_ratio', 'x', $this->fragmentationNote($info)),
        ];
    }

    /** The state of a server that will grow until the kernel stops it. */
    private function unbounded(string $note): Metric
    {
        return Metric::notConfigured(
            self::INFO_SOURCE,
            'Give Redis a ceiling: redis-cli CONFIG SET maxmemory 512mb && redis-cli CONFIG SET maxmemory-policy allkeys-lru, then write both lines into redis.conf so they survive a restart.',
            $note,
        );
    }

    /** @param array<string, mixed> $info */
    private function policyNote(array $info, int $maxmemory): ?string
    {
        if ((string) ($info['maxmemory_policy'] ?? '') !== 'noeviction') {
            return null;
        }

        return $maxmemory > 0
            ? 'With noeviction Redis rejects writes with an OOM error once maxmemory is reached instead of evicting anything. That is correct for a queue or a session store and wrong for a cache, which wants allkeys-lru.'
            : 'Nothing is ever evicted and nothing bounds this server: no maxmemory, no eviction policy.';
    }

    /** @param array<string, mixed> $info */
    private function fragmentationNote(array $info): ?string
    {
        $used = $this->integer($info, 'used_memory');
        $resident = $this->integer($info, 'used_memory_rss');

        if ($used <= 0 || $used >= self::FRAGMENTATION_FLOOR_BYTES) {
            return null;
        }

        return 'Only ' . $this->inMegabytes($used) . ' MB of data against ' . $this->inMegabytes($resident)
            . ' MB resident, so this ratio is measuring Redis\'s own baseline rather than fragmentation. It means nothing until the server holds real data.';
    }

    /**
     * @param  array<string, mixed>  $info
     * @return array<string, Metric>
     */
    private function persistenceReadings(array $info): array
    {
        $savedAt = $this->integer($info, 'rdb_last_save_time');
        $aof = array_key_exists('aof_enabled', $info) ? (bool) (int) $info['aof_enabled'] : null;
        $pending = $this->integer($info, 'rdb_changes_since_last_save');

        return [
            'rdb_last_save_at' => $savedAt > 0
                ? Metric::of(Clock::stamp($savedAt), self::INFO_SOURCE)
                : Metric::noData(self::INFO_SOURCE, 'This server has never written an RDB snapshot.'),
            'rdb_last_save_age_minutes' => $savedAt > 0
                ? Metric::of(intdiv(max(0, Clock::now()->getTimestamp() - $savedAt), 60), self::INFO_SOURCE, 'minutes')
                : Metric::noData(self::INFO_SOURCE, 'This server has never written an RDB snapshot.'),
            'rdb_last_save_status' => $this->text($info, 'rdb_last_bgsave_status', $this->saveStatusNote($info)),
            'rdb_changes_since_last_save' => $this->number(
                $info,
                'rdb_changes_since_last_save',
                'writes',
                $pending > 0 ? 'Writes that exist only in memory: they are lost if this server stops before the next snapshot.' : null,
            ),
            'rdb_bgsave_in_progress' => $this->flag($info, 'rdb_bgsave_in_progress'),
            'aof_enabled' => match ($aof) {
                null => Metric::noData(self::INFO_SOURCE, 'This server\'s INFO output does not include aof_enabled.'),
                true => Metric::of(true, self::INFO_SOURCE),
                false => Metric::of(false, self::INFO_SOURCE, note: 'The append-only file is off, so every write since the last RDB snapshot is lost if this server stops abruptly. That is the right trade for a cache and the wrong one for a queue or a session store.'),
            },
            'aof_last_write_status' => match ($aof) {
                true => $this->text($info, 'aof_last_write_status'),
                false => Metric::notConfigured(
                    self::INFO_SOURCE,
                    'Turn the append-only file on with redis-cli CONFIG SET appendonly yes, and set appendonly yes in redis.conf so it survives a restart.',
                    'AOF is disabled, so there is no append-only write status to report.',
                ),
                default => Metric::noData(self::INFO_SOURCE, 'This server\'s INFO output does not say whether AOF is enabled.'),
            },
        ];
    }

    /** @param array<string, mixed> $info */
    private function saveStatusNote(array $info): ?string
    {
        return (string) ($info['rdb_last_bgsave_status'] ?? 'ok') === 'ok'
            ? null
            : 'The last background save failed. Redis keeps serving from memory and keeps failing to persist — check free space on the disk and that the redis user can write to the dir set in redis.conf.';
    }

    /**
     * Rates Redis measures for itself.
     *
     * These are the one set of numbers here that can be reported as they arrive: Redis samples them
     * internally several times a second, so they are already a rate. Everything else in the Stats
     * section is a total since boot and is reported as a total.
     *
     * @param  array<string, mixed>  $info
     * @return array<string, Metric>
     */
    private function throughputReadings(array $info): array
    {
        return [
            'ops_per_sec' => $this->number($info, 'instantaneous_ops_per_sec', 'ops/s', 'Redis\'s own rolling estimate over the last second, not a counter divided by uptime.'),
            // Redis names these kbps but divides bytes by 1024: they are kilobytes, not kilobits.
            'net_input_kbps' => $this->number($info, 'instantaneous_input_kbps', 'kB/s'),
            'net_output_kbps' => $this->number($info, 'instantaneous_output_kbps', 'kB/s'),
        ];
    }

    /**
     * @param  array<string, mixed>  $info
     * @return array<string, Metric>
     */
    private function keyspaceReadings(array $info): array
    {
        $databases = $this->databases($info);
        $evicted = $this->integer($info, 'evicted_keys');

        return [
            'total_keys' => Metric::of(
                array_sum(array_column($databases, 'keys')),
                self::INFO_SOURCE,
                'keys',
                $databases === [] ? 'No database on this server holds a key.' : null,
            ),
            'databases' => Metric::of($databases, self::INFO_SOURCE),
            'keyspace_hits' => $this->number($info, 'keyspace_hits', 'lookups', $this->sinceStart($info)),
            'keyspace_misses' => $this->number($info, 'keyspace_misses', 'lookups', $this->sinceStart($info)),
            'hit_ratio' => $this->hitRatio($info),
            'hit_ratio_interval' => $this->intervalHitRatio($info),
            'evicted_keys' => $this->number(
                $info,
                'evicted_keys',
                'keys',
                $evicted > 0
                    ? "Redis has evicted {$evicted} keys to stay under maxmemory. Cached values are disappearing before they expire; either raise maxmemory or accept the misses."
                    : $this->sinceStart($info),
            ),
            'expired_keys' => $this->number($info, 'expired_keys', 'keys', $this->sinceStart($info)),
        ];
    }

    /**
     * Keys per database, from the Keyspace section.
     *
     * @param  array<string, mixed>  $info
     * @return array<int, array<string, int>>
     */
    private function databases(array $info): array
    {
        $databases = [];

        foreach ($info as $key => $value) {
            if (preg_match('/^db(\d+)$/', (string) $key, $match) !== 1) {
                continue;
            }

            $fields = $this->fields($value);
            $databases[] = [
                'database' => (int) $match[1],
                'keys' => (int) ($fields['keys'] ?? 0),
                'expiring' => (int) ($fields['expires'] ?? 0),
                // Redis reports the average TTL in milliseconds; seconds is what a panel can read.
                'avg_ttl_seconds' => intdiv((int) ($fields['avg_ttl'] ?? 0), 1000),
            ];
        }

        return $databases;
    }

    /** @param array<string, mixed> $info */
    private function hitRatio(array $info): Metric
    {
        $hits = $this->integer($info, 'keyspace_hits');
        $misses = $this->integer($info, 'keyspace_misses');
        $lookups = $hits + $misses;

        if ($lookups <= 0) {
            return Metric::noData(self::INFO_SOURCE, 'No key has been looked up since this server started.');
        }

        $uptime = $this->uptimeForHumans($info);

        return Metric::of(
            round(100 * $hits / $lookups, 1),
            self::INFO_SOURCE,
            '%',
            'Every lookup since this server started' . ($uptime === null ? '' : " {$uptime} ago")
                . ', so it barely moves on a long-running server. hit_ratio_interval is the same ratio over the last sampling window.',
        );
    }

    /**
     * The hit ratio over the window between this sample and the last one.
     *
     * @param  array<string, mixed>  $info
     */
    private function intervalHitRatio(array $info): Metric
    {
        if (!array_key_exists('keyspace_hits', $info) || !array_key_exists('keyspace_misses', $info)) {
            return Metric::noData(self::INFO_SOURCE, 'This server\'s INFO output does not include the keyspace counters.');
        }

        $sample = [
            'at' => microtime(true),
            'hits' => $this->integer($info, 'keyspace_hits'),
            'misses' => $this->integer($info, 'keyspace_misses'),
        ];
        $previous = $this->rotate($sample);

        if ($previous === null) {
            return Metric::noData(self::INFO_SOURCE, 'Collecting the first sample; the interval hit ratio appears one minute after monitoring starts.');
        }

        $elapsed = $sample['at'] - (float) $previous['at'];
        if ($elapsed < self::MIN_INTERVAL_SECONDS) {
            return Metric::noData(self::INFO_SOURCE, 'The previous sample is too recent to derive a ratio from.');
        }

        $hits = $sample['hits'] - (int) $previous['hits'];
        $misses = $sample['misses'] - (int) $previous['misses'];
        if ($hits < 0 || $misses < 0) {
            return Metric::noData(self::INFO_SOURCE, 'The counters went backwards, so Redis restarted between the two samples.');
        }

        $lookups = $hits + $misses;
        if ($lookups === 0) {
            return Metric::noData(self::INFO_SOURCE, 'No key was looked up between the last two samples.');
        }

        return Metric::of(
            round(100 * $hits / $lookups, 1),
            self::INFO_SOURCE,
            '%',
            'Measured over the last ' . round($elapsed) . ' seconds, from ' . $lookups . ' lookups.',
        );
    }

    /**
     * Store this sample and hand back the one it replaces.
     *
     * @param  array<string, float|int>  $sample
     * @return array<string, float|int>|null
     */
    private function rotate(array $sample): ?array
    {
        try {
            $previous = Cache::get(self::PREVIOUS_KEY);
            Cache::put(self::PREVIOUS_KEY, $sample, self::SAMPLE_TTL_SECONDS);
        } catch (\Throwable) {
            // A cache store that has fallen over costs the interval ratio, not the page.
            return null;
        }

        return is_array($previous) && isset($previous['at'], $previous['hits'], $previous['misses']) ? $previous : null;
    }

    // -------------------------------------------------------------------------------------------
    // Slow commands and per-command statistics

    /** @return array<string, Metric> */
    private function slowlogReadings(Connection $connection): array
    {
        try {
            $length = $connection->command('slowlog', ['len']);
            $entries = $connection->command('slowlog', ['get', self::SLOWLOG_ENTRIES]);
        } catch (\Throwable $exception) {
            $metric = $this->refused($exception, self::SLOWLOG_SOURCE, $this->slowlogRemedy())
                ?? Metric::failed(self::SLOWLOG_SOURCE, $exception);

            return ['slowlog_length' => $metric, 'slow_commands' => $metric];
        }

        if (!is_array($entries)) {
            // phpredis reports a refused command as false rather than throwing, so a non-array
            // answer is the server saying no — not an empty slow log.
            $metric = Metric::permissionDenied(
                self::SLOWLOG_SOURCE,
                'The server did not answer SLOWLOG GET. It is commonly renamed or blocked by ACL on a managed Redis.',
                $this->slowlogRemedy(),
            );

            return ['slowlog_length' => $metric, 'slow_commands' => $metric];
        }

        return [
            'slowlog_length' => is_numeric($length)
                ? Metric::of((int) $length, 'Redis SLOWLOG LEN', 'entries', 'The log holds the last slowlog-max-len entries and is cleared by SLOWLOG RESET or a restart.')
                : Metric::noData('Redis SLOWLOG LEN'),
            'slow_commands' => Metric::of(
                array_map($this->redactEntry(...), $entries),
                self::SLOWLOG_SOURCE,
                note: $entries === []
                    ? 'No command has exceeded slowlog-log-slower-than since the log was last cleared.'
                    : 'Arguments are dropped: only the command, its duration and the number of redacted arguments are kept.',
            ),
        ];
    }

    /**
     * One slow entry, with everything the command was called with removed.
     *
     * The argument list is where the cache key, the session payload and the password-reset token
     * live, so the name is kept, the subcommand is kept where it is a fixed keyword rather than a
     * value, and the rest is reduced to a count.
     *
     * @param  array<int, mixed>  $entry  [id, unix time, microseconds, arguments, client, name]
     * @return array<string, mixed>
     */
    private function redactEntry(mixed $entry): array
    {
        $entry = (array) $entry;
        $arguments = array_values((array) ($entry[3] ?? []));
        $command = strtoupper((string) ($arguments[0] ?? 'unknown'));
        $kept = 1;

        if (in_array($command, self::CONTAINER_COMMANDS, true) && isset($arguments[1])) {
            $command .= ' ' . strtoupper((string) $arguments[1]);
            $kept = 2;
        }

        $at = (int) ($entry[1] ?? 0);

        return [
            'command' => $command,
            'microseconds' => (int) ($entry[2] ?? 0),
            'arguments_redacted' => max(0, count($arguments) - $kept),
            'at' => $at > 0 ? Clock::stamp($at) : null,
        ];
    }

    /**
     * Which commands this server spends its time on.
     *
     * Not in the default INFO output — the section has to be asked for by name — and it is a set of
     * totals since the server started, never a rate.
     */
    private function topCommands(Connection $connection): Metric
    {
        $stats = $this->section($connection, 'commandstats', self::COMMANDSTATS_SOURCE, $this->infoRemedy());
        if ($stats instanceof Metric) {
            return $stats;
        }

        $commands = [];
        foreach ($stats as $key => $value) {
            if (!str_starts_with((string) $key, 'cmdstat_')) {
                continue;
            }

            $fields = $this->fields($value);
            $commands[] = [
                'command' => strtoupper(substr((string) $key, strlen('cmdstat_'))),
                'calls' => (int) ($fields['calls'] ?? 0),
                'total_usec' => (int) ($fields['usec'] ?? 0),
                'usec_per_call' => round((float) ($fields['usec_per_call'] ?? 0), 2),
                'failed_calls' => (int) ($fields['failed_calls'] ?? 0),
            ];
        }

        if ($commands === []) {
            return Metric::noData(self::COMMANDSTATS_SOURCE, 'This server reported no per-command statistics; CONFIG RESETSTAT clears them and they rebuild as commands run.');
        }

        usort($commands, static fn (array $first, array $second) => $second['total_usec'] <=> $first['total_usec']);

        return Metric::of(
            array_slice($commands, 0, self::TOP_COMMANDS),
            self::COMMANDSTATS_SOURCE,
            note: 'Ranked by total time spent. Totals since this server started or since the last CONFIG RESETSTAT — counts, not rates.',
        );
    }

    /**
     * Per-command latency percentiles.
     *
     * Redis 7 and newer only, and only while latency-tracking is on, so both are checked before
     * anything is reported: an older server has no percentiles to hide, and a server with tracking
     * switched off is a setting away from having them.
     *
     * @param  array<string, mixed>  $info
     */
    private function latencyPercentiles(Connection $connection, array $info): Metric
    {
        $version = (string) ($info['redis_version'] ?? '');
        if ($version !== '' && version_compare($version, '7.0.0', '<')) {
            return Metric::notSupported(
                self::LATENCYSTATS_SOURCE,
                "Per-command latency percentiles arrived in Redis 7 and this server runs {$version}.",
                'Upgrade the server to Redis 7 or newer to get p50/p99 per command.',
            );
        }

        $stats = $this->section($connection, 'latencystats', self::LATENCYSTATS_SOURCE, $this->infoRemedy());
        if ($stats instanceof Metric) {
            return $stats;
        }

        $commands = [];
        foreach ($stats as $key => $value) {
            if (!str_starts_with((string) $key, 'latency_percentiles_usec_')) {
                continue;
            }

            $fields = $this->fields($value);
            $commands[] = [
                'command' => strtoupper(substr((string) $key, strlen('latency_percentiles_usec_'))),
                'p50_ms' => round(((float) ($fields['p50'] ?? 0)) / 1000, 3),
                'p99_ms' => round(((float) ($fields['p99'] ?? 0)) / 1000, 3),
                'p99_9_ms' => round(((float) ($fields['p99.9'] ?? 0)) / 1000, 3),
            ];
        }

        if ($commands === []) {
            return Metric::notConfigured(
                self::LATENCYSTATS_SOURCE,
                'Switch tracking on with redis-cli CONFIG SET latency-tracking yes, and set latency-tracking yes in redis.conf so it survives a restart.',
                'This server reports no latency percentiles, which is what a Redis 7 with latency-tracking off looks like.',
            );
        }

        usort($commands, static fn (array $first, array $second) => $second['p99_ms'] <=> $first['p99_ms']);

        return Metric::of(
            array_slice($commands, 0, self::TOP_COMMANDS),
            self::LATENCYSTATS_SOURCE,
            note: 'Ranked by p99. Measured by Redis itself, so it excludes the network between this host and the server. Accumulated since this server started or since the last CONFIG RESETSTAT, so a slow minute stays in the p99 and a recovered one does not clear it.',
        );
    }

    /**
     * One INFO section that the default INFO call does not return.
     *
     * @return array<string, mixed>|Metric
     */
    private function section(Connection $connection, string $section, string $source, string $remedy): array|Metric
    {
        try {
            $stats = $connection->command('info', [$section]);
        } catch (\Throwable $exception) {
            return $this->refused($exception, $source, $remedy) ?? Metric::failed($source, $exception);
        }

        if (!is_array($stats)) {
            return Metric::permissionDenied($source, "The server did not answer INFO {$section}.", $remedy);
        }

        return $this->flatten($stats);
    }

    /**
     * A refusal, told apart from a failure.
     *
     * A managed Redis answers a command it does not allow with an error rather than by dropping the
     * connection, and that error is the operator's cue to grant it — not something monitoring
     * should file under "the probe threw".
     */
    private function refused(\Throwable $exception, string $source, string $remedy): ?Metric
    {
        $message = $this->withoutPassword(trim($exception->getMessage()));

        return preg_match('/NOPERM|unknown command|not allowed|DENIED|disabled/i', $message) === 1
            ? Metric::permissionDenied($source, $message, $remedy)
            : null;
    }

    private function infoRemedy(): string
    {
        return 'Grant the monitoring user the full INFO command with redis-cli ACL SETUSER <user> +info, or read these figures from the provider console on a managed Redis that hides them.';
    }

    private function slowlogRemedy(): string
    {
        return 'Grant the slow log with redis-cli ACL SETUSER <user> +slowlog, or check that SLOWLOG has not been renamed in redis.conf. A managed Redis that blocks it (ElastiCache, Azure Cache) exposes slow commands in its own console instead.';
    }

    // -------------------------------------------------------------------------------------------
    // What this application actually uses Redis for

    /**
     * The subsystems that could be served by Redis, and whether they are.
     *
     * This is the part of the page that keeps the rest of it honest. A Redis with a 99% hit ratio
     * and a 0.1 ms ping is doing nothing at all for a shop whose cache is on disk and whose queue
     * is in MySQL, and a dashboard that shows the first two without the third invites exactly that
     * conclusion.
     *
     * @return array<string, Metric>
     */
    private function applicationReadings(): array
    {
        $store = (string) config('cache.default');
        $cache = (string) config("cache.stores.{$store}.driver", $store);
        $connection = (string) config('queue.default');
        $queue = (string) config("queue.connections.{$connection}.driver", $connection);
        $session = (string) config('session.driver');

        $subsystems = [
            ['subsystem' => 'cache', 'setting' => 'CACHE_DRIVER', 'configured' => $store, 'driver' => $cache, 'uses_redis' => $cache === 'redis'],
            ['subsystem' => 'queue', 'setting' => 'QUEUE_CONNECTION', 'configured' => $connection, 'driver' => $queue, 'uses_redis' => $queue === 'redis'],
            ['subsystem' => 'session', 'setting' => 'SESSION_DRIVER', 'configured' => $session, 'driver' => $session, 'uses_redis' => $session === 'redis'],
        ];
        $served = array_column(array_filter($subsystems, static fn (array $row) => $row['uses_redis']), 'subsystem');

        return [
            'subsystems' => Metric::of($subsystems, self::APP_SOURCE),
            'serves_app' => Metric::of($served !== [], self::APP_SOURCE, note: $this->servesNote($subsystems, $served)),
            'cache_driver' => Metric::of($cache, 'Laravel config cache.default', note: $cache === 'redis' ? null : "The cache is served by the {$cache} store, not by Redis."),
            'queue_driver' => Metric::of($queue, 'Laravel config queue.default', note: $queue === 'redis' ? null : "Jobs are queued on the {$queue} driver, not on Redis."),
            'session_driver' => Metric::of($session, 'Laravel config session.driver', note: $session === 'redis' ? null : "Sessions are stored by the {$session} driver, not by Redis."),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $subsystems
     * @param  array<int, string>  $served
     */
    private function servesNote(array $subsystems, array $served): string
    {
        $drivers = implode(', ', array_map(
            static fn (array $row) => $row['subsystem'] . '=' . $row['driver'],
            $subsystems,
        ));

        if ($served === []) {
            return "Nothing in this application talks to Redis: {$drivers}. Everything else on this page describes a server the shop does not currently depend on, so a healthy Redis here does not mean a fast cache, a fast queue or a fast login.";
        }

        return 'Redis serves ' . implode(' and ', $served) . " for this application ({$drivers}), so latency and evictions on this page are paid by the shop.";
    }

    // -------------------------------------------------------------------------------------------
    // Reading INFO fields

    /** @param array<string, mixed> $info */
    private function number(array $info, string $field, ?string $unit = null, ?string $note = null): Metric
    {
        if (!array_key_exists($field, $info)) {
            return Metric::noData(self::INFO_SOURCE, "This server's INFO output does not include {$field}.");
        }

        if (!is_numeric($info[$field])) {
            return Metric::noData(self::INFO_SOURCE, "INFO reported {$field} as something other than a number.");
        }

        $value = (string) $info[$field];

        return Metric::of(str_contains($value, '.') ? round((float) $value, 2) : (int) $value, self::INFO_SOURCE, $unit, $note);
    }

    /** @param array<string, mixed> $info */
    private function text(array $info, string $field, ?string $note = null): Metric
    {
        return array_key_exists($field, $info)
            ? Metric::of((string) $info[$field], self::INFO_SOURCE, note: $note)
            : Metric::noData(self::INFO_SOURCE, "This server's INFO output does not include {$field}.");
    }

    /** @param array<string, mixed> $info */
    private function flag(array $info, string $field, ?string $note = null): Metric
    {
        return array_key_exists($field, $info)
            ? Metric::of((bool) (int) $info[$field], self::INFO_SOURCE, note: $note)
            : Metric::noData(self::INFO_SOURCE, "This server's INFO output does not include {$field}.");
    }

    /** @param array<string, mixed> $info */
    private function megabytes(array $info, string $field, ?string $note = null): Metric
    {
        if (!array_key_exists($field, $info) || !is_numeric($info[$field])) {
            return Metric::noData(self::INFO_SOURCE, "This server's INFO output does not include a numeric {$field}.");
        }

        return Metric::of($this->inMegabytes((int) $info[$field]), self::INFO_SOURCE, 'MB', $note);
    }

    private function inMegabytes(int $bytes): float
    {
        return round($bytes / self::MEGABYTE, 1);
    }

    /** A field read as an integer for arithmetic, where a missing field is genuinely nothing. */
    private function integer(array $info, string $field): int
    {
        return isset($info[$field]) && is_numeric($info[$field]) ? (int) $info[$field] : 0;
    }

    /**
     * The sentence that stops a since-boot total being read as a current rate.
     *
     * @param  array<string, mixed>  $info
     */
    private function sinceStart(array $info): string
    {
        $uptime = $this->uptimeForHumans($info);

        return $uptime === null
            ? 'Counted since this server started; a total, not a rate.'
            : "Counted since this server started {$uptime} ago; a total, not a rate.";
    }

    /** @param array<string, mixed> $info */
    private function uptimeForHumans(array $info): ?string
    {
        $uptime = $this->integer($info, 'uptime_in_seconds');

        return $uptime > 0
            ? CarbonInterval::seconds($uptime)->cascade()->forHumans(['parts' => 2, 'short' => true])
            : null;
    }

    /**
     * A "field=value,field=value" line from INFO, or the array predis has already made of it.
     *
     * @return array<string, string>
     */
    private function fields(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $fields = [];
        foreach (explode(',', (string) $value) as $pair) {
            if (!str_contains($pair, '=')) {
                continue;
            }

            [$name, $field] = explode('=', $pair, 2);
            $fields[trim($name)] = trim($field);
        }

        return $fields;
    }
}
