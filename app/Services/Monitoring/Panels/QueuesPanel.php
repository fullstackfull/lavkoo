<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Collectors\CollectorRegistry;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The queue: what is waiting, how long it has waited, and whether anything is taking it.
 *
 * Two halves that answer different questions and cannot answer each other's. The backend — the
 * jobs table or the Redis keyspace — knows only what is still there: a finished job is deleted, so
 * depth and lag come from it and throughput never can. The workers know the rest: the job
 * listeners in MonitoringServiceProvider record every completion into monitoring_series keyed by
 * queue name, which is where processed-per-minute and average runtime come from. This panel reads
 * both and keeps them apart, because a gap in one is not evidence about the other.
 *
 * Age leads, not depth. A queue with three jobs stuck for an hour is an outage; one with three
 * thousand flowing through is a busy evening, and a table sorted by depth puts the healthy queue
 * on top. So rows are ordered by how long the oldest job has waited and that column is scored
 * against the lag thresholds, while depth sits beside it as context.
 *
 * The worker verdict is deliberately three-valued. "No worker is running" and "I cannot tell
 * whether one is" send an operator in opposite directions — one restarts supervisors, the other
 * checks another host — and only one of those is recoverable from a wrong guess. The collector
 * already refuses to turn an unreadable process list into a zero; this panel refuses to flatten
 * its states back into a boolean.
 */
class QueuesPanel implements Panel
{
    /**
     * Readings drawn as single values above the table.
     *
     * Only ones that are honestly one number. `queues` and `recent_failures` are lists and have
     * their own sections; handing either to the metric partial would print a PHP notice where a
     * value belongs.
     *
     * @var list<string>
     */
    private const HEADLINE = [
        'queue_count', 'pending', 'reserved', 'delayed', 'stuck_reserved', 'oldest_wait_seconds',
        'failed_24h', 'failed_total', 'pending_batches', 'throughput_per_minute', 'average_runtime_ms',
    ];

    /**
     * Per-queue columns the backend answers, each paired with the top-level metric that explains a
     * gap in it. Redis, for one, cannot say how long the head of a list has waited, and the column
     * has to be able to say why rather than showing a dash that reads as zero.
     *
     * @var list<string>
     */
    private const BACKEND_COLUMNS = [
        'pending', 'reserved', 'delayed', 'stuck_reserved', 'oldest_wait_seconds',
        'failed_24h', 'failed_total',
    ];

    /** Per-queue columns computed from the stored series rather than read from the backend. */
    private const SERIES_COLUMNS = ['processed', 'per_minute', 'avg_runtime_ms', 'failed_in_window'];

    /** The two series the job listeners write, both labelled with the queue name. */
    private const PROCESSED_METRIC = 'queue.processed';

    private const FAILED_METRIC = 'queue.failed';

    /**
     * Upper bound on the grouped series read.
     *
     * The grouping is metric × queue name, which the collector already caps at fifty queues — this
     * is the guard for the case where the stored labels outran that cap, so the query returns a
     * bounded set and the panel says it was cut instead of quietly reporting a partial total.
     */
    private const MAX_SERIES_GROUPS = 200;

    /**
     * Hard ceiling on the timeline read.
     *
     * The real bound is the number of buckets the chosen window can hold, which never exceeds 360;
     * this is the backstop that keeps a mis-sized window from turning a chart into a table scan.
     */
    private const MAX_TIMELINE_BUCKETS = 1500;

    public function __construct(
        private readonly CollectorRegistry $collectors,
        private readonly SeriesReader $reader,
    ) {
    }

