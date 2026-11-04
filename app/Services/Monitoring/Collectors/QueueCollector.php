<?php

namespace App\Services\Monitoring\Collectors;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\Environment;
use App\Services\Monitoring\Support\Redactor;
use Illuminate\Database\Connection;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * The queue, per queue name, and whether anything is actually draining it.
 *
 * Five things this deliberately does NOT do, because each one is a standard way a queue panel
 * reassures the person reading it while the queue is dead:
 *
 * 1. It does not treat depth as health. Three jobs stuck for an hour is an outage; three thousand
 *    flowing through is a busy evening. The age of the oldest waiting job is therefore the headline
 *    number here, and depth is reported beside it rather than instead of it.
 *
 * 2. It does not report "0 workers" when it simply cannot see. Counting processes needs a shell,
 *    and shared hosting routinely takes that away — so with exec disabled the worker count comes
 *    back permission_denied, not zero. "No worker is running" and "I cannot tell" lead an operator
 *    to opposite actions, and only one of them is recoverable from a wrong guess.
 *
 * 3. It does not describe the sync driver as an empty queue. On sync a dispatched job runs inside
 *    the request that dispatched it: there is nothing to be behind, so pending and lag do not exist
 *    rather than being zero — and a green "0 pending" would hide the fact that every job is running
 *    on the customer's page load.
 *
 * 4. It does not read reserved_at as proof of progress. A worker killed mid-job leaves its
 *    reservation behind until retry_after expires, so a row that says "being processed" can mean
 *    "was being processed by a process that no longer exists". Reservations older than retry_after
 *    are counted separately and reported as the worker failure they are.
 *
 * 5. It does not call a paused queue a stalled one. `queue:pause` is a deliberate act; jobs piling
 *    up behind it is the intended behaviour, and reporting that as a dead worker sends someone
 *    restarting supervisors that are working perfectly.
 */
class QueueCollector implements Collector
{
    /** Enough failures to see a pattern, few enough to render beside everything else. */
    private const RECENT_FAILURES = 10;

    /**
     * Upper bound on how many queue names are reported.
     *
     * Queue names are normally a handful of constants, but a job dispatched onto a per-tenant or
     * per-order queue makes them unbounded — and every one of those would become a permanent gauge
     * series. Beyond this the list is cut and says so.
     */
    private const MAX_QUEUES = 50;

    /** Upper bound on one Redis discovery pass, so a huge keyspace cannot stall the dashboard. */
    private const REDIS_SCAN_KEYS = 500;

    private const REDIS_SCAN_BATCH = 100;

    /**
     * The readings only the queue backend can answer, so one unusable driver is reported once, in
     * one state, instead of as a page full of separate failures.
     *
     * @var list<string>
     */
    private const BACKEND_METRICS = [
        'queues', 'queue_count', 'pending', 'reserved', 'delayed', 'oldest_wait_seconds',
        'stuck_reserved', 'workers_consuming',
    ];

    /**
     * The readings that come from this application's own tables and hold on every driver.
     *
     * Failures land in failed_jobs, batches in job_batches, and throughput and runtime in
     * monitoring_series from the workers' own JobProcessed events — none of which the broker is
     * involved in. Blanketing these with the driver's excuse would hide a real count of failed jobs
     * behind "read it from CloudWatch", and hand the operator a remedy that cannot fix the thing it
     * is attached to.
     *
     * @var list<string>
     */
    private const LOCAL_METRICS = [
        'failed_24h', 'failed_total', 'recent_failures', 'pending_batches',
        'throughput_per_minute', 'average_runtime_ms',
    ];

    /**
     * The window throughput is averaged over.
     *
     * Fifteen minutes is long enough to survive a worker restart and short enough that the number
     * still describes now rather than this morning.
     */
    private const THROUGHPUT_WINDOW_MINUTES = 15;

    /** The per-queue readings gauges() publishes, metric name => gauge name. */
    private const PER_QUEUE_GAUGES = [
        'pending' => 'queue.pending',
        'oldest_wait_seconds' => 'queue.oldest_wait_seconds',
        'reserved' => 'queue.reserved',
    ];

    /** @var array<string, array<string, Metric>> queue name => metric name => reading */
    private array $perQueue = [];

    /** Whether more queue names were found than MAX_QUEUES reports. */
    private bool $queuesTruncated = false;

    /** @var array<string, Metric>|null */
    private ?array $readings = null;

    public function __construct(private readonly Environment $environment)
    {
    }

    public function key(): string
    {
        return 'queue';
    }

    public function collect(): array
    {
        // Sampled once per instance: collecting twice in one request — once for the panel, once for
        // gauges() — would run the aggregate over the jobs table and the process listing twice, and
        // the two passes would disagree about a queue that moved in between.
        return $this->readings ??= $this->read();
    }

    public function gauges(): array
    {
        $collected = $this->collect();
        $gauges = [];

        foreach ($this->perQueue as $queue => $metrics) {
            $label = $this->gaugeLabel($queue);
            if ($label === null) {
                continue;
            }

            foreach (self::PER_QUEUE_GAUGES as $name => $gauge) {
                $metric = $metrics[$name] ?? null;
                if ($metric instanceof Metric && $metric->isOk() && is_numeric($metric->value)) {
                    $gauges["{$gauge}@{$label}"] = $metric;
                }
            }
        }

        $failed = $collected['failed_24h'] ?? null;
        if ($failed instanceof Metric && $failed->isOk() && is_numeric($failed->value)) {
            $gauges['queue.failed_24h'] = $failed;
        }

        return $gauges;
    }

    // -------------------------------------------------------------------------------------------

