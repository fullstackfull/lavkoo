<?php

namespace App\Services\Monitoring;

use App\Services\Monitoring\Collectors\CollectorRegistry;
use App\Services\Monitoring\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * One number for "is the shop alright", derived from measurements rather than declared.
 *
 * A health score is easy to make meaningless: pick a few numbers, average them, print 96. This one
 * has three properties that stop it being decorative.
 *
 * First, every signal is scored against a threshold the operator can see and change, and each
 * carries the reading that produced it — so a score can always be opened up and argued with.
 *
 * Second, a signal that cannot be measured does NOT score as healthy. It is excluded and the
 * COVERAGE is reported alongside the score: "94/100 from 9 of 12 signals". A dashboard that says
 * 100/100 because three quarters of its probes are broken is worse than no dashboard, and this is
 * the specific failure mode being designed out.
 *
 * Third, the score cannot be green while monitoring itself is stale. If no telemetry has arrived
 * for longer than the configured window, the answer is not a number — it is "unknown", because at
 * that point the dashboard has no idea what the shop is doing.
 */
class HealthScoreService
{
    public const HEALTHY = 'healthy';
    public const DEGRADED = 'degraded';
    public const CRITICAL = 'critical';
    public const MAINTENANCE = 'maintenance';
    public const UNKNOWN = 'unknown';

    public function __construct(private readonly CollectorRegistry $collectors)
    {
    }

    /**
     * @return array{
     *     status: string, score: int|null, coverage: array{measured: int, total: int},
     *     signals: array<int, array<string, mixed>>, stale: bool, generated_at: string
     * }
     */
    public function evaluate(): array
    {
        $stale = $this->isStale();
        $signals = $this->signals();

        $measured = array_values(array_filter($signals, static fn (array $signal) => $signal['measured']));
        $coverage = ['measured' => count($measured), 'total' => count($signals)];

        if ($measured === []) {
            return [
                'status' => self::UNKNOWN,
                'score' => null,
                'coverage' => $coverage,
                'signals' => $signals,
                'stale' => $stale,
                'generated_at' => Clock::now()->toIso8601String(),
            ];
        }

        // Weighted mean of the measured signals only. Weighting matters: the checkout being down
        // is not one twelfth of a problem.
        $weightTotal = array_sum(array_column($measured, 'weight'));
        $weighted = 0.0;
        foreach ($measured as $signal) {
            $weighted += $signal['score'] * $signal['weight'];
        }
        $score = (int) round($weighted / max(0.001, $weightTotal));

        return [
            'status' => $this->statusFor($score, $measured, $stale),
            'score' => $score,
            'coverage' => $coverage,
            'signals' => $signals,
            'stale' => $stale,
            'generated_at' => Clock::now()->toIso8601String(),
        ];
    }

    /**
     * The overall verdict.
     *
     * Deliberately not a pure function of the score: one critical signal makes the system critical
     * even when eleven others are perfect, because an average is exactly the wrong way to describe
     * "payments are failing but the CPU is fine".
     */
    private function statusFor(int $score, array $measured, bool $stale): string
    {
        if ($stale) {
            return self::UNKNOWN;
        }

        if ($this->inMaintenance()) {
            return self::MAINTENANCE;
        }

        foreach ($measured as $signal) {
            if ($signal['score'] <= 25 && $signal['weight'] >= 2) {
                return self::CRITICAL;
            }
        }

        return match (true) {
            $score >= 90 => self::HEALTHY,
            $score >= 70 => self::DEGRADED,
            default => self::CRITICAL,
        };
    }