    public function data(string $range, Request $request): array
    {
        $window = $this->reader->window($range);
        $readings = $this->collectors->collect('queue');
        $throughput = $this->throughput($range, $window);
        $backend = $this->backend($readings);

        return [
            'window' => [
                'range' => $range,
                'minutes' => $window['minutes'],
                'resolution' => $window['resolution'],
                'since' => Clock::display($this->reader->since($range))->toDateTimeString(),
                'until' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
            ],
            'backend' => $backend,
            'headline' => $this->headline($readings),
            'queues' => $this->queues($readings, $throughput, $backend),
            'throughput' => $throughput,
            'timeline' => $this->timeline($range, $window),
            'workers' => $this->workers($readings),
            'failures' => $this->failures($readings),
            'thresholds' => [
                'lag_warning_seconds' => (int) config('monitoring.thresholds.queue_lag_warning_seconds', 300),
                'lag_critical_seconds' => (int) config('monitoring.thresholds.queue_lag_critical_seconds', 900),
            ],
            'unrendered' => $this->unrendered($readings),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Which queue this page is about

    /**
     * The connection and driver, stated once at the top.
     *
     * On sync or null every reading below is unavailable for the same single reason, and forty
     * copies of one fact read as forty faults. Naming the driver first turns them into one.
     *
     * @param  array<string, Metric>  $readings
     * @return array<string, mixed>
     */
    private function backend(array $readings): array
    {
        if ($readings === []) {
            return [
                'state' => 'not_supported',
                'connection' => null,
                'driver' => null,
                'note' => 'The queue collector is not installed in this build, so nothing on this page can be read.',
                'remedy' => null,
            ];
        }

        $failure = $readings['__collector'] ?? null;
        if ($failure instanceof Metric) {
            return ['state' => 'failed', 'connection' => null, 'driver' => null, 'note' => $failure->note, 'remedy' => null];
        }

        $connection = $readings['connection'] ?? null;
        $driver = $readings['driver'] ?? null;

        // The driver is readable from configuration on every deployment; what decides whether this
        // page has anything to show is whether that driver keeps a queue at all, which the pending
        // reading states in its own words rather than this panel guessing from the driver name.
        $pending = $readings['pending'] ?? null;

        return [
            'state' => $pending instanceof Metric && !$pending->isOk() ? $pending->state : 'ok',
            'connection' => $connection instanceof Metric ? $connection->valueOr(null) : null,
            'driver' => $driver instanceof Metric ? $driver->valueOr(null) : null,
            'note' => $pending instanceof Metric && !$pending->isOk() ? $pending->note : null,
            'remedy' => $pending instanceof Metric && !$pending->isOk() ? $pending->remedy : null,
        ];
    }

    /**
     * @param  array<string, Metric>  $readings
     * @return array<string, Metric>
     */
    private function headline(array $readings): array
    {
        $headline = [];

        foreach (self::HEADLINE as $name) {
            $metric = $readings[$name] ?? null;
            if (!$metric instanceof Metric) {
                continue;
            }
            // An unavailable reading goes in whole — its state and remedy are the content. Only a
            // successful reading that is not a single value has nowhere honest to be drawn.
            if ($metric->isOk() && !is_scalar($metric->value)) {
                continue;
            }

            $headline[$name] = $metric;
        }

        return $headline;
    }

    // -------------------------------------------------------------------------------------------
    // The per-queue table

    /**
     * One row per queue, backend depth and worker throughput side by side.
     *
     * @param  array<string, Metric>  $readings
     * @param  array<string, mixed>  $throughput
     * @return array<string, mixed>
     */
    private function queues(array $readings, array $throughput, array $backend): array
    {
        $table = $readings['queues'] ?? null;
        $rows = [];
        $state = 'ok';
        $note = null;
        $remedy = null;
        $source = null;

        if (!$table instanceof Metric) {
            $state = 'no_data';
            $note = 'The queue collector returned no queue list, so no queue can be named here.';
        } elseif (!$table->isOk() || !is_array($table->value)) {
            $state = $table->isOk() ? 'no_data' : $table->state;
            $note = $table->note;
            $remedy = $table->remedy;
            $source = $table->source;
        } else {
            $source = $table->source;
            foreach ($table->value as $entry) {
                $entry = (array) $entry;
                $name = (string) ($entry['queue'] ?? '');
                if ($name === '') {
                    continue;
                }
                $rows[$name] = $this->row($name, $entry, $throughput, inBackend: true);
            }
        }

        // A queue the workers reported jobs for that the backend does not list is still a real
        // queue — it drained between the two reads, or its jobs live on a connection this collector
        // cannot see. Leaving it out would hide work that demonstrably happened.
        foreach (array_keys($throughput['by_queue'] ?? []) as $name) {
            if (!array_key_exists($name, $rows)) {
                $rows[(string) $name] = $this->row((string) $name, [], $throughput, inBackend: false);
            }
        }

        $rows = array_values($rows);

        // Ordered by how long the oldest job has waited, then by depth. A table sorted by depth
        // puts the busy healthy queue above the small stalled one, which is exactly backwards.
        usort($rows, static fn (array $a, array $b) => [$b['oldest_wait_seconds'] ?? -1, $b['pending'] ?? -1]
            <=> [$a['oldest_wait_seconds'] ?? -1, $a['pending'] ?? -1]);

        return [
            'state' => $state,
            'note' => $note,
            'remedy' => $remedy,
            'source' => $source,
            'rows' => $rows,
            'columns' => $this->columns($readings, $throughput, $rows, $backend),
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $throughput
     * @return array<string, mixed>
     */
    private function row(string $name, array $entry, array $throughput, bool $inBackend): array
    {
        // A readable series with no row for this queue is a measured zero — no job completed on it
        // in the window — while an unreadable one is an unknown. Only the second may render blank,
        // and the difference is the whole reason this panel carries the series state around.
        $recorded = $throughput['by_queue'][$name]
            ?? ($throughput['state'] === 'ok'
                ? ['processed' => 0, 'per_minute' => 0.0, 'avg_runtime_ms' => null, 'failed_in_window' => 0]
                : null);
        $waited = $entry['oldest_wait_seconds'] ?? null;

        return [
            'queue' => $name,
            'in_backend' => $inBackend,
            'pending' => $this->integerOrNull($entry['pending'] ?? null),
            'reserved' => $this->integerOrNull($entry['reserved'] ?? null),
            'delayed' => $this->integerOrNull($entry['delayed'] ?? null),
            'stuck_reserved' => $this->integerOrNull($entry['stuck_reserved'] ?? null),
            'oldest_wait_seconds' => $this->integerOrNull($waited),
            'lag_state' => $this->lagState($this->integerOrNull($waited)),
            'failed_24h' => $this->integerOrNull($entry['failed_24h'] ?? null),
            'failed_total' => $this->integerOrNull($entry['failed_total'] ?? null),
            'paused' => array_key_exists('paused', $entry) && is_bool($entry['paused']) ? $entry['paused'] : null,
            'processed' => $recorded === null ? null : $recorded['processed'],
            'per_minute' => $recorded === null ? null : $recorded['per_minute'],
            'avg_runtime_ms' => $recorded === null ? null : $recorded['avg_runtime_ms'],
            'failed_in_window' => $recorded === null ? null : $recorded['failed_in_window'],
        ];
    }

    /**
     * How bad the wait is, or null when the wait itself is unknown.
     *
     * Null rather than a colour: on Redis the age of the head of a list cannot be recovered at all,
     * and a green cell there would be a verdict nobody measured.
     */
    private function lagState(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        $warning = (int) config('monitoring.thresholds.queue_lag_warning_seconds', 300);
        $critical = (int) config('monitoring.thresholds.queue_lag_critical_seconds', 900);

        return match (true) {
            $seconds >= $critical => 'critical',
            $seconds >= $warning => 'degraded',
            default => 'healthy',
        };
    }

    /**
     * Why a whole column is empty, for the columns that are.
     *
     * Only reported when every row is missing that value: one queue without a number is a fact
     * about that queue, and a column that is blank everywhere is a fact about the driver — with a
     * remedy attached, which is the difference between a dead column and a task somebody can do.
     *
     * @param  array<string, Metric>  $readings
     * @param  array<string, mixed>  $throughput
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function columns(array $readings, array $throughput, array $rows, array $backend): array
    {
        $columns = [];

        foreach (self::BACKEND_COLUMNS as $column) {
            if ($this->columnHasAValue($rows, $column)) {
                continue;
            }

            $metric = $readings[$column] ?? null;

            // On sync, on a null driver, on an unreachable backend, every one of these columns is
            // missing for the same single reason — and that reason is already stated once at the
            // top of the section. Repeating it seven times turns one fault into seven.
            if ($backend['state'] !== 'ok' && $metric instanceof Metric && $metric->note === $backend['note']) {
                continue;
            }

            $columns[$column] = $this->backendColumnGap($metric);
        }

        if ($throughput['state'] !== 'ok') {
            foreach (self::SERIES_COLUMNS as $column) {
                $columns[$column] = [
                    'state' => $throughput['state'],
                    'note' => $throughput['note'],
                    'remedy' => $throughput['remedy'],
                ];
            }
        }

        return $columns;
    }

    /**
     * The reason one backend column is blank on every row.
     *
     * A readable metric with no per-queue rows behind it is the case worth separating. Calling that
     * "no data" contradicts the very figure this page prints in the totals directly above — the
     * page would say "1 failed job" and "failed jobs: no data" in the same screen — and it left the
     * reason blank, because a successful Metric carries no note to borrow.
     *
     * @return array<string, mixed>
     */
    private function backendColumnGap(?Metric $metric): array
    {
        if (!$metric instanceof Metric) {
            return ['state' => 'no_data', 'note' => 'This driver does not report that figure per queue.', 'remedy' => null];
        }

        if (!$metric->isOk()) {
            return ['state' => $metric->state, 'note' => $metric->note, 'remedy' => $metric->remedy];
        }

        return [
            'state' => 'not_supported',
            'note' => 'This figure was read for the connection as a whole but not per queue, so the total above is the real number and the column beside it has nothing to fill.',
            'remedy' => null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function columnHasAValue(array $rows, string $column): bool
    {
        foreach ($rows as $row) {
            if (($row[$column] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------------------------
    // What the workers reported

    /**
     * Throughput and runtime per queue, from the series the workers themselves wrote.
     *
     * Neither the jobs table nor a Redis list can answer this: a finished job is deleted, so the
     * backend only ever knows what is still waiting. JobProcessed and JobFailed are recorded into
     * monitoring_series as samples (jobs) and value_sum (total milliseconds), keyed by queue name
     * in the label column, which is what makes a rate and an average possible on both drivers.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>
     */
    private function throughput(string $range, array $window): array
    {
        $source = 'monitoring_series (' . self::PROCESSED_METRIC . ', ' . self::FAILED_METRIC . ')';

        try {
            $rows = $this->reader->connection()->table('monitoring_series')
                ->whereIn('metric', [self::PROCESSED_METRIC, self::FAILED_METRIC])
                ->where('resolution', $window['resolution'])
                ->where('bucket_at', '>=', $this->reader->since($range))
                ->groupBy('metric', 'label')
                ->limit(self::MAX_SERIES_GROUPS + 1)
                ->get([
                    'metric',
                    'label',
                    $this->reader->connection()->raw('SUM(samples) AS jobs'),
                    $this->reader->connection()->raw('SUM(value_sum) AS total_ms'),
                    $this->reader->connection()->raw('COUNT(*) AS buckets'),
                ]);
        } catch (\Throwable $exception) {
            // Caught here rather than left to PanelRegistry: failing this read blanks four columns,
            // while letting it escape would blank the depth and lag figures that were read fine.
            return [
                'state' => 'failed',
                'note' => Metric::describeFailure($exception),
                'remedy' => null,
                'source' => $source,
                'window_minutes' => $window['minutes'],
                'resolution' => $window['resolution'],
                'by_queue' => [],
                'totals' => ['processed' => null, 'failed' => null, 'per_minute' => null, 'avg_runtime_ms' => null],
                'truncated' => false,
            ];
        }

        $truncated = $rows->count() > self::MAX_SERIES_GROUPS;
        $minutes = max(1, $window['minutes']);
        $byQueue = [];
        $processedTotal = 0;
        $failedTotal = 0;
        $runtimeTotal = 0.0;

        foreach ($rows->take(self::MAX_SERIES_GROUPS) as $row) {
            $queue = (string) $row->label;
            $jobs = (int) $row->jobs;
            $byQueue[$queue] ??= ['processed' => 0, 'total_ms' => 0.0, 'failed_in_window' => 0, 'buckets' => 0];

            if ($row->metric === self::FAILED_METRIC) {
                $byQueue[$queue]['failed_in_window'] += $jobs;
                $failedTotal += $jobs;

                continue;
            }

            $byQueue[$queue]['processed'] += $jobs;
            $byQueue[$queue]['total_ms'] += (float) $row->total_ms;
            $byQueue[$queue]['buckets'] += (int) $row->buckets;
            $processedTotal += $jobs;
            $runtimeTotal += (float) $row->total_ms;
        }

        foreach ($byQueue as $queue => $counts) {
            $byQueue[$queue] = [
                'processed' => $counts['processed'],
                'per_minute' => round($counts['processed'] / $minutes, 3),
                // 0 divided by 0 is not 0 ms: with nothing completed there is no runtime to
                // average, and a zero here would read as a queue whose jobs finish instantly.
                'avg_runtime_ms' => $counts['processed'] > 0 ? round($counts['total_ms'] / $counts['processed'], 1) : null,
                'failed_in_window' => $counts['failed_in_window'],
                'buckets' => $counts['buckets'],
            ];
        }

        return array_merge([
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'source' => $source,
            'window_minutes' => $window['minutes'],
            'resolution' => $window['resolution'],
            'by_queue' => $byQueue,
            'totals' => [
                'processed' => $processedTotal,
                'failed' => $failedTotal,
                'per_minute' => round($processedTotal / $minutes, 3),
                'avg_runtime_ms' => $processedTotal > 0 ? round($runtimeTotal / $processedTotal, 1) : null,
            ],
            'truncated' => $truncated,
        ], $byQueue === [] ? $this->noRecordedJobs($window) : []);
    }

    /**
     * Why the workers have reported nothing, which is four different situations.
     *
     * An empty window is a legitimate reading — no job ran — but so is a switched-off collector, a
     * rollup that has not caught up, and a worker running an older release that has no listener.
     * They draw the same blank columns and lead to different actions.
     *
     * Two of the four also take the totals down with them. Nothing was recorded because nothing was
     * listening, so a total of zero would be a number this panel made up — and the same payload is
     * served as JSON, where a caller reading `totals.processed` has no state beside it to warn them.
     * The fourth case keeps its zero, because there nothing running IS the measurement.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>
     */
    private function noRecordedJobs(array $window): array
    {
        $unmeasured = ['processed' => null, 'failed' => null, 'per_minute' => null, 'avg_runtime_ms' => null];

        if (!config('monitoring.enabled', true)) {
            return [
                'state' => 'not_configured',
                'note' => 'Monitoring collection is switched off, so no completed job has been recorded since it was disabled — this is not a reading of zero jobs.',
                'remedy' => 'Set MONITORING_ENABLED=true in .env, then run `php artisan optimize:clear`.',
                'totals' => $unmeasured,
            ];
        }

        if ($window['resolution'] !== 'minute') {
            // Job outcomes are written into minute buckets. Longer ranges read the rolled-up rows,
            // which the rollup builds — so this window can be empty while the minute rows under it
            // are full, and that is a scheduler problem rather than an idle queue.
            return [
                'state' => 'no_data',
                'note' => 'This range reads ' . $window['resolution'] . ' rows, which the monitoring rollup builds from the minute samples the workers write directly.',
                'remedy' => 'Choose a shorter range to read the minute samples, or check the rollup is running: `php artisan schedule:list`.',
                'totals' => $unmeasured,
            ];
        }

        return [
            'state' => 'no_data',
            'note' => 'No job completed on any queue in this window. Throughput is recorded by the worker process itself, so a worker running an older release — or none at all — also leaves this empty.',
            'remedy' => 'Dispatch a job and keep a worker alive against this connection: `php artisan queue:work --queue=default`.',
        ];
    }

    /**
     * Completions and failures over the window, for the chart.
     *
     * Every queue folded together on purpose: the per-queue split is the table above, and this line
     * answers the one question a chart is good at — whether the workers kept up throughout the
     * window or stopped partway into it.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>
     */
    private function timeline(string $range, array $window): array
    {
        // Both metrics are folded inside one grouped row per bucket rather than read as a row each.
        // Grouping by bucket and metric returns two rows per bucket while any limit is counted in
        // buckets, and because the read is ordered oldest first it is the NEWEST rows that fall off
        // the end: a six-hour chart that stops two hours short while the workers are still running,
        // which reads as an outage that is not there. It also splits a bucket across the cut, so
        // the last point keeps its completions and loses its failures — a zero nobody measured.
        $limit = min(self::MAX_TIMELINE_BUCKETS, $this->bucketsInWindow($window) + 1);

        try {
            $connection = $this->reader->connection();
            $rows = $connection->table('monitoring_series')
                ->selectRaw(
                    'bucket_at,'
                    . ' SUM(CASE WHEN metric = ? THEN samples ELSE 0 END) AS jobs,'
                    . ' SUM(CASE WHEN metric = ? THEN value_sum ELSE 0 END) AS total_ms,'
                    . ' SUM(CASE WHEN metric = ? THEN samples ELSE 0 END) AS failures',
                    [self::PROCESSED_METRIC, self::PROCESSED_METRIC, self::FAILED_METRIC],
                )
                ->whereIn('metric', [self::PROCESSED_METRIC, self::FAILED_METRIC])
                ->where('resolution', $window['resolution'])
                ->where('bucket_at', '>=', $this->reader->since($range))
                ->groupBy('bucket_at')
                ->orderBy('bucket_at')
                ->limit($limit)
                ->get();
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => Metric::describeFailure($exception),
                'points' => [],
                'truncated' => false,
            ];
        }

        $points = [];
        foreach ($rows as $row) {
            $jobs = (int) $row->jobs;
            $points[] = [
                't' => Clock::parse($row->bucket_at)->toIso8601String(),
                'hits' => $jobs,
                'errors' => (int) $row->failures,
                // Nothing completed in this bucket means there is no runtime to average, which is a
                // different fact from an average of zero milliseconds.
                'avg_ms' => $jobs > 0 ? round((float) $row->total_ms / $jobs, 1) : null,
            ];
        }

        // One bucket is a reading; a line needs two. Saying which of the two this is stops a single
        // sample being read as a flat trend across the whole window.
        return [
            'state' => count($points) >= 2 ? 'ok' : 'no_data',
            'note' => count($points) >= 2
                ? null
                : (count($points) === 1
                    ? 'Only one bucket in this window recorded a completed job, and one point is not a line.'
                    : 'No completed job was recorded in this window.'),
            'points' => $points,
            // Only reachable on a window larger than any this controller offers, and said out loud
            // rather than drawn as a line that quietly ends early.
            'truncated' => count($points) >= $limit,
        ];
    }

    /**
     * How many buckets the chosen window can hold — the figure the timeline read is bounded by.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     */
    private function bucketsInWindow(array $window): int
    {
        $minutesPerBucket = match ($window['resolution']) {
            'day' => 1440,
            'hour' => 60,
            default => 1,
        };

        return (int) max(1, ceil($window['minutes'] / $minutesPerBucket));
    }

    // -------------------------------------------------------------------------------------------
    // Workers

    /**
     * Whether anything is draining the queue, and how confident that answer is.
     *
     * Three verdicts, never two. The collector already refuses to turn an unreadable process list
     * into a count of zero; flattening its states back into "running / not running" here would undo
     * exactly that, and "no worker" versus "cannot tell" is the difference between restarting a
     * supervisor and going to look at another host.
     *
     * @param  array<string, Metric>  $readings
     * @return array<string, mixed>
     */
    private function workers(array $readings): array
    {
        $consuming = $readings['workers_consuming'] ?? null;
        $processes = $readings['worker_processes'] ?? null;

        $verdict = match (true) {
            $consuming instanceof Metric && $consuming->isOk() && $consuming->value === true => 'consuming',
            $consuming instanceof Metric && $consuming->state === Metric::COLLECTOR_OFFLINE => 'stalled',
            $consuming instanceof Metric && $consuming->state === Metric::NOT_CONFIGURED => 'not_processing',
            default => 'unknown',
        };

        return [
            'verdict' => $verdict,
            'note' => $consuming instanceof Metric ? $consuming->note : 'The queue collector returned no verdict on worker activity.',
            'remedy' => $consuming instanceof Metric ? $consuming->remedy : null,
            'source' => $consuming instanceof Metric ? $consuming->source : null,
            'consuming' => $consuming instanceof Metric ? $consuming : null,
            'processes' => $processes instanceof Metric ? $processes : null,
            // The distinction the whole section turns on: a counted zero is evidence, an uncounted
            // one is not, and the view has to be able to tell them apart without re-deriving it.
            'process_count' => $processes instanceof Metric && $processes->isOk() ? (int) $processes->value : null,
            'process_count_known' => $processes instanceof Metric && $processes->isOk(),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Failures

    /**
     * The last handful of failed jobs, first line of the exception only.
     *
     * The collector has already cut the stack trace off and put what remains through the Redactor:
     * an exception message is one of the most reliable places in an application to find a token, a
     * card number or a customer's address.
     *
     * @param  array<string, Metric>  $readings
     * @return array<string, mixed>
     */
    private function failures(array $readings): array
    {
        $metric = $readings['recent_failures'] ?? null;

        if (!$metric instanceof Metric) {
            return [
                'state' => 'no_data',
                'note' => 'The queue collector returned no failure list.',
                'remedy' => null,
                'source' => null,
                'rows' => [],
                'timezone' => Clock::displayTimezone(),
            ];
        }

        if (!$metric->isOk() || !is_array($metric->value)) {
            return [
                'state' => $metric->isOk() ? 'no_data' : $metric->state,
                'note' => $metric->note,
                'remedy' => $metric->remedy,
                'source' => $metric->source,
                'rows' => [],
                'timezone' => Clock::displayTimezone(),
            ];
        }

        $rows = [];
        foreach ($metric->value as $entry) {
            $entry = (array) $entry;
            $rows[] = [
                'job' => (string) ($entry['job'] ?? ''),
                'queue' => (string) ($entry['queue'] ?? ''),
                'failed_at' => $this->failedAtForDisplay((string) ($entry['failed_at'] ?? '')),
                'exception' => (string) ($entry['exception'] ?? ''),
            ];
        }

        return [
            'state' => 'ok',
            'note' => $metric->note,
            'remedy' => null,
            'source' => $metric->source,
            'rows' => $rows,
            'timezone' => Clock::displayTimezone(),
        ];
    }

    /**
     * A failed_at stamp, converted for rendering from the timezone Laravel wrote it in.
     *
     * This is the one timestamp on the page that is not monitoring's own, and it carries no offset,
     * so it has to be told which zone it was stamped in before it can be shown. Laravel writes it
     * with Date::now(), which resolves in PHP's process timezone — and that is not app.timezone
     * here: ConfigServiceProvider sets the process default from the merchant's own timezone
     * setting, leaving the process on Asia/Kuwait while app.timezone stays Asia/Dhaka. Reading it
     * as app.timezone therefore shows every failure three hours early, which is how a job that died
     * a minute ago appears before the start of the window printed on this same card.
     *
     * date_default_timezone_get() is not a clock read — it is the zone Carbon::now() resolves in,
     * which is the only honest answer to "how was this row stamped". Same reasoning, and the same
     * call, as LiveTrafficPanel uses for the shop's own tables.
     */
    private function failedAtForDisplay(string $stored): ?string
    {
        if (trim($stored) === '') {
            return null;
        }

        try {
            return Carbon::parse($stored, date_default_timezone_get())
                ->setTimezone(Clock::displayTimezone())
                ->toDateTimeString();
        } catch (\Throwable) {
            // An unparseable stamp is shown as it was stored rather than dropped: the row is still
            // a real failure, and inventing a time for it would be worse than showing none.
            return $stored;
        }
    }

    // -------------------------------------------------------------------------------------------

    /**
     * Readings this page draws nowhere.
     *
     * Normally empty. It exists so a collector that grows a reading cannot have it silently
     * disappear: an undrawn measurement is indistinguishable from an unmeasured one.
     *
     * @param  array<string, Metric>  $readings
     * @return array<int, array{metric: string, state: string}>
     */
    private function unrendered(array $readings): array
    {
        $claimed = array_merge(self::HEADLINE, [
            'connection', 'driver', 'queues', 'recent_failures',
            'worker_processes', 'workers_consuming', '__collector',
        ]);

        $unrendered = [];
        foreach ($readings as $name => $metric) {
            if (in_array($name, $claimed, true) || !$metric instanceof Metric) {
                continue;
            }

            $unrendered[] = ['metric' => $name, 'state' => $metric->state];
        }

        return $unrendered;
    }

    /**
     * A count, or null when the collector had none to give.
     *
     * Null is preserved rather than cast: (int) null is 0, and a zero in a depth column is the
     * single most misleading value this page could print.
     */
    private function integerOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