    /**
     * @return array<string, Metric>
     */
    private function read(): array
    {
        $this->perQueue = [];
        $this->queuesTruncated = false;

        $connectionName = (string) config('queue.default', 'sync');
        $driver = (string) config("queue.connections.{$connectionName}.driver", '');
        $source = "config/queue.php [{$connectionName}]";

        $header = [
            'connection' => Metric::of($connectionName, $source),
            'driver' => Metric::of($driver !== '' ? $driver : 'unknown', $source),
        ];

        try {
            return $header + match ($driver) {
                'database' => $this->fromDatabase($connectionName),
                'redis' => $this->fromRedis($connectionName),
                'sync' => $this->sync(),
                default => $this->otherDriver($driver, $source),
            };
        } catch (Throwable $exception) {
            // The outermost catch, so the throw could have come from anywhere in the read —
            // including the parts that do not touch the broker. Nothing here is known good.
            return $header + array_fill_keys($this->everyMetric(), Metric::failed($source, $exception));
        }
    }

    /**
     * @return array<string, Metric>
     */
    private function sync(): array
    {
        // Workers are blanketed with the rest here, unlike on a broker this collector cannot read:
        // on sync there is nothing for a worker to consume, so a count of local queue:work
        // processes would be a true number answering a question that does not apply.
        return $this->unavailable(Metric::notConfigured(
            'config/queue.php [sync]',
            'Set QUEUE_CONNECTION=database in .env, run php artisan config:clear, then keep a worker alive: php artisan queue:work --queue=default (under supervisor or systemd, not by hand).',
            'This application has no queue. On the sync driver a dispatched job runs inline, inside the request that dispatched it, so the customer waits for the invoice PDF and the mail send. Pending, lag and worker counts are not zero here — they do not exist.',
        ), workersToo: true);
    }

    /**
     * @return array<string, Metric>
     */
    private function otherDriver(string $driver, string $source): array
    {
        if ($driver === '' || $driver === 'null') {
            return $this->unavailable(Metric::notConfigured(
                $source,
                'Set QUEUE_CONNECTION=database (or redis) in .env and run php artisan config:clear.',
                $driver === 'null'
                    ? 'The null driver throws every dispatched job away: nothing is queued, nothing runs, and nothing fails visibly.'
                    : 'No queue driver is configured for this connection.',
            ), workersToo: true);
        }

        return $this->unavailable(Metric::notSupported(
            $source,
            "This application queues onto {$driver}, which keeps its depth, age and worker counters inside the broker rather than anywhere this server can read.",
            $driver === 'sqs'
                ? 'Read ApproximateNumberOfMessagesVisible and ApproximateAgeOfOldestMessage from CloudWatch for this queue, or point MONITORING_NODE_EXPORTER_URL at an exporter that already does.'
                : null,
        ));
    }

    /**
     * One reason, applied to every reading that reason actually covers.
     *
     * Only the depths come from the backend. The failure counts, batches, throughput and runtime are
     * read out of this application's own tables and stay real whatever the queue is running on, so
     * they are collected normally rather than being buried under the driver's excuse — a `pending`
     * that says "read it from CloudWatch" is honest, and a `failed_total` that says the same is a
     * count this server has sitting in a table it can read.
     *
     * @param  bool  $workersToo  whether the reason covers the worker count as well
     * @return array<string, Metric>
     */
    private function unavailable(Metric $reason, bool $workersToo = false): array
    {
        return array_fill_keys(self::BACKEND_METRICS, $reason)
            + ['worker_processes' => $workersToo ? $reason : $this->workerProcesses()]
            + $this->localReadings();
    }

    /**
     * Every name this collector answers to, for the case where nothing at all could be read.
     *
     * @return list<string>
     */
    private function everyMetric(): array
    {
        return array_merge(self::BACKEND_METRICS, self::LOCAL_METRICS, ['worker_processes']);
    }

    /**
     * The readings that do not depend on the queue backend being reachable.
     *
     * @return array<string, Metric>
     */
    private function localReadings(): array
    {
        $failures = $this->failureCounts();

        return [
            'failed_24h' => $this->failureTotal($failures, 'last24'),
            'failed_total' => $this->failureTotal($failures, 'total'),
            'recent_failures' => $this->recentFailures(),
            'pending_batches' => $this->pendingBatches(),
            'throughput_per_minute' => $this->recordedThroughput(),
            'average_runtime_ms' => $this->recordedRuntime(),
        ];
    }

    /**
     * @param  array<string, array{total: int, last24: int}>|Metric  $failures
     */
    private function failureTotal(array|Metric $failures, string $field): Metric
    {
        if ($failures instanceof Metric) {
            return $failures;
        }

        return Metric::of(
            array_sum(array_column($failures, $field)),
            'database table ' . (string) config('queue.failed.table', 'failed_jobs'),
            'jobs',
        );
    }

    // ---- database driver ------------------------------------------------------------------------