    /**
     * Every signal that feeds the score.
     *
     * @return array<int, array<string, mixed>>
     */
    private function signals(): array
    {
        $thresholds = (array) config('monitoring.thresholds', []);

        return [
            $this->fromRequests('availability', 'request_success_rate', weight: 3, window: 15),
            $this->fromLatency('latency', 'response_time_p95', weight: 2, window: 15,
                warning: (float) ($thresholds['p95_warning_ms'] ?? 800),
                critical: (float) ($thresholds['p95_critical_ms'] ?? 2000)),
            $this->fromCollector('database', 'database_latency', 'db', 'latency_ms', weight: 3,
                warning: (float) ($thresholds['db_latency_warning_ms'] ?? 50),
                critical: (float) ($thresholds['db_latency_critical_ms'] ?? 250)),
            $this->fromCollector('cpu', 'cpu_utilisation', 'cpu', 'usage_pct', weight: 1,
                warning: (float) ($thresholds['cpu_warning'] ?? 75),
                critical: (float) ($thresholds['cpu_critical'] ?? 90)),
            $this->fromCollector('memory', 'memory_utilisation', 'memory', 'used_pct', weight: 1,
                warning: (float) ($thresholds['memory_warning'] ?? 80),
                critical: (float) ($thresholds['memory_critical'] ?? 92)),
            $this->fromCollector('disk', 'disk_utilisation', 'disk', 'used_pct', weight: 2,
                warning: (float) ($thresholds['disk_warning'] ?? 80),
                critical: (float) ($thresholds['disk_critical'] ?? 90)),
            $this->fromCollector('queue', 'queue_lag', 'queue', 'oldest_wait_seconds', weight: 2,
                warning: (float) ($thresholds['queue_lag_warning_seconds'] ?? 300),
                critical: (float) ($thresholds['queue_lag_critical_seconds'] ?? 900)),
            $this->schedulerSignal(),
            $this->fromCollector('redis', 'redis_latency', 'redis', 'latency_ms', weight: 1,
                warning: (float) ($thresholds['redis_latency_warning_ms'] ?? 10),
                critical: (float) ($thresholds['redis_latency_critical_ms'] ?? 50)),
            $this->dependencySignal(),
            $this->errorSignal(),
            $this->sslSignal((float) ($thresholds['ssl_expiry_warning_days'] ?? 21)),
        ];
    }

    /**
     * Availability, measured as the share of requests that did not fail.
     */
    private function fromRequests(string $key, string $label, int $weight, int $window): array
    {
        try {
            $row = $this->monitoring()->table('monitoring_request_buckets')
                ->where('resolution', 'minute')
                ->where('bucket_at', '>=', Clock::minutesAgo($window))
                ->selectRaw('SUM(hits) hits, SUM(errors) errors')
                ->first();

            $hits = (int) ($row->hits ?? 0);
            if ($hits === 0) {
                return $this->unmeasured($key, $label, $weight, 'No requests recorded in the last ' . $window . ' minutes.');
            }

            $errorRate = 100 * (int) ($row->errors ?? 0) / $hits;
            $thresholds = (array) config('monitoring.thresholds', []);

            return [
                'key' => $key,
                'label' => $label,
                'measured' => true,
                'weight' => $weight,
                'value' => round(100 - $errorRate, 2),
                'unit' => '%',
                'display' => round(100 - $errorRate, 2) . '% of ' . number_format($hits) . ' requests succeeded',
                'score' => $this->scoreDescending(
                    $errorRate,
                    (float) ($thresholds['error_rate_warning'] ?? 1.0),
                    (float) ($thresholds['error_rate_critical'] ?? 5.0),
                ),
                'source' => 'monitoring_request_buckets',
            ];
        } catch (\Throwable $exception) {
            return $this->unmeasured($key, $label, $weight, class_basename($exception) . ' while reading request buckets.');
        }
    }

