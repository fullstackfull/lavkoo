<?php

namespace App\Services\Monitoring\Checks;

use App\Services\Monitoring\Collectors\CollectorRegistry;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\MonitoringSettings;

/**
 * Is anything draining the queue?
 *
 * The verdict is the age of the oldest waiting job, not the depth: a deep queue moving quickly is
 * a busy evening, while three jobs that have waited forty minutes is a dead worker. Reuses the
 * queue collector rather than re-reading the jobs table, so the check and the Queues panel can
 * never disagree about what they saw.
 */
class QueueCheck implements Check
{
    public function __construct(
        private readonly CollectorRegistry $collectors,
        private readonly MonitoringSettings $settings,
    ) {
    }

    public function key(): string
    {
        return 'queue';
    }

    public function kind(): string
    {
        return 'health';
    }

    public function run(): CheckResult
    {
        $readings = $this->collectors->collect('queue');
        $oldest = $readings['oldest_wait_seconds'] ?? null;

        if (!$oldest instanceof Metric) {
            return CheckResult::unknown($this->key(), 'The queue collector is not installed.');
        }

        if ($oldest->state === Metric::NOT_CONFIGURED) {
            return CheckResult::notConfigured($this->key(), (string) $oldest->remedy, ['driver' => $this->driver($readings)]);
        }

        if ($oldest->state === Metric::NOT_SUPPORTED) {
            return CheckResult::notSupported($this->key(), (string) $oldest->note, ['driver' => $this->driver($readings)]);
        }

        if (!$oldest->isOk()) {
            return CheckResult::unknown($this->key(), (string) ($oldest->note ?? 'The oldest waiting job could not be read.'));
        }

        $seconds = (int) $oldest->value;
        $context = [
            'driver' => $this->driver($readings),
            'pending' => $this->number($readings['pending'] ?? null),
            'stuck_reserved' => $this->number($readings['stuck_reserved'] ?? null),
            'failed_24h' => $this->number($readings['failed_24h'] ?? null),
        ];

        $stuck = $context['stuck_reserved'];
        if (is_numeric($stuck) && $stuck > 0) {
            return CheckResult::failing(
                $this->key(),
                "{$stuck} job(s) are reserved past retry_after — a worker died mid-job.",
                context: $context,
            );
        }

        $warning = $this->settings->threshold('queue_lag_warning_seconds');
        $critical = $this->settings->threshold('queue_lag_critical_seconds');

        if ($critical !== null && $seconds >= $critical) {
            return CheckResult::failing($this->key(), "The oldest job has waited {$seconds} s.", context: $context);
        }

        if ($warning !== null && $seconds >= $warning) {
            return CheckResult::degraded($this->key(), "The oldest job has waited {$seconds} s.", context: $context);
        }

        return CheckResult::ok(
            $this->key(),
            $context['pending'] === 0 ? 'The queue is empty.' : "The oldest job has waited {$seconds} s.",
            context: $context,
        );
    }

    private function driver(array $readings): ?string
    {
        $driver = $readings['driver'] ?? null;

        return $driver instanceof Metric ? $driver->valueOr(null) : null;
    }

    private function number(mixed $metric): int|float|null
    {
        return $metric instanceof Metric && $metric->isOk() && is_numeric($metric->value) ? $metric->value + 0 : null;
    }
}