    /**
     * @return array<string, Metric>
     */
    private function fromDatabase(string $connectionName): array
    {
        $table = (string) config("queue.connections.{$connectionName}.table", 'jobs');
        $retryAfter = (int) config("queue.connections.{$connectionName}.retry_after", 90);
        $source = "database table {$table}";

        try {
            $connection = DB::connection(config("queue.connections.{$connectionName}.connection"));
            $connection->getPdo();
        } catch (Throwable $exception) {
            // Not narrowed to the depths: failed_jobs, job_batches and the monitoring series all
            // default to this same server, so probing each of them in turn would add a connection
            // timeout per reading to a dashboard render and arrive at the same answer.
            return array_fill_keys($this->everyMetric(), Metric::failed($source, $exception));
        }

        if (!$this->hasTable($connection, $table)) {
            // Without this table every dispatch throws, so every depth is unreadable — one
            // statement of that, rather than a set of metrics each inventing a zero.
            return $this->unavailable(Metric::notConfigured(
                $source,
                "php artisan make:queue-table && php artisan migrate (creates the `{$table}` table this connection is configured to use).",
                "The queue is configured to use the `{$table}` table and this database does not have it, so no job can be queued at all.",
            ));
        }

        $now = time();
        $rows = $connection->table($table)
            ->selectRaw(
                // Every alias is quoted: DELAYED is a keyword in MySQL and MariaDB, and an
                // unquoted one turns this whole aggregate into a syntax error.
                '`queue`,'
                . ' SUM(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN 1 ELSE 0 END) AS `pending`,'
                . ' SUM(CASE WHEN reserved_at IS NULL AND available_at > ? THEN 1 ELSE 0 END) AS `delayed`,'
                . ' SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) AS `reserved`,'
                . ' SUM(CASE WHEN reserved_at IS NOT NULL AND reserved_at < ? THEN 1 ELSE 0 END) AS `stuck`,'
                . ' MIN(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN available_at END) AS `oldest_available_at`,'
                . ' MAX(reserved_at) AS `newest_reserved_at`',
                [$now, $now, $now - $retryAfter, $now],
            )
            ->groupBy('queue')
            ->get()
            ->keyBy('queue');

        $failures = $this->failureCounts();
        $names = $this->queueNames($connectionName, $rows->keys()->all(), $failures);
        $newestReservation = null;

        foreach ($names as $name) {
            $row = $rows->get($name);
            $pending = (int) ($row->pending ?? 0);
            $oldestAvailableAt = $row->oldest_available_at ?? null;
            $reservedAt = (int) ($row->newest_reserved_at ?? 0);
            if ($reservedAt > 0) {
                $newestReservation = max($newestReservation ?? 0, $reservedAt);
            }

            $this->perQueue[$name] = [
                'pending' => Metric::of($pending, $source, 'jobs'),
                'delayed' => Metric::of((int) ($row->delayed ?? 0), $source, 'jobs', 'Dispatched with a delay, not yet due.'),
                'reserved' => Metric::of(
                    (int) ($row->reserved ?? 0),
                    $source,
                    'jobs',
                    'Held by a worker right now — or by a worker that died, until retry_after expires.',
                ),
                'stuck_reserved' => Metric::of((int) ($row->stuck ?? 0), $source, 'jobs'),
                'oldest_wait_seconds' => Metric::of(
                    $oldestAvailableAt === null ? 0 : max(0, $now - (int) $oldestAvailableAt),
                    $source,
                    'seconds',
                    $pending === 0 ? 'Nothing is waiting on this queue.' : null,
                ),
                'paused' => $this->paused($connectionName, $name),
                ...$this->failuresFor($failures, $name),
            ];
        }

        $totals = $this->totals($source);
        $oldest = $this->oldestWait($source);
        $processes = $this->workerProcesses();

        return $totals + [
            'queues' => $this->queueTable($source),
            'queue_count' => $this->queueCount($source),
            'oldest_wait_seconds' => $oldest,
            'recent_failures' => $this->recentFailures(),
            'worker_processes' => $processes,
            'workers_consuming' => $this->consumption([
                'source' => $source,
                'processes' => $processes,
                'pending' => $totals['pending'],
                'reserved' => $totals['reserved'],
                'stuck' => $totals['stuck_reserved'],
                'stuck_note' => "reserved longer than this connection's retry_after of {$retryAfter}s",
                'fresh_reservation' => $newestReservation !== null && $newestReservation >= $now - $retryAfter,
                'paused' => $this->allPaused(),
                'resume' => $this->resumeRemedy($connectionName),
                'stalled_reason' => $this->lagVerdict($oldest),
                'waited' => $oldest->isOk() ? (int) $oldest->value : null,
                'restart' => 'php artisan queue:restart, then confirm the supervisor is actually respawning workers (supervisorctl status).',
                'start' => "Start a worker for this connection and keep it alive: php artisan queue:work {$connectionName} --queue=" . implode(',', array_keys($this->perQueue)),
            ]),
            'pending_batches' => $this->pendingBatches(),
            'throughput_per_minute' => $this->recordedThroughput(),
            'average_runtime_ms' => $this->recordedRuntime(),
        ];
    }

    // ---- redis driver ---------------------------------------------------------------------------