    private function fromLatency(string $key, string $label, int $weight, int $window, float $warning, float $critical): array
    {
        try {
            $rows = $this->monitoring()->table('monitoring_request_buckets')
                ->where('resolution', 'minute')
                ->where('bucket_at', '>=', Clock::minutesAgo($window))
                ->get(['duration_buckets', 'hits', 'duration_sum_ms', 'duration_min_ms', 'duration_max_ms']);

            if ($rows->isEmpty()) {
                return $this->unmeasured($key, $label, $weight, 'No requests recorded in the last ' . $window . ' minutes.');
            }

            $histogram = new Support\Histogram();
            foreach ($rows as $row) {
                $histogram->merge(Support\Histogram::fromState(
                    json_decode((string) $row->duration_buckets, true) ?: [],
                    (int) $row->hits,
                    (float) $row->duration_sum_ms,
                    $row->duration_min_ms !== null ? (float) $row->duration_min_ms : null,
                    $row->duration_max_ms !== null ? (float) $row->duration_max_ms : null,
                ));
            }

            $p95 = $histogram->quantile(0.95);
            if ($p95 === null) {
                return $this->unmeasured($key, $label, $weight, 'Not enough samples for a percentile.');
            }

            return [
                'key' => $key,
                'label' => $label,
                'measured' => true,
                'weight' => $weight,
                'value' => round($p95, 1),
                'unit' => 'ms',
                'display' => round($p95) . 'ms at p95 across ' . number_format($histogram->count()) . ' requests',
                'score' => $this->scoreDescending($p95, $warning, $critical),
                'source' => 'monitoring_request_buckets',
            ];
        } catch (\Throwable $exception) {
            return $this->unmeasured($key, $label, $weight, class_basename($exception) . ' while computing latency.');
        }
    }

    /**
     * A signal read straight from a collector, scored against two thresholds.
     */
    private function fromCollector(string $key, string $label, string $collector, string $metric, int $weight, float $warning, float $critical): array
    {
        $readings = $this->collectors->collect($collector);
        $reading = $this->worstReading($readings, $metric);

        if (!$reading instanceof Metric || !$reading->isOk() || !is_numeric($reading->value)) {
            return $this->unmeasured(
                $key,
                $label,
                $weight,
                $this->explainMissing($collector, $metric, $reading),
                $reading instanceof Metric ? $reading->remedy : null,
            );
        }

        $value = (float) $reading->value;

        return [
            'key' => $key,
            'label' => $label,
            'measured' => true,
            'weight' => $weight,
            'value' => $value,
            'unit' => $reading->unit,
            'display' => $value . ($reading->unit ? ' ' . $reading->unit : ''),
            'score' => $this->scoreDescending($value, $warning, $critical),
            'source' => $reading->source,
        ];
    }

    /**
     * The scheduler is pass/fail rather than a gradient: cron is either driving the schedule or
     * it is not, and "mostly running" is not a state that exists.
     */
    private function schedulerSignal(): array
    {
        $readings = $this->collectors->collect('scheduler');
        $installed = $readings['cron_installed'] ?? null;

        if (!$installed instanceof Metric) {
            return $this->unmeasured('scheduler', 'scheduler', 2, 'The scheduler collector is not installed.');
        }

        if (!$installed->isOk()) {
            return [
                'key' => 'scheduler',
                'label' => 'scheduler',
                'measured' => true,
                'weight' => 2,
                'value' => 0,
                'unit' => null,
                'display' => $installed->note ?? 'The scheduler is not running.',
                'score' => 0,
                'source' => $installed->source,
                'remedy' => $installed->remedy,
            ];
        }

        $missed = $readings['missed_tasks'] ?? null;
        $failed = $readings['failed_tasks'] ?? null;

        // A count that did not arrive is not a count of zero. Awarding a full 100 for a ledger
        // nobody could read is the score equivalent of inventing a measurement, and it moved the
        // whole health figure — this signal carries a weight of two.
        if (!$missed instanceof Metric || !$missed->isOk() || !$failed instanceof Metric || !$failed->isOk()) {
            return [
                'key' => 'scheduler',
                'label' => 'scheduler',
                'measured' => false,
                'weight' => 2,
                'value' => null,
                'unit' => 'tasks',
                'display' => 'The run ledger could not be read, so whether every task ran is unknown.',
                'score' => null,
                'source' => 'Laravel Schedule + monitoring_scheduled_runs',
            ];
        }

        $broken = (int) $missed->value + (int) $failed->value;

        return [
            'key' => 'scheduler',
            'label' => 'scheduler',
            'measured' => true,
            'weight' => 2,
            'value' => $broken,
            'unit' => 'tasks',
            'display' => $broken === 0 ? 'All scheduled tasks on time' : $broken . ' task(s) missed or failed',
            'score' => $broken === 0 ? 100 : max(0, 100 - ($broken * 25)),
            'source' => 'Laravel Schedule + monitoring_scheduled_runs',
        ];
    }

