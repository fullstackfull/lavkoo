<?php

namespace App\Console\Commands;

use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\MonitoringSettings;
use App\Services\Monitoring\Support\Histogram;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Minutes into hours, hours into days, and everything past its retention deleted.
 *
 * Minute buckets are what make an incident investigable and they are also what would eventually
 * fill the disk: a busy shop writes a few hundred rows a minute, which is fine for a week and
 * ruinous for a year. So each resolution has its own lifetime — high resolution while an incident
 * is fresh, aggregates for the chart that goes back a quarter.
 *
 * Rolling up a histogram is the part worth getting right. Percentiles cannot be averaged: the mean
 * of twelve p95 values is not the p95 of the hour. The bucket COUNTS are what add up, so an hour's
 * histogram is the element-wise sum of its minutes, and its percentiles are computed from that.
 */
class MonitoringRollup extends Command
{
    protected $signature = 'monitoring:rollup
                            {--prune : Also delete data past its retention window}
                            {--hours=3 : How many recent hours to (re)build}';

    protected $description = 'Fold monitoring minutes into hours and days, and prune past retention';

    public function handle(): int
    {
        if (!config('monitoring.enabled', true)) {
            $this->warn('Monitoring is disabled; nothing to roll up.');

            return self::SUCCESS;
        }

        $hours = max(1, (int) $this->option('hours'));

        $folded = $this->foldRequests('minute', 'hour', $hours)
            + $this->foldRequests('hour', 'day', 24 * 2)
            + $this->foldSeries('minute', 'hour', $hours)
            + $this->foldSeries('hour', 'day', 24 * 2)
            + $this->foldDependencies('minute', 'hour', $hours)
            + $this->foldDependencies('hour', 'day', 24 * 2);

        $this->info("Rolled up {$folded} bucket(s).");

        if ($this->option('prune')) {
            $this->info('Pruned ' . $this->prune() . ' row(s) past retention.');
        }

        return self::SUCCESS;
    }

    /**
     * Re-fold a window of source buckets into their parent resolution.
     *
     * The recent window is rebuilt rather than appended to, because a minute can still be written
     * after its hour was first folded — a late drain, a second web server. Rebuilding is idempotent;
     * appending would double-count.
     */
    private function foldRequests(string $from, string $to, int $lookbackHours): int
    {
        $since = $this->truncate(Clock::hoursAgo($lookbackHours), $to);

        $rows = $this->connection()->table('monitoring_request_buckets')
            ->where('resolution', $from)
            ->where('bucket_at', '>=', $since)
            ->orderBy('bucket_at')
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $grouped = [];
        foreach ($rows as $row) {
            $parent = $this->truncate(Clock::parse($row->bucket_at), $to)->toDateTimeString();
            $key = $parent . '|' . $row->channel . '|' . $row->method . '|' . $row->route;
            $grouped[$key][] = $row;
        }

        $written = 0;
        foreach (array_chunk($grouped, 200, true) as $chunk) {
            $payload = [];
            foreach ($chunk as $key => $group) {
                [$parent, $channel, $method, $route] = explode('|', $key, 4);

                $histogram = new Histogram();
                $totals = array_fill_keys([
                    'hits', 'errors', 'client_errors', 'timeouts', 'duration_sum_ms', 'db_ms_sum',
                    'db_query_count', 'cache_ms_sum', 'external_ms_sum', 'external_calls',
                    'queue_dispatches', 'memory_peak_sum_kb', 'response_bytes_sum', 'request_bytes_sum',
                ], 0);

                foreach ($group as $row) {
                    foreach (array_keys($totals) as $field) {
                        $totals[$field] += (int) ($row->{$field} ?? 0);
                    }
                    $histogram->merge(Histogram::fromState(
                        json_decode((string) $row->duration_buckets, true) ?: [],
                        (int) $row->hits,
                        (float) $row->duration_sum_ms,
                        $row->duration_min_ms !== null ? (float) $row->duration_min_ms : null,
                        $row->duration_max_ms !== null ? (float) $row->duration_max_ms : null,
                    ));
                }

                $payload[] = array_merge([
                    'resolution' => $to,
                    'bucket_at' => $parent,
                    'channel' => $channel,
                    'method' => $method,
                    'route' => $route,
                    'duration_buckets' => json_encode($histogram->counts()),
                    'duration_min_ms' => $histogram->min() !== null ? (int) $histogram->min() : null,
                    'duration_max_ms' => $histogram->max() !== null ? (int) $histogram->max() : null,
                ], $totals);
            }

            // upsert with a plain column list: this is a REBUILD of the parent bucket from its
            // children, so replacing is correct — summing here would double-count on every run.
            $this->connection()->table('monitoring_request_buckets')->upsert(
                $payload,
                ['resolution', 'bucket_at', 'channel', 'route', 'method'],
                array_merge(array_keys($payload[0] ?? []), []),
            );
            $written += count($payload);
        }

        return $written;
    }