    /**
     * @return array<string, Metric>
     */
    private function fromRedis(string $connectionName): array
    {
        $source = 'Redis queue keys (queues:*)';

        if (!$this->environment->has('redis_ext') && !$this->environment->has('predis')) {
            return $this->unavailable(Metric::notConfigured(
                $source,
                'Install the phpredis extension (pecl install redis, then extension=redis.so) or require predis/predis — the queue is configured for Redis but this PHP has no client to reach it with.',
            ));
        }

        try {
            $redis = Redis::connection((string) config("queue.connections.{$connectionName}.connection", 'default'));
            $redis->ping();
        } catch (Throwable $exception) {
            return $this->unavailable(Metric::failed($source, $exception));
        }

        $now = time();
        $failures = $this->failureCounts();
        $names = $this->queueNames($connectionName, $this->discoverRedisQueues($redis), $failures);

        try {
            $counts = $this->redisCounts($redis, $names, $now);
        } catch (Throwable $exception) {
            return $this->unavailable(Metric::failed($source, $exception));
        }

        // Laravel's Redis queue writes no timestamp into the job payload, so how long the head of
        // the list has been sitting there genuinely cannot be read back. Horizon adds pushedAt for
        // exactly this reason, which makes it the only honest way to get this number.
        $noEnqueueTime = 'A job on a Redis list carries no enqueue time, so the age of the oldest waiting job cannot be recovered from Redis alone.';
        $overdue = 0;
        $reservedTotal = 0;

        foreach ($counts as $name => $count) {
            $overdue += $count['delayed_overdue'];
            $reservedTotal += $count['reserved'];

            $this->perQueue[$name] = [
                'pending' => Metric::of($count['pending'], $source, 'jobs'),
                'delayed' => Metric::of(
                    $count['delayed'],
                    $source,
                    'jobs',
                    $count['delayed_overdue'] > 0
                        ? "{$count['delayed_overdue']} of them came due and have not been moved onto the queue."
                        : null,
                ),
                'reserved' => Metric::of($count['reserved'], $source, 'jobs'),
                'stuck_reserved' => Metric::of(
                    $count['reserved_expired'],
                    $source,
                    'jobs',
                    'Reservations whose retry_after has already passed: the worker holding them is gone.',
                ),
                'oldest_wait_seconds' => $this->horizonOnly($source, $noEnqueueTime),
                'paused' => $this->paused($connectionName, $name),
                ...$this->failuresFor($failures, $name),
            ];
        }

        $totals = $this->totals($source);
        $processes = $this->workerProcesses();

        return $totals + [
            'queues' => $this->queueTable($source),
            'queue_count' => $this->queueCount($source),
            'oldest_wait_seconds' => $this->horizonOnly($source, $noEnqueueTime),
            'recent_failures' => $this->recentFailures(),
            'worker_processes' => $processes,
            'workers_consuming' => $this->consumption([
                'source' => $source,
                'processes' => $processes,
                'pending' => $totals['pending'],
                'reserved' => $reservedTotal,
                'stuck' => $totals['stuck_reserved'],
                'stuck_note' => 'past the point Redis says their reservation expired',
                // Null, not false, when the stuck count could not be read: valueOr(0) turned "we
                // could not count expired reservations" into "none are expired", which is the
                // reading that says the workers are healthy.
                'fresh_reservation' => $totals['stuck_reserved']->isOk()
                    ? ($reservedTotal > 0 && (float) $totals['stuck_reserved']->value < $reservedTotal)
                    : null,
                'paused' => $this->allPaused(),
                'resume' => $this->resumeRemedy($connectionName),
                // Migrating a due job from the delayed set onto the list is something only a
                // running worker does, so an overdue backlog is proof nothing is polling.
                'stalled_reason' => $overdue > 0
                    ? "{$overdue} delayed job(s) came due and are still sitting in the delayed set; only a running worker moves them onto the queue."
                    : null,
                // Redis cannot say how long the head of the list has waited, and guessing would be
                // the one thing this collector must not do.
                'waited' => null,
                'restart' => 'php artisan queue:restart, then confirm the supervisor is actually respawning workers (supervisorctl status).',
                'start' => "Start a worker for this connection and keep it alive: php artisan queue:work {$connectionName} --queue=" . implode(',', array_keys($this->perQueue)),
            ]),
            'pending_batches' => $this->pendingBatches(),
            'throughput_per_minute' => $this->recordedThroughput(),
            'average_runtime_ms' => $this->recordedRuntime(),
        ];
    }

    /**
     * Every queue's depths in one round trip.
     *
     * Five commands per queue, sent as one pipeline: a dashboard that opened a hundred separate
     * round trips to a Redis on another host would spend more time measuring the queue than the
     * queue spends running. A reply that is not a number is refused rather than cast — (int) false
     * is 0, and a zero here would read as an empty, healthy queue.
     *
     * @param  list<string>  $names
     * @return array<string, array{pending: int, delayed: int, delayed_overdue: int, reserved: int, reserved_expired: int}>
     */
    private function redisCounts(RedisConnection $redis, array $names, int $now): array
    {
        if ($names === []) {
            return [];
        }

        $fields = ['pending', 'delayed', 'delayed_overdue', 'reserved', 'reserved_expired'];
        $replies = (array) $redis->pipeline(function ($pipe) use ($names, $now) {
            foreach ($names as $name) {
                $key = 'queues:' . $name;
                $pipe->llen($key);
                $pipe->zcard($key . ':delayed');
                $pipe->zcount($key . ':delayed', '-inf', (string) $now);
                $pipe->zcard($key . ':reserved');
                $pipe->zcount($key . ':reserved', '-inf', (string) $now);
            }
        });

        if (count($replies) !== count($names) * count($fields)) {
            throw new \RuntimeException('Redis returned ' . count($replies) . ' replies to ' . count($names) * count($fields) . ' pipelined queue commands.');
        }

        $counts = [];
        foreach (array_values($names) as $index => $name) {
            $values = array_slice($replies, $index * count($fields), count($fields));
            foreach ($values as $value) {
                if (!is_numeric($value)) {
                    throw new \RuntimeException("Redis did not answer the depth of queue \"{$name}\".");
                }
            }
            $counts[$name] = array_map('intval', array_combine($fields, $values));
        }

        return $counts;
    }

    /**
     * Queue names in use, discovered from the keyspace rather than guessed from configuration.
     *
     * SCAN is used rather than KEYS because this runs against a production Redis, and it is bounded
     * so that a keyspace with a million entries costs the dashboard a fixed number of round trips.
     * The pattern and the returned keys carry the client's prefix — phpredis applies it to values
     * but not to SCAN — so it is added going in and stripped coming out.
     *
     * @return list<string>
     */
    private function discoverRedisQueues(RedisConnection $redis): array
    {
        try {
            $client = $redis->client();
            if (!$client instanceof \Redis) {
                return [];
            }

            $prefix = (string) config('database.redis.options.prefix', '');
            $cursor = null;
            $names = [];
            $seen = 0;

            do {
                $keys = $client->scan($cursor, $prefix . 'queues:*', self::REDIS_SCAN_BATCH);
                if ($keys === false) {
                    break;
                }
                foreach ($keys as $key) {
                    $seen++;
                    $name = substr((string) $key, strlen($prefix) + strlen('queues:'));
                    // queues:orders, queues:orders:delayed and queues:orders:reserved are all the
                    // same queue; the suffixes are the parts of it, not queues of their own.
                    $name = preg_replace('/:(delayed|reserved|notify)$/', '', $name);
                    if (is_string($name) && $name !== '') {
                        $names[$name] = true;
                    }
                }
            } while ($cursor && $seen < self::REDIS_SCAN_KEYS);

            return array_keys($names);
        } catch (Throwable) {
            // Discovery is a convenience; the configured queue is still reported without it.
            return [];
        }
    }

    private function horizonOnly(string $source, string $note): Metric
    {
        if (class_exists(\Laravel\Horizon\Horizon::class)) {
            return Metric::noData('Laravel Horizon', $note . ' Horizon is installed and records it; this collector does not read Horizon\'s own metrics yet.');
        }

        return Metric::notConfigured(
            $source,
            'Install Laravel Horizon (composer require laravel/horizon && php artisan horizon:install) and run php artisan horizon in place of queue:work — it stamps each job with its enqueue time and keeps the wait, runtime and throughput series this needs.',
            $note,
        );
    }

