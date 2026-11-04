<?php

namespace App\Services\Monitoring\Collectors;

use App\Services\Monitoring\Metric;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * The application's database, from its own counters — and an honest account of what this login is
 * not allowed to see.
 *
 * Four things this deliberately does NOT do, because each one is a standard way a database page
 * misleads the person reading it:
 *
 * 1. It does not divide SHOW GLOBAL STATUS counters by Uptime. Queries, Com_select and the rest
 *    are monotonic since the server started, so "queries per second" only exists as the difference
 *    between two readings. Rolling them off uptime publishes a lifetime average as if it were
 *    current load — a number that stays calm through the busiest hour the server has ever had. The
 *    previous sample is cached and the delta is what gets reported; the first sample says so.
 *
 * 2. It does not decide from the version string what this login may read. performance_schema,
 *    SHOW ENGINE INNODB STATUS and innodb_metrics are each attempted, and the driver's error
 *    number is what separates "switched off" from "not granted" — 1142 and 1227 need different
 *    fixes, and a version number can tell you neither. Whatever comes back unavailable carries the
 *    exact line that would make it real.
 *
 * 3. It does not report a counter the server is not keeping as zero. A disabled innodb_metrics row
 *    still returns COUNT 0, which reads as "no deadlocks" when it means "not counting", so only
 *    rows the server marks enabled are published — and a deadlock counter this flavour does not
 *    have is reported as missing, not as none.
 *
 * 4. It does not publish the text of a running statement. How many queries are past five seconds
 *    is the thing worth knowing during an incident; the statements themselves would put customer
 *    data on a dashboard, so the count comes out and the SQL stays in the server.
 */
class DatabaseCollector implements Collector
{
    private const PREVIOUS_KEY = 'monitoring:db:previous';
    private const SAMPLE_TTL_SECONDS = 600;

    /** Below this the window is short enough that one background job decides the whole rate. */
    private const MIN_INTERVAL_SECONDS = 1.0;

    private const LATENCY_SAMPLES = 3;
    private const LONG_RUNNING_SECONDS = 5;
    private const LARGEST_TABLES = 10;
    private const BYTES_PER_MB = 1048576;

    /** Driver error numbers meaning "the object is there, this login may not have it". */
    private const DENIED_CODES = [1044, 1045, 1142, 1143, 1227];

    /** Driver error numbers meaning "there is no such schema or table on this server". */
    private const ABSENT_CODES = [1049, 1109, 1146];

    /** Driver error numbers meaning the server refused the login itself, before any statement. */
    private const LOGIN_DENIED_CODES = [1044, 1045, 1130, 1698];

    /** Status counters snapshotted for the delta, in the case the server publishes them. */
    private const COUNTERS = [
        'Uptime', 'Queries', 'Com_select', 'Com_insert', 'Com_update', 'Com_delete',
        'Com_commit', 'Com_rollback', 'Slow_queries',
        'Innodb_buffer_pool_read_requests', 'Innodb_buffer_pool_reads',
        'Created_tmp_tables', 'Created_tmp_disk_tables',
    ];

    /** Rates published as metric name => [status fields to add up, unit]. */
    private const RATES = [
        'queries_per_s' => [['Queries'], 'queries/s'],
        'selects_per_s' => [['Com_select'], 'selects/s'],
        'inserts_per_s' => [['Com_insert'], 'inserts/s'],
        'updates_per_s' => [['Com_update'], 'updates/s'],
        'deletes_per_s' => [['Com_delete'], 'deletes/s'],
        'transactions_per_s' => [['Com_commit', 'Com_rollback'], 'transactions/s'],
    ];

    /** The InnoDB counters worth pulling out of innodb_metrics when the login may read it. */
    private const INNODB_METRICS = [
        'trx_rseg_history', 'lock_deadlocks', 'lock_row_lock_time_avg', 'buffer_pool_wait_free',
    ];

    /** The chartable subset: gauge name => metric name. */
    private const GAUGES = [
        'db.latency_ms' => 'latency_ms',
        'db.threads_connected' => 'threads_connected',
        'db.threads_running' => 'threads_running',
        'db.connection_utilisation_pct' => 'connection_utilisation_pct',
        'db.queries_per_s' => 'queries_per_s',
        'db.slow_queries' => 'slow_queries',
        'db.buffer_pool_hit_ratio' => 'buffer_pool_hit_ratio_window',
        'db.row_lock_waits' => 'row_lock_waits',
        'db.size_mb' => 'size_mb',
    ];

    /**
     * Every name this collector answers to, so an unreachable server can be reported once, in one
     * state, across the whole page instead of as forty separate connection failures.
     *
     * @var list<string>
     */
    private const METRICS = [
        'flavour', 'version', 'database', 'uptime_seconds', 'latency_ms',
        'threads_connected', 'threads_running', 'max_connections', 'connection_utilisation_pct',
        'max_used_connections', 'aborted_connects', 'aborted_clients',
        'queries_per_s', 'selects_per_s', 'inserts_per_s', 'updates_per_s', 'deletes_per_s',
        'transactions_per_s',
        'commits', 'rollbacks', 'slow_queries',
        'buffer_pool_hit_ratio', 'buffer_pool_hit_ratio_window', 'buffer_pool_size_mb', 'buffer_pool_pages_data',
        'buffer_pool_pages_free', 'buffer_pool_pages_dirty',
        'row_lock_waits', 'row_lock_time_avg_ms', 'deadlocks',
        'tmp_tables_created', 'tmp_disk_tables_created', 'tmp_disk_table_ratio_pct',
        'table_open_cache', 'open_tables',
        'size_mb', 'index_size_mb', 'row_estimate', 'table_count', 'largest_tables',
        'processlist_count', 'long_running_queries',
        'query_digests', 'innodb_engine_status', 'innodb_metrics', 'slow_query_log',
    ];

    /** @var array<string, Metric>|null */
    private ?array $readings = null;