    /**
     * External dependencies — the gateways and services the shop cannot complete an order without.
     */
    private function dependencySignal(): array
    {
        try {
            $row = $this->monitoring()->table('monitoring_dependency_buckets')
                ->where('resolution', 'minute')
                ->where('bucket_at', '>=', Clock::minutesAgo(30))
                ->selectRaw('SUM(calls) calls, SUM(failures) failures')
                ->first();

            $calls = (int) ($row->calls ?? 0);
            if ($calls === 0) {
                return $this->unmeasured('dependencies', 'external_services', 2, 'No outbound service calls recorded in the last 30 minutes.');
            }

            $failureRate = 100 * (int) ($row->failures ?? 0) / $calls;

            return [
                'key' => 'dependencies',
                'label' => 'external_services',
                'measured' => true,
                'weight' => 2,
                'value' => round($failureRate, 2),
                'unit' => '%',
                'display' => round($failureRate, 2) . '% of ' . number_format($calls) . ' outbound calls failed',
                'score' => $this->scoreDescending($failureRate, 2.0, 10.0),
                'source' => 'monitoring_dependency_buckets',
            ];
        } catch (\Throwable $exception) {
            return $this->unmeasured('dependencies', 'external_services', 2, class_basename($exception) . ' while reading dependency buckets.');
        }
    }

    private function errorSignal(): array
    {
        try {
            $newGroups = $this->monitoring()->table('monitoring_error_groups')
                ->where('status', 'open')
                ->where('last_seen_at', '>=', Clock::hoursAgo(1))
                ->count();

            return [
                'key' => 'errors',
                'label' => 'unresolved_errors',
                'measured' => true,
                'weight' => 2,
                'value' => $newGroups,
                'unit' => 'groups',
                'display' => $newGroups === 0 ? 'No error groups active in the last hour' : $newGroups . ' error group(s) active in the last hour',
                'score' => match (true) {
                    $newGroups === 0 => 100,
                    $newGroups <= 2 => 75,
                    $newGroups <= 5 => 50,
                    default => 20,
                },
                'source' => 'monitoring_error_groups',
            ];
        } catch (\Throwable $exception) {
            return $this->unmeasured('errors', 'unresolved_errors', 2, class_basename($exception) . ' while reading error groups.');
        }
    }

    private function sslSignal(float $warningDays): array
    {
        $readings = $this->collectors->collect('ssl');
        $days = $readings['days_until_expiry'] ?? null;

        if (!$days instanceof Metric || !$days->isOk()) {
            return $this->unmeasured(
                'ssl',
                'tls_certificate',
                1,
                $days instanceof Metric ? ($days->note ?? $days->state) : 'The TLS collector is not installed.',
                $days instanceof Metric ? $days->remedy : null,
            );
        }

        $remaining = (float) $days->value;

        return [
            'key' => 'ssl',
            'label' => 'tls_certificate',
            'measured' => true,
            'weight' => 1,
            'value' => $remaining,
            'unit' => 'days',
            'display' => (int) $remaining . ' days until expiry',
            // Ascending: MORE days is better, so the scale runs the other way to every other signal.
            'score' => match (true) {
                $remaining <= 0 => 0,
                $remaining < 7 => 20,
                $remaining < $warningDays => 60,
                default => 100,
            },
            'source' => $days->source,
        ];
    }