    // ---- shared readings ------------------------------------------------------------------------

    /**
     * The union of what is in the backend, what has failed, and what is configured.
     *
     * The configured queue is always included: an empty queue that a worker is watching is a real
     * reading of zero, and dropping it off the list would make a working queue disappear from the
     * dashboard the moment it caught up.
     *
     * @param  list<string>  $discovered
     * @param  array<string, array{total: int, last24: int}>|Metric  $failures
     * @return list<string>
     */
    private function queueNames(string $connectionName, array $discovered, array|Metric $failures): array
    {
        $names = [(string) config("queue.connections.{$connectionName}.queue", 'default')];

        foreach ($discovered as $name) {
            $names[] = (string) $name;
        }

        if (is_array($failures)) {
            foreach (array_keys($failures) as $name) {
                $names[] = (string) $name;
            }
        }

        $unique = array_values(array_unique(array_filter($names, static fn (string $name) => $name !== '')));
        $this->queuesTruncated = count($unique) > self::MAX_QUEUES;

        // The configured queue comes first and the ones only seen in failed_jobs come last, so a
        // truncated list keeps the queues that are actually in use.
        return array_slice($unique, 0, self::MAX_QUEUES);
    }

    /**
     * @return array<string, Metric>
     */
    private function totals(string $source): array
    {
        $sum = function (string $name) use ($source): Metric {
            if ($this->perQueue === []) {
                // Nothing was found to add up, which is not the same as adding up to nothing: a
                // zero here would report an idle queue on a connection whose queue name is blank
                // and which therefore cannot be read at all.
                return Metric::noData($source, 'No queue is configured or in use on this connection, so there is nothing to total.');
            }

            $total = 0;
            // The total is published under the source of the rows it came from, not the source of
            // the collector: failures are read from failed_jobs, and a total labelled `jobs` would
            // send anyone checking the number to the wrong table.
            $origin = $source;

            foreach ($this->perQueue as $metrics) {
                $metric = $metrics[$name] ?? null;
                if (!$metric instanceof Metric) {
                    continue;
                }
                if (!$metric->isOk()) {
                    // One queue that cannot be read makes the total unknowable, and a total that
                    // silently omits it would read lower than the truth.
                    return $metric;
                }
                $origin = $metric->source;
                $total += (int) $metric->value;
            }

            return Metric::of($total, $origin, 'jobs', $this->truncationNote());
        };

        return [
            'pending' => $sum('pending'),
            'reserved' => $sum('reserved'),
            'delayed' => $sum('delayed'),
            'stuck_reserved' => $sum('stuck_reserved'),
            'failed_24h' => $sum('failed_24h'),
            'failed_total' => $sum('failed_total'),
        ];
    }

    /**
     * The worst queue's oldest waiting job — the number this whole collector exists for.
     */
    private function oldestWait(string $source): Metric
    {
        $worst = null;
        $worstQueue = null;

        foreach ($this->perQueue as $name => $metrics) {
            $metric = $metrics['oldest_wait_seconds'] ?? null;
            if (!$metric instanceof Metric) {
                continue;
            }
            if (!$metric->isOk()) {
                return $metric;
            }
            if ($worst === null || (int) $metric->value > $worst) {
                $worst = (int) $metric->value;
                $worstQueue = $name;
            }
        }

        if ($worst === null) {
            return Metric::noData($source, 'No queue is configured or in use on this connection.');
        }

        // Zero is a measured backlog of nothing, not an oldest job that waited no time — naming a
        // queue beside it would assert a waiting job that is not there.
        return Metric::of(
            $worst,
            $source,
            'seconds',
            $this->truncationNote(
                $worst === 0
                    ? 'No job is waiting on any queue.'
                    : "Oldest waiting job across all queues (on \"{$worstQueue}\").",
            ),
        );
    }

    /**
     * How many queues are in use, and whether that is all of them.
     */
    private function queueCount(string $source): Metric
    {
        if ($this->perQueue === []) {
            return Metric::noData($source, 'No queue is configured or in use on this connection.');
        }

        return Metric::of(count($this->perQueue), $source, 'queues', $this->truncationNote());
    }

    /**
     * One row per queue, for the table on the panel.
     */
    private function queueTable(string $source): Metric
    {
        $rows = [];

        foreach ($this->perQueue as $name => $metrics) {
            $row = ['queue' => $name];
            foreach (['pending', 'reserved', 'delayed', 'stuck_reserved', 'oldest_wait_seconds', 'failed_24h', 'failed_total', 'paused'] as $field) {
                $metric = $metrics[$field] ?? null;
                // null here means "this driver cannot answer that"; the metric of the same name
                // alongside carries the state and the remedy, so the table never has to invent one.
                $row[$field] = $metric instanceof Metric ? $metric->valueOr(null) : null;
            }
            $rows[] = $row;
        }

        return $rows === []
            ? Metric::noData($source, 'No queue is configured or in use on this connection.')
            : Metric::of($rows, $source, null, $this->truncationNote());
    }

    /**
     * Said out loud rather than silently, on every number the cut affects.
     *
     * A count of fifty on a list cut at fifty is wrong, and so is a total summed from it — both in
     * the direction that makes a queue name built from an id look tidy.
     */
    private function truncationNote(?string $note = null): ?string
    {
        if (!$this->queuesTruncated) {
            return $note;
        }

        $cut = 'More than ' . self::MAX_QUEUES . ' queue names are in use; the rest are not listed, counted or totalled here. A queue name built from an id or a tenant is the usual cause.';

        return $note === null ? $cut : $note . ' ' . $cut;
    }