    private function foldSeries(string $from, string $to, int $lookbackHours): int
    {
        $since = $this->truncate(Clock::hoursAgo($lookbackHours), $to);

        $rows = $this->connection()->table('monitoring_series')
            ->where('resolution', $from)
            ->where('bucket_at', '>=', $since)
            ->orderBy('bucket_at')
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $grouped = [];
        foreach ($rows as $row) {
            $parent = $this->truncate(Clock::parse($row->bucket_at), $to)->toDateTimeString();
            $grouped[$parent . '|' . $row->metric . '|' . $row->label][] = $row;
        }

        $payload = [];
        foreach ($grouped as $key => $group) {
            [$parent, $metric, $label] = array_pad(explode('|', $key, 3), 3, '');

            $samples = 0;
            $sum = 0.0;
            $min = null;
            $max = null;
            $last = null;
            foreach ($group as $row) {
                $samples += (int) $row->samples;
                $sum += (float) $row->value_sum;
                $min = $row->value_min === null ? $min : ($min === null ? (float) $row->value_min : min($min, (float) $row->value_min));
                $max = $row->value_max === null ? $max : ($max === null ? (float) $row->value_max : max($max, (float) $row->value_max));
                // Ordered by bucket_at, so the last non-null reading in the group is the newest.
                $last = $row->value_last ?? $last;
            }

            $payload[] = [
                'resolution' => $to,
                'bucket_at' => $parent,
                'metric' => $metric,
                'label' => $label,
                'samples' => $samples,
                'value_sum' => $sum,
                'value_min' => $min,
                'value_max' => $max,
                'value_last' => $last,
            ];
        }

        foreach (array_chunk($payload, 400) as $chunk) {
            $this->connection()->table('monitoring_series')->upsert(
                $chunk,
                ['resolution', 'bucket_at', 'metric', 'label'],
                ['samples', 'value_sum', 'value_min', 'value_max', 'value_last'],
            );
        }

        return count($payload);
    }

    private function foldDependencies(string $from, string $to, int $lookbackHours): int
    {
        $since = $this->truncate(Clock::hoursAgo($lookbackHours), $to);

        $rows = $this->connection()->table('monitoring_dependency_buckets')
            ->where('resolution', $from)
            ->where('bucket_at', '>=', $since)
            ->orderBy('bucket_at')
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $grouped = [];
        foreach ($rows as $row) {
            $parent = $this->truncate(Clock::parse($row->bucket_at), $to)->toDateTimeString();
            $grouped[$parent . '|' . $row->service . '|' . $row->operation][] = $row;
        }

        $payload = [];
        foreach ($grouped as $key => $group) {
            [$parent, $service, $operation] = array_pad(explode('|', $key, 3), 3, '');

            $histogram = new Histogram();
            $totals = array_fill_keys(['calls', 'failures', 'timeouts', 'client_errors', 'server_errors', 'rate_limited', 'duration_sum_ms'], 0);
            $maxMs = null;
            $lastSuccess = null;
            $lastFailure = null;
            $lastError = null;

            foreach ($group as $row) {
                foreach (array_keys($totals) as $field) {
                    $totals[$field] += (int) ($row->{$field} ?? 0);
                }
                $maxMs = $row->duration_max_ms === null ? $maxMs : max((int) $maxMs, (int) $row->duration_max_ms);
                $lastSuccess = $row->last_success_at ?? $lastSuccess;
                $lastFailure = $row->last_failure_at ?? $lastFailure;
                $lastError = $row->last_error ?? $lastError;
                $histogram->merge(Histogram::fromState(
                    json_decode((string) $row->duration_buckets, true) ?: [],
                    (int) $row->calls,
                    (float) $row->duration_sum_ms,
                    null,
                    $row->duration_max_ms !== null ? (float) $row->duration_max_ms : null,
                ));
            }

            $payload[] = array_merge([
                'resolution' => $to,
                'bucket_at' => $parent,
                'service' => $service,
                'operation' => $operation,
                'duration_buckets' => json_encode($histogram->counts()),
                'duration_max_ms' => $maxMs,
                'last_success_at' => $lastSuccess,
                'last_failure_at' => $lastFailure,
                'last_error' => $lastError,
            ], $totals);
        }

        foreach (array_chunk($payload, 200) as $chunk) {
            $this->connection()->table('monitoring_dependency_buckets')->upsert(
                $chunk,
                ['resolution', 'bucket_at', 'service', 'operation'],
                array_keys($chunk[0]),
            );
        }

        return count($payload);
    }

