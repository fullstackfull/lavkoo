<?php

namespace App\Services\Monitoring\Checks;

use App\Services\Monitoring\EventLog;
use App\Services\Monitoring\Ingest\BucketWriter;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Runs every check, records what each one said, and publishes the result as a series.
 *
 * The history is the point. A check that is green right now tells you nothing about last night;
 * one row per check per run is what makes uptime, time-to-detect and time-to-recover computable
 * from measurements instead of asserted in a status page. Each result is also written as a numeric
 * series (1 for up, 0 for down, and the probe's duration), so the same alert engine that watches
 * CPU can watch availability without a second mechanism.
 *
 * A check that throws is recorded as failing with the exception on it — never swallowed, never
 * allowed to stop the checks after it.
 */
class CheckRunner
{
    /** @var array<int, class-string<Check>> */
    private const CHECKS = [
        DatabaseCheck::class,
        RedisCheck::class,
        QueueCheck::class,
        SchedulerCheck::class,
        StorageCheck::class,
        SslCheck::class,
        BackupCheck::class,
        SyntheticCheck::class,
    ];

    public function __construct(
        private readonly BucketWriter $writer,
        private readonly EventLog $events,
    ) {
    }

    /**
     * @param  array<int, string>  $only  run just these check keys; empty means all
     * @return array<int, CheckResult>
     */
    public function run(array $only = []): array
    {
        $results = [];

        foreach (self::CHECKS as $class) {
            /** @var Check $check */
            $check = app($class);

            if ($only !== [] && !in_array($check->key(), $only, true)) {
                continue;
            }

            foreach ($this->runOne($check) as $result) {
                $results[] = $result;
            }
        }

        $this->record($results);

        return $results;
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_map(static fn (string $class) => app($class)->key(), self::CHECKS);
    }

    /**
     * @return array<int, CheckResult>
     */
    private function runOne(Check $check): array
    {
        try {
            $outcome = $check->run();
        } catch (\Throwable $exception) {
            // The contract says checks handle their own failures; this is the belt to those braces.
            return [CheckResult::failing(
                $check->key(),
                Metric::describeFailure($exception),
                kind: $check->kind(),
            )];
        }

        return is_array($outcome) ? $outcome : [$outcome];
    }

    /**
     * @param  array<int, CheckResult>  $results
     */
    private function record(array $results): void
    {
        if ($results === []) {
            return;
        }

        $checkedAt = Clock::stamp();
        $rows = [];

        foreach ($results as $result) {
            $rows[] = [
                'check_key' => mb_substr($result->key, 0, 64),
                'kind' => $result->kind,
                'status' => $result->status,
                'duration_ms' => $result->durationMs,
                'detail' => $result->detail !== null ? mb_substr($result->detail, 0, 191) : null,
                'context' => $result->context === [] ? null : json_encode($result->context),
                'checked_at' => $checkedAt,
            ];
        }

        // Read BEFORE the insert, or every check compares itself against the row it just wrote.
        $previous = $this->previousStatuses(array_map(static fn (CheckResult $r) => $r->key, $results));

        try {
            DB::connection(config('monitoring.connection'))->table('monitoring_check_results')->insert($rows);
        } catch (\Throwable) {
            // Losing a check's history must not stop the run that produced it; the series write
            // below is the second copy, and the next run will record again.
        }

        $this->recordTransitions($results, $previous);
        $this->publishSeries($results);
    }

    /**
     * What each of these checks said last time.
     *
     * @param  array<int, string>  $keys
     * @return array<string, string>  check key => status
     */
    private function previousStatuses(array $keys): array
    {
        try {
            $connection = DB::connection(config('monitoring.connection'));
            $latest = [];

            foreach (array_unique($keys) as $key) {
                $row = $connection->table('monitoring_check_results')
                    ->where('check_key', $key)
                    ->orderByDesc('id')
                    ->first(['status']);

                if ($row !== null) {
                    $latest[$key] = (string) $row->status;
                }
            }

            return $latest;
        } catch (\Throwable) {
            // No history to compare against is not a transition; it is a first run.
            return [];
        }
    }

    /**
     * A check crossing between working and not is an event; a check still working is not.
     *
     * Recorded on the CHANGE only. A check that runs every five minutes and writes an event each
     * time would put 288 rows a day on the timeline per check and bury the one that mattered.
     *
     * @param  array<int, CheckResult>  $results
     * @param  array<string, string>  $previous
     */
    private function recordTransitions(array $results, array $previous): void
    {
        foreach ($results as $result) {
            $was = $previous[$result->key] ?? null;

            if ($was === null || $was === $result->status) {
                continue;
            }

            // Only movement between "this is working" and "this is not". A check going from
            // not_configured to ok because somebody finally configured it is worth a line;
            // degraded flapping to failing and back is the alert engine's business, not the axis'.
            $wasUp = in_array($was, [CheckResult::OK, CheckResult::DEGRADED], true);
            $isUp = in_array($result->status, [CheckResult::OK, CheckResult::DEGRADED], true);

            if ($wasUp === $isUp) {
                continue;
            }

            $this->events->record(
                type: EventLog::CHECK,
                severity: $isUp ? EventLog::SUCCESS : EventLog::CRITICAL,
                title: $result->key . ($isUp ? ' recovered' : ' is failing'),
                key: $result->key,
                description: $result->detail,
                context: ['from' => $was, 'to' => $result->status, 'kind' => $result->kind],
            );
        }
    }

    /**
     * Availability as a number, so uptime is computed from the same store as every other metric.
     *
     * Only checks that actually ran contribute: a not_configured check is neither up nor down, and
     * folding it in as either would make an uptime figure that is not about uptime.
     *
     * @param  array<int, CheckResult>  $results
     */
    private function publishSeries(array $results): void
    {
        $minute = intdiv(time(), 60) * 60;
        $points = [];

        foreach ($results as $result) {
            if (in_array($result->status, [CheckResult::NOT_CONFIGURED, CheckResult::NOT_SUPPORTED, CheckResult::UNKNOWN], true)) {
                continue;
            }

            $label = mb_substr($result->key, 0, 96);
            $up = $result->status === CheckResult::OK ? 1.0 : 0.0;

            $points[BucketWriter::SERIES_PREFIX . 'check.up|' . $label] = [
                'n' => 1, 'sum' => $up, 'v:min' => $up, 'v:max' => $up, 'last' => $up,
            ];

            if ($result->durationMs !== null) {
                $duration = (float) $result->durationMs;
                $points[BucketWriter::SERIES_PREFIX . 'check.duration_ms|' . $label] = [
                    'n' => 1, 'sum' => $duration, 'v:min' => $duration, 'v:max' => $duration, 'last' => $duration,
                ];
            }
        }

        if ($points !== []) {
            $this->writer->apply([$minute => $points]);
        }
    }
}