    private function paused(string $connectionName, string $queue): Metric
    {
        return Metric::probe('Laravel queue pause flag (cache)', function () use ($connectionName, $queue) {
            $manager = Queue::getFacadeRoot();
            if (!method_exists($manager, 'isPaused')) {
                return Metric::notSupported(
                    'Laravel queue pause flag (cache)',
                    'This Laravel version has no queue:pause, so a queue cannot be paused.',
                );
            }

            return (bool) $manager->isPaused($connectionName, $queue);
        });
    }

    /**
     * The exact command that un-pauses this connection, queue names included.
     */
    private function resumeRemedy(string $connectionName): string
    {
        $queues = array_keys($this->perQueue);
        $first = $queues[0] ?? 'default';
        $others = array_slice($queues, 1);

        return "php artisan queue:resume {$connectionName}:{$first}"
            . ($others === [] ? '' : ', and once more for each of ' . implode(', ', $others))
            . '. The pause flag is stored in the cache and survives restarts and deployments, so find out what set it before resuming.';
    }

    private function allPaused(): bool
    {
        $paused = false;

        foreach ($this->perQueue as $metrics) {
            $metric = $metrics['paused'] ?? null;
            if (!$metric instanceof Metric || !$metric->isOk() || $metric->value !== true) {
                return false;
            }
            $paused = true;
        }

        return $paused;
    }

    // ---- failures ---------------------------------------------------------------------------------

    /**
     * Failed jobs per queue: the whole history, and the last 24 hours beside it.
     *
     * @return array<string, array{total: int, last24: int}>|Metric
     */
    private function failureCounts(): array|Metric
    {
        $unusable = $this->failedStoreUnavailable();
        if ($unusable !== null) {
            return $unusable;
        }

        $table = (string) config('queue.failed.table', 'failed_jobs');
        $source = "database table {$table}";

        try {
            $connection = DB::connection(config('queue.failed.database'));
            if (!$this->hasTable($connection, $table)) {
                return Metric::notConfigured(
                    $source,
                    'php artisan make:queue-failed-table && php artisan migrate',
                    "Failures are configured to be recorded in `{$table}`, and this database has no such table — so a job that fails leaves no trace at all.",
                );
            }

            $counts = [];
            $rows = $connection->table($table)
                ->selectRaw('`queue`, COUNT(*) AS `total`, SUM(CASE WHEN failed_at >= ? THEN 1 ELSE 0 END) AS `last24`', [$this->failureWindow()])
                ->groupBy('queue')
                ->get();

            foreach ($rows as $row) {
                $counts[(string) $row->queue] = ['total' => (int) $row->total, 'last24' => (int) $row->last24];
            }

            return $counts;
        } catch (Throwable $exception) {
            // Returned rather than thrown: a failure table this login cannot read must cost the
            // failure columns, not the pending and lag numbers that were read successfully.
            return Metric::failed($source, $exception);
        }
    }

    /**
     * @param  array<string, array{total: int, last24: int}>|Metric  $failures
     * @return array<string, Metric>
     */
    private function failuresFor(array|Metric $failures, string $queue): array
    {
        if ($failures instanceof Metric) {
            return ['failed_24h' => $failures, 'failed_total' => $failures];
        }

        $table = (string) config('queue.failed.table', 'failed_jobs');
        $source = "database table {$table}";
        $counts = $failures[$queue] ?? ['total' => 0, 'last24' => 0];

        return [
            'failed_24h' => Metric::of($counts['last24'], $source, 'jobs'),
            'failed_total' => Metric::of($counts['total'], $source, 'jobs', 'Every failure ever recorded, until php artisan queue:prune-failed removes it.'),
        ];
    }

    /**
     * The last handful of failures, with the first line of each exception and nothing else.
     *
     * The first line is the class and message — what actually failed. The stack trace below it is
     * pages of framework frames that no dashboard row can show, and it is also where file paths and
     * argument values live. What is kept goes through the Redactor, because an exception message is
     * one of the most reliable places in an application to find a token or a card number.
     */
    private function recentFailures(): Metric
    {
        $unusable = $this->failedStoreUnavailable();
        if ($unusable !== null) {
            return $unusable;
        }

        $table = (string) config('queue.failed.table', 'failed_jobs');
        $source = "database table {$table}";

        return Metric::probe($source, function () use ($table, $source) {
            $connection = DB::connection(config('queue.failed.database'));
            if (!$this->hasTable($connection, $table)) {
                return Metric::notConfigured(
                    $source,
                    'php artisan make:queue-failed-table && php artisan migrate',
                    "Failures are configured to be recorded in `{$table}`, and this database has no such table.",
                );
            }

            $redactor = Redactor::make();
            $failures = [];

            foreach ($connection->table($table)->orderByDesc('id')->limit(self::RECENT_FAILURES)->get() as $row) {
                $failures[] = [
                    // The identifier Laravel's own failed-job store addresses a row by. Without it
                    // this table can only be read: an operator can see that an order confirmation
                    // failed and has no way to send it again except from a shell.
                    'uuid' => (string) ($row->uuid ?? ''),
                    'job' => $this->jobName((string) ($row->payload ?? '')),
                    'queue' => (string) ($row->queue ?? ''),
                    'failed_at' => (string) ($row->failed_at ?? ''),
                    'exception' => $redactor->text($this->firstLine((string) ($row->exception ?? ''))),
                ];
            }

            return $failures === [] ? Metric::noData($source, 'No job has ever failed on this installation.') : $failures;
        });
    }

    /**
     * Whether the failed-job store can be read at all, or null when it can.
     */
    private function failedStoreUnavailable(): ?Metric
    {
        $driver = (string) config('queue.failed.driver', '');
        $source = 'config/queue.php [queue.failed]';

        if ($driver === '' || $driver === 'null') {
            return Metric::notConfigured(
                $source,
                'Set QUEUE_FAILED_DRIVER=database in .env and run php artisan make:queue-failed-table && php artisan migrate.',
                'Failed jobs are discarded rather than recorded, so a job that dies leaves nothing behind to count or retry.',
            );
        }

        if (!str_starts_with($driver, 'database')) {
            return Metric::notSupported(
                $source,
                "Failed jobs are stored by the {$driver} driver, which this collector cannot read.",
                'Set QUEUE_FAILED_DRIVER=database to keep failures in this application\'s own database.',
            );
        }

        return null;
    }