    /**
     * Score a metric where a bigger number is worse.
     *
     * Linear between the two thresholds rather than a cliff, so a metric drifting toward trouble
     * shows as a score sliding down instead of flipping from 100 to 0 in one sample.
     */
    private function scoreDescending(float $value, float $warning, float $critical): int
    {
        if ($critical <= $warning) {
            return $value >= $critical ? 0 : 100;
        }
        if ($value <= $warning) {
            return 100;
        }
        if ($value >= $critical) {
            return 0;
        }

        return (int) round(100 * (1 - (($value - $warning) / ($critical - $warning))));
    }

    /**
     * Find a metric that may be published per-dimension, and take the worst reading.
     *
     * Collectors that measure several of something publish one metric per instance —
     * `used_pct@/`, `used_pct@/var`, `oldest_wait_seconds@emails`. Health cares about the WORST of
     * them, because a system with one full disk is not four-fifths healthy; scoring the average
     * would let three empty disks hide the one that is about to stop the shop.
     *
     * @param  array<string, Metric>  $readings
     */
    private function worstReading(array $readings, string $metric): ?Metric
    {
        if (isset($readings[$metric])) {
            return $readings[$metric];
        }

        $worst = null;
        foreach ($readings as $name => $reading) {
            if (!str_starts_with($name, $metric . '@') || !$reading instanceof Metric || !$reading->isOk() || !is_numeric($reading->value)) {
                continue;
            }
            if ($worst === null || (float) $reading->value > (float) $worst->value) {
                $worst = $reading;
            }
        }

        return $worst;
    }

    /**
     * Why a signal has no reading, told apart properly.
     *
     * "This collector is not installed" and "this collector is installed but cannot read that
     * number here" lead to completely different actions, so they must not share a message.
     */
    private function explainMissing(string $collector, string $metric, ?Metric $reading): string
    {
        if ($reading instanceof Metric) {
            return $reading->note ?? match ($reading->state) {
                Metric::NOT_SUPPORTED => 'Not supported in this environment.',
                Metric::NOT_CONFIGURED => 'Not configured yet.',
                Metric::PERMISSION_DENIED => 'The application is not permitted to read this.',
                Metric::COLLECTOR_OFFLINE => 'Nothing has reported this recently.',
                default => 'No reading available.',
            };
        }

        return $this->collectors->get($collector) === null
            ? 'The ' . $collector . ' collector is not installed.'
            : 'The ' . $collector . ' collector reports no ' . $metric . ' on this host.';
    }

    /**
     * A signal that could not be measured. It scores nothing and is counted against coverage.
     */
    private function unmeasured(string $key, string $label, int $weight, string $reason, ?string $remedy = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'measured' => false,
            'weight' => $weight,
            'value' => null,
            'unit' => null,
            'display' => $reason,
            'score' => null,
            'source' => null,
            'remedy' => $remedy,
        ];
    }

    /**
     * Whether monitoring itself has gone quiet.
     *
     * Without this check the dashboard reports the last thing it knew as though it were current —
     * a green board describing a shop that has been down for an hour.
     */
    public function isStale(): bool
    {
        try {
            $newest = $this->monitoring()->table('monitoring_series')
                ->where('resolution', 'minute')
                ->max('bucket_at');

            if ($newest === null) {
                return true;
            }

            return Clock::parse($newest)->lt(Clock::now()->subSeconds((int) config('monitoring.stale_after_seconds', 180)));
        } catch (\Throwable) {
            return true;
        }
    }

    private function inMaintenance(): bool
    {
        try {
            return app()->isDownForMaintenance();
        } catch (\Throwable) {
            return false;
        }
    }

    private function monitoring(): \Illuminate\Database\Connection
    {
        return DB::connection(config('monitoring.connection', 'monitoring'));
    }
}