    private ?string $flavour = null;

    private ?string $account = null;

    public function key(): string
    {
        return 'db';
    }

    public function collect(): array
    {
        // Sampled once per instance. The rates here are deltas against a cached previous reading,
        // so collecting twice in one request — once for the table, once for gauges() — would hand
        // the second pass a window of a few milliseconds and a second round of privilege probes.
        return $this->readings ??= $this->read();
    }

    public function gauges(): array
    {
        $collected = $this->collect();
        $gauges = [];

        foreach (self::GAUGES as $gauge => $name) {
            $metric = $collected[$name] ?? null;
            if ($metric instanceof Metric && $metric->isOk() && is_numeric($metric->value)) {
                $gauges[$gauge] = $metric;
            }
        }

        return $gauges;
    }

    // -------------------------------------------------------------------------------------------

    /**
     * @return array<string, Metric>
     */
    private function read(): array
    {
        $label = 'database connection [' . config('database.default') . ']';

        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();
        } catch (Throwable $exception) {
            return array_fill_keys(self::METRICS, Metric::failed($label, $exception));
        }

        if ($driver !== 'mysql') {
            return array_fill_keys(self::METRICS, Metric::notSupported(
                $label,
                "The application is connected to {$driver}; every reading here comes from MySQL/MariaDB status counters.",
            ));
        }

        try {
            // Connect before anything is timed. Laravel opens the PDO lazily, so the first query
            // would otherwise carry the TCP handshake and the login into the latency reading.
            $connection->getPdo();
        } catch (Throwable $exception) {
            return array_fill_keys(self::METRICS, $this->connectionFailure($connection, $exception, $label));
        }

        $variables = $this->readVariables($connection);
        $this->flavour = $this->detectFlavour($connection, $variables);

        $status = $this->readStatus($connection);
        $deltas = $this->deltas($status, microtime(true));

        $version = $this->serverVersion($connection, $variables);
        $threadsConnected = $this->counter($status, 'Threads_connected', 'connections');
        $maxConnections = $this->setting($variables, 'max_connections', 'connections');