    /**
     * The left edge of the 24-hour window, in the timezone failed_at was written in.
     *
     * Laravel writes failed_at with the application timezone (Asia/Dhaka here), not UTC, so the
     * window has to be built the same way. Monitoring's own Clock is deliberately not used: it
     * returns UTC, and against this column that would silently move the window by six hours and
     * quietly drop or invent a day's failures.
     */
    private function failureWindow(): string
    {
        return Carbon::now(config('app.timezone', 'UTC'))->subDay()->toDateTimeString();
    }

    private function jobName(string $payload): string
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return 'unknown job';
        }

        $name = $decoded['displayName'] ?? $decoded['data']['commandName'] ?? $decoded['job'] ?? null;

        return is_string($name) && $name !== '' ? $name : 'unknown job';
    }

    private function firstLine(string $exception): string
    {
        $line = trim(explode("\n", str_replace("\r\n", "\n", $exception), 2)[0]);

        return $line === '' ? 'no exception recorded' : $line;
    }

    // ---- workers ----------------------------------------------------------------------------------

    /**
     * How many worker processes this host is running.
     *
     * Reported as permission_denied rather than 0 when there is no shell, because those two answers
     * send an operator in opposite directions and only one of them can be walked back.
     */
    private function workerProcesses(): Metric
    {
        $source = 'ps -eo args';
        $noShell = fn () => Metric::permissionDenied(
            $source,
            'This PHP may not run ps, so the number of worker processes cannot be determined. This is not a count of zero — it is unknown.',
            'Remove exec and shell_exec from disable_functions in php.ini and reload PHP-FPM, or read the count from the supervisor instead (supervisorctl status).',
        );

        if (!$this->environment->has('shell')) {
            return $noShell();
        }

        return Metric::probe($source, function () use ($source, $noShell) {
            // timeout first: ps is normally instant, but a monitoring page must never be the thing
            // that hangs on a wedged host. The bare retry covers images without coreutils' timeout.
            $output = @shell_exec('timeout 5 ps -eo args= 2>/dev/null');
            if (!is_string($output) || trim($output) === '') {
                $output = @shell_exec('ps -eo args= 2>/dev/null');
            }
            if (!is_string($output) || trim($output) === '') {
                return $noShell();
            }

            $workers = 0;
            foreach (explode("\n", $output) as $line) {
                // horizon:work is counted too: on a Horizon deployment the consuming processes are
                // named that, and missing them would report a healthy queue as unattended.
                if (preg_match('/artisan[\'"]?\s+(queue:(work|listen)|horizon:work)\b/', $line) === 1) {
                    $workers++;
                }
            }

            return Metric::of(
                $workers,
                $source,
                'processes',
                'Counted on this host only: workers running on a separate machine are invisible here, and every Laravel application on this host is counted alike.',
            );
        });
    }

    /**
     * Whether anything is actually draining the queue.
     *
     * Ordered by how conclusive each piece of evidence is, because the two wrong answers here cost
     * opposite mistakes. "No workers" when a worker sits on another host sends someone restarting
     * something that was fine; "workers running" over a queue that has been stalled for an hour is
     * how a stalled queue survives being monitored. Anything short of proof says so.
     *
     * @param  array<string, mixed>  $facts
     */
    private function consumption(array $facts): Metric
    {
        $source = (string) $facts['source'];
        $processes = $facts['processes'];
        $running = $processes instanceof Metric && $processes->isOk() ? (int) $processes->value : null;
        $pending = $this->depth($facts['pending']);
        $reserved = $this->depth($facts['reserved']);
        $stuck = $this->depth($facts['stuck']);

        if ($facts['paused'] === true) {
            return Metric::notConfigured(
                'Laravel queue pause flag (cache)',
                (string) $facts['resume'],
                'Job processing is paused. Workers may well be running; they are deliberately not taking jobs, so this backlog is expected rather than a failure.',
            );
        }

        if ($stuck !== null && $stuck > 0) {
            return Metric::collectorOffline(
                $source,
                $stuck . ' job(s) are ' . $facts['stuck_note'] . ', which means the worker that took them is gone. They will be retried once, then start again from the beginning — a job that is not idempotent will run twice.',
                (string) $facts['restart'],
            );
        }

        if ($facts['fresh_reservation'] === true) {
            return Metric::of(true, $source, null, 'A worker holds ' . ($reserved ?? 'some') . ' job(s) reserved right now, so something is consuming this queue.');
        }

        if ($facts['stalled_reason'] !== null) {
            return Metric::collectorOffline($source, (string) $facts['stalled_reason'], (string) $facts['start']);
        }

        if ($running !== null && $running > 0) {
            return Metric::of(true, 'ps -eo args', null, "{$running} worker process(es) are running on this host.");
        }

        $waited = $facts['waited'] === null ? null : (int) $facts['waited'];
        $warning = (int) config('monitoring.thresholds.queue_lag_warning_seconds', 300);

        // ps alone is not enough to declare a queue unattended: on a deployment where workers run
        // on their own machine, this host correctly sees none. It takes a backlog that is also
        // ageing before the absence of a local worker means anything.
        if ($pending !== null && $pending > 0 && $running === 0 && $waited !== null && $waited > $warning) {
            return Metric::collectorOffline(
                'ps -eo args',
                $pending . " job(s) are waiting, the oldest for {$waited}s, and no worker process is running on this host. If the workers run on another machine, check there; if they do not, nothing is draining this queue.",
                (string) $facts['start'],
            );
        }

        // An unreadable depth is not an empty one. Every remaining verdict is an inference from how
        // much is sitting in the queue, so without that figure there is nothing left to infer from.
        if ($pending === null || $reserved === null) {
            return Metric::noData(
                $source,
                'The depth of this queue could not be read, so whether anything is draining it cannot be judged from here either.',
            );
        }

        if ($pending === 0 && $reserved === 0) {
            return Metric::noData(
                $source,
                'The queue is empty, so an idle worker and a stopped one look identical from here. Worker state becomes readable again as soon as a job is queued.',
            );
        }

        return Metric::noData(
            $source,
            $waited === null
                ? 'Jobs are waiting, and on this driver their age cannot be read, so a busy worker and a stopped one cannot be told apart from the queue alone.'
                : "Jobs are waiting but the oldest has only waited {$waited}s, short of the {$warning}s threshold, so a busy worker and a stopped one cannot yet be told apart.",
        );
    }

    /**
     * A depth as a number, or null when it was never actually read.
     */
    private function depth(Metric|int|null $fact): ?int
    {
        if ($fact instanceof Metric) {
            return $fact->isOk() && is_numeric($fact->value) ? (int) $fact->value : null;
        }

        return $fact;
    }

    /**
     * Whether the oldest waiting job has aged past the point that only a stopped worker explains.
     */
    private function lagVerdict(Metric $oldest): ?string
    {
        if (!$oldest->isOk()) {
            return null;
        }

        $waited = (int) $oldest->value;
        $critical = (int) config('monitoring.thresholds.queue_lag_critical_seconds', 900);

        if ($waited <= $critical) {
            return null;
        }

        return "The oldest job has been waiting {$waited}s, past the critical lag threshold of {$critical}s, so jobs are arriving and nothing is taking them.";
    }

    // ---- batches ------------------------------------------------------------------------------------

    private function pendingBatches(): Metric
    {
        $table = (string) config('queue.batching.table', 'job_batches');
        $source = "database table {$table}";

        return Metric::probe($source, function () use ($table, $source) {
            $connection = DB::connection(config('queue.batching.database'));
            if (!$this->hasTable($connection, $table)) {
                return Metric::notConfigured(
                    $source,
                    'php artisan make:queue-batches-table && php artisan migrate',
                    "This installation has no `{$table}` table, so Bus::batch() cannot be used and there are no batches to count.",
                );
            }

            return Metric::of(
                $connection->table($table)->whereNull('finished_at')->whereNull('cancelled_at')->count(),
                $source,
                'batches',
            );
        });
    }

    // ---- plumbing -----------------------------------------------------------------------------------

    /**
     * The throw is deliberately not swallowed. A login that may not read information_schema fails
     * here exactly as a missing table would, and answering "false" would put `php artisan migrate`
     * in front of an operator whose table is present and whose grant is not. Every caller already
     * sits inside a probe or a catch that turns it into a failed reading carrying the driver's own
     * message, which names the real problem.
     */
    private function hasTable(Connection $connection, string $table): bool
    {
        return $connection->getSchemaBuilder()->hasTable($table);
    }

    /**
     * A queue name safe to use as a gauge dimension, or null when it is not one.
     *
     * Series names live forever, so anything that is not plainly a configured queue name — a length
     * that suggests an id was used as a queue, a character that would break the `name@label` form —
     * is left out of the time series rather than being allowed to create a series per value.
     */
    private function gaugeLabel(string $queue): ?string
    {
        $label = trim($queue);

        return $label !== '' && strlen($label) <= 64 && preg_match('/^[A-Za-z0-9._:-]+$/', $label) === 1
            ? $label
            : null;
    }

    /**
     * Jobs completed per minute, from what the workers themselves reported.
     *
     * Neither the jobs table nor a Redis list can answer this — a finished job is deleted, so the
     * queue itself only knows what is still waiting. But the worker knows: MonitoringServiceProvider
     * listens to JobProcessed and records the count and duration into monitoring_series, which makes
     * throughput and runtime real on both drivers without Horizon.
     */
    private function recordedThroughput(): Metric
    {
        return Metric::probe('monitoring_series (queue.processed)', function () {
            $row = $this->monitoring()->table('monitoring_series')
                ->where('metric', 'queue.processed')
                ->where('resolution', 'minute')
                ->where('bucket_at', '>=', Clock::minutesAgo(self::THROUGHPUT_WINDOW_MINUTES))
                ->selectRaw('SUM(samples) AS jobs')
                ->first();

            $jobs = (int) ($row->jobs ?? 0);
            if ($jobs === 0) {
                return Metric::noData(
                    'monitoring_series (queue.processed)',
                    'Nothing has been recorded as processed in the last fifteen minutes. Either no job ran, or nothing is recording them — the workers write this series themselves, so a worker on an older release, or monitoring switched off, looks the same from here.',
                );
            }

            // Divided by the whole window, not by the minutes that happen to contain jobs. A minute
            // with no work writes no row, so the second denominator would make the rate independent
            // of how long the queue stood idle: 300 jobs in one minute of fifteen would read as
            // 300/min and could never fall. It is also the divisor the queue panel uses, and two
            // throughput figures on one page have to agree.
            return round($jobs / self::THROUGHPUT_WINDOW_MINUTES, 2);
        }, 'jobs/min');
    }

    private function recordedRuntime(): Metric
    {
        return Metric::probe('monitoring_series (queue.processed)', function () {
            $row = $this->monitoring()->table('monitoring_series')
                ->where('metric', 'queue.processed')
                ->where('resolution', 'minute')
                ->where('bucket_at', '>=', Clock::minutesAgo(60))
                ->selectRaw('SUM(samples) AS jobs, SUM(value_sum) AS total_ms')
                ->first();

            $jobs = (int) ($row->jobs ?? 0);
            if ($jobs === 0) {
                return Metric::noData(
                    'monitoring_series (queue.processed)',
                    'No job has been processed in the last hour, so there is no runtime to average.',
                );
            }

            return round((float) $row->total_ms / $jobs, 1);
        }, 'ms');
    }

    private function monitoring(): \Illuminate\Database\Connection
    {
        return \Illuminate\Support\Facades\DB::connection(config('monitoring.connection', 'monitoring'));
    }
}