    /**
     * Delete everything past its configured lifetime.
     *
     * Deleted in chunks: one DELETE across a week of minute buckets on a busy shop is a long lock
     * on a table the request path writes to every minute.
     */
    private function prune(): int
    {
        // Through the settings service, not config(): a window changed on the Settings page has to
        // be the window this command prunes by, or the page is describing a system it cannot move.
        $settings = app(MonitoringSettings::class);
        $retention = [];
        foreach (['minute_days' => 7, 'hour_days' => 90, 'day_days' => 400, 'trace_days' => 3, 'error_days' => 60, 'log_days' => 14, 'incident_days' => 400] as $kind => $default) {
            $retention[$kind] = $settings->retentionDays($kind, $default);
        }
        $deleted = 0;

        $plan = [
            ['monitoring_request_buckets', 'bucket_at', 'minute', (int) ($retention['minute_days'] ?? 7)],
            ['monitoring_request_buckets', 'bucket_at', 'hour', (int) ($retention['hour_days'] ?? 90)],
            ['monitoring_request_buckets', 'bucket_at', 'day', (int) ($retention['day_days'] ?? 400)],
            ['monitoring_series', 'bucket_at', 'minute', (int) ($retention['minute_days'] ?? 7)],
            ['monitoring_series', 'bucket_at', 'hour', (int) ($retention['hour_days'] ?? 90)],
            ['monitoring_series', 'bucket_at', 'day', (int) ($retention['day_days'] ?? 400)],
            ['monitoring_dependency_buckets', 'bucket_at', 'minute', (int) ($retention['minute_days'] ?? 7)],
            ['monitoring_dependency_buckets', 'bucket_at', 'hour', (int) ($retention['hour_days'] ?? 90)],
            // The rollup writes day rows for this table too, and they were the one resolution with
            // no line in this plan — so they grew for the life of the installation.
            ['monitoring_dependency_buckets', 'bucket_at', 'day', (int) ($retention['day_days'] ?? 400)],
            ['monitoring_slow_queries', 'bucket_at', 'hour', (int) ($retention['hour_days'] ?? 90)],
        ];

        foreach ($plan as [$table, $column, $resolution, $days]) {
            $deleted += $this->deleteInChunks(
                fn () => $this->connection()->table($table)
                    ->where('resolution', $resolution)
                    ->where($column, '<', Clock::daysAgo(max(1, $days))),
            );
        }

        // Tables with no resolution column, each on its own lifetime.
        foreach ([
            ['monitoring_traces', 'started_at', (int) ($retention['trace_days'] ?? 3)],
            ['monitoring_errors', 'created_at', (int) ($retention['error_days'] ?? 60)],
            ['monitoring_check_results', 'checked_at', (int) ($retention['hour_days'] ?? 90)],
            ['monitoring_scheduled_runs', 'started_at', (int) ($retention['hour_days'] ?? 90)],
            ['monitoring_events', 'occurred_at', (int) ($retention['incident_days'] ?? 400)],
            // The operations history. These three grow one row per backup, per deploy and per
            // outage — slowly, but forever: without an entry here they were the only monitoring
            // tables with no lifetime at all, so a shop three years old still carried its first
            // week of deploys. They share incident_days because they are read together: an
            // incident whose deployment row has been pruned has lost the answer to "what changed".
            ['monitoring_backups', 'started_at', (int) ($retention['incident_days'] ?? 400)],
            ['monitoring_deployments', 'deployed_at', (int) ($retention['incident_days'] ?? 400)],
        ] as [$table, $column, $days]) {
            $deleted += $this->deleteInChunks(
                fn () => $this->connection()->table($table)->where($column, '<', Clock::daysAgo(max(1, $days))),
            );
        }

        // Spans belong to their trace; orphans would otherwise outlive it forever.
        $deleted += $this->deleteInChunks(fn () => $this->connection()->table('monitoring_spans')
            ->whereNotIn('trace_id', $this->connection()->table('monitoring_traces')->select('trace_id')));

        // An incident that is CLOSED and older than the window. An open one is never pruned,
        // however old it is — an incident nobody resolved is the one row worth keeping.
        $deleted += $this->deleteInChunks(fn () => $this->connection()->table('monitoring_incidents')
            ->whereNotNull('resolved_at')
            ->where('started_at', '<', Clock::daysAgo(max(1, (int) ($retention['incident_days'] ?? 400)))));

        // An error group whose every occurrence has aged out, and which nobody is looking at.
        // Counted, like every other delete here: the command reports how many rows it removed, and
        // discarding this one made that figure quietly short.
        $deleted += (int) $this->connection()->table('monitoring_error_groups')
            ->where('status', '!=', 'open')
            ->where('last_seen_at', '<', Clock::daysAgo(max(1, (int) ($retention['error_days'] ?? 60))))
            ->delete();

        return $deleted;
    }

    private function deleteInChunks(callable $query): int
    {
        $deleted = 0;
        do {
            $round = $query()->limit(2000)->delete();
            $deleted += $round;
        } while ($round > 0);

        return $deleted;
    }

    private function truncate(\Illuminate\Support\Carbon $moment, string $resolution): \Illuminate\Support\Carbon
    {
        return match ($resolution) {
            'day' => $moment->copy()->startOfDay(),
            'hour' => $moment->copy()->startOfHour(),
            default => $moment->copy()->startOfMinute(),
        };
    }

    private function connection(): Connection
    {
        return DB::connection(config('monitoring.connection', 'monitoring'));
    }
}