        return [
            'flavour' => $version->isOk() ? Metric::of($this->flavour, $version->source) : $version,
            'version' => $version,
            'database' => Metric::of($connection->getDatabaseName(), $label),
            'uptime_seconds' => $this->counter(
                $status,
                'Uptime',
                'seconds',
                'Every reading below marked "since the server started" covers exactly this window.',
            ),
            'latency_ms' => $this->latency($connection),

            'threads_connected' => $threadsConnected,
            'threads_running' => $this->counter(
                $status,
                'Threads_running',
                'threads',
                'Connections executing a statement right now, as opposed to sitting idle inside a pool.',
            ),
            'max_connections' => $maxConnections,
            'connection_utilisation_pct' => $this->connectionUtilisation($threadsConnected, $maxConnections),
            'max_used_connections' => $this->counter(
                $status,
                'Max_used_connections',
                'connections',
                'The high-water mark since the server started.',
            ),
            'aborted_connects' => $this->counter(
                $status,
                'Aborted_connects',
                'attempts',
                'Attempts that never authenticated: wrong credentials, a host that may not connect, or a timeout mid-handshake.',
            ),
            'aborted_clients' => $this->counter(
                $status,
                'Aborted_clients',
                'connections',
                'Clients that disappeared without closing the connection — usually a killed PHP worker or a dropped network path.',
            ),

            ...$this->rates($deltas),

            'commits' => $this->counter($status, 'Com_commit', 'transactions', 'Since the server started.'),
            'rollbacks' => $this->counter($status, 'Com_rollback', 'transactions', 'Since the server started.'),
            'slow_queries' => $this->slowQueryCount($status, $variables),

            'buffer_pool_hit_ratio' => $this->bufferPoolHitRatio($status, $deltas),
            'buffer_pool_hit_ratio_window' => $this->bufferPoolHitRatioWindow($status, $deltas),
            'buffer_pool_size_mb' => $this->setting($variables, 'innodb_buffer_pool_size', 'bytes')
                ->map(fn (int|float $bytes) => $this->toMegabytes($bytes), 'MB'),
            'buffer_pool_pages_data' => $this->counter($status, 'Innodb_buffer_pool_pages_data', 'pages'),
            'buffer_pool_pages_free' => $this->counter($status, 'Innodb_buffer_pool_pages_free', 'pages'),
            'buffer_pool_pages_dirty' => $this->counter(
                $status,
                'Innodb_buffer_pool_pages_dirty',
                'pages',
                'Modified pages not yet written back to disk.',
            ),

            'row_lock_waits' => $this->counter($status, 'Innodb_row_lock_waits', 'waits', 'Since the server started.'),
            'row_lock_time_avg_ms' => $this->counter(
                $status,
                'Innodb_row_lock_time_avg',
                'ms',
                'Averaged across the life of the server, so a bad minute is diluted by every good one before it.',
            ),
            'deadlocks' => $this->deadlocks($status, $connection),

            'tmp_tables_created' => $this->counter($status, 'Created_tmp_tables', 'tables', 'Since the server started.'),
            'tmp_disk_tables_created' => $this->counter($status, 'Created_tmp_disk_tables', 'tables', 'Since the server started.'),
            'tmp_disk_table_ratio_pct' => $this->tmpDiskRatio($status, $variables, $deltas),

            'table_open_cache' => $this->setting($variables, 'table_open_cache', 'tables'),
            'open_tables' => $this->counter(
                $status,
                'Open_tables',
                'tables',
                'Tables currently held open; at the table_open_cache ceiling the server starts closing one to open another.',
            ),

            ...$this->sizes($connection),
            ...$this->processlist($connection, $status),

            'query_digests' => $this->queryDigests($connection, $variables),
            'innodb_engine_status' => $this->innodbEngineStatus($connection),
            'innodb_metrics' => $this->innodbMetrics($connection),
            'slow_query_log' => $this->slowQueryLog($variables),
        ];
    }

    // ---- server identity ----------------------------------------------------------------------

    /**
     * MariaDB, MySQL or Percona — read off the running server, never assumed.
     *
     * The flavour decides real behaviour further down: MariaDB keeps a deadlock counter in SHOW
     * GLOBAL STATUS and MySQL does not, so getting this wrong turns a missing counter into a
     * confident zero.
     */
    private function detectFlavour(Connection $connection, array|Metric $variables): string
    {
        $version = $this->variableString($variables, 'version') ?? '';
        $comment = $this->variableString($variables, 'version_comment') ?? '';

        if ($version === '') {
            try {
                $version = (string) $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
            } catch (Throwable) {
                // The flavour is a label on a source string, not a reading; not knowing it must
                // not cost the metrics that do not depend on it.
            }
        }

        $signature = strtolower($version . ' ' . $comment);

        return match (true) {
            str_contains($signature, 'mariadb') => 'MariaDB',
            str_contains($signature, 'percona') => 'Percona Server',
            trim($signature) === '' => 'MySQL/MariaDB',
            default => 'MySQL',
        };
    }

    private function serverVersion(Connection $connection, array|Metric $variables): Metric
    {
        $version = $this->variableString($variables, 'version');
        if ($version !== null) {
            return Metric::of(
                $version,
                $this->source('SHOW GLOBAL VARIABLES'),
                null,
                $this->variableString($variables, 'version_comment'),
            );
        }

        return Metric::probe(
            'PDO::ATTR_SERVER_VERSION',
            fn () => (string) $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION),
        );
    }

    /** Provenance that names the server that answered, e.g. "MariaDB SHOW GLOBAL STATUS". */
    private function source(string $origin): string
    {
        return ($this->flavour ?? 'MySQL/MariaDB') . ' ' . $origin;
    }

    /**
     * The account as the server itself knows it, so a GRANT in a remedy can be pasted as printed.
     *
     * current_user() is the grant row that matched, which is not always the credentials in .env:
     * a login as staging@localhost can match 'staging'@'127.0.0.1', and granting to the wrong one
     * of those fixes nothing.
     */
    private function account(Connection $connection): string
    {
        if ($this->account !== null) {
            return $this->account;
        }

        $identity = '';
        try {
            $identity = (string) ($connection->selectOne('select current_user() as account')->account ?? '');
        } catch (Throwable) {
            // Fall back to the configured credentials below.
        }

        if ($identity === '') {
            $config = $connection->getConfig();
            $host = is_string($config['host'] ?? null) ? $config['host'] : '%';
            $identity = (is_string($config['username'] ?? null) ? $config['username'] : 'app_user') . '@' . $host;
        }

        $separator = strrpos($identity, '@');
        $user = $separator === false ? $identity : substr($identity, 0, $separator);
        $host = $separator === false ? '%' : substr($identity, $separator + 1);

        return $this->account = "'{$user}'@'{$host}'";
    }

    // ---- raw server state ---------------------------------------------------------------------

    /** @return array<string, string>|Metric */
    private function readStatus(Connection $connection): array|Metric
    {
        $source = $this->source('SHOW GLOBAL STATUS');

        try {
            return $this->keyed($connection->select('SHOW GLOBAL STATUS'));
        } catch (Throwable $exception) {
            return $this->unavailable($exception, $source, 'SHOW GLOBAL STATUS', $this->showRemedy($connection, 'global_status'));
        }
    }

    /** @return array<string, string>|Metric */
    private function readVariables(Connection $connection): array|Metric
    {
        try {
            return $this->keyed($connection->select('SHOW GLOBAL VARIABLES'));
        } catch (Throwable $exception) {
            return $this->unavailable(
                $exception,
                ($this->flavour ?? 'MySQL/MariaDB') . ' SHOW GLOBAL VARIABLES',
                'SHOW GLOBAL VARIABLES',
                $this->showRemedy($connection, 'global_variables'),
            );
        }
    }

    /**
     * @param  array<int, object>  $rows
     * @return array<string, string>
     */
    private function keyed(array $rows): array
    {
        $keyed = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            // MariaDB and MySQL disagree on the case of these two column names between versions.
            $name = $row['Variable_name'] ?? $row['VARIABLE_NAME'] ?? null;
            if ($name !== null) {
                $keyed[strtolower((string) $name)] = (string) ($row['Value'] ?? $row['VALUE'] ?? '');
            }
        }

        return $keyed;
    }

    // ---- rates --------------------------------------------------------------------------------

    /**
     * The counter movement since the previous sample, or the reason there is none.
     *
     * The reason comes back as text rather than as a Metric so each caller can attach it to its own
     * source and unit.
     *
     * @param  array<string, string>|Metric  $status
     * @return array{window: float, counters: array<string, float>}|string
     */
    private function deltas(array|Metric $status, float $sampledAt): array|string
    {
        if ($status instanceof Metric) {
            return 'SHOW GLOBAL STATUS is unavailable, so no rate can be derived from it.';
        }

        $wanted = array_flip(array_map('strtolower', self::COUNTERS));
        $sample = ['at' => $sampledAt, 'counters' => array_intersect_key($status, $wanted)];

        try {
            $previous = Cache::get(self::PREVIOUS_KEY);
            $comparable = is_array($previous) && isset($previous['at']) && is_array($previous['counters'] ?? null);
            $window = $comparable ? $sampledAt - (float) $previous['at'] : 0.0;

            // The stored sample is only replaced once it is old enough to have been used for
            // something. Overwriting it on every dashboard refresh would hold the window under a
            // second for as long as anyone was watching, and the rates would never appear at all.
            if (!$comparable || $window >= self::MIN_INTERVAL_SECONDS) {
                Cache::put(self::PREVIOUS_KEY, $sample, self::SAMPLE_TTL_SECONDS);
            }
        } catch (Throwable) {
            // A cache store that has fallen over costs the rates, not the page.
            return 'The cache store is unavailable, so there is no previous sample to compare against.';
        }

        if (!$comparable) {
            return 'Collecting the first sample; rates appear one minute after monitoring starts.';
        }

        if ($window < self::MIN_INTERVAL_SECONDS) {
            return 'The previous sample is too recent to derive a rate from.';
        }

        if ($this->number($sample['counters'], 'Uptime') < $this->number($previous['counters'], 'Uptime')) {
            return 'The server restarted between samples, so its counters started again from zero.';
        }

        $counters = [];
        foreach ($sample['counters'] as $field => $value) {
            $counters[$field] = (float) $value - (float) ($previous['counters'][$field] ?? 0);
        }

        if ($counters !== [] && min($counters) < 0) {
            // Status counters only ever climb, so this is a reset underneath us rather than
            // negative traffic — and a negative rate on a chart is worse than a gap in it.
            return 'The server counters went backwards between samples, so this window cannot be measured.';
        }

        return ['window' => $window, 'counters' => $counters];
    }

    /**
     * @param  array{window: float, counters: array<string, float>}|string  $deltas
     * @return array<string, Metric>
     */
    private function rates(array|string $deltas): array
    {
        $rates = [];

        foreach (self::RATES as $name => [$fields, $unit]) {
            $rates[$name] = $this->rate($deltas, $fields, $unit);
        }

        return $rates;
    }

    /**
     * @param  array{window: float, counters: array<string, float>}|string  $deltas
     * @param  list<string>  $fields
     */
    private function rate(array|string $deltas, array $fields, string $unit): Metric
    {
        $source = $this->source('SHOW GLOBAL STATUS');

        if (is_string($deltas)) {
            return Metric::noData($source, $deltas);
        }

        $moved = 0.0;
        foreach ($fields as $field) {
            $delta = $deltas['counters'][strtolower($field)] ?? null;
            if ($delta === null) {
                return Metric::notSupported($source, 'This server does not publish ' . implode(' or ', $fields) . '.');
            }
            $moved += $delta;
        }

        return Metric::of(round($moved / $deltas['window'], 2), $source, $unit, $this->windowNote($deltas));
    }

    /** @param array{window: float, counters: array<string, float>} $deltas */
    private function windowNote(array $deltas): string
    {
        return 'Measured across the ' . round($deltas['window'], 1) . 's since the previous sample.';
    }

    // ---- derived readings -----------------------------------------------------------------------

    private function connectionUtilisation(Metric $connected, Metric $ceiling): Metric
    {
        if (!$connected->isOk()) {
            return $connected;
        }
        if (!$ceiling->isOk()) {
            return $ceiling;
        }
        if ((float) $ceiling->value <= 0) {
            return Metric::noData($this->source('SHOW GLOBAL VARIABLES'), 'max_connections is not a positive number on this server.');
        }

        return Metric::of(
            round(100 * (float) $connected->value / (float) $ceiling->value, 1),
            $this->source('SHOW GLOBAL STATUS with SHOW GLOBAL VARIABLES'),
            '%',
            'Threads_connected against max_connections; at 100% the server refuses new connections and the site stops.',
        );
    }

    /**
     * The share of InnoDB page reads served from memory rather than from disk.
     *
     * Reported since server start, because that is the number the counters actually describe — but
     * it saturates: after a week even a pool thrashing right now still reads 99%. The window ratio
     * beside it is the one that moves during an incident.
     *
     * @param  array<string, string>|Metric  $status
     * @param  array{window: float, counters: array<string, float>}|string  $deltas
     */
    private function bufferPoolHitRatio(array|Metric $status, array|string $deltas): Metric
    {
        if ($status instanceof Metric) {
            return $status;
        }

        $source = $this->source('SHOW GLOBAL STATUS');
        $requests = $this->number($status, 'Innodb_buffer_pool_read_requests');
        $reads = $this->number($status, 'Innodb_buffer_pool_reads');

        if ($requests === null || $reads === null) {
            return Metric::notSupported($source, 'This server does not publish the InnoDB buffer pool read counters.');
        }
        if ($requests <= 0) {
            return Metric::noData($source, 'The buffer pool has not been read from since the server started.');
        }

        $note = 'Since the server started.';
        if (is_array($deltas)) {
            $windowRequests = $deltas['counters']['innodb_buffer_pool_read_requests'] ?? 0.0;
            $windowReads = $deltas['counters']['innodb_buffer_pool_reads'] ?? 0.0;
            $note .= $windowRequests > 0
                ? ' Over the last ' . round($deltas['window'], 1) . 's it was ' . $this->hitRatio($windowRequests, $windowReads) . '%.'
                : ' No page was requested from the buffer pool in the last window.';
        }

        return Metric::of($this->hitRatio($requests, $reads), $source, '%', $note);
    }

    /**
     * The same ratio over the last window, which is the one that belongs on a chart.
     *
     * The lifetime figure saturates: after a week of uptime a pool thrashing right now still reads
     * 99%, so a line drawn from it stays flat straight through the incident it was meant to show.
     *
     * @param  array<string, string>|Metric  $status
     * @param  array{window: float, counters: array<string, float>}|string  $deltas
     */
    private function bufferPoolHitRatioWindow(array|Metric $status, array|string $deltas): Metric
    {
        if ($status instanceof Metric) {
            return $status;
        }

        $source = $this->source('SHOW GLOBAL STATUS');
        if (is_string($deltas)) {
            return Metric::noData($source, $deltas);
        }

        $requests = $deltas['counters']['innodb_buffer_pool_read_requests'] ?? null;
        $reads = $deltas['counters']['innodb_buffer_pool_reads'] ?? null;
        if ($requests === null || $reads === null) {
            return Metric::notSupported($source, 'This server does not publish the InnoDB buffer pool read counters.');
        }
        if ($requests <= 0) {
            return Metric::noData($source, 'No page was requested from the buffer pool in the last window.');
        }

        return Metric::of($this->hitRatio($requests, $reads), $source, '%', $this->windowNote($deltas));
    }

    private function hitRatio(float $requests, float $reads): float
    {
        return round(100 * max(0, $requests - $reads) / $requests, 2);
    }

    /**
     * @param  array<string, string>|Metric  $status
     * @param  array<string, string>|Metric  $variables
     * @param  array{window: float, counters: array<string, float>}|string  $deltas
     */
    private function tmpDiskRatio(array|Metric $status, array|Metric $variables, array|string $deltas): Metric
    {
        if ($status instanceof Metric) {
            return $status;
        }

        $source = $this->source('SHOW GLOBAL STATUS');
        $created = $this->number($status, 'Created_tmp_tables');
        $onDisk = $this->number($status, 'Created_tmp_disk_tables');

        if ($created === null || $onDisk === null) {
            return Metric::notSupported($source, 'This server does not publish the temporary table counters.');
        }
        if ($created <= 0) {
            return Metric::noData($source, 'No temporary table has been created since the server started.');
        }

        $ceiling = $this->variableString($variables, 'tmp_table_size');
        $note = 'Since the server started.';
        if (is_array($deltas) && ($deltas['counters']['created_tmp_tables'] ?? 0.0) > 0) {
            $note .= ' Over the last ' . round($deltas['window'], 1) . 's it was '
                . round(100 * ($deltas['counters']['created_tmp_disk_tables'] ?? 0.0) / $deltas['counters']['created_tmp_tables'], 1) . '%.';
        }
        $note .= ' A temporary table spills to disk when it outgrows tmp_table_size'
            . ($ceiling !== null ? ' (' . $this->toMegabytes((float) $ceiling) . ' MB here)' : '')
            . ' or holds a BLOB/TEXT column.';

        return Metric::of(round(100 * $onDisk / $created, 1), $source, '%', $note);
    }

    /**
     * @param  array<string, string>|Metric  $status
     * @param  array<string, string>|Metric  $variables
     */
    private function slowQueryCount(array|Metric $status, array|Metric $variables): Metric
    {
        $threshold = $this->variableString($variables, 'long_query_time');
        $note = 'Since the server started';
        if ($threshold !== null) {
            $note .= ', counting statements slower than ' . $this->trimSeconds($threshold) . 's (long_query_time)';
        }

        return $this->counter($status, 'Slow_queries', 'queries', $note . '.');
    }

    /**
     * MariaDB keeps a deadlock counter; MySQL does not, and reading zero there would be a lie.
     *
     * @param  array<string, string>|Metric  $status
     */
    private function deadlocks(array|Metric $status, Connection $connection): Metric
    {
        if ($status instanceof Metric) {
            return $status;
        }

        $source = $this->source('SHOW GLOBAL STATUS');
        if (!array_key_exists('innodb_deadlocks', $status)) {
            return Metric::notSupported(
                $source,
                'This server publishes no deadlock counter in SHOW GLOBAL STATUS. The count exists only inside SHOW ENGINE INNODB STATUS, which this login may not read.',
                'GRANT PROCESS ON *.* TO ' . $this->account($connection) . ';',
            );
        }

        return Metric::of((int) $status['innodb_deadlocks'], $source, 'deadlocks', 'Since the server started.');
    }

    // ---- probes -------------------------------------------------------------------------------

    /**
     * A real round trip to the server and back.
     *
     * Three of them, reported as the median: one round trip on a busy box is decided by the process
     * scheduler as much as by the database, and the median is the honest "typical" of the three
     * where the fastest would flatter the server and the slowest would libel it.
     */
    private function latency(Connection $connection): Metric
    {
        return Metric::probe($this->source('select 1 round trip'), function () use ($connection) {
            $samples = [];

            for ($sample = 0; $sample < self::LATENCY_SAMPLES; $sample++) {
                $started = microtime(true);
                $connection->select('select 1');
                $samples[] = (microtime(true) - $started) * 1000;
            }

            sort($samples);

            return round($samples[intdiv(count($samples), 2)], 2);
        }, 'ms');
    }

    /**
     * Size on disk, straight from the server's own catalogue.
     *
     * @return array<string, Metric>
     */
    private function sizes(Connection $connection): array
    {
        $source = $this->source('information_schema.tables');
        $names = ['size_mb', 'index_size_mb', 'row_estimate', 'table_count', 'largest_tables'];
        $schema = $connection->getDatabaseName();

        try {
            $totals = (array) $connection->selectOne(
                'select count(*) as table_count, coalesce(sum(data_length), 0) as data_bytes,'
                . ' coalesce(sum(index_length), 0) as index_bytes, coalesce(sum(table_rows), 0) as row_estimate'
                . ' from information_schema.tables where table_schema = ? and table_type = ?',
                [$schema, 'BASE TABLE'],
            );
            $largest = $connection->select(
                'select table_name as name, data_length as data_bytes, index_length as index_bytes,'
                . ' table_rows as row_estimate from information_schema.tables'
                . ' where table_schema = ? and table_type = ?'
                . ' order by (coalesce(data_length, 0) + coalesce(index_length, 0)) desc limit ' . self::LARGEST_TABLES,
                [$schema, 'BASE TABLE'],
            );
        } catch (Throwable $exception) {
            return array_fill_keys($names, $this->unavailable(
                $exception,
                $source,
                'information_schema.tables',
                "GRANT SELECT ON `{$schema}`.* TO " . $this->account($connection) . ';',
            ));
        }

        if ($totals === []) {
            return array_fill_keys($names, Metric::noData($source, "The catalogue lists no base table in {$schema}."));
        }

        // table_rows is InnoDB's own sampled guess, not a count, and it can be out by a factor of
        // two on a large table. Saying so is cheaper than someone reconciling it against an export.
        $estimated = 'InnoDB estimates row counts from index statistics; read this as an order of magnitude, not a total.';

        return [
            'size_mb' => Metric::of(
                $this->toMegabytes((float) $totals['data_bytes'] + (float) $totals['index_bytes']),
                $source,
                'MB',
                "Data and indexes together for {$schema}.",
            ),
            'index_size_mb' => Metric::of($this->toMegabytes((float) $totals['index_bytes']), $source, 'MB'),
            'row_estimate' => Metric::of((int) $totals['row_estimate'], $source, 'rows', $estimated),
            'table_count' => Metric::of((int) $totals['table_count'], $source, 'tables'),
            'largest_tables' => Metric::of(
                array_map(fn (object $table) => [
                    'table' => (string) $table->name,
                    'data_mb' => $this->toMegabytes((float) $table->data_bytes),
                    'index_mb' => $this->toMegabytes((float) $table->index_bytes),
                    'rows' => (int) $table->row_estimate,
                ], $largest),
                $source,
                null,
                $estimated,
            ),
        ];
    }

    /**
     * What the server is doing right now, which is the only part of this page that is not history.
     *
     * Without the PROCESS privilege the server does not refuse this query — it quietly answers with
     * this login's own threads and nothing else. Threads_connected is what settles it: while the two
     * agree, every connection really is in view; the moment it is higher, the rest of the server is
     * hidden and a count of long-running statements taken from what is left would be the reassuring
     * zero this whole page exists to avoid.
     *
     * @param  array<string, string>|Metric  $status
     * @return array<string, Metric>
     */
    private function processlist(Connection $connection, array|Metric $status): array
    {
        $source = $this->source('information_schema.processlist');
        $names = ['processlist_count', 'long_running_queries'];
        $grant = 'GRANT PROCESS ON *.* TO ' . $this->account($connection) . ';';

        try {
            $row = (array) ($connection->selectOne(
                'select count(*) as threads,'
                . ' coalesce(sum(case when command not in (?, ?, ?) and time >= ? then 1 else 0 end), 0) as long_running'
                . ' from information_schema.processlist',
                ['Sleep', 'Daemon', 'Binlog Dump', self::LONG_RUNNING_SECONDS],
            ) ?? []);
        } catch (Throwable $exception) {
            return array_fill_keys($names, $this->unavailable($exception, $source, 'information_schema.processlist', $grant));
        }

        if (!isset($row['threads'])) {
            return array_fill_keys($names, Metric::noData($source, 'The server answered the process list query with no row.'));
        }

        $visible = (int) $row['threads'];
        $connected = $this->number(is_array($status) ? $status : [], 'Threads_connected');
        $blind = $connected !== null && $connected > $visible;

        $scope = match (true) {
            $blind => ' The server reports ' . (int) $connected . " connections but only {$visible} are visible to this login;"
                . " without the PROCESS privilege other accounts' threads are hidden.",
            $connected === null => ' Threads_connected is unavailable, so whether this login can see the whole server could not be checked.',
            default => ' Threads_connected agrees with this count, so nothing is hidden from this login.',
        };

        return [
            'processlist_count' => Metric::of($visible, $source, 'threads', 'Connections visible to this login, idle ones included.' . $scope),
            'long_running_queries' => $blind
                ? Metric::permissionDenied(
                    $source,
                    'Only ' . $visible . ' of the ' . (int) $connected . " connections the server is holding are visible to this login,"
                    . ' so a statement running long on another account would not be counted here.',
                    $grant,
                )
                : Metric::of(
                    (int) ($row['long_running'] ?? 0),
                    $source,
                    'queries',
                    'Statements running longer than ' . self::LONG_RUNNING_SECONDS . 's. The statements themselves are not published here: query text carries customer data.' . $scope,
                ),
        ];
    }

    /**
     * Per-statement summaries, which need performance_schema switched on AND granted.
     *
     * Both halves are checked against the running server rather than inferred, because they fail
     * identically from the outside and are fixed completely differently.
     *
     * @param  array<string, string>|Metric  $variables
     */
    private function queryDigests(Connection $connection, array|Metric $variables): Metric
    {
        $source = $this->source('performance_schema.events_statements_summary_by_digest');
        $account = $this->account($connection);
        $enabled = in_array(strtoupper((string) $this->variableString($variables, 'performance_schema')), ['ON', '1'], true);
        $grant = "GRANT SELECT ON performance_schema.* TO {$account};";
        $switchOn = 'Add performance_schema = ON under [mysqld] in my.cnf (on Debian/Ubuntu: /etc/mysql/mariadb.conf.d/50-server.cnf), restart the server, then ' . $grant;

        try {
            $rows = $connection->select(
                'select digest_text as statement, count_star as calls, avg_timer_wait as avg_picoseconds,'
                . ' sum_rows_examined as rows_examined'
                . ' from performance_schema.events_statements_summary_by_digest'
                . ' where schema_name = ? and digest_text is not null'
                . ' order by sum_timer_wait desc limit ' . self::LARGEST_TABLES,
                [$connection->getDatabaseName()],
            );
        } catch (Throwable $exception) {
            $code = $this->driverErrorCode($exception);

            if (in_array($code, self::DENIED_CODES, true)) {
                return $enabled
                    ? Metric::permissionDenied($source, "The login {$account} may not read performance_schema: " . $this->driverMessage($exception), $grant)
                    : Metric::notConfigured($source, $switchOn, "performance_schema is OFF on this server, and {$account} may not read it either.");
            }
            if (in_array($code, self::ABSENT_CODES, true)) {
                return Metric::notSupported($source, 'This server was built without performance_schema, so it keeps no per-statement summary.');
            }

            return Metric::failed($source, $exception);
        }

        if (!$enabled) {
            return Metric::notConfigured($source, $switchOn, 'performance_schema is OFF, so the server is summarising nothing to read.');
        }
        if ($rows === []) {
            return Metric::noData($source, 'No statement has been summarised for this schema yet.');
        }

        // Digest text is the server's normalised form — literals are already replaced with "?" —
        // so this is a shape of query, not anybody's data.
        return Metric::of(array_map(fn (object $digest) => [
            'statement' => $this->truncate((string) $digest->statement, 200),
            'calls' => (int) $digest->calls,
            'avg_ms' => round((float) $digest->avg_picoseconds / 1_000_000_000, 3),
            'rows_examined' => (int) $digest->rows_examined,
        ], $rows), $source);
    }

    private function innodbEngineStatus(Connection $connection): Metric
    {
        $source = $this->source('SHOW ENGINE INNODB STATUS');

        try {
            $row = (array) $connection->selectOne('SHOW ENGINE INNODB STATUS');
        } catch (Throwable $exception) {
            return $this->unavailable(
                $exception,
                $source,
                'the InnoDB engine status',
                'GRANT PROCESS ON *.* TO ' . $this->account($connection) . ';',
            );
        }

        $report = (string) ($row['Status'] ?? $row['status'] ?? '');
        if ($report === '') {
            return Metric::noData($source, 'The server returned an empty engine status.');
        }

        return Metric::of([
            'history_list_length' => $this->matchInt('/History list length\s+(\d+)/', $report),
            'queries_inside_innodb' => $this->matchInt('/(\d+)\s+queries inside InnoDB/', $report),
            'queries_in_queue' => $this->matchInt('/queries inside InnoDB,\s+(\d+)\s+queries in queue/', $report),
            'latest_deadlock_at' => $this->matchString('/LATEST DETECTED DEADLOCK\s*\n-+\n([^\n]+)/', $report),
        ], $source, null, 'Parsed from the engine report; a null field is one this server did not print.');
    }

    private function innodbMetrics(Connection $connection): Metric
    {
        $source = $this->source('information_schema.innodb_metrics');
        $placeholders = implode(', ', array_fill(0, count(self::INNODB_METRICS), '?'));

        try {
            // Every column, because the one that says whether a counter is running is named STATUS
            // on MySQL and ENABLED on MariaDB, and naming the wrong one fails before the server
            // ever gets as far as checking the privilege.
            $rows = $connection->select(
                'select * from information_schema.innodb_metrics where `NAME` in (' . $placeholders . ')',
                self::INNODB_METRICS,
            );
        } catch (Throwable $exception) {
            return $this->unavailable(
                $exception,
                $source,
                'information_schema.innodb_metrics',
                'GRANT PROCESS ON *.* TO ' . $this->account($connection) . ';',
            );
        }

        $enabled = [];
        $disabled = [];
        foreach ($rows as $row) {
            $row = array_change_key_case((array) $row);
            $name = (string) ($row['name'] ?? '');

            // A switched-off row still returns COUNT 0. Publishing that would put "0 deadlocks" on
            // the page for a server that is not counting deadlocks at all.
            $running = isset($row['status'])
                ? strtolower((string) $row['status']) === 'enabled'
                : (bool) ($row['enabled'] ?? false);

            if ($running) {
                $enabled[$name] = (int) ($row['count'] ?? 0);
                continue;
            }
            $disabled[] = $name;
        }

        if ($enabled === []) {
            $wanted = implode(',', self::INNODB_METRICS);

            return Metric::notConfigured(
                $source,
                "SET GLOBAL innodb_monitor_enable = '{$wanted}'; (needs SUPER, and is lost on restart — add"
                . " innodb_monitor_enable = {$wanted} under [mysqld] to make it stick.)",
                'Every counter asked for is switched off on this server, and a disabled counter still reports 0.',
            );
        }

        return Metric::of(
            $enabled,
            $source,
            null,
            $disabled === [] ? null : 'Switched off, so not reported: ' . implode(', ', $disabled) . '.',
        );
    }

    /**
     * @param  array<string, string>|Metric  $variables
     */
    private function slowQueryLog(array|Metric $variables): Metric
    {
        if ($variables instanceof Metric) {
            return $variables;
        }

        $source = $this->source('SHOW GLOBAL VARIABLES');
        $setting = $this->variableString($variables, 'slow_query_log');
        if ($setting === null) {
            return Metric::notSupported($source, 'This server does not publish slow_query_log.');
        }

        $threshold = $this->variableString($variables, 'long_query_time') ?? '?';
        if (in_array(strtoupper($setting), ['ON', '1'], true)) {
            return Metric::of(
                true,
                $source,
                null,
                'Logging statements slower than ' . $this->trimSeconds($threshold) . 's to '
                . ($this->variableString($variables, 'slow_query_log_file') ?? 'the configured slow log') . '.',
            );
        }

        return Metric::notConfigured(
            $source,
            'Set slow_query_log = ON and long_query_time = 1 under [mysqld] in my.cnf and restart, or on the running server: SET GLOBAL slow_query_log = ON; SET GLOBAL long_query_time = 1; (needs SUPER).',
            'The server writes no slow query log. This one is optional: the application records its own slow statements to monitoring_slow_queries regardless, and that is what the dashboard reads.',
        );
    }

    // ---- helpers ------------------------------------------------------------------------------

    /**
     * A connection that never opened, told apart by the number the server refused it with.
     *
     * A wrong password, an account that may not come in from this host and a mistyped DB_DATABASE
     * all arrive here as one exception, and each is a different one-line fix. Reporting all three
     * as "failed" hands the operator a PDO message and leaves them to guess which it was.
     */
    private function connectionFailure(Connection $connection, Throwable $exception, string $source): Metric
    {
        $code = $this->driverErrorCode($exception);
        $config = $connection->getConfig();
        $database = is_string($config['database'] ?? null) && $config['database'] !== '' ? $config['database'] : '<database>';

        if (in_array($code, self::LOGIN_DENIED_CODES, true)) {
            $account = $this->refusedAccount($exception, $connection);

            return Metric::permissionDenied(
                $source,
                'The server refused the login: ' . $this->driverMessage($exception),
                "Check DB_USERNAME and DB_PASSWORD in .env. If those are right the account may not connect from this"
                . " host, which is a different account row: CREATE USER {$account} IDENTIFIED BY '<password>';"
                . " GRANT ALL PRIVILEGES ON `{$database}`.* TO {$account}; FLUSH PRIVILEGES;",
            );
        }

        if (in_array($code, self::ABSENT_CODES, true)) {
            return Metric::notConfigured(
                $source,
                'Point DB_DATABASE in .env at a schema that exists, or create this one:'
                . " CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
                'The server answered, but has no schema by that name: ' . $this->driverMessage($exception),
            );
        }

        return Metric::failed($source, $exception);
    }

    /**
     * The account the server itself named while refusing the login.
     *
     * It is the grantee a GRANT has to target, and it is not always the one in .env: connecting to
     * 127.0.0.1 can be matched against 'staging'@'localhost', and granting to the other one of those
     * changes nothing.
     */
    private function refusedAccount(Throwable $exception, Connection $connection): string
    {
        if (preg_match("/for user '([^']*)'@'([^']*)'/", $this->driverMessage($exception), $match) === 1) {
            return "'{$match[1]}'@'{$match[2]}'";
        }

        return $this->account($connection);
    }

    /**
     * SHOW GLOBAL STATUS and SHOW GLOBAL VARIABLES need no privilege of their own.
     *
     * So a refusal is either MySQL 8 refusing the performance_schema table the statement reads
     * underneath, or a proxy in front of the server filtering it. GRANT USAGE — the grant that
     * grants nothing — would answer neither, which is why it is not offered here.
     */
    private function showRemedy(Connection $connection, string $table): string
    {
        return 'GRANT SELECT ON performance_schema.' . $table . ' TO ' . $this->account($connection)
            . '; If the account already has that, the connection is going through a proxy or a managed-database'
            . ' policy that filters the statement, and it has to be allowed there instead.';
    }

    /**
     * Turn a driver error into the state that names the fix.
     *
     * "Denied" and "absent" are different problems with different answers, and the server has
     * already told us which one this is — the error number is the only trustworthy witness.
     */
    private function unavailable(Throwable $exception, string $source, string $what, ?string $remedy = null): Metric
    {
        $code = $this->driverErrorCode($exception);

        if (in_array($code, self::DENIED_CODES, true)) {
            return Metric::permissionDenied(
                $source,
                "The database login is not allowed to read {$what}: " . $this->driverMessage($exception),
                $remedy,
            );
        }

        if (in_array($code, self::ABSENT_CODES, true)) {
            return Metric::notSupported($source, "This server has no {$what}: " . $this->driverMessage($exception));
        }

        return Metric::failed($source, $exception);
    }

    private function driverErrorCode(Throwable $exception): ?int
    {
        for ($error = $exception; $error !== null; $error = $error->getPrevious()) {
            $info = property_exists($error, 'errorInfo') ? $error->errorInfo : null;
            if (is_array($info) && isset($info[1])) {
                return (int) $info[1];
            }
        }

        return null;
    }

    private function driverMessage(Throwable $exception): string
    {
        for ($error = $exception; $error !== null; $error = $error->getPrevious()) {
            $info = property_exists($error, 'errorInfo') ? $error->errorInfo : null;
            if (is_array($info) && isset($info[1], $info[2])) {
                return 'error ' . $info[1] . ', ' . $info[2];
            }
        }

        return Metric::describeFailure($exception);
    }

    /**
     * @param  array<string, string>|Metric  $status
     */
    private function counter(array|Metric $status, string $field, ?string $unit = null, ?string $note = null): Metric
    {
        return $this->numeric($status, $this->source('SHOW GLOBAL STATUS'), $field, $unit, $note);
    }

    /**
     * @param  array<string, string>|Metric  $variables
     */
    private function setting(array|Metric $variables, string $field, ?string $unit = null, ?string $note = null): Metric
    {
        return $this->numeric($variables, $this->source('SHOW GLOBAL VARIABLES'), $field, $unit, $note);
    }

    /**
     * @param  array<string, string>|Metric  $bag
     */
    private function numeric(array|Metric $bag, string $source, string $field, ?string $unit, ?string $note): Metric
    {
        if ($bag instanceof Metric) {
            return $bag;
        }

        $value = $bag[strtolower($field)] ?? null;
        if (!is_numeric($value)) {
            return Metric::notSupported($source, "This server does not publish {$field}.");
        }

        return Metric::of(str_contains($value, '.') ? (float) $value : (int) $value, $source, $unit, $note);
    }

    /**
     * @param  array<string, string>  $bag
     */
    private function number(array $bag, string $field): ?float
    {
        $value = $bag[strtolower($field)] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  array<string, string>|Metric  $variables
     */
    private function variableString(array|Metric $variables, string $name): ?string
    {
        if ($variables instanceof Metric) {
            return null;
        }

        $value = $variables[strtolower($name)] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * long_query_time comes back as "10.000000"; the zeros are noise on a dashboard.
     *
     * Only ever trimmed past the decimal point — a server that answers "100" would otherwise be
     * printed as "1".
     */
    private function trimSeconds(string $seconds): string
    {
        return str_contains($seconds, '.') ? rtrim(rtrim($seconds, '0'), '.') : $seconds;
    }

    private function toMegabytes(int|float $bytes): float
    {
        return round($bytes / self::BYTES_PER_MB, 1);
    }

    private function truncate(string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return strlen($text) > $limit ? substr($text, 0, $limit) . '…' : $text;
    }

    private function matchInt(string $pattern, string $subject): ?int
    {
        return preg_match($pattern, $subject, $match) === 1 ? (int) $match[1] : null;
    }

    private function matchString(string $pattern, string $subject): ?string
    {
        return preg_match($pattern, $subject, $match) === 1 ? trim($match[1]) : null;
    }
}
